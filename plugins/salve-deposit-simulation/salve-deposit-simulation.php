<?php
/**
 * Plugin Name: Salve Deposit Simulation
 * Description: Records a 50% Cash on Delivery deposit simulation for Oxide High and tracks the manual balance due after shipment.
 * Version: 1.0.0
 * Author: Salve
 * License: GPL-2.0-or-later
 * Text Domain: salve-deposit-simulation
 */

defined( 'ABSPATH' ) || exit;

final class Salve_Deposit_Simulation {
	const PRODUCT_SLUG       = 'oxide-high';
	const CART_FULL_PRICE    = 'salve_deposit_full_unit_price';
	const ITEM_FLAG          = '_salve_deposit_simulation';
	const ITEM_BALANCE       = '_salve_deposit_balance_due';
	const ORDER_FLAG         = '_salve_deposit_simulation';
	const ORDER_DEPOSIT      = '_salve_deposit_due_now';
	const ORDER_BALANCE      = '_salve_deposit_balance_due';
	const ORDER_BALANCE_DUE  = '_salve_deposit_balance_status';

	public function __construct() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_deposit_price' ), 100 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_deposit_orders_to_cod' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_deposit_line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'record_deposit_order_meta' ) );
		add_action( 'init', array( $this, 'register_shipped_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_shipped_status' ) );
		add_action( 'woocommerce_order_status_shipped', array( $this, 'mark_balance_due_on_shipment' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_deposit_details' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_data' ) );
	}

	public function apply_deposit_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $this->is_deposit_product( $cart_item['data'] ) ) {
				continue;
			}

			$full_price = isset( $cart_item[ self::CART_FULL_PRICE ] )
				? (float) $cart_item[ self::CART_FULL_PRICE ]
				: (float) $cart_item['data']->get_price( 'edit' );
			if ( $full_price <= 0 ) {
				continue;
			}

			$cart->cart_contents[ $cart_item_key ][ self::CART_FULL_PRICE ] = $full_price;
			$cart->cart_contents[ $cart_item_key ]['data']->set_price( wc_format_decimal( $full_price / 2 ) );
		}
	}

	public function limit_deposit_orders_to_cod( $gateways ) {
		if ( ! $this->cart_has_deposit_product() ) {
			return $gateways;
		}
		return isset( $gateways['cod'] ) ? array( 'cod' => $gateways['cod'] ) : array();
	}

	public function add_deposit_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['data'] ) || ! $this->is_deposit_product( $values['data'] ) ) {
			return;
		}

		$due_now = (float) $item->get_total() + (float) $item->get_total_tax();
		$item->add_meta_data( self::ITEM_FLAG, 'yes', true );
		$item->add_meta_data( self::ITEM_BALANCE, wc_format_decimal( $due_now ), true );
		$item->add_meta_data( __( 'Deposit simulation', 'salve-deposit-simulation' ), __( '50% by Cash on Delivery; remaining 50% recorded for manual collection after shipment.', 'salve-deposit-simulation' ), true );
	}

	public function record_deposit_order_meta( $order ) {
		$deposit_due = 0.0;
		$balance_due = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( 'yes' !== $item->get_meta( self::ITEM_FLAG, true ) ) {
				continue;
			}
			$deposit_due += (float) $item->get_total() + (float) $item->get_total_tax();
			$balance_due += (float) $item->get_meta( self::ITEM_BALANCE, true );
		}

		if ( $deposit_due <= 0 ) {
			return;
		}

		$order->update_meta_data( self::ORDER_FLAG, 'yes' );
		$order->update_meta_data( self::ORDER_DEPOSIT, wc_format_decimal( $deposit_due ) );
		$order->update_meta_data( self::ORDER_BALANCE, wc_format_decimal( $balance_due ) );
		$order->update_meta_data( self::ORDER_BALANCE_DUE, 'pending_shipment' );
		$order->add_order_note( sprintf( __( 'Deposit simulation: %1$s is due by Cash on Delivery. A separate %2$s balance is recorded for manual collection after shipment. No online payment was collected.', 'salve-deposit-simulation' ), wp_strip_all_tags( wc_price( $deposit_due, array( 'currency' => $order->get_currency() ) ) ), wp_strip_all_tags( wc_price( $balance_due, array( 'currency' => $order->get_currency() ) ) ) ) );
		$order->save();
	}

	public function register_shipped_status() {
		register_post_status(
			'wc-shipped',
			array(
				'label'                     => _x( 'Shipped', 'Order status', 'salve-deposit-simulation' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'salve-deposit-simulation' ),
			)
		);
	}

	public function add_shipped_status( $statuses ) {
		$updated_statuses = array();
		foreach ( $statuses as $status => $label ) {
			$updated_statuses[ $status ] = $label;
			if ( 'wc-processing' === $status ) {
				$updated_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'salve-deposit-simulation' );
			}
		}
		return $updated_statuses;
	}

	public function mark_balance_due_on_shipment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( self::ORDER_FLAG, true ) || 'due' === $order->get_meta( self::ORDER_BALANCE_DUE, true ) ) {
			return;
		}

		$balance_due = (float) $order->get_meta( self::ORDER_BALANCE, true );
		$order->update_meta_data( self::ORDER_BALANCE_DUE, 'due' );
		$order->add_order_note( sprintf( __( 'Your shipment is on its way. The remaining %s balance is due for manual collection. This is a deposit simulation; no automatic payment has been taken.', 'salve-deposit-simulation' ), wp_strip_all_tags( wc_price( $balance_due, array( 'currency' => $order->get_currency() ) ) ) ), true );
		$order->save();
	}

	public function render_order_deposit_details( $order ) {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( self::ORDER_FLAG, true ) ) {
			return;
		}
		$deposit = (float) $order->get_meta( self::ORDER_DEPOSIT, true );
		$balance = (float) $order->get_meta( self::ORDER_BALANCE, true );
		$status  = $order->get_meta( self::ORDER_BALANCE_DUE, true );
		?>
		<div class="order_data_column" style="clear:both;float:none;width:auto;margin-top:18px;padding:14px;background:#f6f7f7;border-left:4px solid #2271b1">
			<h3 style="margin-top:0"><?php esc_html_e( '50% deposit simulation', 'salve-deposit-simulation' ); ?></h3>
			<p><?php printf( esc_html__( 'Cash on Delivery amount: %s', 'salve-deposit-simulation' ), wp_kses_post( wc_price( $deposit, array( 'currency' => $order->get_currency() ) ) ) ); ?><br>
			<?php printf( esc_html__( 'Manual balance: %s', 'salve-deposit-simulation' ), wp_kses_post( wc_price( $balance, array( 'currency' => $order->get_currency() ) ) ) ); ?><br>
			<?php echo 'due' === $status ? esc_html__( 'Balance status: due after shipment', 'salve-deposit-simulation' ) : esc_html__( 'Balance status: pending shipment', 'salve-deposit-simulation' ); ?></p>
		</div>
		<?php
	}

	public function register_store_api_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		$endpoints = array(
			\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
			\Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
		);
		foreach ( $endpoints as $endpoint ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => $endpoint,
					'namespace'       => 'salve-deposit-simulation',
					'schema_callback' => array( $this, 'store_api_schema' ),
					'data_callback'   => array( $this, 'store_api_data' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'salve-deposit-simulation',
				'schema_callback' => array( $this, 'store_api_cart_item_schema' ),
				'data_callback'   => array( $this, 'store_api_cart_item_data' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	public function store_api_schema() {
		return array(
			'enabled'         => array( 'description' => __( 'Whether the cart contains a deposit-simulation product.', 'salve-deposit-simulation' ), 'type' => 'boolean', 'readonly' => true ),
			'deposit_due_now' => array( 'description' => __( 'Deposit simulation amount due by Cash on Delivery in minor units.', 'salve-deposit-simulation' ), 'type' => 'integer', 'readonly' => true ),
			'balance_due'     => array( 'description' => __( 'Manual balance recorded for collection after shipment in minor units.', 'salve-deposit-simulation' ), 'type' => 'integer', 'readonly' => true ),
		);
	}

	public function store_api_data() {
		$deposit_due = 0.0;
		$balance_due = 0.0;
		$cart        = WC()->cart;
		if ( ! $cart ) {
			return array( 'enabled' => false, 'deposit_due_now' => 0, 'balance_due' => 0 );
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $this->is_deposit_product( $cart_item['data'] ) ) {
				continue;
			}
			$line_deposit = (float) $cart_item['line_total'] + (float) $cart_item['line_tax'];
			$deposit_due += $line_deposit;
			$balance_due += $line_deposit;
		}

		return array(
			'enabled'         => $deposit_due > 0,
			'deposit_due_now' => wc_add_number_precision( $deposit_due ),
			'balance_due'     => wc_add_number_precision( $balance_due ),
		);
	}

	public function store_api_cart_item_schema() {
		return array(
			'enabled'         => array( 'description' => __( 'Whether this item uses the deposit simulation.', 'salve-deposit-simulation' ), 'type' => 'boolean', 'readonly' => true ),
			'full_unit_price' => array( 'description' => __( 'Full product price before the 50% simulation in minor units.', 'salve-deposit-simulation' ), 'type' => 'integer', 'readonly' => true ),
		);
	}

	public function store_api_cart_item_data( $cart_item ) {
		if ( empty( $cart_item['data'] ) || ! $this->is_deposit_product( $cart_item['data'] ) ) {
			return array( 'enabled' => false, 'full_unit_price' => 0 );
		}

		$full_price = isset( $cart_item[ self::CART_FULL_PRICE ] )
			? (float) $cart_item[ self::CART_FULL_PRICE ]
			: (float) $cart_item['data']->get_price( 'edit' );
		return array(
			'enabled'         => true,
			'full_unit_price' => wc_add_number_precision( $full_price ),
		);
	}

	private function cart_has_deposit_product() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return false;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['data'] ) && $this->is_deposit_product( $cart_item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_deposit_product( $product ) {
		return $product instanceof WC_Product && self::PRODUCT_SLUG === $product->get_slug();
	}
}

new Salve_Deposit_Simulation();
