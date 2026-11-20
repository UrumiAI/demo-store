<?php
/**
 * Settings the service reads from the store's read replica (contract.md section 6).
 *
 * The service reads these rows straight out of `wp_options` on the replica, so
 * the stored shape is part of the contract: channels are a JSON string, never a
 * PHP-serialized array, and every value is a plain string.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's `wp_options` rows.
 */
class Urumi_Agentic_Settings {

	const OPTION_CHANNELS       = 'urumi_agentic_channels';
	const OPTION_RISKY_ORDERS   = 'urumi_agentic_risky_orders';
	const OPTION_PLUGIN_VERSION = 'urumi_agentic_plugin_version';

	/**
	 * Order events that will not be delivered: dropped, given up after 24
	 * hours, or never queued (JSON list, newest last, capped at 50, not
	 * autoloaded). Ids, statuses and error codes only (PL-8).
	 */
	const OPTION_ORDER_EVENTS_FAILED = 'urumi_agentic_order_events_failed';

	/**
	 * How many checkout orders were created without passing through the
	 * tagging hooks (an integer string, not autoloaded). A debug count only:
	 * above zero means some checkout path skips both hooks.
	 */
	const OPTION_DEBUG_UNHOOKED_CHECKOUTS = 'urumi_agentic_debug_unhooked_checkouts';

	/**
	 * How many hand-off links carried a channel this plugin does not know (an
	 * integer string, not autoloaded). Those links open the cart but tag no order.
	 */
	const OPTION_DEBUG_UNKNOWN_CHANNEL = 'urumi_agentic_debug_unknown_channel';

	/**
	 * The last A1 check-in outcome (JSON: time, ok, status, error_code,
	 * latest_plugin_version, last_ok_time). Not autoloaded. No secrets: the replica copies it.
	 */
	const OPTION_LAST_CHECK_IN = 'urumi_agentic_last_check_in';

	/**
	 * `<plugin version>|<unix time>` of the last time the check-in actions were
	 * verified queued. Autoloaded (a few bytes) so an ordinary request needs no
	 * query and no Action Scheduler call.
	 */
	const OPTION_CHECK_IN_SCHEDULED = 'urumi_agentic_check_in_scheduled';

	/**
	 * The A4 circuit breaker's state (JSON, not autoloaded; see
	 * Urumi_Agentic_Order_Events_Breaker). Read straight from the table and
	 * written only by compare-and-swap.
	 */
	const OPTION_BREAKER = 'urumi_agentic_breaker';

	/**
	 * Every option the plugin writes. uninstall.php deletes them all.
	 */
	const OPTIONS = array(
		self::OPTION_CHANNELS,
		self::OPTION_RISKY_ORDERS,
		self::OPTION_ORDER_EVENTS_FAILED,
		self::OPTION_PLUGIN_VERSION,
		self::OPTION_DEBUG_UNHOOKED_CHECKOUTS,
		self::OPTION_DEBUG_UNKNOWN_CHANNEL,
		self::OPTION_LAST_CHECK_IN,
		self::OPTION_CHECK_IN_SCHEDULED,
		self::OPTION_BREAKER,
	);

	/**
	 * Channels the merchant can switch on. Every one defaults to off, and a
	 * channel missing from the stored option counts as off (contract section 6),
	 * so an install from before `other` existed keeps it off.
	 *
	 * `other` is the open tier: agents that are not on the service's signed
	 * allowlist (Claude, Grok, Perplexity, Copilot, ...). Its orders are tagged
	 * but send no A4 events (see Urumi_Agentic_Order_Events::NO_EVENT_CHANNELS).
	 */
	const CHANNELS = array( 'chatgpt', 'google', 'muse', 'other' );

	/**
	 * The open-tier channel.
	 */
	const CHANNEL_OTHER = 'other';

	const RISKY_ORDERS_FLAG  = 'flag';
	const RISKY_ORDERS_BLOCK = 'block';

	/**
	 * The shape of a stored version string. `+` (build metadata) is left out on
	 * purpose: version_compare() has no rule for it, and plugin versions never use
	 * it. URUMI_AGENTIC_VERSION must match it, or every request would read the
	 * stored copy as malformed and install again (a unit test pins this).
	 */
	const VERSION_PATTERN = '/^[0-9][0-9A-Za-z.-]{0,31}$/';

	/**
	 * Writes the defaults on activation without touching existing choices, and
	 * asks for an A1 check-in (queued, never sent here).
	 *
	 * Every step here must be idempotent: it can run again for the same version
	 * (after `wp option delete urumi_agentic_plugin_version`, or a re-activation).
	 *
	 * @return void
	 */
	public static function install() {
		if ( false === get_option( self::OPTION_CHANNELS ) ) {
			self::set_channels( array() );
		}
		if ( false === get_option( self::OPTION_RISKY_ORDERS ) ) {
			self::set_risky_orders( self::RISKY_ORDERS_FLAG );
		}
		update_option( self::OPTION_PLUGIN_VERSION, URUMI_AGENTIC_VERSION, true );
		// A closed breaker row, so its first write is an update. Never resets an existing one.
		add_option( self::OPTION_BREAKER, Urumi_Agentic_Order_Events_Breaker::initial_json(), '', false );
		Urumi_Agentic_Check_In::request();
	}

	/**
	 * The activation hook. Network activation on multisite is refused: every site
	 * would run the plugin over the one Secret the pod mounts (one store slug and
	 * key set), so order ids would collide and uninstall would clean one site only.
	 * wp_die() inside the hook means WordPress never records the plugin as active,
	 * so there is nothing to deactivate. A bulk network activation stops at this
	 * plugin: the ones before it are activated, this one and those after it are
	 * not. Activating it on one site of a network is allowed.
	 *
	 * @param bool $network_wide Whether WordPress is activating it network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Urumi Agentic Checkout cannot be network activated. Activate it on the one site that uses it.', 'urumi-agentic' ),
				esc_html__( 'Plugin not activated', 'urumi-agentic' ),
				array(
					'response'  => 400,
					'back_link' => true,
				)
			);
		}
		self::install();
	}

	/**
	 * Re-runs install when the stored version is older than the code (an update, or
	 * the first request after a git deploy), which also queues one check-in.
	 *
	 * One-way: a pod on older code never writes the stored version. During a rolling
	 * deploy the old pods leave the row alone, so it is written once, not flipped
	 * back and forth on visitor requests. After a rollback the row keeps the newer
	 * version until a newer one is deployed; it means "highest version that has run",
	 * and A1's `plugin_version` is the running one. The old pod's check-in marker still
	 * names another version, so it still queues a prompt A1 with the version it runs.
	 * A hotfix after a rollback must be numbered above the highest version ever
	 * deployed, or be followed by `wp option delete urumi_agentic_plugin_version`
	 * (the next request then installs again).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::is_older( get_option( self::OPTION_PLUGIN_VERSION ), URUMI_AGENTIC_VERSION ) ) {
			self::install();
		}
		// The hand-off table has its own schema version (urumi_agentic_db_version).
		Urumi_Agentic_Handoff_Uses::maybe_install();
	}

	/**
	 * Whether a stored version is older than the code's. A missing, empty or
	 * malformed value (not a string, or not VERSION_PATTERN) counts as older, so it
	 * is written again and repairs itself.
	 *
	 * @param mixed  $stored Stored value, as get_option() returned it.
	 * @param string $code   The running code's version.
	 * @return bool
	 */
	public static function is_older( $stored, $code ) {
		if ( ! is_string( $stored ) || '' === $stored || ! preg_match( self::VERSION_PATTERN, $stored ) ) {
			return true;
		}
		return version_compare( $stored, (string) $code, '<' );
	}

	/**
	 * Every known channel with its on/off state. Missing or unreadable means off.
	 *
	 * @return array<string, bool>
	 */
	public static function get_channels() {
		$stored = json_decode( (string) get_option( self::OPTION_CHANNELS, '' ), true );
		$stored = is_array( $stored ) ? $stored : array();

		$channels = array();
		foreach ( self::CHANNELS as $channel ) {
			$channels[ $channel ] = true === ( $stored[ $channel ] ?? false );
		}
		return $channels;
	}

	/**
	 * Saves the channel switches. Unknown channels are dropped, and every known
	 * channel is written, so the service always sees the full set.
	 *
	 * @param array<string, mixed> $enabled Channel => truthy for on.
	 * @return void
	 */
	public static function set_channels( array $enabled ) {
		$channels = array();
		foreach ( self::CHANNELS as $channel ) {
			$channels[ $channel ] = ! empty( $enabled[ $channel ] );
		}
		update_option( self::OPTION_CHANNELS, wp_json_encode( $channels ), true );
	}

	/**
	 * Whether one channel is switched on.
	 *
	 * @param string $channel Channel name.
	 * @return bool
	 */
	public static function is_channel_enabled( $channel ) {
		return self::get_channels()[ $channel ] ?? false;
	}

	/**
	 * What to do with risky agent orders: `flag` (the default) or `block`.
	 *
	 * @return string
	 */
	public static function get_risky_orders() {
		$value = get_option( self::OPTION_RISKY_ORDERS, self::RISKY_ORDERS_FLAG );
		return self::RISKY_ORDERS_BLOCK === $value ? self::RISKY_ORDERS_BLOCK : self::RISKY_ORDERS_FLAG;
	}

	/**
	 * Saves the risky-orders choice. Anything but `block` is stored as `flag`.
	 *
	 * @param string $value `flag` or `block`.
	 * @return void
	 */
	public static function set_risky_orders( $value ) {
		update_option(
			self::OPTION_RISKY_ORDERS,
			self::RISKY_ORDERS_BLOCK === $value ? self::RISKY_ORDERS_BLOCK : self::RISKY_ORDERS_FLAG,
			true
		);
	}

	/**
	 * Adds one to the unhooked-checkout debug count. Not atomic: two requests
	 * at the same instant can lose one, which is fine for a debug count.
	 *
	 * @return void
	 */
	public static function count_unhooked_checkout() {
		$count = (int) get_option( self::OPTION_DEBUG_UNHOOKED_CHECKOUTS, '0' );
		update_option( self::OPTION_DEBUG_UNHOOKED_CHECKOUTS, (string) ( max( 0, $count ) + 1 ), false );
	}

	/**
	 * Adds one to the unknown-channel debug count (see OPTION_DEBUG_UNKNOWN_CHANNEL).
	 * Not atomic, which is fine for a debug count.
	 *
	 * @return void
	 */
	public static function count_unknown_channel() {
		$count = (int) get_option( self::OPTION_DEBUG_UNKNOWN_CHANNEL, '0' );
		update_option( self::OPTION_DEBUG_UNKNOWN_CHANNEL, (string) ( max( 0, $count ) + 1 ), false );
	}
}
