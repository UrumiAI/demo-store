<?php
/**
 * Agent channel filter and column on the WooCommerce orders list (PL-4).
 *
 * Both lists are covered: the HPOS list (`admin.php?page=wc-orders`) and the
 * legacy posts list (`edit.php?post_type=shop_order`). The filter only reads
 * `_urumi_agent_channel`, the tag PL-3 writes, so it excludes nothing else.
 *
 * Hooks (present in WooCommerce 8.2.0 and 11.2.1, the plugin's floor and the
 * newest tested version):
 *  - HPOS: `woocommerce_order_list_table_restrict_manage_orders` (dropdown) and
 *    `woocommerce_order_list_table_prepare_items_query_args` (wc_get_orders args),
 *    `manage_{screen}_columns` and `manage_{screen}_custom_column`.
 *  - Legacy: `restrict_manage_posts` (dropdown), `request` (query vars),
 *    `manage_shop_order_posts_columns` and `manage_shop_order_posts_custom_column`.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the filter dropdown, its query and an "Agent" column to the orders list.
 */
class Urumi_Agentic_Order_Filter {

	const QUERY_VAR = 'urumi_agent_channel';
	const ANY       = 'any';
	const COLUMN    = 'urumi_agent';
	const ORDER     = 'shop_order';

	/**
	 * Registers the hooks. Call only in the admin.
	 *
	 * @return void
	 */
	public static function boot() {
		// HPOS list.
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_hpos_dropdown' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos_args' ) );
		// Legacy posts list. Priority 20: Woo's own `request` handler replaces
		// `meta_query` when a customer is picked, so ours must run after it and add to it.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_legacy_dropdown' ), 10, 2 );
		add_filter( 'request', array( __CLASS__, 'filter_legacy_request' ), 20 );
		// The columns hooks need Woo's screen id, which is known once Woo has loaded.
		add_action( 'admin_init', array( __CLASS__, 'register_column_hooks' ) );
	}

	/**
	 * Registers the Agent column on both lists.
	 *
	 * @return void
	 */
	public static function register_column_hooks() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		if ( $screen ) {
			// Priority 20: Woo's list table sets its columns at 0.
			add_filter( "manage_{$screen}_columns", array( __CLASS__, 'add_column' ), 20 );
			add_action( "manage_{$screen}_custom_column", array( __CLASS__, 'render_hpos_column' ), 10, 2 );
		}
		// Priority 20: Woo's legacy handler rebuilds the column list at 10.
		add_filter( 'manage_' . self::ORDER . '_posts_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_' . self::ORDER . '_posts_custom_column', array( __CLASS__, 'render_legacy_column' ), 10, 2 );
	}

	/**
	 * Channel names as shown to the merchant.
	 *
	 * @param string $channel Channel slug.
	 * @return string The slug itself for one this version does not know.
	 */
	public static function channel_label( $channel ) {
		$labels = array(
			'chatgpt' => __( 'ChatGPT', 'urumi-agentic' ),
			'google'  => __( 'Google', 'urumi-agentic' ),
			'muse'    => __( 'Muse', 'urumi-agentic' ),
			'other'   => __( 'Other AI agents', 'urumi-agentic' ),
		);
		return $labels[ $channel ] ?? (string) $channel;
	}

	/**
	 * The filter the merchant picked: `any`, a known channel, or '' for all orders.
	 * Anything else in the URL counts as no filter.
	 *
	 * @return string
	 */
	public static function selected() {
		// Read-only list filter in the URL, whitelisted below: no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( self::ANY === $value || in_array( $value, Urumi_Agentic_Settings::CHANNELS, true ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * The meta query for a filter choice, or null for no filter.
	 *
	 * @param string $selected From selected().
	 * @return array|null
	 */
	public static function meta_query_for( $selected ) {
		if ( self::ANY === $selected ) {
			return array(
				'key'     => Urumi_Agentic_Order_Tagger::META_CHANNEL,
				'compare' => 'EXISTS',
			);
		}
		if ( in_array( $selected, Urumi_Agentic_Settings::CHANNELS, true ) ) {
			return array(
				'key'     => Urumi_Agentic_Order_Tagger::META_CHANNEL,
				'value'   => $selected,
				'compare' => '=',
			);
		}
		return null;
	}

	/**
	 * Adds the filter to a `meta_query` list, keeping what is already there.
	 *
	 * @param mixed $existing Current meta_query, or anything else.
	 * @param array $clause   Our clause.
	 * @return array
	 */
	private static function and_meta_query( $existing, array $clause ) {
		if ( ! is_array( $existing ) || ! $existing ) {
			return array( $clause );
		}
		// Nest the old query so its own `relation` is kept.
		return array(
			'relation' => 'AND',
			$existing,
			$clause,
		);
	}

	/**
	 * HPOS: prints the dropdown beside Woo's own filters.
	 *
	 * @param string $order_type Order type of the list.
	 * @return void
	 */
	public static function render_hpos_dropdown( $order_type = self::ORDER ) {
		if ( self::ORDER === $order_type ) {
			self::render_dropdown();
		}
	}

	/**
	 * Legacy: prints the dropdown on the posts list.
	 *
	 * @param string $post_type Post type of the list.
	 * @return void
	 */
	public static function render_legacy_dropdown( $post_type = '' ) {
		if ( self::ORDER === $post_type ) {
			self::render_dropdown();
		}
	}

	/**
	 * Prints the dropdown: all orders, any agent, or one channel.
	 *
	 * @return void
	 */
	private static function render_dropdown() {
		$selected = self::selected();
		echo '<label class="screen-reader-text" for="filter-by-urumi-agent">' . esc_html__( 'Filter by agent channel', 'urumi-agentic' ) . '</label>';
		echo '<select name="' . esc_attr( self::QUERY_VAR ) . '" id="filter-by-urumi-agent">';
		printf( '<option value=""%s>%s</option>', selected( $selected, '', false ), esc_html__( 'All orders', 'urumi-agentic' ) );
		printf( '<option value="%s"%s>%s</option>', esc_attr( self::ANY ), selected( $selected, self::ANY, false ), esc_html__( 'Any agent', 'urumi-agentic' ) );
		foreach ( Urumi_Agentic_Settings::CHANNELS as $channel ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $channel ), selected( $selected, $channel, false ), esc_html( self::channel_label( $channel ) ) );
		}
		echo '</select>';
	}

	/**
	 * HPOS: narrows the orders query.
	 *
	 * @param array $args wc_get_orders arguments.
	 * @return array
	 */
	public static function filter_hpos_args( $args ) {
		$args   = (array) $args;
		$clause = self::meta_query_for( self::selected() );
		// Only orders: other types that share this list table are never tagged.
		if ( null === $clause || ( isset( $args['type'] ) && self::ORDER !== $args['type'] ) ) {
			return $args;
		}
		$args['meta_query'] = self::and_meta_query( $args['meta_query'] ?? array(), $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	/**
	 * Legacy: narrows the posts query.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function filter_legacy_request( $vars ) {
		global $typenow;
		if ( ! is_admin() || self::ORDER !== $typenow || ! is_array( $vars ) ) {
			return $vars;
		}
		$clause = self::meta_query_for( self::selected() );
		if ( null === $clause ) {
			return $vars;
		}
		$vars['meta_query'] = self::and_meta_query( $vars['meta_query'] ?? array(), $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $vars;
	}

	/**
	 * Adds the Agent column after the status column.
	 *
	 * @param mixed $columns Columns, id => label.
	 * @return mixed
	 */
	public static function add_column( $columns ) {
		// Screen Options calls this filter with an empty array (and the order edit
		// screens share the screen id): add nothing there.
		if ( ! is_array( $columns ) || ! $columns ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Agent', 'urumi-agentic' );
			}
		}
		// No status column (a customised list): add it at the end.
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Agent', 'urumi-agentic' );
		}
		return $out;
	}

	/**
	 * HPOS: prints the column for one order.
	 *
	 * @param string $column Column id.
	 * @param mixed  $order  Order object.
	 * @return void
	 */
	public static function render_hpos_column( $column, $order = null ) {
		if ( self::COLUMN === $column ) {
			self::render_cell( $order );
		}
	}

	/**
	 * Legacy: prints the column for one post.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Order id.
	 * @return void
	 */
	public static function render_legacy_column( $column, $post_id = 0 ) {
		if ( self::COLUMN === $column ) {
			self::render_cell( wc_get_order( $post_id ) );
		}
	}

	/**
	 * Prints "Channel (mode)" for a tagged order, a dash for any other.
	 *
	 * @param mixed $order Order object, or false.
	 * @return void
	 */
	private static function render_cell( $order ) {
		$channel = is_object( $order ) ? (string) $order->get_meta( Urumi_Agentic_Order_Tagger::META_CHANNEL ) : '';
		if ( '' === $channel ) {
			echo '<span aria-hidden="true">&ndash;</span>';
			return;
		}
		$mode   = (string) $order->get_meta( Urumi_Agentic_Order_Tagger::META_MODE );
		$modes  = array(
			'handoff'  => __( 'hand-off', 'urumi-agentic' ),
			'referred' => __( 'referred', 'urumi-agentic' ),
		);
		$suffix = '' === $mode ? '' : ' (' . ( $modes[ $mode ] ?? $mode ) . ')';
		echo esc_html( self::channel_label( $channel ) . $suffix );
	}
}
