<?php
/**
 * The merchant screen (PL-4): WooCommerce > Agentic checkout.
 *
 * Channel switches and the risky-orders choice are saved through an
 * `admin-post.php` handler (capability, then nonce) into the options the
 * service reads from the replica. The feed status panel is fetched only on this
 * screen. The agent order filter lives in class-order-filter.php.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-feed-status.php';
require_once __DIR__ . '/class-order-filter.php';

/**
 * Menu entry, settings form and its handlers.
 */
class Urumi_Agentic_Admin {

	const CAPABILITY     = 'manage_woocommerce';
	const PAGE_SLUG      = 'urumi-agentic';
	const ACTION_SAVE    = 'urumi_agentic_save';
	const ACTION_REFRESH = 'urumi_agentic_refresh';
	const REFRESH_NONCE  = '_urumi_refresh_nonce';

	/**
	 * Registers the hooks. Nothing is added outside the admin.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH, array( __CLASS__, 'handle_refresh' ) );
		Urumi_Agentic_Order_Filter::boot();
	}

	/**
	 * Adds the screen under WooCommerce.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Agentic checkout', 'urumi-agentic' ),
			__( 'Agentic checkout', 'urumi-agentic' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * The screen's address.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Stops the request unless the user may manage the store.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage agentic checkout.', 'urumi-agentic' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Saves the switches and the risky-orders choice, then goes back to the screen.
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::require_capability();
		check_admin_referer( self::ACTION_SAVE );

		// A checked box is posted; an unchecked one is absent. set_channels() writes
		// every known channel and drops any other key.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$posted = isset( $_POST['channels'] ) && is_array( $_POST['channels'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['channels'] ) ) : array();
		$on     = array();
		foreach ( Urumi_Agentic_Settings::CHANNELS as $channel ) {
			$on[ $channel ] = isset( $posted[ $channel ] ) && '1' === $posted[ $channel ];
		}
		Urumi_Agentic_Settings::set_channels( $on );

		// Only an exact `flag` or `block` changes the choice; set_risky_orders() would
		// turn anything else into `flag`, which a forged request must not do.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$risky = isset( $_POST['risky_orders'] ) && is_string( $_POST['risky_orders'] ) ? sanitize_text_field( wp_unslash( $_POST['risky_orders'] ) ) : '';
		if ( in_array( $risky, array( Urumi_Agentic_Settings::RISKY_ORDERS_FLAG, Urumi_Agentic_Settings::RISKY_ORDERS_BLOCK ), true ) ) {
			Urumi_Agentic_Settings::set_risky_orders( $risky );
		}

		self::redirect( self::page_url( array( 'updated' => '1' ) ) );
	}

	/**
	 * Drops the cached feed status, then goes back to the screen.
	 *
	 * @return void
	 */
	public static function handle_refresh() {
		self::require_capability();
		check_admin_referer( self::ACTION_REFRESH, self::REFRESH_NONCE );
		Urumi_Agentic_Feed_Status::refresh();
		self::redirect( self::page_url() );
	}

	/**
	 * Redirects inside the admin and stops.
	 *
	 * @param string $url Address.
	 * @return void
	 */
	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The orders list address with the agent filter on.
	 *
	 * @return string
	 */
	private static function agent_orders_url() {
		$query = array( Urumi_Agentic_Order_Filter::QUERY_VAR => Urumi_Agentic_Order_Filter::ANY );
		$hpos  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			return add_query_arg( array_merge( array( 'page' => 'wc-orders' ), $query ), admin_url( 'admin.php' ) );
		}
		return add_query_arg( array_merge( array( 'post_type' => Urumi_Agentic_Order_Filter::ORDER ), $query ), admin_url( 'edit.php' ) );
	}

	/**
	 * One neutral line while the order-event breaker is not closed. The merchant
	 * cannot fix this, so there is no call to action. Once the next try time
	 * has passed the line says sending resumes with the next update, never
	 * "paused".
	 *
	 * @return void
	 */
	private static function render_delayed_notice() {
		$delayed = Urumi_Agentic_Order_Events_Breaker::merchant_state( get_option( Urumi_Agentic_Settings::OPTION_BREAKER, '' ), time() );
		if ( null === $delayed ) {
			return;
		}
		$line = __( 'Order updates to Urumi are delayed; they are sent automatically once the service answers again.', 'urumi-agentic' );
		if ( null !== $delayed['next_try'] ) {
			$when = wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $delayed['next_try'] );
			/* translators: %s: date and time in the site's time zone. */
			$line .= ' ' . sprintf( __( 'Next try at %s.', 'urumi-agentic' ), $when );
		} else {
			$line .= ' ' . __( 'Resuming with the next order update.', 'urumi-agentic' );
		}
		echo '<div class="notice notice-warning inline"><p>' . esc_html( $line ) . '</p></div>';
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		self::require_capability();

		$channels = Urumi_Agentic_Settings::get_channels();
		$risky    = Urumi_Agentic_Settings::get_risky_orders();
		$notes    = array(
			'chatgpt' => __( 'Shoppers can find and buy from this store in ChatGPT.', 'urumi-agentic' ),
			'google'  => __( 'Shoppers can find and buy from this store in Google AI.', 'urumi-agentic' ),
			'muse'    => __( 'Muse publishes no user agent or IP ranges, so orders from Muse are tagged on a best-effort basis and some may not be recognised.', 'urumi-agentic' ),
			'other'   => __( 'AI agents that are not on the verified list, such as Claude, Grok, Perplexity and Copilot. The service offers them only where it has switched this open tier on, and these orders send no status updates to the agent.', 'urumi-agentic' ),
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Agentic checkout', 'urumi-agentic' ) . '</h1>';

		// A fixed message keyed off a flag: nothing from the URL is printed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'urumi-agentic' ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The service reads these settings about once an hour, so a change to a channel or to risky orders can take over an hour to apply.', 'urumi-agentic' ) . '</p></div>';

		self::render_delayed_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		wp_nonce_field( self::ACTION_SAVE );

		echo '<h2>' . esc_html__( 'Channels', 'urumi-agentic' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose where shoppers can buy from this store through an AI agent. Every channel is off until you switch it on.', 'urumi-agentic' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Channels', 'urumi-agentic' ) . '</legend>';
		foreach ( Urumi_Agentic_Settings::CHANNELS as $channel ) {
			$id = 'urumi-agentic-channel-' . $channel;
			echo '<p><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="channels[' . esc_attr( $channel ) . ']" value="1"' . checked( $channels[ $channel ], true, false ) . ' /> <strong>' . esc_html( Urumi_Agentic_Order_Filter::channel_label( $channel ) ) . '</strong></label>';
			echo '<br /><span class="description">' . esc_html( $notes[ $channel ] ?? '' ) . '</span></p>';
		}
		echo '</fieldset>';

		echo '<h2>' . esc_html__( 'Risky orders', 'urumi-agentic' ) . '</h2>';
		echo '<p>' . esc_html__( 'What to do with an agent order that looks risky.', 'urumi-agentic' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Risky orders', 'urumi-agentic' ) . '</legend>';
		$choices = array(
			Urumi_Agentic_Settings::RISKY_ORDERS_FLAG  => __( 'Flag them for review (the order is accepted)', 'urumi-agentic' ),
			Urumi_Agentic_Settings::RISKY_ORDERS_BLOCK => __( 'Block them (the order is not accepted)', 'urumi-agentic' ),
		);
		foreach ( $choices as $value => $label ) {
			echo '<p><label><input type="radio" name="risky_orders" value="' . esc_attr( $value ) . '"' . checked( $risky, $value, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'urumi-agentic' ) . '</button></p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'Agent orders', 'urumi-agentic' ) . '</h2>';
		echo '<p><a href="' . esc_url( self::agent_orders_url() ) . '">' . esc_html__( 'View orders that came from an agent', 'urumi-agentic' ) . '</a>. ' . esc_html__( 'You can also filter the orders list by channel.', 'urumi-agentic' ) . '</p>';

		echo '<h2>' . esc_html__( 'Product feed', 'urumi-agentic' ) . '</h2>';
		Urumi_Agentic_Feed_Status::render( Urumi_Agentic_Feed_Status::get() );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REFRESH ) . '" />';
		// Own field name and no referer field: the save form already has `_wpnonce` and
		// `_wp_http_referer`, and ids must stay unique.
		wp_nonce_field( self::ACTION_REFRESH, self::REFRESH_NONCE, false );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Refresh', 'urumi-agentic' ) . '</button></p>';
		echo '</form>';

		echo '</div>';
	}
}
