<?php
/**
 * Sends the A1 check-in (contract.md section 4) from Action Scheduler jobs.
 *
 * A store counts as on only if a check-in arrived in the last 48 hours
 * (contract.md section 6). The plugin arrives by git deploy, so no activation
 * hook runs on a tenant; a changed plugin version is the "activation" signal.
 *
 * No HTTP call is made inside a web request. Every send is an Action Scheduler
 * action. On tenants that holds because urumi-magic's Urumi_Action_Scheduler_Control
 * disables the async runner and the queue runner outside WP-CLI, so only WP-CLI in
 * the `action-scheduler` container (which also has the agentic Secret mounted) runs
 * them. The handlers do not rely on that alone: outside WP-CLI and WP-Cron they send
 * nothing and put themselves back 60 seconds later. "On update" therefore means
 * within about 30 seconds of the first request after the deploy.
 *
 * "Daily" in the contract is a minimum. The recurring action runs every 12 hours,
 * because Action Scheduler computes the next run from the end of the previous one
 * (it drifts later) and one failed run must not push a store past the 48 hours.
 * A1 is idempotent, so the extra calls cost nothing.
 *
 * Three hooks, one per kind of action. Action Scheduler's `$unique` flag (3.6.0 and
 * later, so every WooCommerce from 8.2) refuses an insert when a pending or running
 * action has the same hook and group, whatever its arguments. Sharing a hook would
 * let the recurring action block the one-off. It is a best-effort guard, not a lock:
 * the group row is found with a select-then-insert and the insert is not atomic under
 * READ COMMITTED, so pods racing on the first-ever deploy can leave a few duplicate
 * chains. That is harmless (A1 is idempotent) and the daily job cancels the extra
 * pending copies it finds.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues and runs the check-in.
 */
class Urumi_Agentic_Check_In {

	/** The recurring check-in. */
	const HOOK_DAILY = 'urumi_agentic_check_in';
	/** The one-off after a version change or activation. */
	const HOOK_UPDATE = 'urumi_agentic_check_in_update';
	/** A retry (or a re-queued run) of either. Args: attempt, lenient flag, requeue count. */
	const HOOK_RETRY = 'urumi_agentic_check_in_retry';

	const GROUP          = 'urumi-agentic';
	const INTERVAL       = 43200;
	const MARKER_TTL     = 21600;
	const BACKOFF_MARKER = 300;
	const MAX_REQUEUES   = 30;
	const REQUEUE_WAIT   = 60;
	const MAX_RETRIES    = 3;
	const BLOCKS_UTILS   = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';

	/**
	 * Seconds to wait before retry number 1, 2 and 3 of one original run.
	 */
	const BACKOFF = array( 300, 1800, 7200 );

	/**
	 * Whether this request wants a one-off queued (activation in a request where
	 * Action Scheduler is already ready). A version change does not depend on it:
	 * that is read back from the stored marker, so a request that dies early loses
	 * nothing.
	 *
	 * @var bool
	 */
	private static $wanted = false;

	/**
	 * Registers the hooks. Cheap: no query and no Action Scheduler call.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'action_scheduler_init', array( __CLASS__, 'on_scheduler_ready' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( self::HOOK_UPDATE, array( __CLASS__, 'run_update' ), 10, 0 );
		add_action( self::HOOK_RETRY, array( __CLASS__, 'run_retry' ), 10, 3 );
	}

	/**
	 * Asks for one check-in now (activation). If Action Scheduler is not ready yet,
	 * the version check on the stored marker queues it when it is.
	 *
	 * @return void
	 */
	public static function request() {
		self::$wanted = true;
		if ( did_action( 'action_scheduler_init' ) ) {
			self::on_scheduler_ready();
		}
	}

	/**
	 * Runs when Action Scheduler is ready. The marker option holds
	 * `<plugin version>|<unix time>` of the last time both the daily action and the
	 * one-off were verified queued. Nothing is asked of Action Scheduler while the
	 * marker is for this version and younger than MARKER_TTL, so an ordinary request
	 * costs no query (the option is autoloaded). A different version means the
	 * one-off is due (stored state, so a request that died earlier cannot lose it);
	 * an old timestamp means only that the daily chain gets looked at again, which
	 * brings it back if it was lost (cancelled, purged, stopped by Action Scheduler
	 * after repeated failures, or the plugin was removed and re-added), and a chain
	 * that had to be created also gets a one-off, so that store checks in promptly.
	 *
	 * When queueing fails, the marker becomes `<previous version>|<time + 300>|r`: the
	 * pending version change stays pending, and nothing asks Action Scheduler again for
	 * five minutes, so a database fault does not add a query to every request. The usual
	 * failure is a 0 returned by the `$unique` insert, logged as a warning. A throw is possible
	 * too, and handled the same way (logged by class name only): the hybrid store while Action Scheduler's
	 * migration is incomplete (no unique save, so the factory falls back to a plain save
	 * that throws on a database error), the post store, or a listener on
	 * `action_scheduler_stored_action` that throws. The backoff only holds if the marker
	 * write itself succeeds. Nothing escapes into the visitor's request.
	 *
	 * @return void
	 */
	public static function on_scheduler_ready() {
		$version = '';
		$created = false;
		$now     = time();
		try {
			if ( ! self::scheduler_available() ) {
				return;
			}

			list( $version, $stamp, $backoff ) = self::read_marker();
			if ( $backoff ) {
				// A stamp far in the future (a clock-skewed pod wrote it) must not block for good.
				$due = $now >= $stamp || $stamp > $now + 2 * self::BACKOFF_MARKER || self::$wanted;
			} else {
				$due = URUMI_AGENTIC_VERSION !== $version || $stamp < $now - self::MARKER_TTL || $stamp > $now + self::MARKER_TTL || self::$wanted;
			}
			if ( ! $due ) {
				return;
			}

			$ok = self::ensure_recurring( $created );
			if ( URUMI_AGENTIC_VERSION !== $version || self::$wanted || $created ) {
				$ok = self::enqueue_update() && $ok;
			}
			if ( $ok ) {
				update_option( Urumi_Agentic_Settings::OPTION_CHECK_IN_SCHEDULED, URUMI_AGENTIC_VERSION . '|' . $now, true );
			} else {
				self::write_backoff( $version, $now, $created );
				self::log_queue_failure();
			}
		} catch ( \Throwable $e ) {
			// Runs at `init` in a visitor's request: contain it, and write the backoff so the
			// failing call is not repeated by every request.
			self::log_exception( $e );
			try {
				self::write_backoff( $version, $now, $created );
			} catch ( \Throwable $ignored ) {
				// Best effort: if even the marker cannot be written, there is nothing left to do.
				unset( $ignored );
			}
		}
		self::$wanted = false;
	}

	/**
	 * Records that queueing failed: keeps the version change pending (a wanted one-off
	 * with a current version, or a daily chain created in this attempt, is recorded as no
	 * version at all, so the one-off is still due) and looks again in five minutes.
	 *
	 * @param string $version The version in the marker before this attempt.
	 * @param int    $now     Unix time of the attempt.
	 * @param bool   $created Whether this attempt had to create the daily chain.
	 * @return void
	 */
	private static function write_backoff( $version, $now, $created ) {
		$keep = ( ( self::$wanted || $created ) && URUMI_AGENTIC_VERSION === $version ) ? '' : $version;
		update_option( Urumi_Agentic_Settings::OPTION_CHECK_IN_SCHEDULED, $keep . '|' . ( $now + self::BACKOFF_MARKER ) . '|r', true );
	}

	/**
	 * Whether the Action Scheduler functions the queueing needs exist.
	 *
	 * @return bool
	 */
	private static function scheduler_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * The marker as `[version, unix time, backoff]`; `['', 0, false]` when absent or
	 * malformed. With the backoff flag the time is when to look again.
	 *
	 * @return array{0: string, 1: int, 2: bool}
	 */
	private static function read_marker() {
		$parts = explode( '|', (string) get_option( Urumi_Agentic_Settings::OPTION_CHECK_IN_SCHEDULED, '' ) );
		$count = count( $parts );
		if ( ( 2 !== $count && 3 !== $count ) || ! ctype_digit( $parts[1] ) || ( 3 === $count && 'r' !== $parts[2] ) ) {
			return array( '', 0, false );
		}
		return array( $parts[0], (int) $parts[1], 3 === $count );
	}

	/**
	 * Makes sure a daily action exists. The result is the fact, not the attempt: the
	 * unique insert answers 0 on a database error or when a pending one was just
	 * queued, so a 0 is checked again.
	 *
	 * @param bool $created Set to true when this call had to create the chain.
	 * @return bool True once an action is known to be queued.
	 */
	private static function ensure_recurring( &$created = false ) {
		if ( ! self::scheduler_available() ) {
			return false;
		}
		if ( as_has_scheduled_action( self::HOOK_DAILY, null, self::GROUP ) ) {
			return true;
		}
		$created = true;
		$id      = as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK_DAILY, array(), self::GROUP, true );
		return (int) $id > 0 || as_has_scheduled_action( self::HOOK_DAILY, null, self::GROUP );
	}

	/**
	 * Makes sure the one-off is queued.
	 *
	 * @return bool True once an action is known to be queued.
	 */
	private static function enqueue_update() {
		if ( as_has_scheduled_action( self::HOOK_UPDATE, null, self::GROUP ) ) {
			return true;
		}
		$id = as_enqueue_async_action( self::HOOK_UPDATE, array(), self::GROUP, true );
		return (int) $id > 0 || as_has_scheduled_action( self::HOOK_UPDATE, null, self::GROUP );
	}

	/**
	 * Whether this process may send: never in a visitor's page request. On tenants that
	 * means WP-CLI only (WP-Cron is off there); WP-Cron is accepted for installs that
	 * are not on the tenant platform.
	 *
	 * @return bool
	 */
	private static function may_send() {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron();
	}

	/**
	 * Action Scheduler callback: the recurring check-in.
	 *
	 * @return void
	 */
	public static function run_daily() {
		// Nothing to re-queue: the recurring chain is its own retry.
		if ( ! self::may_send() ) {
			return;
		}
		self::cancel_duplicate_daily();
		self::run( new Urumi_Agentic_Client(), 0, false );
	}

	/**
	 * Action Scheduler callback: the check-in after an update or activation. It is
	 * lenient: a Secret or service row that lands slightly after the deploy is
	 * retried instead of given up on.
	 *
	 * @return void
	 */
	public static function run_update() {
		if ( ! self::may_send() ) {
			self::requeue( 0, true, 0 );
			return;
		}
		self::run( new Urumi_Agentic_Client(), 0, true );
	}

	/**
	 * Action Scheduler callback: retry number $attempt of an earlier run.
	 *
	 * @param int  $attempt  Retries already made (0 for a re-queued original).
	 * @param bool $lenient  Whether the original was the update run.
	 * @param int  $requeues Times this run has been put back for running outside WP-CLI.
	 * @return void
	 */
	public static function run_retry( $attempt = 0, $lenient = false, $requeues = 0 ) {
		if ( ! self::may_send() ) {
			self::requeue( (int) $attempt, (bool) $lenient, (int) $requeues );
			return;
		}
		self::run( new Urumi_Agentic_Client(), (int) $attempt, (bool) $lenient );
	}

	/**
	 * Puts an update or retry run back 60 seconds when it was started outside WP-CLI.
	 * At most one retry stays pending, and after MAX_REQUEUES the run is dropped with
	 * one log line, so a runner that never reaches WP-CLI cannot loop forever.
	 *
	 * @param int  $attempt  Attempt number to carry.
	 * @param bool $lenient  Update-run flag.
	 * @param int  $requeues Requeues so far.
	 * @return void
	 */
	private static function requeue( $attempt, $lenient, $requeues ) {
		try {
			if ( $requeues >= self::MAX_REQUEUES ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'A1 check-in dropped: it kept running outside WP-CLI', array( 'source' => 'urumi-agentic' ) );
				}
				return;
			}
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$pending = as_get_scheduled_actions(
					array(
						'hook'     => self::HOOK_RETRY,
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 20,
					)
				);
				foreach ( $pending as $action ) {
					$args = is_object( $action ) && method_exists( $action, 'get_args' ) ? $action->get_args() : array();
					// A pending retry covers this run unless this one is the lenient update run
					// and that one is not.
					if ( ! $lenient || ! empty( $args[1] ) ) {
						if ( function_exists( 'wc_get_logger' ) ) {
							wc_get_logger()->debug( 'A1 check-in requeue skipped: a retry is already pending', array( 'source' => 'urumi-agentic' ) );
						}
						return;
					}
				}
			}
			self::schedule_retry( $attempt, $lenient, self::REQUEUE_WAIT, $requeues + 1 );
		} catch ( \Throwable $e ) {
			self::log_exception( $e );
		}
	}

	/**
	 * The running daily action is the only one that should be in flight, so any
	 * pending daily action is a duplicate chain from a deploy race: cancel it.
	 *
	 * @return void
	 */
	private static function cancel_duplicate_daily() {
		try {
			if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
				return;
			}
			$extra = as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK_DAILY,
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 20,
				),
				'ids'
			);
			foreach ( $extra as $unused ) {
				as_unschedule_action( self::HOOK_DAILY, array(), self::GROUP );
			}
		} catch ( \Throwable $e ) {
			self::log_exception( $e );
		}
	}

	/**
	 * Sends the check-in, records the outcome and schedules a retry when one could
	 * help. Never throws: a throw would make Action Scheduler mark the action failed.
	 *
	 * @param Urumi_Agentic_Client $client  Client over the mounted Secret.
	 * @param int                  $attempt Retries already made for this original run.
	 * @param bool                 $lenient Also retry a missing Secret or unknown store.
	 * @return void
	 */
	public static function run( $client, $attempt = 0, $lenient = false ) {
		try {
			// Brings back a daily action that went missing.
			self::ensure_recurring();

			try {
				$result = $client->check_in( self::checkout_type() );
			} catch ( \Throwable $e ) {
				self::log_exception( $e );
				$result = array(
					'ok'      => false,
					'status'  => 0,
					'headers' => array(),
					'data'    => null,
					'error'   => array( 'code' => 'exception' ),
				);
			}

			self::record( $result );
			if ( $result['ok'] ) {
				return;
			}

			self::log_failure( $result );
			if ( $attempt < self::MAX_RETRIES && self::is_transient( $result, $lenient ) ) {
				self::schedule_retry( $attempt + 1, $lenient, self::retry_delay( $attempt, $result ) );
			}
		} catch ( \Throwable $e ) {
			self::log_exception( $e );
		}
	}

	/**
	 * `blocks` or `classic`, from what the checkout page actually holds. WooCommerce's
	 * `is_checkout_block_default()` reports Woo's default, not what is in use (on WC
	 * 8.2 it is true on any block theme, and later versions miss theme-file
	 * templates), so it only decides when the page says nothing.
	 *
	 * @return string
	 */
	public static function checkout_type() {
		try {
			if ( function_exists( 'wc_get_page_id' ) ) {
				$page_id = (int) wc_get_page_id( 'checkout' );
				if ( $page_id > 0 ) {
					if ( has_block( 'woocommerce/checkout', $page_id ) ) {
						return 'blocks';
					}
					if ( has_shortcode( (string) get_post_field( 'post_content', $page_id ), 'woocommerce_checkout' ) ) {
						return 'classic';
					}
				}
			}
			if ( class_exists( self::BLOCKS_UTILS ) && method_exists( self::BLOCKS_UTILS, 'is_checkout_block_default' ) ) {
				return call_user_func( array( self::BLOCKS_UTILS, 'is_checkout_block_default' ) ) ? 'blocks' : 'classic';
			}
		} catch ( \Throwable $e ) {
			return 'classic';
		}
		return 'classic';
	}

	/**
	 * Whether trying again could work: no answer, throttled, a server error, a
	 * clock skew between the pod and the service, or a key the service does not
	 * know yet (401 `unknown_key`: a rotation in flight; the retry is a new run with
	 * a new client, so it signs with whatever key is current then, as the contract's
	 * error table asks, the same as A4). The update run also retries a
	 * missing Secret and any 404 (the service's row for the store can land just
	 * after the enable; the contract names `unknown_store`, but codes may change).
	 *
	 * @param array $result  Client result.
	 * @param bool  $lenient The update run.
	 * @return bool
	 */
	private static function is_transient( array $result, $lenient ) {
		$status = (int) $result['status'];
		$code   = self::error_code( $result );
		if ( 0 === $status ) {
			return 'transport_error' === $code || ( $lenient && 'not_configured' === $code );
		}
		return 429 === $status
			|| $status >= 500
			|| ( 401 === $status && in_array( $code, array( 'stale_request', 'unknown_key' ), true ) )
			|| ( $lenient && 404 === $status );
	}

	/**
	 * Seconds before the next retry: the backoff step, or the service's
	 * Retry-After on a 429 when that is longer (capped at a day).
	 *
	 * @param int   $attempt Retries already made.
	 * @param array $result  Client result.
	 * @return int
	 */
	private static function retry_delay( $attempt, array $result ) {
		$delay = self::BACKOFF[ min( $attempt, count( self::BACKOFF ) - 1 ) ];
		if ( 429 === (int) $result['status'] ) {
			$after = $result['headers']['retry-after'] ?? '';
			if ( is_string( $after ) && ctype_digit( $after ) ) {
				$delay = max( $delay, min( (int) $after, 86400 ) );
			}
		}
		return $delay;
	}

	/**
	 * Schedules one retry. Not `$unique`: the run that is scheduling it is itself
	 * a running action of this hook, which would block the insert.
	 *
	 * @param int  $attempt  Attempt number the new action will carry.
	 * @param bool $lenient  Update-run flag.
	 * @param int  $delay    Seconds from now.
	 * @param int  $requeues Requeue count the new action will carry.
	 * @return void
	 */
	private static function schedule_retry( $attempt, $lenient, $delay, $requeues = 0 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK_RETRY, array( $attempt, $lenient, $requeues ), self::GROUP );
		}
	}

	/**
	 * Keeps the last outcome in a non-autoloaded option. No secrets and no response
	 * body: the replica copies wp_options. `last_ok_time` survives later failures.
	 *
	 * @param array $result Client result.
	 * @return void
	 */
	private static function record( array $result ) {
		$latest = is_array( $result['data'] ) && isset( $result['data']['latest_plugin_version'] ) ? $result['data']['latest_plugin_version'] : null;
		$latest = is_string( $latest ) && preg_match( '/^[0-9A-Za-z.+-]{1,32}$/', $latest ) ? $latest : null;
		$now    = gmdate( 'Y-m-d\TH:i:s\Z' );

		$previous = json_decode( (string) get_option( Urumi_Agentic_Settings::OPTION_LAST_CHECK_IN, '' ), true );
		$last_ok  = $result['ok'] ? $now : ( is_array( $previous ) && is_string( $previous['last_ok_time'] ?? null ) ? $previous['last_ok_time'] : null );

		update_option(
			Urumi_Agentic_Settings::OPTION_LAST_CHECK_IN,
			wp_json_encode(
				array(
					'time'                  => $now,
					'ok'                    => (bool) $result['ok'],
					'status'                => (int) $result['status'],
					'error_code'            => $result['ok'] ? null : self::error_code( $result ),
					'latest_plugin_version' => $latest,
					'last_ok_time'          => $last_ok,
				)
			),
			false
		);
	}

	/**
	 * Logs status and error code only, to WooCommerce's log under `urumi-agentic`.
	 * A missing Secret is expected on a staging clone, so it is info; a stale
	 * request is most likely clock skew.
	 *
	 * @param array $result Client result.
	 * @return void
	 */
	private static function log_failure( array $result ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$code    = self::error_code( $result );
		$message = sprintf( 'A1 check-in failed: status=%d code=%s', (int) $result['status'], $code );
		if ( 'stale_request' === $code ) {
			wc_get_logger()->warning( $message . ' (likely clock skew between this pod and the service)', array( 'source' => 'urumi-agentic' ) );
		} elseif ( 'unknown_key' === $code ) {
			wc_get_logger()->warning( $message . ' (a key rotation in flight; if it persists after the retries, the Secret\'s current key is not one the service knows)', array( 'source' => 'urumi-agentic' ) );
		} elseif ( 'not_configured' === $code ) {
			wc_get_logger()->info( $message, array( 'source' => 'urumi-agentic' ) );
		} else {
			wc_get_logger()->warning( $message, array( 'source' => 'urumi-agentic' ) );
		}
	}

	/**
	 * Logs that the job hit an unexpected error: the class only, never the message.
	 *
	 * @param \Throwable $e The error.
	 * @return void
	 */
	private static function log_exception( $e ) {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( 'A1 check-in job error: ' . get_class( $e ), array( 'source' => 'urumi-agentic' ) );
			}
		} catch ( \Throwable $ignored ) {
			return;
		}
	}

	/**
	 * Logs that queueing came back without an action (a 0 from the insert, no throw). The
	 * backoff limits this to about one line per five minutes.
	 *
	 * @return void
	 */
	private static function log_queue_failure() {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( 'A1 check-in could not be queued, trying again in five minutes', array( 'source' => 'urumi-agentic' ) );
			}
		} catch ( \Throwable $ignored ) {
			return;
		}
	}

	/**
	 * The error code, cut down to a safe token. The service chooses it, so it
	 * is never logged or stored raw.
	 *
	 * @param array $result Client result.
	 * @return string
	 */
	private static function error_code( array $result ) {
		$code = isset( $result['error']['code'] ) ? (string) $result['error']['code'] : 'unknown';
		$code = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) ), 0, 64 );
		return '' === $code ? 'unknown' : $code;
	}

	/**
	 * Cancels every pending action of the plugin's group and forgets the marker.
	 * Used on deactivation and uninstall. Skipped while Action Scheduler is not
	 * ready (its API would complain). Actions left behind after the files are
	 * removed by git fail with "no callbacks" and keep rescheduling until Action
	 * Scheduler stops the recurring one after five failures, so the dashboard's
	 * disable flow (PL-2) should cancel the group over WP-CLI before it removes the
	 * plugin: `wp eval 'as_unschedule_all_actions( "", array(), "urumi-agentic" );'`.
	 *
	 * @return void
	 */
	public static function unschedule() {
		try {
			if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) ) {
				// An empty hook with a group cancels the whole group (Action Scheduler 3.6.3 and later).
				as_unschedule_all_actions( '', array(), self::GROUP );
			}
		} catch ( \Throwable $e ) {
			self::log_exception( $e );
		}
		delete_option( Urumi_Agentic_Settings::OPTION_CHECK_IN_SCHEDULED );
	}
}
