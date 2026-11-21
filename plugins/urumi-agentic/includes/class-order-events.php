<?php
/**
 * Order status events to the service (plugin-plan.md, PL-8; contract.md A4).
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts the status changes of agent-tagged orders and queues one A4 event per change.
 *
 * Capture, on `woocommerce_before_order_object_save` (priority 1000):
 *   - only `shop_order` objects carrying `_urumi_agent_channel`, including tags
 *     the tagger added to the object before this save (its hooks run before the
 *     checkout calls save()). An untagged order costs one in-memory meta read;
 *   - a change counts when the status differs from the stored one, or when a
 *     new order is created with a real status. A move into a non-status
 *     (`checkout-draft`, `draft`, `auto-draft`, `trash`) never counts;
 *   - `_urumi_agent_status_seq` is set to the stored value plus one on the
 *     object, so the data store writes it in the same save() call as the
 *     status. That is not one DB transaction: for a few milliseconds the row
 *     can show the new status before the seq, which the A4 rules tolerate
 *     (a sweep in that window sees an unchanged seq and a different status).
 *
 * Queue, on `woocommerce_after_order_object_save` (priority 0): Woo fires it
 * only after the data store wrote the order without throwing.
 *
 * Re-entrant and interrupted saves. Woo fires `woocommerce_new_order` /
 * `woocommerce_update_order` inside the data store, after the write and
 * apply_changes(), before `after_save`. A callback that saves the same object
 * there (order attribution, order-number plugins) runs `before_save` again
 * while the first capture is still pending, and an exception thrown after the
 * write skips `after_save` altogether. So a pending capture found in
 * `before_save`, or at shutdown, is checked against the stored seq row:
 *   - stored seq >= its seq: the write landed, so it is queued now (once);
 *   - lower: the write never landed, so the unsaved increment is undone and
 *     the change worked out again.
 * The seq on the object is never lowered below what is stored.
 *
 * Send, in the Action Scheduler job: signed fresh by the client at send time;
 * retried with backoff for 24 hours after `changed_at`, reusing the `event_id`.
 * A job that dies (fatal, OOM, timeout) is rescheduled from Action Scheduler's
 * failure hooks. While the service does not answer, a circuit breaker
 * (Urumi_Agentic_Order_Events_Breaker) defers jobs instead of sending, so a
 * hung service cannot fill the shared runner (see handle()).
 *
 * Concurrency (accepted by contract A4):
 *   - two requests saving one order at the same instant can write the same seq
 *     (order meta has no atomic increment). The service sweep will repair that
 *     once it exists (#4383); until then the second event is dropped as a
 *     repeat of that seq and the agent sees the order's next change;
 *   - Action Scheduler can run batches in parallel and out of order. The
 *     service orders events by seq and drops lower ones, never by arrival.
 */
class Urumi_Agentic_Order_Events {

	const HOOK  = 'urumi_agentic_send_order_event';
	const GROUP = 'urumi-agentic';

	/**
	 * Action Scheduler priority of our actions: after store jobs at the default 10.
	 * The trade-off: A4 sends wait behind any priority-10 backlog.
	 */
	const PRIORITY = 20;

	/**
	 * After the tagger (which works before save() is called) and after
	 * ordinary `before_save` callbacks, so the status and tags we read are final.
	 */
	const BEFORE_SAVE_PRIORITY = 1000;

	/**
	 * Before ordinary `after_save` callbacks, which may save the order again.
	 */
	const AFTER_SAVE_PRIORITY = 0;

	/**
	 * Values of the status field that are not order statuses: no seq, no event.
	 * `trash` is housekeeping, not a Woo order status; untrashing reports the
	 * restored status with `status_from: null`.
	 */
	const NON_STATUSES = array( 'checkout-draft', 'draft', 'auto-draft', 'trash' );

	/**
	 * Event fields in contract order.
	 */
	const FIELDS = array( 'event_id', 'order_id', 'channel', 'mode', 'status_from', 'status_to', 'changed_at', 'seq' );

	/**
	 * Format of the Action Scheduler args: [ARGS_VERSION, ...FIELDS values, attempt].
	 */
	const ARGS_VERSION = 1;

	const MODES = array( 'handoff', 'referred' );

	/**
	 * Channels whose orders send no A4 events (contract A4, open tier): the
	 * service queues nothing for them, and a service rolled back to a version
	 * without that rule must not get any to queue. Their status changes are
	 * still counted in `_urumi_agent_status_seq`; nothing is queued, sent or
	 * recorded as failed.
	 */
	const NO_EVENT_CHANNELS = array( Urumi_Agentic_Settings::CHANNEL_OTHER );

	/**
	 * Delay before attempt n+1 after attempt n failed (n = 1..4), then hourly
	 * with ±10% jitter so retries of one outage spread out.
	 */
	const BACKOFF = array( 30, 120, 600, 3600 );
	const HOURLY  = 3600;
	const JITTER  = 0.1;

	const RETRY_AFTER_CAP = 3600;
	const GIVE_UP_AFTER   = 86400;

	/**
	 * Service error codes that a retry can never fix (agentic-service
	 * pluginapi/pluginapi.go and orderevents/intake.go), plus the client's own
	 * `encode_failed` and `redirect_refused`. Any other failure, including a
	 * non-2xx without the service's error body (a WAF page, a gateway 404 during
	 * a deploy), is retried within the 24 hours.
	 */
	const DROP_CODES = array(
		'invalid_request',
		'unknown_store',
		'body_too_large',
		'missing_signature_headers',
		'store_mismatch',
		'bad_timestamp',
		'bad_signature',
		'encode_failed',
		'redirect_refused',
	);

	/**
	 * Action Scheduler hooks for a job that died: (action id, ...).
	 */
	const AS_FAILURE_HOOKS = array( 'action_scheduler_failed_execution', 'action_scheduler_unexpected_shutdown', 'action_scheduler_failed_action' );

	/**
	 * Entries kept in the failure record, newest last.
	 */
	const FAILED_CAP = 50;

	/**
	 * The service's validation (agentic-service orderevents/event.go).
	 */
	const UUID_PATTERN       = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
	const STATUS_PATTERN     = '/^[a-z0-9][a-z0-9_-]{0,19}$/';
	const CHANGED_AT_FORMAT  = 'Y-m-d\TH:i:s\Z';
	const CHANGED_AT_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/';

	/**
	 * The registered instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Events captured in `before_save`, waiting for the save to complete. Keyed
	 * by order object (a new order has id 0 until the data store creates it).
	 * Strong references, so an event whose save threw after the write is still
	 * found at shutdown.
	 *
	 * @var SplObjectStorage
	 */
	private $pending;

	/**
	 * Events waiting for Action Scheduler's data store to be ready.
	 *
	 * @var array[]
	 */
	private $deferred = array();

	/**
	 * Whether flush_deferred() is hooked to `action_scheduler_init`.
	 *
	 * @var bool
	 */
	private $deferred_hooked = false;

	/**
	 * Action ids whose failure was already handled in this process.
	 *
	 * @var array<int, true>
	 */
	private $failures_handled = array();

	/**
	 * Injected dependencies (see __construct).
	 *
	 * @var callable
	 */
	private $clock;
	/**
	 * Returns a new Urumi_Agentic_Client (one per job, so the Secret is read fresh).
	 *
	 * @var callable
	 */
	private $client;
	/**
	 * Queues an async action: (hook, args, group) => action id, 0 on failure.
	 *
	 * @var callable
	 */
	private $enqueue;
	/**
	 * Schedules a single action: (timestamp, hook, args, group) => action id.
	 *
	 * @var callable
	 */
	private $schedule;
	/**
	 * Whether Action Scheduler's data store is ready: () => bool.
	 *
	 * @var callable
	 */
	private $ready;
	/**
	 * Reads a stored action: (action id) => [hook, args] or null.
	 *
	 * @var callable
	 */
	private $fetch;
	/**
	 * Whether an action is already queued: (hook, args, group) => bool.
	 *
	 * @var callable
	 */
	private $has_scheduled;
	/**
	 * Logs one line: (level, message).
	 *
	 * @var callable
	 */
	private $logger;
	/**
	 * Returns a random UUID.
	 *
	 * @var callable
	 */
	private $uuid;
	/**
	 * Returns a number in [-1, 1] for the retry jitter.
	 *
	 * @var callable
	 */
	private $random;
	/**
	 * Returns a whole number in [min, max] for the deferral jitter: (min, max) => int.
	 *
	 * @var callable
	 */
	private $random_int;
	/**
	 * The A4 circuit breaker.
	 *
	 * @var Urumi_Agentic_Order_Events_Breaker
	 */
	private $breaker;

	/**
	 * When this PHP process last logged a breaker failure (fail-open). A static,
	 * not an option or a transient: the database may be what is failing.
	 *
	 * @var int|null
	 */
	private static $breaker_error_logged_at = null;

	/**
	 * Builds the event queue. Every dependency can be replaced in tests.
	 *
	 * @param array $deps Optional clock, client, enqueue, schedule, ready, fetch, has_scheduled, logger, uuid, random, random_int callables and a breaker.
	 */
	public function __construct( array $deps = array() ) {
		$this->pending  = new SplObjectStorage();
		$this->clock    = $deps['clock'] ?? 'time';
		$this->client   = $deps['client'] ?? static function () {
			return new Urumi_Agentic_Client();
		};
		$this->enqueue  = $deps['enqueue'] ?? array( self::class, 'as_enqueue' );
		$this->schedule = $deps['schedule'] ?? array( self::class, 'as_schedule' );
		$this->ready    = $deps['ready'] ?? array( self::class, 'as_ready' );
		$this->fetch    = $deps['fetch'] ?? array( self::class, 'as_fetch' );

		$this->has_scheduled = $deps['has_scheduled'] ?? array( self::class, 'as_has_scheduled' );
		$this->logger        = $deps['logger'] ?? array( self::class, 'wc_log' );
		$this->uuid          = $deps['uuid'] ?? 'wp_generate_uuid4';
		$this->random        = $deps['random'] ?? static function () {
			return wp_rand( 0, 2000 ) / 1000 - 1;
		};
		$this->random_int    = $deps['random_int'] ?? 'wp_rand';
		$this->breaker       = $deps['breaker'] ?? new Urumi_Agentic_Order_Events_Breaker(
			array(
				'clock'  => $this->clock,
				'logger' => $this->logger,
			)
		);
	}

	/**
	 * Lets the next breaker failure be logged again (tests).
	 *
	 * @return void
	 */
	public static function reset_breaker_error_log() {
		self::$breaker_error_logged_at = null;
	}

	/**
	 * Registers once WooCommerce has loaded, whichever of us loads first.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( did_action( 'woocommerce_loaded' ) ) {
			self::register();
		} else {
			add_action( 'woocommerce_loaded', array( self::class, 'register' ) );
		}
	}

	/**
	 * Adds the hooks. Safe to call more than once.
	 *
	 * @return self
	 */
	public static function register() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->add_hooks();
		}
		return self::$instance;
	}

	/**
	 * Adds this instance's hooks.
	 *
	 * @return void
	 */
	public function add_hooks() {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'on_before_save' ), self::BEFORE_SAVE_PRIORITY, 2 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'on_after_save' ), self::AFTER_SAVE_PRIORITY, 1 );
		add_action( 'shutdown', array( $this, 'on_shutdown' ), 10, 0 );
		add_action( self::HOOK, array( $this, 'handle' ), 10, count( self::FIELDS ) + 2 );
		foreach ( self::AS_FAILURE_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'on_job_failed' ), 10, 1 );
		}
	}

	/**
	 * Cancels every queued event (deactivation and uninstall). Pending retries
	 * are lost; once the service sweep exists (#4383) it covers them. A throw from
	 * Action Scheduler is logged by class only and contained: on deactivation it would
	 * otherwise stop WordPress from switching the plugin off.
	 *
	 * @return void
	 */
	public static function unschedule() {
		try {
			// Hook only, no group: with a group and no args Action Scheduler matches only `[]` args.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK );
			}
		} catch ( \Throwable $e ) {
			try {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->warning( 'A4 order events unschedule error: ' . get_class( $e ), array( 'source' => 'urumi-agentic' ) );
				}
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
		}
	}

	/**
	 * Before the data store writes the order: count the change and capture the event.
	 *
	 * @param mixed $order      The order being saved.
	 * @param mixed $data_store Its data store.
	 * @return void
	 */
	public function on_before_save( $order, $data_store = null ) {
		try {
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				return;
			}
			$channel = (string) $order->get_meta( Urumi_Agentic_Order_Tagger::META_CHANNEL );
			if ( '' === $channel ) {
				return;
			}

			if ( $this->pending->contains( $order ) ) {
				$this->settle( $order, $data_store );
			}

			$to = self::new_status( $order );
			if ( $order->get_id() ) {
				$changes = $order->get_changes();
				if ( ! is_array( $changes ) || ! array_key_exists( 'status', $changes ) ) {
					return;
				}
				$data = $order->get_data();
				$from = self::strip_prefix( (string) ( $data['status'] ?? '' ) );
				if ( $from === $to ) {
					return;
				}
			} else {
				$from = '';
			}
			if ( '' === $to || in_array( $to, self::NON_STATUSES, true ) ) {
				return;
			}

			$stored = self::db_seq( $order, $data_store );
			$seq    = max( self::object_seq( $order ), (int) $stored ) + 1;
			$event  = array(
				'event_id'    => (string) call_user_func( $this->uuid ),
				'order_id'    => 0,
				'channel'     => $channel,
				'mode'        => (string) $order->get_meta( Urumi_Agentic_Order_Tagger::META_MODE ),
				'status_from' => ( '' === $from || in_array( $from, self::NON_STATUSES, true ) ) ? null : $from,
				'status_to'   => $to,
				'changed_at'  => gmdate( self::CHANGED_AT_FORMAT, (int) call_user_func( $this->clock ) ),
				'seq'         => $seq,
			);
			$order->update_meta_data( Urumi_Agentic_Order_Tagger::META_SEQ, (string) $seq );
			$this->pending[ $order ] = $event;
		} catch ( Throwable $e ) {
			$this->log( 'warning', 'Order event capture skipped after ' . get_class( $e ) );
		}
	}

	/**
	 * After the data store wrote the order: queue the captured event.
	 *
	 * @param mixed $order The saved order.
	 * @return void
	 */
	public function on_after_save( $order ) {
		try {
			if ( ! is_object( $order ) || ! $this->pending->contains( $order ) ) {
				return;
			}
			$event = $this->pending[ $order ];
			$this->pending->detach( $order );
			$this->queue( $order, $event );
		} catch ( Throwable $e ) {
			$this->log( 'warning', 'Order event queueing skipped after ' . get_class( $e ) );
		}
	}

	/**
	 * End of the request: queue captures whose save wrote the order but never
	 * reached `after_save` (an exception after the write), and record events
	 * Action Scheduler never became ready for.
	 *
	 * @return void
	 */
	public function on_shutdown() {
		try {
			$orders = array();
			foreach ( $this->pending as $order ) {
				$orders[] = $order;
			}
			foreach ( $orders as $order ) {
				$this->settle( $order, is_callable( array( $order, 'get_data_store' ) ) ? $order->get_data_store() : null );
			}
			foreach ( $this->deferred as $event ) {
				$this->fail( 'not_queued', $event, 'scheduler_not_ready', 0, 0 );
			}
			$this->deferred = array();
		} catch ( Throwable $e ) {
			$this->log( 'warning', 'Order event shutdown skipped after ' . get_class( $e ) );
		}
	}

	/**
	 * Queues events deferred until Action Scheduler's data store was ready.
	 *
	 * @return void
	 */
	public function flush_deferred() {
		$events         = $this->deferred;
		$this->deferred = array();
		foreach ( $events as $event ) {
			$this->enqueue_event( $event );
		}
	}

	/**
	 * Action Scheduler job: sends one event, then retries, drops or gives up.
	 * Never throws.
	 *
	 * A job for a channel that sends no events (`other`) returns before anything
	 * else and never asks the breaker. Otherwise the circuit breaker
	 * (Urumi_Agentic_Order_Events_Breaker) is asked first.
	 * While it is open the job does not send: it schedules the same args (same
	 * attempt) for later, always before the event's deadline (`changed_at` + 24h).
	 *
	 * Invariant: a run that starts at or past deadline − 1 sends once, bypassing
	 * the breaker, and is never rescheduled; any other run gives up without
	 * sending only if no probe is in flight and the latest probe, claimed < M
	 * ago, failed, and no slot before the deadline remains; every deferral is to
	 * a time before the deadline.
	 *   - M is Urumi_Agentic_Order_Events_Breaker::EARLY_PROBE_GAP (300 s).
	 *   - "Failed": the probe got no HTTP answer (`transport_error`) or ended
	 *     `not_configured` / `encode_failed`.
	 *   - "Slot": a time before the deadline at which this event could claim a
	 *     probe: `open_until`, or `last_probe_at + M` for an early probe.
	 * So no run gives up because of the deadline alone, and a probe in flight
	 * never causes a give-up.
	 *
	 * @param mixed ...$args [ARGS_VERSION, event fields in FIELDS order, attempt].
	 * @return void
	 */
	public function handle( ...$args ) {
		try {
			$parsed = self::from_args( $args );
			if ( null === $parsed ) {
				$this->fail( 'dropped', array(), 'invalid_args', 0, 0 );
				return;
			}
			list( $event, $attempt ) = $parsed;
			if ( ! self::sends_events( $event['channel'] ) ) {
				return; // Queued before this rule: not sent, not a failure.
			}
			$invalid = self::validation_error( $event );
			if ( null !== $invalid ) {
				$this->fail( 'dropped', $event, $invalid, 0, $attempt );
				return;
			}

			$now      = (int) call_user_func( $this->clock );
			$deadline = self::deadline( $event, $now );
			if ( $deadline <= $now + 1 ) {
				// A late run (runner lag, or a deferral to deadline - 1): send once, as without a breaker.
				$token = self::closed_token();
			} else {
				$token = $this->ask_breaker( 'allow', $now );
				if ( empty( $token['send'] ) ) {
					$token = $this->defer( $event, $attempt, $token, $now, $deadline );
					if ( null === $token ) {
						return;
					}
				}
			}

			try {
				$result = call_user_func( $this->client )->post_order_event( $event );
			} catch ( Throwable $e ) {
				$result = array(
					'ok'      => false,
					'status'  => 0,
					'headers' => array(),
					'error'   => array( 'code' => 'exception' ),
				);
			}
			$result = is_array( $result ) ? $result : array();
			// Schedule the retry (or record the failure) first: a throwing breaker must not lose it.
			$this->after_attempt( $event, $attempt, $result );
			if ( 'exception' !== ( $result['error']['code'] ?? null ) ) {
				try {
					$this->breaker->record( $token, $result );
				} catch ( Throwable $e ) {
					$this->breaker_error( $e, 'recording' );
				}
			}
		} catch ( Throwable $e ) {
			$this->log( 'warning', 'Order event job failed after ' . get_class( $e ) );
		}
	}

	/**
	 * A job the breaker turned away: schedules the same attempt for later, or
	 * claims an early probe at a last-chance run, or gives up (handle()'s
	 * invariant). Steps 2 to 5 of the plan's deferral rule.
	 *
	 * @param array $event    The event.
	 * @param int   $attempt  The attempt that was not made.
	 * @param array $deny     The breaker's deny token.
	 * @param int   $now      Unix seconds.
	 * @param int   $deadline Unix seconds; more than $now + 1.
	 * @return array|null A token to send with, or null when nothing is sent.
	 */
	private function defer( array $event, $attempt, array $deny, $now, $deadline ) {
		$gap   = Urumi_Agentic_Order_Events_Breaker::EARLY_PROBE_GAP;
		$until = (int) ( $deny['until'] ?? $now );
		if ( 'probing' === ( $deny['why'] ?? '' ) ) {
			$target = $until;
		} else {
			$spread = min( max( 0, (int) ( $deny['open_secs'] ?? 0 ) ), 1800 );
			$target = $until + 5 + (int) call_user_func( $this->random_int, 0, $spread );
		}

		if ( $target <= $deadline - $gap ) {
			$this->defer_to( $event, $attempt, $target );
			return null;
		}
		if ( $now < $deadline - $gap ) {
			// The last-chance run.
			$this->defer_to( $event, $attempt, $deadline - $gap );
			return null;
		}

		// This run is already the last chance.
		if ( 'probing' === ( $deny['why'] ?? '' ) ) {
			$this->defer_to( $event, $attempt, min( $target, $deadline - 1 ) );
			return null;
		}
		if ( $until + 5 < $deadline ) {
			$this->defer_to( $event, $attempt, $until + 5 );
			return null;
		}
		$token = $this->ask_breaker( 'early_probe', $now );
		if ( ! empty( $token['send'] ) ) {
			return $token;
		}
		if ( 'probing' === ( $token['why'] ?? '' ) ) {
			$this->defer_to( $event, $attempt, min( (int) $token['until'], $deadline - 1 ) );
			return null;
		}
		// Open, and the latest probe, claimed less than M ago, failed.
		$slot = min( (int) ( $token['until'] ?? 0 ), (int) ( $token['last_probe_at'] ?? 0 ) + $gap );
		if ( $slot < $deadline ) {
			$this->defer_to( $event, $attempt, max( $slot, $now + 1 ) );
			return null;
		}
		$this->fail( 'gave_up', $event, 'breaker_open', 0, $attempt - 1, (string) ( $token['last_code'] ?? '' ) );
		return null;
	}

	/**
	 * Schedules the same attempt again at $time, recording a failure to queue.
	 *
	 * @param array $event   The event.
	 * @param int   $attempt The attempt.
	 * @param int   $time    Unix seconds.
	 * @return void
	 */
	private function defer_to( array $event, $attempt, $time ) {
		$action_id = 0;
		try {
			$action_id = (int) call_user_func( $this->schedule, (int) $time, self::HOOK, self::to_args( $event, $attempt ), self::GROUP );
		} catch ( Throwable $e ) {
			$action_id = 0;
		}
		if ( $action_id <= 0 ) {
			$this->fail( 'not_queued', $event, 'breaker_open', 0, $attempt - 1 );
		}
	}

	/**
	 * Asks the breaker. If it throws (a database error), sends anyway: a broken
	 * breaker must never stop delivery.
	 *
	 * @param string $method allow | early_probe.
	 * @param int    $now    Unix seconds.
	 * @return array A token.
	 */
	private function ask_breaker( $method, $now ) {
		try {
			$token = 'early_probe' === $method ? $this->breaker->early_probe( $now ) : $this->breaker->allow( $now );
			if ( is_array( $token ) ) {
				return $token;
			}
		} catch ( Throwable $e ) {
			$this->breaker_error( $e, 'deciding' );
		}
		return self::closed_token();
	}

	/**
	 * Logs a breaker failure, at most once a minute per PHP process. Only the
	 * exception class is logged, never its message (it may hold SQL text).
	 *
	 * @param Throwable $e    What was thrown.
	 * @param string    $when `deciding` (allow() or early_probe(): the job sends without
	 *                        the breaker) or `recording` (record(): the send already happened).
	 * @return void
	 */
	private function breaker_error( Throwable $e, $when ) {
		$now = (int) call_user_func( $this->clock );
		if ( null !== self::$breaker_error_logged_at && $now - self::$breaker_error_logged_at < 60 ) {
			return;
		}
		self::$breaker_error_logged_at = $now;
		$what                          = 'recording' === $when ? 'while recording the result' : 'while deciding; sending without it';
		$this->log( 'error', 'Order events breaker unavailable (' . get_class( $e ) . ') ' . $what );
	}

	/**
	 * The token of a send that does not go through the breaker.
	 *
	 * @return array
	 */
	private static function closed_token() {
		return array(
			'send' => true,
			'mode' => 'closed',
		);
	}

	/**
	 * An event's deadline: `changed_at` (its first queue time) plus 24 hours.
	 *
	 * @param array $event The event.
	 * @param int   $now   Unix seconds, used when `changed_at` cannot be read.
	 * @return int
	 */
	private static function deadline( array $event, $now ) {
		$changed = DateTime::createFromFormat( '!' . self::CHANGED_AT_FORMAT, (string) $event['changed_at'], new DateTimeZone( 'UTC' ) );
		return ( $changed ? $changed->getTimestamp() : (int) $now ) + self::GIVE_UP_AFTER;
	}

	/**
	 * A job of ours died (fatal error, out of memory, killed past the failure
	 * period): Action Scheduler marks it failed and never runs it again, so the
	 * event is rescheduled like a failed send, unless the job had already
	 * scheduled its next attempt before it died (a second chain would follow).
	 * An out-of-memory fatal can leave too little memory for this callback to
	 * schedule anything; that event is then lost until the service sweep
	 * (#4383) exists.
	 *
	 * @param mixed $action_id Action id.
	 * @return void
	 */
	public function on_job_failed( $action_id ) {
		try {
			$action_id = (int) $action_id;
			if ( $action_id <= 0 || isset( $this->failures_handled[ $action_id ] ) ) {
				return;
			}
			$action = call_user_func( $this->fetch, $action_id );
			if ( ! is_array( $action ) || self::HOOK !== ( $action[0] ?? null ) ) {
				return;
			}
			$this->failures_handled[ $action_id ] = true;
			$parsed                               = self::from_args( is_array( $action[1] ?? null ) ? $action[1] : array() );
			if ( null === $parsed ) {
				$this->fail( 'dropped', array(), 'invalid_args', 0, 0 );
				return;
			}
			if ( ! self::sends_events( $parsed[0]['channel'] ) ) {
				return;
			}
			// Action Scheduler marks the action failed before it fires these hooks (checked
			// in 3.6.3 and 4.1.0), so the dying action itself never matches here. A pending
			// deferral of the same attempt, or the next attempt, means the chain goes on.
			foreach ( array( $parsed[1], $parsed[1] + 1 ) as $attempt ) {
				if ( call_user_func( $this->has_scheduled, self::HOOK, self::to_args( $parsed[0], $attempt ), self::GROUP ) ) {
					return;
				}
			}
			$this->after_attempt(
				$parsed[0],
				$parsed[1],
				array(
					'ok'     => false,
					'status' => 0,
					'error'  => array( 'code' => 'job_failed' ),
				)
			);
		} catch ( Throwable $e ) {
			$this->log( 'warning', 'Order event job failure handling skipped after ' . get_class( $e ) );
		}
	}

	/**
	 * What one send's result means: `done`, `retry` or `drop` (contract A4 retry rule).
	 *
	 * Drop: a 3xx, or a service error code in DROP_CODES. Retry: everything
	 * else that is not a 2xx: no answer, the Secret not mounted yet, 408, 429,
	 * 5xx, 401 `stale_request` / `unknown_key`, and any answer without the
	 * service's error body.
	 *
	 * @param array $result From Urumi_Agentic_Client.
	 * @return string
	 */
	public static function outcome( array $result ) {
		$status = (int) ( $result['status'] ?? 0 );
		$code   = (string) ( $result['error']['code'] ?? '' );
		if ( ! empty( $result['ok'] ) && $status >= 200 && $status < 300 ) {
			return 'done';
		}
		if ( ( $status >= 300 && $status < 400 ) || in_array( $code, self::DROP_CODES, true ) ) {
			return 'drop';
		}
		return 'retry';
	}

	/**
	 * Seconds to wait before the next attempt after attempt $attempt failed.
	 *
	 * @param int      $attempt     The attempt that failed, 1-based.
	 * @param int|null $retry_after Seconds the service asked for (429), if any.
	 * @param float    $jitter      In [-1, 1]; scales the hourly steps by up to ±10%.
	 * @return int
	 */
	public static function delay( $attempt, $retry_after = null, $jitter = 0.0 ) {
		$attempt = max( 1, (int) $attempt );
		$delay   = self::BACKOFF[ $attempt - 1 ] ?? self::HOURLY;
		if ( $delay >= self::HOURLY ) {
			$delay = (int) round( self::HOURLY * ( 1 + self::JITTER * max( -1.0, min( 1.0, (float) $jitter ) ) ) );
		}
		if ( null !== $retry_after && $retry_after > $delay ) {
			$delay = min( (int) $retry_after, max( $delay, self::RETRY_AFTER_CAP ) );
		}
		return $delay;
	}

	/**
	 * Whether orders of a channel send A4 events (all but NO_EVENT_CHANNELS).
	 *
	 * @param mixed $channel Channel.
	 * @return bool
	 */
	public static function sends_events( $channel ) {
		return ! in_array( $channel, self::NO_EVENT_CHANNELS, true );
	}

	/**
	 * Whether an event passes the service's validation.
	 *
	 * @param mixed $event Event fields.
	 * @return bool
	 */
	public static function is_valid_event( $event ) {
		return null === self::validation_error( $event );
	}

	/**
	 * Why the service would refuse an event, as a local error code, or null.
	 *
	 * @param mixed $event Event fields.
	 * @return string|null `invalid_event`, `unknown_channel`, `unknown_mode` or null.
	 */
	public static function validation_error( $event ) {
		if ( ! is_array( $event ) || array_keys( $event ) !== self::FIELDS ) {
			return 'invalid_event';
		}
		$valid = is_string( $event['event_id'] ) && 1 === preg_match( self::UUID_PATTERN, $event['event_id'] )
			&& is_int( $event['order_id'] ) && $event['order_id'] > 0
			&& ( null === $event['status_from'] || ( is_string( $event['status_from'] ) && 1 === preg_match( self::STATUS_PATTERN, $event['status_from'] ) && 0 !== strpos( $event['status_from'], 'wc-' ) ) )
			&& is_string( $event['status_to'] ) && 1 === preg_match( self::STATUS_PATTERN, $event['status_to'] )
			&& 'checkout-draft' !== $event['status_to'] && 0 !== strpos( $event['status_to'], 'wc-' )
			&& is_string( $event['changed_at'] ) && 1 === preg_match( self::CHANGED_AT_PATTERN, $event['changed_at'] )
			&& is_int( $event['seq'] ) && $event['seq'] >= 1;
		if ( ! $valid ) {
			return 'invalid_event';
		}
		if ( ! in_array( $event['channel'], Urumi_Agentic_Settings::CHANNELS, true ) ) {
			return 'unknown_channel';
		}
		if ( ! in_array( $event['mode'], self::MODES, true ) ) {
			return 'unknown_mode';
		}
		return null;
	}

	/**
	 * The Action Scheduler args for an event: [ARGS_VERSION, fields, attempt],
	 * positional, so the JSON stays under Action Scheduler's 191-character
	 * `args` column on every store (longer args go to `extended_args` on the
	 * DB store, but the old post-type store refuses them). No shopper data:
	 * ids, tags and statuses only.
	 *
	 * @param array $event   Event fields.
	 * @param int   $attempt Attempt number, 1-based.
	 * @return array
	 */
	public static function to_args( array $event, $attempt ) {
		$args = array( self::ARGS_VERSION );
		foreach ( self::FIELDS as $field ) {
			$args[] = $event[ $field ] ?? null;
		}
		$args[] = (int) $attempt;
		return $args;
	}

	/**
	 * The event and attempt from Action Scheduler args, or null for an unknown format.
	 *
	 * @param array $args Positional args.
	 * @return array{0: array, 1: int}|null
	 */
	public static function from_args( array $args ) {
		$args = array_values( $args );
		if ( count( $args ) !== count( self::FIELDS ) + 2 || self::ARGS_VERSION !== ( $args[0] ?? null ) ) {
			return null;
		}
		$event = array();
		foreach ( self::FIELDS as $i => $field ) {
			$event[ $field ] = $args[ $i + 1 ];
		}
		return array( $event, max( 1, (int) $args[ count( self::FIELDS ) + 1 ] ) );
	}

	/**
	 * Resolves a capture whose save did not reach `after_save` yet.
	 *
	 * @param WC_Order $order      The order.
	 * @param mixed    $data_store Its data store.
	 * @return void
	 */
	private function settle( $order, $data_store ) {
		$event = $this->pending[ $order ];
		$this->pending->detach( $order );
		$stored = $order->get_id() ? self::db_seq( $order, $data_store ) : 0;
		if ( null === $stored || $stored >= $event['seq'] ) {
			// The write landed (or cannot be checked: never lower, never lose).
			// If our write failed but a concurrent request pushed the stored seq
			// to or past ours, this queues an event for a status that was never
			// stored, at a seq <= the stored one; the service drops it as stale.
			// Harmless.
			$this->queue( $order, $event );
			return;
		}
		// The write never landed: the object's seq is an unsaved increment.
		if ( $stored > 0 ) {
			$order->update_meta_data( Urumi_Agentic_Order_Tagger::META_SEQ, (string) $stored );
		} else {
			$order->delete_meta_data( Urumi_Agentic_Order_Tagger::META_SEQ );
		}
	}

	/**
	 * Queues a captured event for a saved order.
	 *
	 * @param WC_Order $order The order.
	 * @param array    $event The event.
	 * @return void
	 */
	private function queue( $order, array $event ) {
		if ( ! self::sends_events( $event['channel'] ) ) {
			return;
		}
		$event['order_id'] = (int) $order->get_id();
		if ( ! call_user_func( $this->ready ) ) {
			$this->deferred[] = $event;
			if ( ! $this->deferred_hooked ) {
				$this->deferred_hooked = true;
				add_action( 'action_scheduler_init', array( $this, 'flush_deferred' ), 10, 0 );
			}
			return;
		}
		$this->enqueue_event( $event );
	}

	/**
	 * Enqueues attempt 1 of an event, recording a failure.
	 *
	 * @param array $event The event.
	 * @return void
	 */
	private function enqueue_event( array $event ) {
		$action_id = 0;
		try {
			$action_id = (int) call_user_func( $this->enqueue, self::HOOK, self::to_args( $event, 1 ), self::GROUP );
		} catch ( Throwable $e ) {
			$action_id = 0;
		}
		if ( $action_id <= 0 ) {
			$this->fail( 'not_queued', $event, 'enqueue_failed', 0, 0 );
		}
	}

	/**
	 * Handles a failed or successful attempt.
	 *
	 * @param array $event   The event.
	 * @param int   $attempt The attempt just made.
	 * @param array $result  Client result.
	 * @return void
	 */
	private function after_attempt( array $event, $attempt, array $result ) {
		$outcome = self::outcome( $result );
		$status  = (int) ( $result['status'] ?? 0 );
		$code    = (string) ( $result['error']['code'] ?? ( 'done' === $outcome ? '' : 'http_' . $status ) );

		if ( 'done' === $outcome ) {
			if ( $attempt > 1 ) {
				$this->log( 'info', self::describe( 'Order event delivered after ' . $attempt . ' attempts', $event, '', $status ) );
			}
			return;
		}
		if ( 'drop' === $outcome ) {
			$this->fail( 'dropped', $event, $code, $status, $attempt );
			return;
		}

		$now      = (int) call_user_func( $this->clock );
		$next     = $now + self::delay( $attempt, 429 === $status ? self::retry_after( $result, $now ) : null, (float) call_user_func( $this->random ) );
		$deadline = self::deadline( $event, $now );
		if ( $next > $deadline ) {
			$this->fail( 'gave_up', $event, $code, $status, $attempt );
			return;
		}

		$action_id = 0;
		try {
			$action_id = (int) call_user_func( $this->schedule, $next, self::HOOK, self::to_args( $event, $attempt + 1 ), self::GROUP );
		} catch ( Throwable $e ) {
			$action_id = 0;
		}
		if ( $action_id <= 0 ) {
			$this->fail( 'not_queued', $event, $code, $status, $attempt );
			return;
		}
		$this->log( 'info', self::describe( 'Order event retry ' . ( $attempt + 1 ) . ' in ' . ( $next - $now ) . 's', $event, $code, $status ) );
	}

	/**
	 * Logs and records an event that will not be delivered (or not retried).
	 *
	 * The record is a capped, non-autoloaded option, so it costs one small
	 * write per failure and can be read from the replica or WP-CLI. Logs alone
	 * rotate away and are not visible to Lane 1. Two jobs failing at the same
	 * instant can lose one entry; the log line remains.
	 *
	 * @param string      $outcome   dropped | gave_up | not_queued.
	 * @param array       $event     The event (may be partial).
	 * @param string      $code      Error code.
	 * @param int         $status    HTTP status, 0 for none.
	 * @param int         $attempts  Attempts made.
	 * @param string|null $last_code For `breaker_open`: the breaker's last code, so a
	 *                               missing Secret during an outage still shows.
	 * @return void
	 */
	private function fail( $outcome, array $event, $code, $status, $attempts, $last_code = null ) {
		$this->log( 'warning', self::describe( 'Order event ' . $outcome, $event, $code, $status ) );

		$stored = json_decode( (string) get_option( Urumi_Agentic_Settings::OPTION_ORDER_EVENTS_FAILED, '[]' ), true );
		$stored = is_array( $stored ) ? array_values( $stored ) : array();

		$entry = array(
			'at'          => gmdate( self::CHANGED_AT_FORMAT, (int) call_user_func( $this->clock ) ),
			'outcome'     => $outcome,
			'code'        => (string) $code,
			'http_status' => (int) $status,
			'attempts'    => max( 0, (int) $attempts ),
			'event_id'    => is_string( $event['event_id'] ?? null ) ? $event['event_id'] : null,
			'order_id'    => is_scalar( $event['order_id'] ?? null ) ? (int) $event['order_id'] : null,
			'seq'         => is_scalar( $event['seq'] ?? null ) ? (int) $event['seq'] : null,
			'status_to'   => is_string( $event['status_to'] ?? null ) ? $event['status_to'] : null,
		);
		if ( null !== $last_code ) {
			$entry['last_code'] = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $last_code );
		}
		$stored[] = $entry;
		$stored   = array_slice( $stored, -self::FAILED_CAP );
		update_option( Urumi_Agentic_Settings::OPTION_ORDER_EVENTS_FAILED, wp_json_encode( $stored ), false );
	}

	/**
	 * A log line with the order id, seq, status and error code only.
	 *
	 * @param string $what   What happened.
	 * @param array  $event  The event.
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @return string
	 */
	private static function describe( $what, array $event, $code, $status ) {
		$line = sprintf(
			'%s: order %d seq %d status %s',
			$what,
			is_scalar( $event['order_id'] ?? null ) ? (int) $event['order_id'] : 0,
			is_scalar( $event['seq'] ?? null ) ? (int) $event['seq'] : 0,
			is_string( $event['status_to'] ?? null ) ? preg_replace( '/[^a-z0-9_-]/', '', $event['status_to'] ) : '?'
		);
		if ( '' !== $code ) {
			$line .= ' code ' . preg_replace( '/[^A-Za-z0-9_.:-]/', '', $code );
		}
		if ( $status > 0 ) {
			$line .= ' (HTTP ' . (int) $status . ')';
		}
		return $line;
	}

	/**
	 * Seconds from a 429's Retry-After (delta seconds or an HTTP date), or null.
	 *
	 * @param array $result Client result.
	 * @param int   $now    Unix seconds.
	 * @return int|null
	 */
	private static function retry_after( array $result, $now ) {
		$value = trim( (string) ( $result['headers']['retry-after'] ?? '' ) );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$time = strtotime( $value );
		return false === $time ? null : max( 0, $time - $now );
	}

	/**
	 * The status the save will store, without `wc-`. A new order's empty status
	 * is stored as the `woocommerce_default_order_status` filter's value, as
	 * both order data stores do.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private static function new_status( $order ) {
		$status = self::strip_prefix( (string) $order->get_status( 'edit' ) );
		if ( '' === $status ) {
			$status = self::strip_prefix( (string) apply_filters( 'woocommerce_default_order_status', 'pending' ) );
		}
		return $status;
	}

	/**
	 * A status slug without Woo's `wc-` prefix.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function strip_prefix( $status ) {
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * The seq on the order object. Missing counts as 0.
	 *
	 * @param WC_Order $order The order.
	 * @return int
	 */
	private static function object_seq( $order ) {
		return max( 0, (int) $order->get_meta( Urumi_Agentic_Order_Tagger::META_SEQ ) );
	}

	/**
	 * The stored seq row, read fresh from the order's meta table (both data
	 * stores' read_meta() query the table directly). 0 for a new order or a
	 * missing row; null when there is no data store to ask. Taking the larger
	 * of this and the object's value means an object loaded before another
	 * save in this request bumped the seq does not write the same number again.
	 *
	 * @param WC_Order $order      The order.
	 * @param mixed    $data_store Its data store.
	 * @return int|null
	 */
	private static function db_seq( $order, $data_store ) {
		if ( ! $order->get_id() ) {
			return 0;
		}
		if ( ! is_object( $data_store ) || ! is_callable( array( $data_store, 'read_meta' ) ) ) {
			return null;
		}
		$seq  = 0;
		$rows = $data_store->read_meta( $order );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_object( $row ) && isset( $row->meta_key ) && Urumi_Agentic_Order_Tagger::META_SEQ === $row->meta_key ) {
				$seq = max( $seq, (int) $row->meta_value );
			}
		}
		return $seq;
	}

	/**
	 * Logs one line.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @return void
	 */
	private function log( $level, $message ) {
		try {
			call_user_func( $this->logger, $level, $message );
		} catch ( Throwable $e ) {
			unset( $e ); // Logging must never break a save or a job.
		}
	}

	/**
	 * Default enqueue: Action Scheduler's async action, at priority 20 so store
	 * jobs at the default 10 are claimed first (Action Scheduler 3.6+).
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args.
	 * @param string $group Group.
	 * @return int Action id, 0 when Action Scheduler is missing or not ready.
	 */
	public static function as_enqueue( $hook, array $args, $group ) {
		return function_exists( 'as_enqueue_async_action' ) ? (int) as_enqueue_async_action( $hook, $args, $group, false, self::PRIORITY ) : 0;
	}

	/**
	 * Default schedule: Action Scheduler's single action, at priority 20.
	 *
	 * @param int    $timestamp When.
	 * @param string $hook      Hook.
	 * @param array  $args      Args.
	 * @param string $group     Group.
	 * @return int Action id, 0 when Action Scheduler is missing or not ready.
	 */
	public static function as_schedule( $timestamp, $hook, array $args, $group ) {
		return function_exists( 'as_schedule_single_action' ) ? (int) as_schedule_single_action( $timestamp, $hook, $args, $group, false, self::PRIORITY ) : 0;
	}

	/**
	 * Whether Action Scheduler's data store is ready. True when Action
	 * Scheduler is missing altogether, so the enqueue fails and is recorded
	 * rather than waiting for an `action_scheduler_init` that never comes.
	 *
	 * @return bool
	 */
	public static function as_ready() {
		if ( ! class_exists( 'ActionScheduler' ) || ! is_callable( array( 'ActionScheduler', 'is_initialized' ) ) ) {
			return true;
		}
		return (bool) ActionScheduler::is_initialized();
	}

	/**
	 * Default fetch: a stored action's hook and args.
	 *
	 * @param int $action_id Action id.
	 * @return array|null [hook, args].
	 */
	public static function as_fetch( $action_id ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! is_callable( array( 'ActionScheduler', 'store' ) ) ) {
			return null;
		}
		$action = ActionScheduler::store()->fetch_action( $action_id );
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_hook' ) ) {
			return null;
		}
		return array( $action->get_hook(), $action->get_args() );
	}

	/**
	 * Default has_scheduled: whether a pending or running action matches exactly.
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args.
	 * @param string $group Group.
	 * @return bool
	 */
	public static function as_has_scheduled( $hook, array $args, $group ) {
		return function_exists( 'as_has_scheduled_action' ) && (bool) as_has_scheduled_action( $hook, $args, $group );
	}

	/**
	 * Default logger: WooCommerce's, source `urumi-agentic`.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @return void
	 */
	public static function wc_log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'urumi-agentic' ) );
		}
	}
}
