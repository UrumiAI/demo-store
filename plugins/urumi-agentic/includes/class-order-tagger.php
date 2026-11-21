<?php
/**
 * Agent-order tagging (plugin-plan.md, PL-3; contract.md section 6, "Order tags").
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags checkout orders that came from an agent with `_urumi_agent_*` meta.
 *
 * Hooks:
 *   - classic: `woocommerce_checkout_create_order`, priority 20 (after
 *     Subscriptions puts a manual renewal's `created_via` back at priority 0),
 *     which runs right before the creation save;
 *   - Blocks: `woocommerce_store_api_checkout_update_order_from_request`, which
 *     runs on the draft before it moves to `pending`.
 * So the tags are on the order object before the first save that carries a
 * real status, and PL-8 can read them there (the A4 `seq` rule).
 *
 * Rules:
 *   - only the meta API, never save() (the checkout saves the order);
 *   - only orders whose `created_via` is `checkout` or `store-api`;
 *   - a draft's tag is provisional and resolved again on every call;
 *   - an untagged `pending`/`failed` order (a new classic order's empty
 *     status counts as `pending`) is tagged; a `referred` one is
 *     upgraded once to `handoff` when a matching hint exists and it has no
 *     `_urumi_agent_status_seq`;
 *   - otherwise a tag is frozen: never downgraded, its session never
 *     overwritten, and nothing is touched once the order is paid or later;
 *   - no empty rows, and a failure never reaches the checkout.
 */
class Urumi_Agentic_Order_Tagger {

	const META_PREFIX  = '_urumi_agent_';
	const META_CHANNEL = '_urumi_agent_channel';
	const META_MODE    = '_urumi_agent_mode';
	const META_SIGNAL  = '_urumi_agent_signal';
	const META_SESSION = '_urumi_agent_session';
	const META_SEQ     = '_urumi_agent_status_seq';

	/**
	 * `created_via` values of checkout orders. Subscriptions renewals are `subscription`.
	 */
	const CHECKOUT_CREATED_VIA = array( 'checkout', 'store-api' );

	/**
	 * Statuses with no `seq` and no events, where a tag is provisional.
	 */
	const DRAFT_STATUSES = array( 'checkout-draft', 'draft', 'auto-draft' );

	/**
	 * Unpaid statuses a checkout can resume. A new classic order reads as `pending`.
	 */
	const LIVE_STATUSES = array( 'pending', 'failed' );

	/**
	 * Store API routes of checkout creation, under both namespaces Woo registers
	 * (`wc/store` and `wc/store/v1`). Pay-for-order (`/checkout/{id}`) is not one.
	 */
	const STORE_API_CHECKOUT_ROUTE = '#^/wc/store(?:/v1)?/checkout/?$#';

	/**
	 * The registered instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * The shopper's hand-off hint.
	 *
	 * @var Urumi_Agentic_Handoff_Hint
	 */
	private $hint;

	/**
	 * Returns the current Unix time. Injected so tests can pin it.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Order objects our tagging callbacks have seen in this request, for the
	 * debug counter. Keyed by object: a new classic order has id 0 when we see it.
	 *
	 * @var WeakMap
	 */
	private $seen;

	/**
	 * Order objects tagged from the hint, whose next non-draft save uses the hint up.
	 *
	 * @var WeakMap
	 */
	private $uses_hint;

	/**
	 * Builds a tagger.
	 *
	 * @param Urumi_Agentic_Handoff_Hint|null $hint  Hint store.
	 * @param callable|null                   $clock Returns Unix seconds.
	 */
	public function __construct( $hint = null, $clock = null ) {
		$this->hint      = $hint ? $hint : new Urumi_Agentic_Handoff_Hint( $clock );
		$this->clock     = $clock ? $clock : 'time';
		$this->seen      = new WeakMap();
		$this->uses_hint = new WeakMap();
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
	 * Adds the hooks. Safe to call more than once: only the first call adds them.
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
		add_action( 'woocommerce_checkout_create_order', array( $this, 'on_classic_checkout' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'on_store_api_checkout' ), 20, 2 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'on_order_saved' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 10, 2 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'on_cart_emptied' ), 10, 0 );

		// Subscriptions copies all meta from a parent order to its subscription
		// and on to renewals. The tags describe one checkout, so they must not travel.
		// subscriptions-core 2.5.0 (2022) and later apply this filter to every copy.
		// The deprecated `wcs_{type}_meta` filters are never hooked: a callback on
		// them switches the copier onto its deprecated path.
		add_filter( 'wc_subscriptions_object_data', array( self::class, 'strip_copied_meta' ), 10, 1 );
	}

	/**
	 * Classic checkout, right before the order's creation save.
	 *
	 * @param WC_Order $order The order.
	 * @return void
	 */
	public function on_classic_checkout( $order ) {
		$this->see( $order );
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout verified the nonce before this hook. Values are only compared against allow-lists, never stored.
			$this->tag( $order, Urumi_Agentic_Signals::attribution_from_post( $_POST ) );
		} catch ( Throwable $e ) {
			self::log_failure( $e );
		}
	}

	/**
	 * Blocks checkout (Store API), on the draft before it moves to `pending`.
	 *
	 * The same action fires from the Blocks pay-for-order route
	 * (`/checkout/{id}`) for an existing order. That is not checkout creation
	 * (classic order-pay never fires the classic hook either), so it is skipped.
	 *
	 * @param WC_Order        $order   The order.
	 * @param WP_REST_Request $request The checkout request.
	 * @return void
	 */
	public function on_store_api_checkout( $order, $request = null ) {
		$this->see( $order );
		try {
			if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_param' )
				|| 1 !== preg_match( self::STORE_API_CHECKOUT_ROUTE, (string) $request->get_route() )
			) {
				return;
			}
			$extensions  = $request->get_param( 'extensions' );
			$attribution = is_array( $extensions ) && is_array( $extensions['woocommerce/order-attribution'] ?? null )
				? $extensions['woocommerce/order-attribution']
				: array();
			$this->tag( $order, $attribution );
		} catch ( Throwable $e ) {
			self::log_failure( $e );
		}
	}

	/**
	 * Uses the hint up once an order tagged from it is saved out of draft: the
	 * classic creation save, or the Blocks save that moves the draft to `pending`.
	 *
	 * @param WC_Order $order The saved order.
	 * @return void
	 */
	public function on_order_saved( $order ) {
		try {
			if ( ! is_object( $order ) || ! isset( $this->uses_hint[ $order ] ) ) {
				return;
			}
			// A new classic order's '' counts as `pending`, not a draft.
			if ( in_array( self::status( $order ), self::DRAFT_STATUSES, true ) ) {
				return;
			}
			unset( $this->uses_hint[ $order ] );
			// The order used the pre-fill: what the link wrote to the customer stays.
			$this->hint->forget( Urumi_Agentic_Handoff_Hint::END_ORDER_PLACED );
		} catch ( Throwable $e ) {
			self::log_failure( $e );
		}
	}

	/**
	 * The shopper emptied the cart, or Woo did after payment: the hint goes too. A pre-fill copy still
	 * there (no order used it) puts the customer back (Urumi_Agentic_Handoff_Cart::end_copy()).
	 *
	 * @return void
	 */
	public function on_cart_emptied() {
		try {
			$this->hint->forget( Urumi_Agentic_Handoff_Hint::END_CART_EMPTIED );
		} catch ( Throwable $e ) {
			self::log_failure( $e );
		}
	}

	/**
	 * Debug counter: a checkout order created without passing through our
	 * tagging hooks.
	 *
	 * Woo fires this with the order object being saved, both inside the
	 * creation save of an order created with a real status (classic) and in the
	 * update that moves an order out of a draft status (Blocks: draft to
	 * `pending` or later), on both order stores. So an unhooked Blocks
	 * checkout is counted too on WC 9.2 and later. Not on WC 8.2 to 9.1, on
	 * either store: the legacy draft check does not include `checkout-draft`,
	 * and the HPOS store fires this only for an order leaving `auto-draft`
	 * (PL-1b CounterTest). Not counted:
	 *   - orders our tagging callbacks saw in this request;
	 *   - anything but `shop_order` (Subscriptions' own objects, refunds);
	 *   - `created_via=checkout` outside a classic checkout request (imports,
	 *     migrations, WP-CLI, cron);
	 *   - `created_via=store-api` outside a Store API request (admin, cron).
	 *
	 * @param int      $order_id The new order's id.
	 * @param WC_Order $order    The order.
	 * @return void
	 */
	public function on_new_order( $order_id, $order = null ) {
		try {
			if ( ! is_object( $order ) || isset( $this->seen[ $order ] ) ) {
				return;
			}
			if ( 'shop_order' !== $order->get_type() || in_array( self::status( $order ), self::DRAFT_STATUSES, true ) ) {
				return;
			}
			$created_via = $order->get_created_via();
			if ( ( 'checkout' === $created_via && self::is_classic_checkout_request() )
				|| ( 'store-api' === $created_via && self::is_store_api_request() )
			) {
				Urumi_Agentic_Settings::count_unhooked_checkout();
			}
		} catch ( Throwable $e ) {
			self::log_failure( $e );
		}
	}

	/**
	 * Drops `_urumi_agent_*` keys from data Subscriptions is about to copy.
	 *
	 * @param mixed $data Meta key => value.
	 * @return mixed
	 */
	public static function strip_copied_meta( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( array_keys( $data ) as $key ) {
			if ( self::is_tag_key( $key ) ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Applies the tag lifecycle to one checkout order.
	 *
	 * @param mixed $order       The order.
	 * @param array $attribution Unprefixed Woo attribution values.
	 * @return void
	 */
	private function tag( $order, array $attribution ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! in_array( $order->get_created_via(), self::CHECKOUT_CREATED_VIA, true ) ) {
			return;
		}
		// Events have started (PL-8): the tag can no longer change.
		if ( '' !== (string) $order->get_meta( self::META_SEQ ) ) {
			return;
		}

		$status = self::status( $order );
		$stored = self::stored_tag( $order );
		$hint   = $this->matching_hint( $order );

		if ( in_array( $status, self::DRAFT_STATUSES, true ) ) {
			$resolved = Urumi_Agentic_Signals::resolve( $hint, self::server(), $attribution );
			if ( $resolved !== $stored ) {
				self::delete_tag( $order );
				if ( null !== $resolved ) {
					self::write_tag( $order, $resolved );
				}
				$stored = $resolved;
			}
		} elseif ( in_array( $status, self::LIVE_STATUSES, true ) ) {
			if ( null === $stored ) {
				$stored = Urumi_Agentic_Signals::resolve( $hint, self::server(), $attribution );
				if ( null !== $stored ) {
					self::write_tag( $order, $stored );
				}
			} elseif ( 'referred' === $stored['mode'] && null !== $hint ) {
				$stored = Urumi_Agentic_Signals::resolve( $hint, array(), array() );
				self::write_tag( $order, $stored );
			}
		} else {
			return;
		}

		if ( null !== $hint && null !== $stored && 'handoff' === $stored['mode'] && $stored['session'] === $hint['session_id'] ) {
			$this->uses_hint[ $order ] = true;
		} else {
			unset( $this->uses_hint[ $order ] );
		}
	}

	/**
	 * The hint, when there is one and the order still holds one of its items.
	 *
	 * @param WC_Order $order The order.
	 * @return array|null
	 */
	private function matching_hint( WC_Order $order ) {
		$hint = $this->hint->get( (int) call_user_func( $this->clock ) );
		return ( null !== $hint && $this->hint->matches( $order, $hint ) ) ? $hint : null;
	}

	/**
	 * Records an order object for the debug counter, before anything can return early.
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	private function see( $order ) {
		if ( is_object( $order ) ) {
			$this->seen[ $order ] = true;
		}
	}

	/**
	 * The tag stored on the order, in Urumi_Agentic_Signals::resolve() form, or null.
	 *
	 * @param WC_Order $order The order.
	 * @return array|null
	 */
	private static function stored_tag( WC_Order $order ) {
		$tag = array(
			'channel' => (string) $order->get_meta( self::META_CHANNEL ),
			'mode'    => (string) $order->get_meta( self::META_MODE ),
			'signal'  => (string) $order->get_meta( self::META_SIGNAL ),
			'session' => (string) $order->get_meta( self::META_SESSION ),
		);
		if ( '' === $tag['channel'] && '' === $tag['mode'] && '' === $tag['signal'] && '' === $tag['session'] ) {
			return null;
		}
		$tag['session'] = '' === $tag['session'] ? null : $tag['session'];
		return $tag;
	}

	/**
	 * Writes a tag. The session row only exists for `handoff` (no empty rows).
	 *
	 * @param WC_Order $order The order.
	 * @param array    $tag   From Urumi_Agentic_Signals::resolve().
	 * @return void
	 */
	private static function write_tag( WC_Order $order, array $tag ) {
		$order->update_meta_data( self::META_CHANNEL, $tag['channel'] );
		$order->update_meta_data( self::META_MODE, $tag['mode'] );
		$order->update_meta_data( self::META_SIGNAL, $tag['signal'] );
		if ( 'handoff' === $tag['mode'] && null !== $tag['session'] ) {
			$order->update_meta_data( self::META_SESSION, $tag['session'] );
		}
	}

	/**
	 * Deletes the four tag keys that are present.
	 *
	 * @param WC_Order $order The order.
	 * @return void
	 */
	private static function delete_tag( WC_Order $order ) {
		foreach ( array( self::META_CHANNEL, self::META_MODE, self::META_SIGNAL, self::META_SESSION ) as $key ) {
			if ( '' !== (string) $order->get_meta( $key ) ) {
				$order->delete_meta_data( $key );
			}
		}
	}

	/**
	 * Whether a meta key is one of our order tags.
	 *
	 * @param mixed $key Meta key.
	 * @return bool
	 */
	private static function is_tag_key( $key ) {
		return is_string( $key ) && 0 === strpos( $key, self::META_PREFIX );
	}

	/**
	 * The request headers the signals read. Only the checkout request itself is checked.
	 *
	 * @return array
	 */
	private static function server() {
		$server = array();
		foreach ( array( 'HTTP_SIGNATURE_AGENT', 'HTTP_USER_AGENT' ) as $name ) {
			if ( isset( $_SERVER[ $name ] ) && is_string( $_SERVER[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared against allow-lists, never stored or printed.
				$server[ $name ] = wp_unslash( $_SERVER[ $name ] );
			}
		}
		return $server;
	}

	/**
	 * The order's status without its view-context default. A new classic order
	 * has '' until it is created; that is `pending` whatever the store's
	 * `woocommerce_default_order_status` filter returns.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private static function status( $order ) {
		$status = (string) $order->get_status( 'edit' );
		return '' === $status ? 'pending' : $status;
	}

	/**
	 * Whether this request is a classic checkout: WC_Checkout::process_checkout()
	 * defines WOOCOMMERCE_CHECKOUT on both the wc-ajax and the form-post path.
	 * WP-CLI and cron never count.
	 *
	 * @return bool
	 */
	private static function is_classic_checkout_request() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return false;
		}
		return defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT;
	}

	/**
	 * Whether this is a Store API request. WC 8.2 has no is_store_api_request(),
	 * so fall back to REST_REQUEST plus a `/wc/store/` route in the path.
	 *
	 * @return bool
	 */
	private static function is_store_api_request() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		if ( is_object( $woocommerce ) && method_exists( $woocommerce, 'is_store_api_request' ) ) {
			return (bool) $woocommerce->is_store_api_request();
		}
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the path is matched.
			$path = wp_parse_url( '/' . ltrim( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' ), PHP_URL_PATH );
			if ( is_string( $path ) && false !== strpos( $path, '/wc/store/' ) ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reading the route only, no state change.
		$route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ? wp_unslash( $_GET['rest_route'] ) : '';
		return 0 === strpos( '/' . ltrim( $route, '/' ), '/wc/store/' );
	}

	/**
	 * Logs that tagging was skipped, with the exception class only: messages
	 * can carry order or shopper data.
	 *
	 * @param Throwable $e What was thrown.
	 * @return void
	 */
	private static function log_failure( Throwable $e ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( 'Order tagging skipped after ' . get_class( $e ), array( 'source' => 'urumi-agentic' ) );
		}
	}
}
