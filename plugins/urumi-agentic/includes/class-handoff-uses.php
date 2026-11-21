<?php
/**
 * Single use for hand-off links (contract.md section 5; pl7-plan.md section 4).
 *
 * A table, not the object cache or transients: Redis is flushed on every
 * deploy. It holds only sha256 hashes of token nonces, which are not secret,
 * so the analytics replica copying it is fine.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `{prefix}urumi_agentic_handoffs` table: install and upgrade, the atomic
 * claim, the daily cleanup job and uninstall.
 */
class Urumi_Agentic_Handoff_Uses {

	const TABLE      = 'urumi_agentic_handoffs';
	const DB_VERSION = '1';

	/**
	 * Schema version installed (autoloaded: read on every request by maybe_install()).
	 */
	const OPTION_DB_VERSION = 'urumi_agentic_db_version';

	/**
	 * Unix time of the last failed install attempt (not autoloaded).
	 */
	const OPTION_DB_ATTEMPT = 'urumi_agentic_db_attempt_at';

	/**
	 * Unix time before which the cleanup schedule is not looked at again (autoloaded).
	 */
	const OPTION_CLEANUP_CHECK = 'urumi_agentic_handoffs_cleanup_check';

	/**
	 * Every option this class writes; uninstall deletes them.
	 */
	const OPTIONS = array( self::OPTION_DB_VERSION, self::OPTION_DB_ATTEMPT, self::OPTION_CLEANUP_CHECK );

	const INSTALL_RETRY = 600;

	const HOOK_CLEANUP     = 'urumi_agentic_handoffs_cleanup';
	const GROUP            = 'urumi-agentic';
	const CLEANUP_INTERVAL = 86400;
	const CHECK_TTL        = 21600;
	const CHECK_RETRY      = 300;

	/**
	 * Rows are kept this long past their expiry: pods' clocks differ a little.
	 */
	const CLOCK_GRACE  = 300;
	const DELETE_BATCH = 1000;
	const MAX_BATCHES  = 50;

	const CLAIMED = 'claimed';
	const USED    = 'used';
	const ERROR   = 'error';

	/**
	 * MySQL ER_DUP_ENTRY.
	 */
	const ER_DUP_ENTRY = 1062;

	/**
	 * MySQL ER_NO_SUCH_TABLE.
	 */
	const ER_NO_SUCH_TABLE = 1146;

	const HASH_PATTERN = '/^[0-9a-f]{64}\z/';

	/**
	 * Registers the hooks. Cheap: no query and no Action Scheduler call.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_cleanup_scheduled' ), 10, 0 );
		add_action( self::HOOK_CLEANUP, array( __CLASS__, 'run_cleanup' ), 10, 0 );
	}

	/**
	 * The table name, with this site's prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Creates or upgrades the table when the stored schema version is older than
	 * this code's (missing or malformed counts as older). Called from
	 * Urumi_Agentic_Settings::maybe_upgrade() on `plugins_loaded`, because a git
	 * deploy never runs the activation hook. When the table does not appear (e.g.
	 * no CREATE privilege), it is tried again at most every ten minutes, never on
	 * every request.
	 *
	 * One-way: a newer stored schema (a pod on older code during a rolling deploy
	 * or after a rollback) is left alone, so dbDelta() never turns the table back
	 * into an older definition. That is why schema changes must be additive (new
	 * nullable or defaulted columns, no type or name changes): old pods keep using
	 * a newer table. dbDelta() itself must stay safe to run again.
	 *
	 * @param int|null $now Unix seconds (tests).
	 * @return bool Whether this schema, or a newer one, is in place.
	 */
	public static function maybe_install( $now = null ) {
		if ( ! Urumi_Agentic_Settings::is_older( get_option( self::OPTION_DB_VERSION ), self::DB_VERSION ) ) {
			return true;
		}
		$now  = null === $now ? time() : (int) $now;
		$last = (int) get_option( self::OPTION_DB_ATTEMPT, 0 );
		if ( $last > 0 && abs( $now - $last ) < self::INSTALL_RETRY ) {
			return false;
		}
		update_option( self::OPTION_DB_ATTEMPT, (string) $now, false );

		if ( ! self::install() ) {
			return false;
		}
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
		delete_option( self::OPTION_DB_ATTEMPT );
		return true;
	}

	/**
	 * Runs dbDelta() and checks that the table exists.
	 *
	 * @return bool
	 */
	private static function install() {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$previous = $wpdb->suppress_errors( true );
		try {
			dbDelta( self::schema() );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		return self::table_exists();
	}

	/**
	 * The CREATE TABLE statement, in the exact layout dbDelta() parses.
	 *
	 * `nonce_hash` is ASCII with a binary collation: lowercase hex only, 64 bytes
	 * of index instead of 256, and an exact-byte primary key. dbDelta() compares
	 * only the type token (`char(64)`) with DESCRIBE, never the charset, so this
	 * is not re-ALTERed on later runs (wp-admin/includes/upgrade.php, dbDelta()).
	 *
	 * @return string
	 */
	private static function schema() {
		global $wpdb;
		return 'CREATE TABLE ' . self::table() . " (\n"
			. "nonce_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
			. "expires_at bigint(20) NOT NULL,\n"
			. "PRIMARY KEY  (nonce_hash),\n"
			. "KEY expires_at (expires_at)\n"
			. ') ' . $wpdb->get_charset_collate() . ';';
	}

	/**
	 * Whether the table exists. `_` is a LIKE wildcard, hence esc_like().
	 *
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check, must not be cached.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Records a nonce hash, atomically, through the primary key: of two opens
	 * at the same instant exactly one gets `claimed`. Call it only for a token
	 * that passed every check (contract section 5), so nobody without the key
	 * can add rows. A plain INSERT, not INSERT IGNORE, so a missing table or any
	 * other fault is an `error` and never looks like `used`.
	 *
	 * @param string $nonce_hash Lowercase hex sha256 of the token's nonce.
	 * @param int    $expires_at The token's expiry, Unix seconds.
	 * @return string `claimed`, `used` or `error`.
	 */
	public static function claim( $nonce_hash, $expires_at ) {
		if ( ! is_string( $nonce_hash ) || 1 !== preg_match( self::HASH_PATTERN, $nonce_hash ) ) {
			return self::ERROR;
		}
		global $wpdb;
		$table    = self::table();
		$previous = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table name; values are prepared; never cached.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (nonce_hash, expires_at) VALUES (%s, %d)", $nonce_hash, (int) $expires_at ) );
			if ( 1 === $inserted ) {
				return self::CLAIMED;
			}
			if ( false === $inserted && self::last_error_is( self::ER_DUP_ENTRY, 'Duplicate entry ' ) ) {
				return self::USED;
			}
			// The table went missing after it was installed (dropped, restored
			// from an old backup): forget the schema version so the next load's
			// maybe_install() creates it again.
			if ( false === $inserted && self::last_error_is( self::ER_NO_SUCH_TABLE, null, "doesn't exist" ) ) {
				delete_option( self::OPTION_DB_VERSION );
			}
			return self::ERROR;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	/**
	 * Whether the last statement failed with MySQL error $errno: the error
	 * number when `$wpdb` has a mysqli handle (readable through wpdb::__get),
	 * else MySQL's message, by prefix or by a fragment.
	 *
	 * @param int         $errno    MySQL error number.
	 * @param string|null $prefix   Message prefix (fallback).
	 * @param string|null $fragment Message fragment (fallback).
	 * @return bool
	 */
	private static function last_error_is( $errno, $prefix = null, $fragment = null ) {
		global $wpdb;
		$dbh = $wpdb->dbh;
		if ( $dbh instanceof mysqli ) {
			$last = mysqli_errno( $dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- wpdb exposes no error number.
			if ( 0 !== $last ) {
				return $errno === $last;
			}
		}
		$message = (string) $wpdb->last_error;
		return ( null !== $prefix && 0 === strpos( $message, $prefix ) )
			|| ( null !== $fragment && false !== strpos( $message, $fragment ) );
	}

	/**
	 * Deletes rows that expired more than CLOCK_GRACE ago, in batches. A row past
	 * its expiry is useless anyway: the token is refused before the claim.
	 *
	 * @param int $now Unix seconds.
	 * @return int Rows deleted.
	 */
	public static function cleanup( $now ) {
		global $wpdb;
		$table   = self::table();
		$deleted = 0;
		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table name; values are prepared.
			$rows = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE expires_at < %d ORDER BY expires_at LIMIT %d", (int) $now - self::CLOCK_GRACE, self::DELETE_BATCH ) );
			if ( false === $rows ) {
				break;
			}
			$deleted += (int) $rows;
			if ( (int) $rows < self::DELETE_BATCH ) {
				break;
			}
		}
		return $deleted;
	}

	/**
	 * Action Scheduler callback for the daily cleanup.
	 *
	 * @return void
	 */
	public static function run_cleanup() {
		self::cleanup( time() );
	}

	/**
	 * Makes sure the daily cleanup is scheduled, looking at Action Scheduler at
	 * most every CHECK_TTL (CHECK_RETRY after a failure), so an ordinary request
	 * costs one autoloaded option read. Runs at `action_scheduler_init` in a visitor's
	 * request, so a throw from Action Scheduler (a store fault or a throwing listener) is
	 * contained, logged and treated as a failure: the retry marker stops every request
	 * from repeating the failing call.
	 *
	 * @param int|null $now Unix seconds (tests).
	 * @return void
	 */
	public static function ensure_cleanup_scheduled( $now = null ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$now = null === $now ? time() : (int) $now;
		$ok  = false;
		try {
			if ( $now < (int) get_option( self::OPTION_CLEANUP_CHECK, 0 ) ) {
				return;
			}
			$ok = (bool) as_has_scheduled_action( self::HOOK_CLEANUP, null, self::GROUP );
			if ( ! $ok ) {
				$id = as_schedule_recurring_action( $now + self::CLEANUP_INTERVAL, self::CLEANUP_INTERVAL, self::HOOK_CLEANUP, array(), self::GROUP, true );
				$ok = (int) $id > 0;
				if ( ! $ok ) {
					// Action Scheduler 3.7+ catches a store fault and returns 0, so this is the usual failure.
					self::log_schedule_problem( 'Hand-off cleanup could not be scheduled, trying again in five minutes' );
				}
			}
		} catch ( \Throwable $e ) {
			self::log_schedule_problem( 'Hand-off cleanup scheduling error: ' . get_class( $e ) );
		}
		try {
			update_option( self::OPTION_CLEANUP_CHECK, (string) ( $now + ( $ok ? self::CHECK_TTL : self::CHECK_RETRY ) ), true );
		} catch ( \Throwable $ignored ) {
			// Best effort: if even the marker cannot be written, there is nothing left to do.
			unset( $ignored );
		}
	}

	/**
	 * Logs a scheduling problem. Callers pass fixed text and an exception's class at most,
	 * never its message.
	 *
	 * @param string $message Fixed text.
	 * @return void
	 */
	private static function log_schedule_problem( $message ) {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( $message, array( 'source' => 'urumi-agentic' ) );
			}
		} catch ( \Throwable $ignored ) {
			return;
		}
	}

	/**
	 * Deactivation: forgets the schedule marker. The plugin-wide deactivation hook cancels the
	 * cleanup job with the rest of the group; without this the marker would stay set and a
	 * reactivation could go up to CHECK_TTL without a cleanup job.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_option( self::OPTION_CLEANUP_CHECK );
	}

	/**
	 * Drops the table, deletes the options and unschedules the cleanup (uninstall.php).
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall drops our own table.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			try {
				as_unschedule_all_actions( self::HOOK_CLEANUP );
			} catch ( \Throwable $e ) {
				self::log_schedule_problem( 'Hand-off cleanup unschedule error: ' . get_class( $e ) );
			}
		}
	}
}
