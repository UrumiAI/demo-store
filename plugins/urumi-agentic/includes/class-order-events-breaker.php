<?php
/**
 * Circuit breaker for A4 order-event sends (P1).
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pauses A4 sends while the service does not answer, so a hung service cannot
 * fill the shared Action Scheduler runner with 5 second timeouts.
 *
 * State is one non-autoloaded option row (`urumi_agentic_breaker`), JSON with
 * a fixed key order:
 *   - `v` (1), `rev` (+1 on every write);
 *   - `state`: `closed`, `open` or `half_open`;
 *   - `failures`: consecutive sends with no answer while closed;
 *   - `open_until`: jobs defer until then; `open_secs`: length of this open period;
 *   - `opened_at`, `opens`: start of this outage and how many opens it had (log and screen);
 *   - `probe_at`: when the current probe was claimed (0 if none);
 *   - `last_probe_at`: when the latest probe of any kind was claimed; set to 0
 *     only by a fresh open from `closed`;
 *   - `last_code`: code of the last counted failure or probe result.
 * A missing row reads as closed with 0 failures. So does a row that is
 * unreadable or of the wrong shape (see parse_row()); allow() rewrites it as a
 * fresh closed row and logs one `info` line. A broken row never reads as open.
 *
 * Clock skew (a pod whose clock runs fast, or a value in milliseconds): a
 * `last_probe_at` in the future reads as M ago, so an early probe can still be
 * claimed; a `probe_at` up to PROBE_TAKEOVER ahead is a live probe, one further
 * ahead is a dead probe and is taken over; an `open_until` more than
 * MAX_OPEN_SECS ahead is capped at now + MAX_OPEN_SECS (and stored that way by
 * allow()). So skew can delay sends by at most two hours at a time, never stop
 * them.
 *
 * Only a send with no HTTP answer (`transport_error`: timeout, refused, reset,
 * DNS, TLS) counts. Any HTTP answer, 5xx and `response_too_large` (the client
 * keeps the status) included, shows the service answers.
 * `not_configured`, `encode_failed` and `exception` neither count nor reset.
 *
 * Five failures in a row open the breaker for 15 minutes. Then one job at a
 * time sends as a probe: an answer closes the breaker, no answer reopens it for
 * 30, 60, then 120 minutes (the cap). A job that asks while it is open gets a
 * deferral instead of a send (Urumi_Agentic_Order_Events::handle()).
 *
 * Every read goes straight to the row and every write is a compare-and-swap on
 * the exact stored string, so two runners on pods whose clocks agree within
 * 60 s never both claim a probe. Strictly, a skew of d seconds shortens the
 * fast pod's wait before a takeover to 60 - d seconds, so the bound for a 5 s
 * send is 55 s. Pods whose clocks differ by 60 s or more can run probes at the
 * same time: the fast pod sees the slow pod's probe as 60 s old or more, and
 * the slow pod (behind by more than 60 s) sees the fast pod's probe as more
 * than PROBE_TAKEOVER ahead; each takes the other's probe over at once. Each
 * probe is a real 5 s send and a stale result changes nothing, so this delays
 * nothing and loses nothing.
 */
class Urumi_Agentic_Order_Events_Breaker {

	const OPTION = Urumi_Agentic_Settings::OPTION_BREAKER;

	const CLOSED    = 'closed';
	const OPEN      = 'open';
	const HALF_OPEN = 'half_open';

	/**
	 * Sends with no answer, in a row, that open the breaker.
	 */
	const THRESHOLD = 5;

	/**
	 * Open periods: the first open, the first reopen, and the cap. Each further
	 * reopen doubles the period up to the cap.
	 */
	const FIRST_OPEN_SECS   = 900;
	const FIRST_REOPEN_SECS = 1800;
	const MAX_OPEN_SECS     = 7200;

	/**
	 * A probe claimed this long ago is taken to be dead and is taken over. It
	 * assumes the 5 second Urumi_Agentic_Client::ORDER_EVENT_TIMEOUT: raise it
	 * if that timeout grows.
	 */
	const PROBE_TAKEOVER = 60;

	/**
	 * A job that finds a probe in flight comes back 30 to 60 seconds later.
	 */
	const PROBING_WAIT   = 30;
	const PROBING_JITTER = 30;

	/**
	 * A probe that ended `not_configured` or `encode_failed` (local, instant)
	 * opens the breaker for this long, without doubling.
	 */
	const LOCAL_RETRY = 60;

	/**
	 * M: the shortest gap between two probes of any kind. An early probe (at a
	 * last-chance run) is claimed only if no probe was claimed in the last M
	 * seconds, and a failed one keeps the breaker open at least M more seconds.
	 */
	const EARLY_PROBE_GAP = 300;

	/**
	 * Read-and-write rounds before a counter change or a claim gives up.
	 */
	const CAS_ROUNDS = 3;

	const TRANSPORT_ERROR = 'transport_error';
	const LOCAL_CODES     = array( 'not_configured', 'encode_failed' );

	/**
	 * Unix seconds: () => int.
	 *
	 * @var callable
	 */
	private $clock;
	/**
	 * A whole number in [min, max]: (min, max) => int.
	 *
	 * @var callable
	 */
	private $random;
	/**
	 * Logs one line: (level, message).
	 *
	 * @var callable
	 */
	private $logger;
	/**
	 * Reads the stored row: () => string, or null when there is no row.
	 *
	 * @var callable
	 */
	private $read;
	/**
	 * Compare-and-swap: (old string or null for no row, new string) => bool won.
	 *
	 * @var callable
	 */
	private $cas;

	/**
	 * Builds a breaker. Every dependency can be replaced in tests.
	 *
	 * @param array $deps Optional clock, random, logger, read, cas callables.
	 */
	public function __construct( array $deps = array() ) {
		$this->clock  = $deps['clock'] ?? 'time';
		$this->random = $deps['random'] ?? 'wp_rand';
		$this->logger = $deps['logger'] ?? array( 'Urumi_Agentic_Order_Events', 'wc_log' );
		$this->read   = $deps['read'] ?? array( self::class, 'db_read' );
		$this->cas    = $deps['cas'] ?? array( self::class, 'db_cas' );
	}

	/**
	 * The state of a closed breaker, in the stored key order.
	 *
	 * @return array
	 */
	public static function closed_state() {
		return array(
			'v'             => 1,
			'rev'           => 0,
			'state'         => self::CLOSED,
			'failures'      => 0,
			'open_until'    => 0,
			'open_secs'     => 0,
			'opened_at'     => 0,
			'opens'         => 0,
			'probe_at'      => 0,
			'last_probe_at' => 0,
			'last_code'     => '',
		);
	}

	/**
	 * The row the plugin installs.
	 *
	 * @return string
	 */
	public static function initial_json() {
		return (string) wp_json_encode( self::closed_state() );
	}

	/**
	 * A stored value as a state. Anything unreadable or of the wrong shape is
	 * closed with 0 failures, never open: a broken row must not stop sends.
	 *
	 * @param mixed $raw Stored value.
	 * @return array
	 */
	public static function parse( $raw ) {
		return self::parse_row( $raw ) ?? self::closed_state();
	}

	/**
	 * A stored value as a state, or null when it is not exactly the stored
	 * shape: JSON with `v` 1, a known `state`, every key present, every number a
	 * whole number of at least 0 and `last_code` a string. Nothing is cast, so a
	 * damaged row is never read as a plausible open one.
	 *
	 * @param mixed $raw Stored value.
	 * @return array|null
	 */
	private static function parse_row( $raw ) {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! in_array( $data['state'] ?? null, array( self::CLOSED, self::OPEN, self::HALF_OPEN ), true ) ) {
			return null;
		}
		$state = array();
		foreach ( self::closed_state() as $key => $default ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$value = $data[ $key ];
			$fits  = is_int( $default ) ? is_int( $value ) && $value >= 0 : is_string( $value );
			if ( ! $fits ) {
				return null;
			}
			$state[ $key ] = $value;
		}
		return $state;
	}

	/**
	 * The stored state, read fresh.
	 *
	 * @return array
	 */
	public function state() {
		return self::parse( call_user_func( $this->read ) );
	}

	/**
	 * Whether a job may send now.
	 *
	 * @param int $now Unix seconds.
	 * @return array A token: ['send' => true, 'mode' => 'closed'],
	 *               ['send' => true, 'mode' => 'probe', 'probe_at' => P], or
	 *               ['send' => false, 'until' => U, 'why' => 'open' | 'probing', ...].
	 */
	public function allow( $now ) {
		return $this->decide( (int) $now, false );
	}

	/**
	 * Like allow(), for a last-chance run that allow() sent away as `open`:
	 * also claims an early probe when no probe was claimed in the last M
	 * seconds (the token then has `'early' => true`).
	 *
	 * @param int $now Unix seconds.
	 * @return array A token, as allow().
	 */
	public function early_probe( $now ) {
		return $this->decide( (int) $now, true );
	}

	/**
	 * Applies one send's result. A stale result (the token's probe was taken
	 * over or already decided) changes nothing.
	 *
	 * @param array $token  From allow() or early_probe(), or a plain closed token.
	 * @param array $result Client result.
	 * @return void
	 */
	public function record( array $token, array $result ) {
		$status = (int) ( $result['status'] ?? 0 );
		$code   = (string) ( $result['error']['code'] ?? '' );
		if ( $status > 0 ) {
			$kind = 'answer';
		} elseif ( self::TRANSPORT_ERROR === $code ) {
			$kind = 'no_answer';
		} elseif ( in_array( $code, self::LOCAL_CODES, true ) ) {
			$kind = 'local';
		} else {
			return; // An exception, or anything else: neither counts nor resets.
		}
		$probe = 'probe' === ( $token['mode'] ?? '' ) ? (int) ( $token['probe_at'] ?? 0 ) : 0;
		$early = $probe > 0 && ! empty( $token['early'] );
		$now   = (int) call_user_func( $this->clock );

		for ( $round = 0; $round < self::CAS_ROUNDS; $round++ ) {
			$raw   = call_user_func( $this->read );
			$state = self::parse( $raw );
			$next  = $this->apply( $state, $kind, $code, $probe, $early, $now );
			if ( null === $next ) {
				return;
			}
			if ( $this->write( $raw, $state, $next[0] ) ) {
				if ( null !== $next[1] ) {
					$this->log( $next[1][0], $next[1][1] );
				}
				return;
			}
		}
	}

	/**
	 * What the merchant screen shows, or null when nothing is delayed.
	 *
	 * @param mixed $raw The stored value (get_option()).
	 * @param int   $now Unix seconds.
	 * @return array{next_try: int|null}|null `next_try` is null once `open_until`
	 *                                        has passed: the next order update resumes.
	 */
	public static function merchant_state( $raw, $now ) {
		$state = self::parse( $raw );
		if ( self::CLOSED === $state['state'] ) {
			return null;
		}
		// Never more than the longest open period ahead, even if a fast clock wrote it.
		$until = min( $state['open_until'], (int) $now + self::MAX_OPEN_SECS );
		$ahead = self::OPEN === $state['state'] && $until > (int) $now;
		return array( 'next_try' => $ahead ? $until : null );
	}

	/**
	 * Allow() and early_probe(): a token, claiming a probe by compare-and-swap.
	 * A lost claim re-reads and decides again.
	 *
	 * @param int  $now   Unix seconds.
	 * @param bool $early Whether an early probe may be claimed.
	 * @return array
	 */
	private function decide( $now, $early ) {
		for ( $round = 0; $round < self::CAS_ROUNDS; $round++ ) {
			$raw   = call_user_func( $this->read );
			$state = self::parse_row( $raw );

			if ( null === $state ) {
				if ( null !== $raw ) {
					// Unreadable or the wrong shape: closed, and rewritten as a fresh closed row.
					// A lost write means another runner wrote first (and logged); send either way.
					// The log line never carries the row: it may hold anything.
					if ( $this->write( $raw, self::closed_state(), self::closed_state() ) ) {
						$this->log( 'info', 'Order events breaker: unreadable state reset to closed' );
					}
				}
				return self::closed_token();
			}
			if ( self::CLOSED === $state['state'] ) {
				return self::closed_token();
			}
			if ( self::OPEN === $state['state'] && $state['open_until'] > $now + self::MAX_OPEN_SECS ) {
				// Written by a fast clock (or in milliseconds): cap it, store the cap, decide again.
				$next               = $state;
				$next['open_until'] = $now + self::MAX_OPEN_SECS;
				$this->write( $raw, $state, $next );
				continue;
			}
			// A probe claimed "in the future" (a fast clock) reads as claimed M ago.
			$last_probe_at = $state['last_probe_at'] > $now ? $now - self::EARLY_PROBE_GAP : $state['last_probe_at'];
			$claim_early   = false;
			if ( self::HALF_OPEN === $state['state'] ) {
				// Live: claimed less than PROBE_TAKEOVER ago, or at most PROBE_TAKEOVER ahead
				// (a pod whose clock runs a little fast). Further ahead is a fast clock or milliseconds.
				$live = $state['probe_at'] <= $now + self::PROBE_TAKEOVER && $now - $state['probe_at'] < self::PROBE_TAKEOVER;
				if ( $live ) {
					return $this->probing( $now );
				}
				// The probe's job died (or its probe_at is far in the future): take it over (a normal probe).
			} elseif ( $now < $state['open_until'] ) {
				$claim_early = $early && $now - $last_probe_at >= self::EARLY_PROBE_GAP;
				if ( ! $claim_early ) {
					return array(
						'send'          => false,
						'until'         => $state['open_until'],
						'why'           => self::OPEN,
						'open_secs'     => $state['open_secs'],
						'last_probe_at' => $last_probe_at,
						'last_code'     => $state['last_code'],
					);
				}
			}

			$next                  = $state;
			$next['state']         = self::HALF_OPEN;
			$next['probe_at']      = $now;
			$next['last_probe_at'] = $now;
			if ( $this->write( $raw, $state, $next ) ) {
				$token = array(
					'send'     => true,
					'mode'     => 'probe',
					'probe_at' => $now,
				);
				if ( $claim_early ) {
					$token['early'] = true;
				}
				return $token;
			}
		}
		// Every claim lost: another runner holds the probe.
		return $this->probing( $now );
	}

	/**
	 * The token of a send while closed.
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
	 * The deny token while a probe is in flight.
	 *
	 * @param int $now Unix seconds.
	 * @return array
	 */
	private function probing( $now ) {
		return array(
			'send'  => false,
			'until' => $now + self::PROBING_WAIT + (int) call_user_func( $this->random, 0, self::PROBING_JITTER ),
			'why'   => 'probing',
		);
	}

	/**
	 * The record() table: the new state and the log line, or null for no write.
	 *
	 * @param array  $state The state read now.
	 * @param string $kind  answer | no_answer | local.
	 * @param string $code  Error code.
	 * @param int    $probe The token's probe_at, 0 for a closed token.
	 * @param bool   $early Whether the token is an early probe.
	 * @param int    $now   Unix seconds.
	 * @return array{0: array, 1: array{0: string, 1: string}|null}|null
	 */
	private function apply( array $state, $kind, $code, $probe, $early, $now ) {
		$next = $state;

		if ( 'answer' === $kind ) {
			if ( self::HALF_OPEN === $state['state'] ) {
				$minutes = (int) round( max( 0, $now - $state['opened_at'] ) / 60 );
				$opens   = max( 1, $state['opens'] );
				$closed  = self::closed_state();
				foreach ( array( 'state', 'failures', 'open_until', 'open_secs', 'opened_at', 'opens', 'probe_at' ) as $key ) {
					$next[ $key ] = $closed[ $key ];
				}
				return array( $next, array( 'info', sprintf( 'Order events resumed after %d min (%d %s)', $minutes, $opens, 1 === $opens ? 'open' : 'opens' ) ) );
			}
			if ( self::CLOSED === $state['state'] && $state['failures'] > 0 ) {
				$next['failures'] = 0;
				return array( $next, null );
			}
			return null; // Closed with no failures, or open: the next probe decides.
		}

		if ( 0 === $probe ) {
			if ( 'no_answer' !== $kind || self::CLOSED !== $state['state'] ) {
				return null;
			}
			$next['failures']  = $state['failures'] + 1;
			$next['last_code'] = $code;
			if ( $next['failures'] < self::THRESHOLD ) {
				return array( $next, null );
			}
			$next['state']         = self::OPEN;
			$next['failures']      = 0;
			$next['open_until']    = $now + self::FIRST_OPEN_SECS;
			$next['open_secs']     = self::FIRST_OPEN_SECS;
			$next['opened_at']     = $now;
			$next['opens']         = 1;
			$next['probe_at']      = 0;
			$next['last_probe_at'] = 0;
			return array( $next, array( 'warning', sprintf( 'Order events paused for %d min: %d sends got no answer (%s)', self::FIRST_OPEN_SECS / 60, self::THRESHOLD, $code ) ) );
		}

		if ( self::HALF_OPEN !== $state['state'] || $state['probe_at'] !== $probe ) {
			return null; // A stale probe: taken over, or already decided.
		}
		$next['state']     = self::OPEN;
		$next['probe_at']  = 0;
		$next['last_code'] = $code;

		if ( 'local' === $kind ) {
			$next['open_until'] = $now + self::LOCAL_RETRY;
			return array( $next, array( 'info', sprintf( 'Order events still paused: probe ended %s; next try in %d min', $code, self::LOCAL_RETRY / 60 ) ) );
		}
		if ( $early ) {
			$next['open_until'] = max( min( $state['open_until'], $now + self::MAX_OPEN_SECS ), $now + self::EARLY_PROBE_GAP );
			return array( $next, array( 'info', sprintf( 'Order events still paused: early probe got no answer (%s); next normal try at %s', $code, gmdate( 'Y-m-d\TH:i:s\Z', $next['open_until'] ) ) ) );
		}
		$next['open_secs']  = min( max( 2 * $state['open_secs'], self::FIRST_REOPEN_SECS ), self::MAX_OPEN_SECS );
		$next['open_until'] = $now + $next['open_secs'];
		$next['opens']      = $state['opens'] + 1;
		return array(
			$next,
			array( 'warning', sprintf( 'Order events still paused: probe got no answer (%s); next try in %d min (open %d, open_secs %d)', $code, $next['open_secs'] / 60, $next['opens'], $next['open_secs'] ) ),
		);
	}

	/**
	 * Writes a new state over the one read, by compare-and-swap. `rev` goes up
	 * by one, so the new string always differs from the old one.
	 *
	 * @param string|null $raw   The value read (null: no row).
	 * @param array       $state That value, parsed.
	 * @param array       $next  The new state.
	 * @return bool Whether this write won.
	 */
	private function write( $raw, array $state, array $next ) {
		$next['rev'] = $state['rev'] + 1;
		$ordered     = array();
		foreach ( self::closed_state() as $key => $default ) {
			$ordered[ $key ] = $next[ $key ] ?? $default;
		}
		return (bool) call_user_func( $this->cas, is_string( $raw ) ? $raw : null, (string) wp_json_encode( $ordered ) );
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
			unset( $e ); // Logging must never break a job.
		}
	}

	/**
	 * Default read: the row straight from the table. The compare-and-swap has to
	 * compare against the row itself, which a cached copy may not match.
	 *
	 * @return string|null Null when there is no row.
	 * @throws RuntimeException When the query fails.
	 */
	public static function db_read() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the compare-and-swap must see the row, not a cache.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( 'The breaker row could not be read.' );
		}
		return null === $value ? null : (string) $value;
	}

	/**
	 * Default compare-and-swap. Updates the row only if it still holds $expected;
	 * a missing row is inserted only if it is still missing (INSERT IGNORE, not
	 * add_option(), whose ON DUPLICATE KEY UPDATE would let two inserts both win).
	 *
	 * @param string|null $expected The value read; null when there was no row.
	 * @param string      $value    The new value.
	 * @return bool Whether exactly this write landed.
	 * @throws RuntimeException When the query fails.
	 */
	public static function db_cas( $expected, $value ) {
		global $wpdb;
		if ( null === $expected ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an insert that must not overwrite.
			$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $value ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the exact stored value.
			$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION, $expected ) );
		}
		if ( false === $rows ) {
			throw new RuntimeException( 'The breaker row could not be written.' );
		}
		if ( 1 !== (int) $rows ) {
			return false;
		}
		wp_cache_delete( self::OPTION, 'options' );
		if ( null === $expected ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
				unset( $notoptions[ self::OPTION ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}
		return true;
	}
}
