<?php
/**
 * Rebuilds the cart from a hand-off link and pre-fills checkout (pl7-plan.md section 5: the lines
 * in 5.1, the customer in 5.2, the shipping rate in 5.3, the coupon in 5.4), re-applies the
 * pre-fill once when a guest logs in, and puts the customer back when the hand-off ends without an
 * order (end_copy()).
 *
 * Every Woo call is public API, and prices never come from the link.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cart and customer half of opening a link.
 */
class Urumi_Agentic_Handoff_Cart {

	const MAX_LINES    = 100;
	const MAX_QUANTITY = 10000;

	/**
	 * Longest customer value (bytes) the pre-fill writes; a longer one is left out.
	 */
	const MAX_FIELD_BYTES = 200;

	/**
	 * Longest coupon code (bytes) the link may carry.
	 */
	const MAX_COUPON_BYTES = 100;

	/**
	 * The pre-fill copy's shape. 2 added `before`: the value each written field had just before the
	 * link wrote it, `hashes`: the trace's hashes of what was written (Urumi_Agentic_Handoff_Trace),
	 * optional, and `opened`: when the link was opened (Unix seconds), optional. A copy of another
	 * version is dropped without putting anything back (it has no
	 * `before`); after an upgrade that is at most the 4 hours of one copy.
	 */
	const PREFILL_VERSION = 2;

	/**
	 * Why a copy ended, besides Urumi_Agentic_Handoff_Hint's END_* reasons: a new link replaced it,
	 * it expired (or is corrupt), or its hint went.
	 */
	const END_REPLACED  = 'replaced';
	const END_EXPIRED   = 'expired';
	const END_HINT_GONE = 'hint_gone';

	/**
	 * Session key: which location fields a link wrote as `""` for a logged-in customer (field names and
	 * the customer id only, no values). Kept for the Woo session's life, longer than the hint and the
	 * pre-fill copy, because a `""` in the session customer can last that long (keep_cleared_location());
	 * end_copy() takes out the fields it gives a value back (put_back_cleared()).
	 */
	const CLEARED_SESSION_KEY = 'urumi_agentic_handoff_cleared';

	/**
	 * The Blocks "create an account" form on the order confirmation page (WooCommerce 9.x and later,
	 * block themes), which registers the order's billing email after checkout.
	 */
	const DELAYED_ACCOUNT_BLOCK = 'Automattic\\WooCommerce\\Blocks\\BlockTypes\\OrderConfirmation\\CreateAccount';

	/**
	 * The Store API's exception (WooCommerce 8.2 to 11.2): what store_api_pre_check() throws.
	 */
	const ROUTE_EXCEPTION = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

	/**
	 * The error code of a registration refused because the email came from a link (R1).
	 */
	const LINK_EMAIL_ERROR = 'urumi_agentic_link_email';

	const RATE_NONE   = 'none';
	const RATE_CHOSEN = 'chosen';
	const RATE_FAILED = 'failed';

	/**
	 * Why a line that was in the cart was taken out while a later line was added (record_reasons()).
	 */
	const REMOVED_MIXED            = 'mixed';
	const REMOVED_ONE_SUBSCRIPTION = 'one_subscription';
	const REMOVED_GENERIC          = 'generic';

	/**
	 * Why the check (refusal()) says a line can't be added, for the kept path's counts. Codes only:
	 * never a name or an id.
	 */
	const REASON_QUANTITY    = 'quantity';
	const REASON_STRUCTURE   = 'structure';
	const REASON_PURCHASABLE = 'purchasable';
	const REASON_STOCK       = 'stock';

	/**
	 * The notice marker for each removal reason, in the order the Notice prints their sentences.
	 */
	const REASON_MARKERS = array(
		self::REMOVED_MIXED            => Urumi_Agentic_Handoff_Notice::MIXED_CART_MARKER,
		self::REMOVED_ONE_SUBSCRIPTION => Urumi_Agentic_Handoff_Notice::ONE_SUBSCRIPTION_MARKER,
		self::REMOVED_GENERIC          => Urumi_Agentic_Handoff_Notice::REMOVED_MARKER,
	);

	/**
	 * Name fields: written one by one, never as "".
	 */
	const NAME_FIELDS = array( 'first_name', 'last_name' );

	/**
	 * Location fields: written together, "" included, when address_1 is not "" (Q5).
	 */
	const LOCATION_FIELDS = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * What the address-comparing checks compare (names and location).
	 */
	const COMPARED_FIELDS = array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * Keys of the session cart just before Woo loaded it this request (and merged a saved cart into it,
	 * after a login). Null when not taken.
	 *
	 * @var string[]|null
	 */
	private static $cart_keys_before_load = null;

	/**
	 * The session's chosen rate for package 0 at that same point: before a login's saved-cart merge
	 * recalculates the totals for the account's address (11.2.1 `class-wc-cart-session.php:302-305`),
	 * which can reset it. Null when not taken.
	 *
	 * @var string|null
	 */
	private static $rate_before_load = null;

	/**
	 * Whether this request is a Store API checkout that has reached Woo's update-customer step (after
	 * which it writes the order's addresses to the account). See keep_account_address_on_save().
	 *
	 * @var bool
	 */
	private static $store_api_checkout = false;

	/**
	 * Ids of the accounts Woo created in this request (`woocommerce_created_customer`): a checkout
	 * that registers the shopper saves the order's details to the new account as Woo does.
	 *
	 * @var array<int, true>
	 */
	private static $created = array();

	/**
	 * Whether a hand-off rebuild is running in this request (rebuild()): the lines it adds are not
	 * checked by after_add_to_cart().
	 *
	 * @var bool
	 */
	private static $rebuilding = false;

	/**
	 * Registers the login re-apply and the classic "ship to a different address" default.
	 *
	 * @return void
	 */
	public static function boot() {
		// Before Woo merges a saved cart into the session cart (get_cart_from_session, wp_loaded 10).
		add_action( 'woocommerce_load_cart_from_session', array( __CLASS__, 'remember_cart_keys' ), 10, 0 );
		// Every request that loads the cart (pages, wc-ajax, the Store API): WC()->customer is built, and
		// the cart's totals (shipping zone, taxes) are not yet worked out from it.
		add_action( 'woocommerce_load_cart_from_session', array( __CLASS__, 'keep_cleared_location' ), 5, 0 );
		// The classic checkout form reads a customer of its own for a logged-in shopper.
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_value' ), 10, 2 );
		// After that merge, and after the hand-off POST (15), which always exits.
		add_action( 'wp_loaded', array( __CLASS__, 'reapply_after_login' ), 16, 0 );
		add_filter( 'woocommerce_ship_to_different_address_checked', array( __CLASS__, 'ship_to_different_address' ), 20, 1 );
		// Every end of the hint (cart emptied, order out of draft) ends the copy.
		add_action( Urumi_Agentic_Handoff_Hint::FORGET_ACTION, array( __CLASS__, 'end_copy' ), 10, 1 );
		// So does removing the cart's last line (WC_Cart::remove_cart_item(), which empty_cart() does not
		// call): it forgets the hint as an emptied cart does.
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'item_removed' ), 10, 2 );
		// Any checkout order, tagged or not, uses the pre-fill: before payment, and before Woo empties the cart.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'order_placed' ), 10, 0 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_placed' ), 10, 0 );
		// The delivery and contact lines, built again on every checkout render while the copy is live.
		add_filter( Urumi_Agentic_Handoff_Notice::LIVE_FILTER, array( __CLASS__, 'live_lines' ), 10, 2 );
		// R2: an order does not save the link's names and addresses to the account. Classic: the
		// account customer WC_Checkout::process_customer() is about to save. Blocks: the one the Store
		// API's sync_customer_data_with_order() saves, after its update-customer step. Not an account
		// created in this request.
		self::$store_api_checkout = false;
		self::$created            = array();
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'keep_account_address' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( __CLASS__, 'start_store_api_checkout' ), 10, 0 );
		add_action( 'woocommerce_before_customer_object_save', array( __CLASS__, 'keep_account_address_on_save' ), 10, 2 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'created_customer' ), 10, 1 );
		// R1, an account needed after the open: an add to cart (classic and Store API, after the line is
		// in the cart) whose checkout can now create an account takes the link's email out.
		self::$rebuilding = false;
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'after_add_to_cart' ), 20, 0 );
		// R1: every wc_create_new_customer() (classic checkout, the Blocks checkout from 10.0, the order
		// confirmation's create-account form, My Account) refuses the link's email once. Late, so Woo's
		// own refusals come first and keep the marker.
		add_filter( 'woocommerce_registration_errors', array( __CLASS__, 'registration_errors' ), 99, 3 );
		// D2: the Blocks checkout POST refuses it before Woo creates the account, on every version
		// (WooCommerce 8.2 swallows a registration refusal and places a guest order).
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( __CLASS__, 'store_api_pre_check' ), 20, 2 );
	}

	/**
	 * Empties the cart, adds the link's lines, pre-fills the customer, applies the coupon, selects
	 * the rate, sets the notice and remembers the hint, in the order of pl7-plan.md section 5.
	 *
	 * Before any of that, each line is checked without adding it (can_add()). When no line can be
	 * added, nothing changes: the cart, the customer, an earlier link's hint and pre-fill copy, its
	 * coupon and rate all stay as they were, and the none-added notice says so (keep()). One line
	 * that can be added is enough for the rest to run as before.
	 *
	 * What counts as added is what is in the cart after the lines (step 3b, reconcile()): a store plugin
	 * can take an earlier line out while a later one is added (Subscriptions' one-subscription and
	 * no-mixed-cart rules, a "one item per cart" snippet). Such a line is left out and named, with the
	 * reason recorded at the step that removed it.
	 *
	 * @param array                      $payload A payload from Urumi_Agentic_Handoff_Token::open().
	 * @param Urumi_Agentic_Handoff_Hint $hint    Hint store.
	 * @param int                        $now     Unix seconds.
	 * @return array{added: int, skipped: int, removed: int, reduced: int, kept: bool, reasons?: array<string, int>}
	 *         Products added (distinct ids), lines left out, of those the lines a store plugin removed,
	 *         units below what the added lines asked for (log only), and whether the cart was kept
	 *         because no line could be added. Only then `reasons`: the checked lines by REASON_*.
	 */
	public static function rebuild( #[\SensitiveParameter] array $payload, Urumi_Agentic_Handoff_Hint $hint, $now ) {
		// R1: the lines added here are not checked after the add (after_add_to_cart()). This link's own
		// email is decided at step 4, and end_copy() has already put back an earlier link's values.
		self::$rebuilding = true;
		try {
			return self::rebuild_cart( $payload, $hint, $now );
		} finally {
			self::$rebuilding = false;
		}
	}

	/**
	 * The body of rebuild().
	 *
	 * @param array                      $payload A payload from Urumi_Agentic_Handoff_Token::open().
	 * @param Urumi_Agentic_Handoff_Hint $hint    Hint store.
	 * @param int                        $now     Unix seconds.
	 * @return array See rebuild().
	 */
	private static function rebuild_cart( #[\SensitiveParameter] array $payload, Urumi_Agentic_Handoff_Hint $hint, $now ) {
		$woocommerce = WC();
		$cart        = $woocommerce->cart;
		$session     = $woocommerce->session;
		$lines       = is_array( $payload['cart'] ?? null ) ? array_values( $payload['cart'] ) : array();

		// H5. Can any line be added? Checked before anything changes, without adding (can_add()).
		$refusals = self::refusals( $lines );
		if ( null !== $refusals ) {
			return self::keep( $payload, $cart, $lines, $refusals, $now );
		}

		// 0. An earlier link's copy ends here, so its fields go back to what they were before it, unless
		// the shopper changed them since; this link then writes its own (end_copy()). Its pre-link values
		// are read after that, so they are the shopper's own again.
		self::end_copy( self::END_REPLACED );

		// 1. Empty the cart, the persistent one too. Fires woocommerce_cart_emptied, so the
		// tagger forgets any old hint (and Woo 10.5+ forgets the Blocks draft order).
		$cart->empty_cart();

		// 2. Every Woo version forgets the draft and awaiting-payment orders, so a hand-off
		// always gets a fresh order and never reuses one with someone else's tags.
		$session->set( 'store_api_draft_order', null );
		$session->set( 'order_awaiting_payment', null );

		// 3. The lines, in link order. Notices other plugins add while a line is checked or added are kept;
		// Woo's own refusal and coupon notices are dropped (add_line(), step 8). The cart's keys are read
		// before and after each line, so an earlier line that this line's step took out of the cart gets
		// this step's reason (record_reasons()).
		$notices  = $session->get( 'wc_notices' );
		$foreign  = array();
		$results  = array();
		$reasons  = array();
		$left_out = array();
		$rules    = self::subscription_rules();
		foreach ( $lines as $index => $line ) {
			$line = is_array( $line ) ? $line : array();
			if ( $index >= self::MAX_LINES ) {
				$left_out[ $index ] = self::left_out_name( $line );
				continue;
			}
			$keys   = self::cart_keys( $cart );
			$result = self::add_line( $line, $foreign );
			if ( is_array( $result ) ) {
				$results[ $index ] = $result + array( 'quantity' => (int) $line['quantity'] );
			} else {
				$left_out[ $index ] = self::left_out_name( $line );
			}
			self::record_reasons( $keys, self::cart_keys( $cart ), $index, (int) ( $line['product_id'] ?? 0 ), $results, $reasons, $rules );
		}

		// 3b. What is in the cart now decides what was added: a line a later step removed is left out,
		// at its place in link order, and every gate below (pre-fill, coupon, totals, rate, hint, copy)
		// follows the real cart. The reason sentences go ahead of the names (Urumi_Agentic_Handoff_Notice).
		$real    = self::reconcile( $cart, $results, $reasons );
		$added   = $real['added'];
		$removed = $real['removed'];
		foreach ( $removed as $index => $reason ) {
			$left_out[ $index ] = self::left_out_name( $lines[ $index ] );
		}
		ksort( $left_out );
		$skipped  = count( $left_out );
		$left_out = array_merge( self::reason_markers( $removed ), array_values( $left_out ) );

		// Steps 4-7 run Woo and extension code (live-rate, fee and tax plugins in calculate_totals()). A throw
		// there still reaches the caller (the outcome log says `failed:<class>`), but only after steps 8 and 9:
		// Woo's notices restored, ours set, the hint and the pre-fill copy kept.
		$prefill       = array();
		$before        = array();
		$hashes        = array();
		$customer      = null;
		$attempted     = false;
		$own_email     = false;
		$coupon_failed = null;
		$rate_failed   = false;
		$rate_chosen   = '';
		try {
			// 4. The customer, saved (to the session: Woo writes the account only when an order is placed,
			// and then not the link's names and addresses, keep_account_address()).
			// A logged-in customer's own email and phone are kept, and a guest whose checkout can create an
			// account gets no email from the link (fields_for_customer()). Only with lines in the cart: an
			// empty cart never reaches checkout, where the delivery and contact notices are shown, so the
			// link's details would sit in the session unannounced. After step 3, so a cart that needs an
			// account (a subscription) counts.
			$customer = self::customer();
			if ( $added ) {
				$values  = self::prefill_values( $payload );
				$prefill = null === $customer ? $values : self::fields_for_customer( $customer, $values );
				// For a guest, fields_for_customer() drops the link's email only when the checkout may create an
				// account (R1), so "the link had one and it was dropped" means exactly that case.
				$own_email = null !== $customer && isset( $values['billing_email'] ) && ! isset( $prefill['billing_email'] ) && self::is_guest( $customer );
			}
			if ( $prefill && null !== $customer ) {
				// Set before the call: a throw part-way leaves the setters already run in the session customer
				// (Woo saves it at shutdown), so a partly written address still gets the copy, and the lines.
				$attempted = true;
				try {
					$before = self::read_fields( $customer, array_keys( $prefill ) );
					self::remember_cleared( $customer, $prefill );
					self::apply_prefill( $customer, $prefill );
				} catch ( Throwable $e ) {
					unset( $e );
				}
				// This link writes, so an earlier link's trace goes (all but its hashes, kept account-only,
				// Urumi_Agentic_Handoff_Trace::clear()); this one's hashes (of what the
				// customer now holds) wait in the copy until it ends. Neither call throws.
				Urumi_Agentic_Handoff_Trace::clear();
				$hashes = Urumi_Agentic_Handoff_Trace::hashes_of( $customer, $prefill );
			}

			if ( $added ) {
				// 5. The coupon: after the customer (email restrictions), before the rate (a coupon can unlock one).
				$coupon_failed = self::apply_coupon( $cart, $payload['coupon'] ?? null );

				// 6. Totals, which calculate shipping for the new address and coupon.
				$cart->calculate_totals();

				// 7. The rate, then the totals again.
				$rate        = $payload['shipping_method'] ?? null;
				$result      = self::choose_rate( $woocommerce, $cart, $session, $rate );
				$rate_failed = self::RATE_FAILED === $result;
				$rate_chosen = self::RATE_CHOSEN === $result ? (string) $rate : '';
			}
		} finally {
			self::finish( $payload, $hint, $now, $notices, $foreign, $left_out, $added, $prefill, $before, $hashes, $attempted ? $customer : null, $coupon_failed, $rate_failed, $rate_chosen, $own_email );
		}

		return array(
			'added'   => count( $added ),
			'skipped' => $skipped,
			'removed' => count( $removed ),
			'reduced' => $real['reduced'],
			'kept'    => false,
		);
	}

	/**
	 * Steps 8 and 9 of the rebuild. Each part is on its own, so one that throws does not stop the others
	 * (and, called from a `finally`, never replaces the throw that is already on its way).
	 *
	 * @param array                      $payload       The payload.
	 * @param Urumi_Agentic_Handoff_Hint $hint          Hint store.
	 * @param int                        $now           Unix seconds.
	 * @param mixed                      $notices       The session's `wc_notices` before step 3.
	 * @param array                      $foreign       Notices other plugins added during step 3, by type (add_line()).
	 * @param string[]                   $left_out      Reason markers, then names of the lines left out.
	 * @param array                      $added         Lines added (in the cart after step 3).
	 * @param array<string, string>      $prefill       From prefill_values().
	 * @param array<string, string>      $before        The written fields' values just before (read_fields()).
	 * @param array<string, string>      $hashes        The trace's hashes of what was written.
	 * @param object|null                $customer      The customer the pre-fill was written to, or null.
	 * @param string|null                $coupon_failed The code to name, or null.
	 * @param bool                       $rate_failed   Whether the link's rate could not be chosen.
	 * @param string                     $rate_chosen   The link's rate, when it was chosen.
	 * @param bool                       $own_email     Whether the link's email was left out for a guest whose
	 *                                                  checkout can create an account.
	 * @return void
	 */
	private static function finish( #[\SensitiveParameter] array $payload, Urumi_Agentic_Handoff_Hint $hint, $now, $notices, array $foreign, array $left_out, array $added, #[\SensitiveParameter] array $prefill, #[\SensitiveParameter] array $before, array $hashes, $customer, $coupon_failed, $rate_failed, $rate_chosen, $own_email = false ) {
		$session = WC()->session;
		$steps   = array(
			// 8. The notices from before the link, plus those other plugins added while the lines were checked
			// and added (appended only, de-duplicated: merged_notices()); Woo's add-to-cart refusals and
			// coupon notices go. Ours are set. The delivery and contact lines are not stored: live_lines()
			// builds them from the copy on every checkout render.
			static function () use ( $session, $notices, $foreign ) {
				$session->set( 'wc_notices', self::merged_notices( $notices, $foreign ) );
			},
			static function () use ( $left_out, $now ) {
				if ( $left_out ) {
					Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_LEFT_OUT, array_values( array_unique( $left_out ) ), $now );
				}
			},
			static function () use ( $coupon_failed, $now ) {
				if ( null !== $coupon_failed ) {
					Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_COUPON, array( $coupon_failed ), $now );
				}
			},
			static function () use ( $rate_failed, $now ) {
				if ( $rate_failed ) {
					Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_SHIPPING, array(), $now );
				}
			},
			// 9. Tag the order later: only for a channel this plugin knows, and only with lines that were added.
			static function () use ( $payload, $hint, $added ) {
				$channel = is_string( $payload['channel'] ?? null ) ? $payload['channel'] : '';
				if ( in_array( $channel, Urumi_Agentic_Settings::CHANNELS, true ) ) {
					$hint->remember( $channel, is_string( $payload['session_id'] ?? null ) ? $payload['session_id'] : '', array_values( $added ), (int) ( $payload['expires_at'] ?? 0 ) );
				} else {
					Urumi_Agentic_Settings::count_unknown_channel();
				}
			},
			// And the pre-fill copy. Only with lines in the cart (an empty cart never reaches checkout).
			// Normally bound to the hint: the login re-apply needs it, and it goes with the hint. A link with
			// a channel this plugin does not know opens the cart without a hint; its copy (unbound, at most
			// 4 hours) still serves the classic "ship to a different address" default, so a gift's recipient
			// is not lost, and is never re-applied. `before` is what end_copy() puts back. `own_email` says
			// the link's email was left out (R1), for live_lines(); a left-out email is in none of `fields`,
			// `before` or `hashes`, so it is never named, put back or traced.
			static function () use ( $session, $hint, $now, $payload, $added, $prefill, $before, $hashes, $customer, $rate_chosen, $own_email ) {
				if ( ! $added || ! $prefill || null === $customer ) {
					return;
				}
				$remembered = $hint->get( (int) $now );
				$session->set(
					Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY,
					array(
						'v'           => self::PREFILL_VERSION,
						'fields'      => $prefill,
						'before'      => array_intersect_key( $before, $prefill ),
						'hashes'      => $hashes,
						'customer_id' => (int) $customer->get_id(),
						'opened'      => (int) $now,
						'expires_at'  => null !== $remembered ? $remembered['expires_at'] : min( (int) $now + Urumi_Agentic_Handoff_Hint::TTL, (int) ( $payload['expires_at'] ?? 0 ) ),
						'hint'        => null !== $remembered,
						'rate'        => $rate_chosen,
						'own_email'   => (bool) $own_email,
					)
				);
			},
		);
		foreach ( $steps as $step ) {
			try {
				$step();
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * LIVE_FILTER (Urumi_Agentic_Handoff_Notice): the delivery-address and contact lines (T1b), built
	 * from the live pre-fill copy on every checkout render, so they show for as long as the link's
	 * details can be in the form and stop when the copy ends. Nothing while the live copy is another
	 * customer's. With a guest's live copy whose link's email was left out (R1), the own-email line
	 * too. With no live copy, the trace's lines for the ended case
	 * (Urumi_Agentic_Handoff_Trace::ended_lines()): values a link wrote that are in the session
	 * customer again after the copy ended. Never throws.
	 *
	 * @param mixed    $lines What an earlier filter gave.
	 * @param int|null $now   Unix seconds.
	 * @return mixed
	 */
	public static function live_lines( $lines, $now = null ) {
		try {
			$session  = self::session();
			$customer = self::customer();
			if ( null === $session || null === $customer || ! is_array( $lines ) ) {
				return $lines;
			}
			$copy = self::prefill_copy( $session, null === $now ? time() : (int) $now );
			if ( null === $copy ) {
				return Urumi_Agentic_Handoff_Trace::ended_lines( $lines, $customer );
			}
			$id = is_callable( array( $customer, 'get_id' ) ) ? $customer->get_id() : null;
			if ( ! is_scalar( $id ) || (int) $id !== $copy['customer_id'] ) {
				return $lines;
			}
			$contact = self::contact_line( $customer, $copy['fields'] );
			if ( $contact ) {
				$lines[ Urumi_Agentic_Handoff_Notice::KIND_CONTACT ] = $contact;
			}
			// R1: the link's email was left out for this guest, whose checkout can create an account. Only
			// while the copy is still the guest's: after a login the form holds the account's own email.
			if ( ! empty( $copy['own_email'] ) && 0 === $copy['customer_id'] && ! ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) ) {
				$lines[ Urumi_Agentic_Handoff_Notice::KIND_OWN_EMAIL ] = array( Urumi_Agentic_Handoff_Notice::OWN_EMAIL_MARKER );
			}
			$delivery = self::delivery_line( $customer, $copy['fields'] );
			if ( $delivery ) {
				$lines[ Urumi_Agentic_Handoff_Notice::KIND_DELIVERY ] = $delivery;
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $lines;
	}

	/**
	 * The delivery-address line (T1b) while a street the link wrote is still in the customer: the
	 * shipping one when it still holds the link's, else billing (the classic checkout ships to billing
	 * when its "different address" box is unchecked). Name, town and postcode, country, read from the
	 * customer. Empty once the shopper has replaced the link's streets, or when it wrote no location.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   What the link wrote (the copy's `fields`).
	 * @return string[]
	 */
	private static function delivery_line( $customer, #[\SensitiveParameter] array $fields ) {
		foreach ( array( 'shipping', 'billing' ) as $type ) {
			if ( self::still_written( $customer, $fields, $type . '_address_1' ) ) {
				return self::delivery_summary( $customer, $type );
			}
		}
		return array();
	}

	/**
	 * The contact-details line (T1b): the billing name, email and phone the link wrote that the customer
	 * still holds, read from the customer. A name shows while either of its two parts is still the
	 * link's. Empty when none is left.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   What the link wrote (the copy's `fields`).
	 * @return string[]
	 */
	private static function contact_line( $customer, #[\SensitiveParameter] array $fields ) {
		$values = array();
		if ( self::still_written( $customer, $fields, 'billing_first_name' ) || self::still_written( $customer, $fields, 'billing_last_name' ) ) {
			$values[] = trim( self::read_field( $customer, 'billing_first_name' ) . ' ' . self::read_field( $customer, 'billing_last_name' ) );
		}
		foreach ( array( 'billing_email', 'billing_phone' ) as $field ) {
			if ( self::still_written( $customer, $fields, $field ) ) {
				$values[] = $fields[ $field ];
			}
		}
		return array_values(
			array_filter(
				$values,
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * Whether the link wrote a field and the customer still holds that value.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   What the link wrote.
	 * @param string                $field    Field name.
	 * @return bool
	 */
	private static function still_written( $customer, #[\SensitiveParameter] array $fields, $field ) {
		return isset( $fields[ $field ] ) && is_string( $fields[ $field ] ) && self::read_field( $customer, $field ) === $fields[ $field ];
	}

	/**
	 * Whether the link wrote `""` as the billing state and the customer shows Woo's default state for
	 * it instead: the session data store's set_defaults() fills an empty billing state with the store's
	 * default on every read, so it still counts as what the link wrote.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   What the link wrote.
	 * @param string                $field    Field name.
	 * @return bool
	 */
	private static function defaulted_state( $customer, #[\SensitiveParameter] array $fields, $field ) {
		if ( 'billing_state' !== $field || '' !== ( $fields[ $field ] ?? null ) || ! function_exists( 'wc_get_customer_default_location' ) ) {
			return false;
		}
		$default = wc_get_customer_default_location();
		$state   = is_array( $default ) && is_string( $default['state'] ?? null ) ? $default['state'] : '';
		return '' !== $state && self::read_field( $customer, $field ) === $state;
	}

	/**
	 * One customer field as a string; null when there is no getter or it throws (so it never equals a
	 * written value).
	 *
	 * @param object $customer WC_Customer.
	 * @param string $field    Field name.
	 * @return string|null
	 */
	private static function read_field( $customer, $field ) {
		try {
			if ( ! is_callable( array( $customer, 'get_' . $field ) ) ) {
				return null;
			}
			$value = $customer->{'get_' . $field}();
			return is_scalar( $value ) || null === $value ? (string) $value : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * The customer's values of some fields, as end_copy() would put them back: a field whose getter is
	 * missing or throws is left out (and so never put back). Never throws.
	 *
	 * @param object   $customer WC_Customer.
	 * @param string[] $names    Field names.
	 * @return array<string, string>
	 */
	private static function read_fields( $customer, array $names ) {
		$values = array();
		foreach ( $names as $field ) {
			$value = is_string( $field ) ? self::read_field( $customer, $field ) : null;
			if ( null !== $value ) {
				$values[ $field ] = $value;
			}
		}
		return $values;
	}

	/**
	 * The customer fields a link sets, as WC_Customer setter suffix => value (pl7-plan.md section 5.2).
	 *
	 * - `""` means "none": a name, email or phone that is `""` is not in the result.
	 * - An address whose `address_1` is not `""` brings its six location fields together, `""`
	 *   included, so a second link never leaves the first one's `address_2` or `state` behind. Its
	 *   country must be a WooCommerce country, else the location is left out.
	 * - `phone` also goes to `shipping_phone` whenever the shipping location is written (Q11).
	 * - Every value is a string of at most 200 bytes, then wc_clean()'d; the email must pass is_email().
	 *
	 * Who the customer is (a logged-in account keeps its email and phone) is applied later, by
	 * fields_for_customer().
	 *
	 * @param array $payload A payload from Urumi_Agentic_Handoff_Token::open().
	 * @return array<string, string>
	 */
	public static function prefill_values( #[\SensitiveParameter] array $payload ) {
		$fields = array();
		$email  = self::clean( $payload['email'] ?? null );
		if ( null !== $email && '' !== $email && is_email( $email ) ) {
			$fields['billing_email'] = $email;
		}
		$phone = self::clean( $payload['phone'] ?? null );
		if ( null !== $phone && '' !== $phone ) {
			$fields['billing_phone'] = $phone;
		}
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$address = is_array( $payload[ $type ] ?? null ) ? $payload[ $type ] : array();
			foreach ( self::NAME_FIELDS as $name ) {
				$value = self::clean( $address[ $name ] ?? null );
				if ( null !== $value && '' !== $value ) {
					$fields[ $type . '_' . $name ] = $value;
				}
			}
			$location = self::location( $address );
			if ( null === $location ) {
				continue;
			}
			foreach ( $location as $name => $value ) {
				$fields[ $type . '_' . $name ] = $value;
			}
			if ( 'shipping' === $type && isset( $fields['billing_phone'] ) ) {
				$fields['shipping_phone'] = $fields['billing_phone'];
			}
		}
		return $fields;
	}

	/**
	 * The fields to write to this customer (pl7-plan.md section 5.2). A logged-in customer keeps the
	 * email and phone the account already has: Woo would show the link's on both checkouts, save them
	 * to the account with the order and send the order mail there. When the phone is kept, a written
	 * shipping location gets the account's phone as `shipping_phone`, so Blocks still sees the two
	 * addresses as the same (Q11).
	 *
	 * A guest whose checkout can create an account gets no email from the link (R1, may_create_account()):
	 * Woo would create the account under that email and send its mail there, so whoever chose the email
	 * could take the account over.
	 *
	 * Fails closed: a customer whose id can't be read as an int counts as logged in, and an email or
	 * phone that can't be read for a logged-in customer counts as present. Never throws.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   From prefill_values().
	 * @return array<string, string>
	 */
	private static function fields_for_customer( $customer, #[\SensitiveParameter] array $fields ) {
		$contact = array( 'billing_email', 'billing_phone', 'shipping_phone' );
		try {
			$id = is_object( $customer ) && is_callable( array( $customer, 'get_id' ) ) ? $customer->get_id() : null;
			if ( 0 === $id ) {
				if ( isset( $fields['billing_email'] ) && self::may_create_account() ) {
					unset( $fields['billing_email'] );
				}
				return $fields;
			}
			$read = static function ( $getter ) use ( $customer ) {
				if ( ! is_callable( array( $customer, $getter ) ) ) {
					return null;
				}
				$value = $customer->{$getter}();
				return is_string( $value ) ? $value : null;
			};
			if ( isset( $fields['billing_email'] ) && ( '' !== $read( 'get_billing_email' ) || '' !== $read( 'get_email' ) ) ) {
				unset( $fields['billing_email'] );
			}
			if ( isset( $fields['billing_phone'] ) ) {
				$phone = $read( 'get_billing_phone' );
				if ( '' !== $phone ) {
					unset( $fields['billing_phone'] );
					if ( isset( $fields['shipping_phone'] ) ) {
						if ( null === $phone ) {
							unset( $fields['shipping_phone'] );
						} else {
							$fields['shipping_phone'] = $phone;
						}
					}
				}
			}
			return $fields;
		} catch ( Throwable $e ) {
			unset( $e );
			return array_diff_key( $fields, array_flip( $contact ) );
		}
	}

	/**
	 * Whether a guest's checkout here can create an account under the billing email (R1). True when any
	 * of these holds:
	 * - registration at checkout is on (`woocommerce_enable_signup_and_login_from_checkout`, filter
	 *   `woocommerce_checkout_registration_enabled`): the classic "Create an account?" box and the Blocks
	 *   `create_account` option. Whether the shopper will tick it is not known when the link opens, so
	 *   offered counts as created;
	 * - registration is required (guest checkout off, or a cart that needs an account: the filter
	 *   `woocommerce_checkout_registration_required`, which subscriptions use). Woo then creates the
	 *   account with the order (classic `WC_Checkout::process_customer()`, Blocks
	 *   `Checkout::should_create_customer_account()`, every supported version);
	 * - the Blocks "create an account" form after checkout is live: WooCommerce has it, a block theme is
	 *   active (it is hooked into the order confirmation block template) and
	 *   `woocommerce_enable_delayed_account_creation` is on, with Woo's own default of `yes`. It
	 *   registers the order's billing email.
	 *
	 * Fails closed: a missing or throwing check counts as "can create". Never throws.
	 *
	 * @return bool
	 */
	private static function may_create_account() {
		try {
			$woocommerce = WC();
			$checkout    = is_object( $woocommerce ) && is_callable( array( $woocommerce, 'checkout' ) ) ? $woocommerce->checkout() : null;
			if ( ! is_object( $checkout ) || ! is_callable( array( $checkout, 'is_registration_enabled' ) ) || ! is_callable( array( $checkout, 'is_registration_required' ) ) ) {
				return true;
			}
			if ( filter_var( $checkout->is_registration_enabled(), FILTER_VALIDATE_BOOLEAN ) || filter_var( $checkout->is_registration_required(), FILTER_VALIDATE_BOOLEAN ) ) {
				return true;
			}
			// The class name is WooCommerce-internal; the registered block name is the stable fallback.
			$form = class_exists( self::DELAYED_ACCOUNT_BLOCK )
				|| ( class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'woocommerce/order-confirmation-create-account' ) );
			return $form
				&& function_exists( 'wp_is_block_theme' ) && wp_is_block_theme()
				&& 'yes' === get_option( 'woocommerce_enable_delayed_account_creation', 'yes' );
		} catch ( Throwable $e ) {
			unset( $e );
			return true;
		}
	}

	/**
	 * Whether a customer is a guest: its id is the int 0 (as fields_for_customer() reads it). Never throws.
	 *
	 * @param object $customer WC_Customer.
	 * @return bool
	 */
	private static function is_guest( $customer ) {
		try {
			return is_callable( array( $customer, 'get_id' ) ) && 0 === $customer->get_id();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * R1, an account needed after the open (pl7-plan.md section 5.2), `woocommerce_add_to_cart`: the
	 * shopper added to the cart (a classic add or the Store API's `cart/add-item`; the line is in the
	 * cart now), and the checkout may now create an account (may_create_account(): a subscription in
	 * the cart, a setting changed since the open). If the guest's session customer still holds an
	 * email a link wrote (Urumi_Agentic_Handoff_Trace::links_email(): the live copy, the record or an
	 * earlier link's `account` set), it is set to `''` and saved, so Woo does not register it.
	 *
	 * With a guest's live copy, the email leaves the copy's `fields` and `before` and `own_email` is
	 * set: the contact line stops naming it and the own-email line shows on every checkout render.
	 * Without one, a one-shot line says so (Urumi_Agentic_Handoff_Notice::KIND_OWN_EMAIL_CLEARED).
	 *
	 * D1: the hash stays (in the copy, the record and the `account` sets), so a stale checkout tab
	 * that posts the email back is still refused once at registration (registration_errors(),
	 * store_api_pre_check()). The cost: a shopper who owns that email and types it again is refused
	 * once.
	 *
	 * Nothing happens during the hand-off rebuild, for a logged-in shopper or a customer with an id
	 * (no account is created for them), for an empty email, an email no link wrote, or an email the
	 * shopper had before the link (the live copy's `before`). The cheap reads come first: an add by a
	 * guest whose session never opened a link reads the customer's id and email and two session keys
	 * (the copy and the trace record), then returns before any hash is computed
	 * (Urumi_Agentic_Handoff_Trace::email_source()). Never throws.
	 *
	 * @return void
	 */
	public static function after_add_to_cart() {
		if ( self::$rebuilding ) {
			return;
		}
		try {
			$session  = self::session();
			$customer = self::customer();
			if ( null === $session || null === $customer || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) || ! self::is_guest( $customer ) ) {
				return;
			}
			$email = self::read_field( $customer, 'billing_email' );
			if ( null === $email || '' === trim( $email ) ) {
				return;
			}
			$copy   = self::prefill_copy( $session, time() );
			$source = Urumi_Agentic_Handoff_Trace::email_source( $email, self::copy_hashes( $copy ) );
			if ( '' === $source || self::had_before( $copy, $email ) || ! self::may_create_account() ) {
				return;
			}
			self::restore( $customer, 0, array( 'billing_email' => '' ), true );
			if ( '' !== self::read_field( $customer, 'billing_email' ) ) {
				return;
			}
			if ( null !== $copy && 0 === $copy['customer_id'] ) {
				self::drop_copy_email( $session, $copy, $email, false );
			} else {
				Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_OWN_EMAIL_CLEARED, array(), time() );
			}
			self::log( 'hand-off email_cleared via=add_to_cart from=' . $source );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * R1 (c), `woocommerce_registration_errors` (wc_create_new_customer(): the classic checkout, the
	 * Blocks checkout from WooCommerce 10.0, the order confirmation's create-account form, My Account
	 * registration): a guest's registration with an email a link wrote in this session
	 * (Urumi_Agentic_Handoff_Trace::links_email(), with the live copy's hashes) is refused once, with
	 * LINK_EMAIL_ERROR and a message worded for the form it is shown on (registration_message()),
	 * which never names the email. The marker is then used up
	 * (use_email()): the next attempt with the same email goes through, so its real owner is never
	 * locked out.
	 *
	 * Passed through unchanged: a value that is not a WP_Error, one that already has an error (Woo
	 * refuses anyway, and the marker is kept), a logged-in or sessionless request (an admin creating
	 * a customer, a REST call), another email, and the email the shopper had before the link (the
	 * live copy's `before`). Never throws.
	 *
	 * @param mixed  $errors   Woo's WP_Error.
	 * @param string $username The username.
	 * @param mixed  $email    The email to register.
	 * @return mixed
	 */
	public static function registration_errors( $errors, $username = '', #[\SensitiveParameter] $email = '' ) {
		unset( $username );
		if ( ! ( $errors instanceof WP_Error ) ) {
			return $errors;
		}
		try {
			$codes = $errors->get_error_codes();
			if ( ( is_array( $codes ) && $codes ) || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) ) {
				return $errors;
			}
			$session = self::session();
			if ( null === $session ) {
				return $errors;
			}
			$copy = self::prefill_copy( $session, time() );
			if ( ! Urumi_Agentic_Handoff_Trace::links_email( $email, self::copy_hashes( $copy ) ) || self::had_before( $copy, $email ) ) {
				return $errors;
			}
			$errors->add( self::LINK_EMAIL_ERROR, self::registration_message() );
			self::use_email( $email, $session, $copy, self::customer() );
			self::log( 'hand-off account_email_refused via=registration' );
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $errors;
	}

	/**
	 * D2, `woocommerce_store_api_checkout_update_customer_from_request` (fires on every supported
	 * version before the Store API's process_customer()): on a `POST .../checkout` that will create an
	 * account (as Checkout::should_create_customer_account() decides, will_create_account()), a
	 * billing email a link wrote is refused before Woo gets there. The marker is used up first
	 * (use_email(), which clears the email on the customer Woo is updating and saves it: Woo saves that
	 * customer only after this action, and not when it throws), then a RouteException
	 * (LINK_EMAIL_ERROR, 400) ends the request. No order is placed and no payment taken; on 8.2 to
	 * 10.x a `checkout-draft` order may already exist (normal Blocks behaviour). The message is the
	 * checkout's wording (link_email_message()). The Blocks client still holds the email, so placing
	 * the order again creates the account.
	 *
	 * Without it, WooCommerce 8.2's Blocks checkout swallows the registration refusal and places a
	 * guest order without a word; 10.0 and later turn it into the same 400. registration_errors()
	 * stays the backstop for every other path, and takes this one when the exception class is
	 * missing (then nothing here is done).
	 *
	 * The one exception to this class's "never throws": the RouteException is thrown on purpose,
	 * outside the guard. Anything else fails open to registration_errors().
	 *
	 * @param mixed $customer The session customer Woo is updating from the request.
	 * @param mixed $request  The WP_REST_Request.
	 * @return void
	 * @throws Exception The Store API's RouteException, to refuse the checkout once.
	 */
	public static function store_api_pre_check( $customer = null, $request = null ) {
		$refuse = false;
		try {
			if ( ! class_exists( self::ROUTE_EXCEPTION ) || ! is_object( $request ) || ! is_callable( array( $request, 'get_method' ) ) || ! is_callable( array( $request, 'get_param' ) ) ) {
				return;
			}
			$route = is_callable( array( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
			if ( 'POST' !== strtoupper( (string) $request->get_method() ) || 1 !== preg_match( '#/checkout/?\z#', $route ) || ! self::will_create_account( $request ) ) {
				return;
			}
			$billing = $request->get_param( 'billing_address' );
			$email   = is_array( $billing ) && is_string( $billing['email'] ?? null ) ? $billing['email'] : '';
			$session = self::session();
			if ( '' === $email || null === $session ) {
				return;
			}
			$copy = self::prefill_copy( $session, time() );
			if ( ! Urumi_Agentic_Handoff_Trace::links_email( $email, self::copy_hashes( $copy ) ) || self::had_before( $copy, $email ) ) {
				return;
			}
			$refuse = true;
			self::use_email( $email, $session, $copy, is_object( $customer ) ? $customer : self::customer() );
			self::log( 'hand-off account_email_refused via=store_api' );
		} catch ( Throwable $e ) {
			unset( $e );
		}
		if ( $refuse ) {
			// Outside the guard, on purpose (see above). The message is escaped, as Woo escapes its own.
			$class     = self::ROUTE_EXCEPTION;
			$exception = new $class( self::LINK_EMAIL_ERROR, esc_html( self::link_email_message() ), 400 );
			throw $exception;
		}
	}

	/**
	 * Whether the Store API checkout will create an account, exactly as WooCommerce's
	 * Checkout::should_create_customer_account() (8.2 to 11.2): not logged in, registration at
	 * checkout enabled, and either required or asked for with the request's `create_account`, each
	 * read with FILTER_VALIDATE_BOOLEAN (so `"false"` and `"0"` are no). False when the checks can't be
	 * read; the caller's guard takes a throw.
	 *
	 * @param object $request The WP_REST_Request.
	 * @return bool
	 */
	private static function will_create_account( $request ) {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return false;
		}
		$woocommerce = WC();
		$checkout    = is_object( $woocommerce ) && is_callable( array( $woocommerce, 'checkout' ) ) ? $woocommerce->checkout() : null;
		if ( ! is_object( $checkout ) || ! is_callable( array( $checkout, 'is_registration_enabled' ) ) || ! is_callable( array( $checkout, 'is_registration_required' ) ) ) {
			return false;
		}
		if ( false === filter_var( $checkout->is_registration_enabled(), FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}
		if ( true === filter_var( $checkout->is_registration_required(), FILTER_VALIDATE_BOOLEAN ) ) {
			return true;
		}
		return true === filter_var( $request->get_param( 'create_account' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Uses up a link's email after one refusal: its hash leaves the trace (record and `account` sets)
	 * and the live copy, the copy's `fields` and `before` lose it (and a guest's copy asks for the
	 * shopper's own email), and a guest session customer still holding it gets `''`, saved. Each part
	 * is on its own, so one that throws does not stop the others. Never throws.
	 *
	 * @param string      $email    The refused email.
	 * @param object      $session  Woo session.
	 * @param array|null  $copy     From prefill_copy().
	 * @param object|null $customer The session customer (or the one the Store API is updating).
	 * @return void
	 */
	private static function use_email( #[\SensitiveParameter] $email, $session, $copy, $customer ) {
		Urumi_Agentic_Handoff_Trace::forget_email( $email );
		try {
			if ( null !== $copy ) {
				self::drop_copy_email( $session, $copy, $email, true );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		try {
			$held = null === $customer ? null : self::read_field( $customer, 'billing_email' );
			if ( null !== $held && self::is_guest( $customer ) && self::same_email( $held, $email ) ) {
				self::restore( $customer, 0, array( 'billing_email' => '' ), true );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The registration refusal's message, worded for the form it is shown on. It never names the
	 * email.
	 *
	 * - The order confirmation's create-account form posts `create-account`. Its email can't be
	 *   changed there (it must be the order's), so the way on is to send the form again.
	 * - A checkout: the classic one fires `woocommerce_before_checkout_process` and
	 *   `woocommerce_checkout_process` in WC_Checkout::process_checkout(), before
	 *   process_customer(); the Store API one fires
	 *   `woocommerce_store_api_checkout_update_customer_from_request` before its process_customer().
	 *   Both hold on WooCommerce 8.2 to 11.2.
	 * - Anything else (My Account registration, which posts `register`, StockNotifications, another
	 *   plugin's form): a neutral wording.
	 *
	 * @return string
	 */
	private static function registration_message() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only to pick the wording; Woo checks the form's nonce.
		if ( isset( $_POST['create-account'] ) ) {
			return __( 'This email address came from your assistant\'s link. If it is yours, select Create account again.', 'urumi-agentic' );
		}
		if ( did_action( 'woocommerce_before_checkout_process' ) || did_action( 'woocommerce_checkout_process' ) || did_action( 'woocommerce_store_api_checkout_update_customer_from_request' ) ) {
			return self::link_email_message();
		}
		return __( 'This email address came from your assistant\'s link. If it is yours, submit the form again; otherwise enter your own email address.', 'urumi-agentic' );
	}

	/**
	 * The refusal's message on the checkouts (classic and Store API). It never names the email.
	 *
	 * @return string
	 */
	private static function link_email_message() {
		return __( 'This email address came from your assistant\'s link. If it is yours, place the order again; otherwise enter your own email address.', 'urumi-agentic' );
	}

	/**
	 * A copy's hashes, or none.
	 *
	 * @param array|null $copy From prefill_copy().
	 * @return array<string, string>
	 */
	private static function copy_hashes( $copy ) {
		return null !== $copy && is_array( $copy['hashes'] ?? null ) ? $copy['hashes'] : array();
	}

	/**
	 * Whether the live copy says the customer held this email just before the link wrote it: the
	 * shopper's own, not the link's.
	 *
	 * @param array|null $copy  From prefill_copy().
	 * @param string     $email An email.
	 * @return bool
	 */
	private static function had_before( $copy, #[\SensitiveParameter] $email ) {
		$before = null !== $copy ? ( $copy['before']['billing_email'] ?? null ) : null;
		return is_string( $before ) && '' !== trim( $before ) && self::same_email( $before, $email );
	}

	/**
	 * Whether two emails are the same, compared canonical (case and spacing do not matter).
	 *
	 * @param string $one   An email.
	 * @param string $other Another.
	 * @return bool
	 */
	private static function same_email( #[\SensitiveParameter] $one, #[\SensitiveParameter] $other ) {
		return is_string( $one ) && is_string( $other )
			&& Urumi_Agentic_Handoff_Trace::canonical( 'billing_email', $one ) === Urumi_Agentic_Handoff_Trace::canonical( 'billing_email', $other );
	}

	/**
	 * Takes an email out of the live copy and stores it: from `fields` and `before` when the link
	 * wrote that email, from `hashes` too when asked (the marker used up), and `own_email` set for a
	 * guest's copy, whose checkout then asks for the shopper's own email on every render.
	 *
	 * @param object $session Woo session.
	 * @param array  $copy    From prefill_copy().
	 * @param string $email   The email.
	 * @param bool   $hash    Whether to drop its hash as well.
	 * @return void
	 */
	private static function drop_copy_email( $session, array $copy, #[\SensitiveParameter] $email, $hash ) {
		if ( is_string( $copy['fields']['billing_email'] ?? null ) && self::same_email( $copy['fields']['billing_email'], $email ) ) {
			unset( $copy['fields']['billing_email'], $copy['before']['billing_email'] );
		}
		if ( $hash ) {
			$copy['hashes'] = Urumi_Agentic_Handoff_Trace::without_email( self::copy_hashes( $copy ), $email );
		}
		if ( 0 === $copy['customer_id'] ) {
			$copy['own_email'] = true;
		}
		$session->set( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY, $copy );
	}

	/**
	 * One line through WooCommerce's logger, codes only: never an email or a hash. Never throws.
	 *
	 * @param string $message The line.
	 * @return void
	 */
	private static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		try {
			wc_get_logger()->log( 'info', $message, array( 'source' => Urumi_Agentic_Handoff_Endpoint::LOG_SOURCE ) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Sets the fields on a customer and saves it. A customer built for the session (WC()->customer)
	 * saves to the Woo session only; Woo writes the account when an order is placed.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   From prefill_values().
	 * @return void
	 */
	public static function apply_prefill( $customer, #[\SensitiveParameter] array $fields ) {
		foreach ( $fields as $field => $value ) {
			if ( is_string( $field ) && 1 === preg_match( '/^(billing|shipping)_[a-z0-9_]+\z/', $field ) && is_string( $value ) && is_callable( array( $customer, 'set_' . $field ) ) ) {
				$customer->{'set_' . $field}( $value );
			}
		}
		// The shopper's agent supplied a delivery address: what Woo's own shipping calculator marks
		// after an address is entered (cart taxes then say nothing is "estimated").
		if ( isset( $fields['shipping_address_1'] ) && is_callable( array( $customer, 'set_calculated_shipping' ) ) ) {
			$customer->set_calculated_shipping( true );
		}
		$customer->save();
	}

	/**
	 * Applies the link's coupon. Returns the code to name in the notice when it could not be applied
	 * (never a refusal of the link, Q2), or null when there was nothing to apply or it applied.
	 *
	 * @param object $cart Woo cart.
	 * @param mixed  $code The link's coupon.
	 * @return string|null
	 */
	private static function apply_coupon( $cart, $code ) {
		$code = is_string( $code ) ? trim( $code ) : '';
		if ( '' === $code ) {
			return null;
		}
		// The code as the link carried it (escaped when printed), cut to a length a notice can show without
		// splitting a UTF-8 character.
		$shown = function_exists( 'mb_strcut' ) ? mb_strcut( $code, 0, self::MAX_COUPON_BYTES, 'UTF-8' ) : substr( $code, 0, self::MAX_COUPON_BYTES );
		if ( strlen( $code ) > self::MAX_COUPON_BYTES ) {
			return $shown;
		}
		try {
			$formatted = (string) wc_format_coupon_code( $code );
			if ( '' === $formatted ) {
				// Nothing left to apply (markup only, say): no code to name either.
				return null;
			}
			if ( $cart->apply_coupon( $formatted ) ) {
				return null;
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $shown;
	}

	/**
	 * Selects the link's rate in package 0 (Q8) when the calculated rates hold it, then recalculates.
	 * RATE_FAILED when a rate was asked for and could not be selected (Woo's default stays);
	 * RATE_NONE when none was asked for or the cart ships nothing.
	 *
	 * @param object $woocommerce WC().
	 * @param object $cart        Woo cart.
	 * @param object $session     Woo session.
	 * @param mixed  $rate_id     The link's `shipping_method`, e.g. `flat_rate:3`.
	 * @return string One of the RATE_* constants.
	 */
	private static function choose_rate( $woocommerce, $cart, $session, $rate_id ) {
		if ( ! is_string( $rate_id ) || '' === $rate_id ) {
			return self::RATE_NONE;
		}
		try {
			// A cart that ships nothing has no rate to choose, and nothing to say about it.
			if ( is_callable( array( $cart, 'needs_shipping' ) ) && ! $cart->needs_shipping() ) {
				return self::RATE_NONE;
			}
			$shipping = is_callable( array( $woocommerce, 'shipping' ) ) ? $woocommerce->shipping() : null;
			$packages = is_object( $shipping ) && is_callable( array( $shipping, 'get_packages' ) ) ? $shipping->get_packages() : array();
			$rates    = is_array( $packages ) && is_array( $packages[0]['rates'] ?? null ) ? $packages[0]['rates'] : array();
			if ( strlen( $rate_id ) > self::MAX_FIELD_BYTES || ! array_key_exists( $rate_id, $rates ) ) {
				return self::RATE_FAILED;
			}
			$chosen    = $session->get( 'chosen_shipping_methods' );
			$chosen    = is_array( $chosen ) ? $chosen : array();
			$chosen[0] = $rate_id;
			$session->set( 'chosen_shipping_methods', $chosen );
			$cart->calculate_totals();
		} catch ( Throwable $e ) {
			return self::RATE_FAILED;
		}
		return self::RATE_CHOSEN;
	}

	/**
	 * What the delivery-address notice shows, read back from the customer: name, town and
	 * postcode, country. Raw; the notice escapes it. The trace's ended-case line uses it too.
	 *
	 * @param object $customer WC_Customer.
	 * @param string $type     `shipping` or `billing`.
	 * @return string[]
	 */
	public static function delivery_summary( $customer, $type ) {
		$get     = static function ( $field ) use ( $customer, $type ) {
			return is_callable( array( $customer, 'get_' . $type . '_' . $field ) ) ? (string) $customer->{'get_' . $type . '_' . $field}() : '';
		};
		$country = $get( 'country' );
		$woo     = WC();
		$names   = is_object( $woo ) && is_object( $woo->countries ?? null ) ? $woo->countries->countries : array();
		$parts   = array(
			trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) ),
			trim( $get( 'city' ) . ' ' . $get( 'postcode' ) ),
			is_array( $names ) && isset( $names[ $country ] ) && is_string( $names[ $country ] ) ? html_entity_decode( $names[ $country ], ENT_QUOTES, 'UTF-8' ) : $country,
		);
		return array_values(
			array_filter(
				$parts,
				static function ( $part ) {
					return '' !== $part;
				}
			)
		);
	}

	/**
	 * Before Woo loads the session cart (and, after a login, merges the account's saved cart into
	 * it): the keys it holds now, so the re-apply can count what the merge added. Only while a
	 * hand-off pre-fill is in the session.
	 *
	 * @return void
	 */
	public static function remember_cart_keys() {
		self::$cart_keys_before_load = null;
		self::$rate_before_load      = null;
		try {
			$session = self::session();
			if ( null === $session || ! is_array( $session->get( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY ) ) ) {
				return;
			}
			$cart                        = $session->get( 'cart' );
			self::$cart_keys_before_load = is_array( $cart ) ? array_map( 'strval', array_keys( $cart ) ) : array();
			$chosen                      = $session->get( 'chosen_shipping_methods' );
			self::$rate_before_load      = is_array( $chosen ) && isset( $chosen[0] ) ? (string) $chosen[0] : '';
		} catch ( Throwable $e ) {
			self::$cart_keys_before_load = null;
			self::$rate_before_load      = null;
		}
	}

	/**
	 * Logged-in customer, WooCommerce 9.0 to 10.8: keeps the location fields the pre-fill cleared.
	 *
	 * The pre-fill writes a location as a unit, `""` included (Q5), to the session customer. On every
	 * later request Woo builds a session customer from the account's user meta, then lays the session
	 * copy over it in WC_Customer_Data_Store_Session::read(). From 9.0.0 through 10.8.1 that loop checks
	 * `! empty()`, so a `""` in the session is skipped and the account's saved `address_2` or `state`
	 * comes back under the link's street: a mixed address, which the order would ship to. 8.2 and 11.x
	 * check `isset()` and apply the `""`.
	 *
	 * This backports the `isset()` rule for the location fields a link cleared (cleared_fields()) on
	 * WC()->customer, which the Store API, the cart and the order read. A cleared billing state then
	 * gets Woo's default state, as 11.x's set_defaults() gives it. The classic checkout form builds a
	 * customer of its own for a logged-in shopper: checkout_value() covers it. Nothing is saved: Woo
	 * saves the session customer at shutdown, and the session already holds `""`. Never throws.
	 *
	 * @return void
	 */
	public static function keep_cleared_location() {
		try {
			$customer      = self::customer();
			$billing_state = false;
			foreach ( self::cleared_fields() as $field ) {
				if ( '' === (string) $customer->{'get_' . $field}() ) {
					continue;
				}
				$customer->{'set_' . $field}( '' );
				$billing_state = $billing_state || 'billing_state' === $field;
			}
			if ( $billing_state && '' === (string) $customer->get_billing_state() && function_exists( 'wc_get_customer_default_location' ) ) {
				$default = wc_get_customer_default_location();
				if ( is_array( $default ) && is_string( $default['state'] ?? null ) && '' !== $default['state'] ) {
					$customer->set_billing_state( $default['state'] );
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Classic checkout form, logged-in shopper (`woocommerce_checkout_get_value`): WC_Checkout::get_value()
	 * reads a customer it builds itself (`new WC_Customer( $id, true )`, the same 9.0-10.8 read() as
	 * above), so a field keep_cleared_location() cleared on WC()->customer is given from WC()->customer.
	 * Any other field, and a value another filter already gave, is passed through. Never throws.
	 *
	 * @param mixed $value Null, or a value a filter before this one gave.
	 * @param mixed $input The field name.
	 * @return mixed
	 */
	public static function checkout_value( $value, $input ) {
		if ( null !== $value || ! is_string( $input ) ) {
			return $value;
		}
		try {
			if ( in_array( $input, self::cleared_fields(), true ) ) {
				return (string) self::customer()->{'get_' . $input}();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $value;
	}

	/**
	 * The location fields a link wrote as `""` that Woo 9.0-10.8 may have refilled from the account, as
	 * WC_Customer field names. Empty unless all of these hold:
	 * - WC()->customer is a logged-in customer (id above 0), and the session's marker
	 *   (CLEARED_SESSION_KEY) was written for this customer and names the field;
	 * - the session's `customer` has this `id` and the account's `date_modified` (read()'s own test for
	 *   applying the session at all; an order or a My Account edit changes `date_modified`);
	 * - the session still holds `""` for the field (a value the shopper typed since wins);
	 * - the customer has a getter and a setter for it.
	 *
	 * Not tied to the hint or the pre-fill copy: the `""` can stay in the session after they go (an
	 * order placed with it, a location the shopper partly changed and so kept, or a `""` put back by
	 * end_copy() where the customer showed `""` before the link), and so would the mixed address.
	 *
	 * @return string[]
	 */
	private static function cleared_fields() {
		$session  = self::session();
		$customer = self::customer();
		if ( null === $session || null === $customer || ! is_callable( array( $customer, 'get_id' ) ) ) {
			return array();
		}
		$id = $customer->get_id();
		if ( ! is_int( $id ) || $id <= 0 ) {
			return array();
		}
		$marker = self::cleared_marker( $session );
		if ( null === $marker || $id !== $marker['customer_id'] ) {
			return array();
		}
		$data = $session->get( 'customer' );
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['date_modified'] ) || (string) $id !== $data['id']
			|| ! is_callable( array( $customer, 'get_date_modified' ) ) || (string) $customer->get_date_modified( 'edit' ) !== $data['date_modified'] ) {
			return array();
		}
		$fields = array();
		foreach ( $marker['fields'] as $field ) {
			// The session keeps billing fields without their prefix (Woo's $session_keys).
			$key = 0 === strpos( $field, 'billing_' ) ? substr( $field, 8 ) : $field;
			if ( array_key_exists( $key, $data ) && '' === $data[ $key ]
				&& is_callable( array( $customer, 'get_' . $field ) ) && is_callable( array( $customer, 'set_' . $field ) ) ) {
				$fields[] = $field;
			}
		}
		return $fields;
	}

	/**
	 * Records, for a logged-in customer, the location fields these fields write as `""` (before they
	 * are written). A type (billing, shipping) whose location is written replaces what was recorded
	 * for it; a type it does not write keeps it, since the earlier `""` is still in the session. A
	 * guest records nothing (Woo builds no account customer for id 0); the login re-apply records for
	 * the account. Never throws.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $fields   What is about to be written.
	 * @return void
	 */
	private static function remember_cleared( $customer, array $fields ) {
		try {
			$session = self::session();
			$id      = is_callable( array( $customer, 'get_id' ) ) ? $customer->get_id() : null;
			if ( null === $session || ! is_int( $id ) || $id <= 0 ) {
				return;
			}
			$old  = self::cleared_marker( $session );
			$keep = array();
			if ( null !== $old && $id === $old['customer_id'] ) {
				foreach ( $old['fields'] as $field ) {
					if ( ! isset( $fields[ strstr( $field, '_', true ) . '_address_1' ] ) ) {
						$keep[] = $field;
					}
				}
			}
			foreach ( $fields as $field => $value ) {
				if ( '' === $value && self::is_location_field( $field ) ) {
					$keep[] = $field;
				}
			}
			$session->set(
				self::CLEARED_SESSION_KEY,
				$keep ? array(
					'v'           => 1,
					'customer_id' => $id,
					'fields'      => array_values( array_unique( $keep ) ),
				) : null
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The session's cleared-fields marker, or null when there is none or it is corrupt.
	 *
	 * @param object $session Woo session.
	 * @return array{v: int, customer_id: int, fields: string[]}|null
	 */
	private static function cleared_marker( $session ) {
		$marker = $session->get( self::CLEARED_SESSION_KEY );
		if ( ! is_array( $marker ) || 1 !== ( $marker['v'] ?? null ) || ! is_int( $marker['customer_id'] ?? null ) || ! is_array( $marker['fields'] ?? null ) ) {
			return null;
		}
		foreach ( $marker['fields'] as $field ) {
			if ( ! self::is_location_field( $field ) ) {
				return null;
			}
		}
		return $marker;
	}

	/**
	 * Whether a name is a billing or shipping location field (LOCATION_FIELDS).
	 *
	 * @param mixed $field Field name.
	 * @return bool
	 */
	private static function is_location_field( $field ) {
		return is_string( $field ) && 1 === preg_match( '/^(billing|shipping)_(' . implode( '|', self::LOCATION_FIELDS ) . ')\z/', $field );
	}

	/**
	 * Guest -> login (Q6): Woo keeps the guest session but shows the account's saved address,
	 * because the session customer was saved for customer id 0. While the hint is valid, the
	 * pre-fill is applied again, once, for the customer now in the session. Woo's merge of the
	 * account's saved cart is kept as Woo does it; the notice says how many lines it added.
	 *
	 * @param int|null $now Unix seconds (tests).
	 * @return void
	 */
	public static function reapply_after_login( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		try {
			$session  = self::session();
			$customer = self::customer();
			if ( null === $session || null === $customer ) {
				return;
			}
			if ( null === $session->get( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY ) ) {
				return;
			}
			$copy  = self::prefill_copy( $session, $now );
			$bound = null !== $copy && $copy['hint'];
			if ( null === $copy ) {
				// Expired or corrupt: it ends now, not when Woo's session cleanup gets to it, and an expired
				// copy puts the customer back (end_copy()).
				self::end_copy( self::END_EXPIRED );
				return;
			}
			if ( $bound && null === ( new Urumi_Agentic_Handoff_Hint() )->get( $now ) ) {
				self::end_copy( self::END_HINT_GONE );
				return;
			}
			$id = (int) $customer->get_id();
			if ( ! $bound || $id === $copy['customer_id'] ) {
				return;
			}
			// What this account gets (it keeps its own email and phone), worked out before the copy is
			// marked as applied for it. The copy then holds only what was written (T18), and, to put back
			// when it ends, what this account showed just before (not what the guest had).
			$fields              = self::fields_for_customer( $customer, $copy['fields'] );
			$copy['customer_id'] = $id;
			$copy['fields']      = $fields;
			$copy['before']      = self::read_fields( $customer, array_keys( $fields ) );
			// The account has its own email in the form now: no own-email line (R1).
			$copy['own_email'] = false;
			$session->set( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY, $copy );
			$woocommerce = WC();
			$cart        = $woocommerce->cart ?? null;
			// T1b: the account's address and name are replaced by the link's. The checkout says so on every
			// render while the copy lives (live_lines()), naming only what was written for the account.
			if ( $fields ) {
				self::remember_cleared( $customer, $fields );
				self::apply_prefill( $customer, $fields );
				// The link writes again: an earlier link's trace goes (all but its hashes, kept
				// account-only), and the copy's hashes cover what the account now holds. The guest's
				// stay for what the account kept (its own email and phone), so the link's email a guest tab
				// still open posts over the account's after the end is named.
				// Not for a location: the account's write covers every location field the guest's did, and
				// a location's recorded fields must all come from one write.
				Urumi_Agentic_Handoff_Trace::clear();
				$copy['hashes'] = Urumi_Agentic_Handoff_Trace::hashes_of( $customer, $fields ) + array_intersect_key( $copy['hashes'] ?? array(), array_flip( Urumi_Agentic_Handoff_Trace::FIELDS ) );
				$session->set( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY, $copy );
			}

			if ( null !== self::$cart_keys_before_load && is_object( $cart ) && is_callable( array( $cart, 'get_cart_contents' ) ) ) {
				$keys   = array_map( 'strval', array_keys( (array) $cart->get_cart_contents() ) );
				$merged = count( array_diff( $keys, self::$cart_keys_before_load ) );
				if ( $merged > 0 ) {
					Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_LOGIN_MERGE, array( (string) $merged ), $now );
				}
			}

			// The account may be in another zone. Woo migrated the guest session, so the rate chosen before this
			// request's cart load is the shopper's last choice: the link's, or one they picked since. It is read
			// from before the load, because the login's saved-cart merge recalculates for the account's address,
			// and a rate that zone does not offer is reset to its default. Calculate for the address now in the
			// session; if Woo then dropped that choice, choose it again where package 0 still offers it. Only the
			// link's rate is named when it cannot be (a rate the shopper picked was never announced). This
			// deliberately restores the shopper's own choice even when the merge unlocked another rate (free
			// shipping, say): the checkout lists every option offered.
			$chosen = $session->get( 'chosen_shipping_methods' );
			$before = null !== self::$rate_before_load ? self::$rate_before_load : ( is_array( $chosen ) && isset( $chosen[0] ) ? (string) $chosen[0] : '' );
			if ( '' !== $before && is_object( $cart ) && is_callable( array( $cart, 'calculate_totals' ) ) ) {
				$cart->calculate_totals();
				$chosen = $session->get( 'chosen_shipping_methods' );
				$after  = is_array( $chosen ) && isset( $chosen[0] ) ? (string) $chosen[0] : '';
				if ( $after !== $before ) {
					$restored = self::choose_rate( $woocommerce, $cart, $session, $before );
					if ( self::RATE_FAILED === $restored && $before === $copy['rate'] ) {
						Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_SHIPPING, array(), $now );
					}
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Classic checkout: "Ship to a different address?" starts checked while this session's pre-fill
	 * wrote a shipping address whose names or location differ from billing. Unchecked, Woo would copy
	 * billing over shipping and lose the recipient (a gift).
	 *
	 * @param mixed $checked Woo's default (from the store's shipping destination setting).
	 * @return mixed
	 */
	public static function ship_to_different_address( $checked ) {
		try {
			$session  = self::session();
			$customer = self::customer();
			if ( null === $session || null === $customer ) {
				return $checked;
			}
			$copy = self::prefill_copy( $session, time() );
			if ( null === $copy || ! isset( $copy['fields']['shipping_address_1'] ) ) {
				return $checked;
			}
			foreach ( self::COMPARED_FIELDS as $field ) {
				$billing  = is_callable( array( $customer, 'get_billing_' . $field ) ) ? (string) $customer->{'get_billing_' . $field}() : '';
				$shipping = is_callable( array( $customer, 'get_shipping_' . $field ) ) ? (string) $customer->{'get_shipping_' . $field}() : '';
				if ( $billing !== $shipping ) {
					return 1;
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $checked;
	}

	/**
	 * R2 (pl7-plan.md section 5.2): an order does not save the link's names and addresses to a
	 * logged-in customer's account. Called with the account customer Woo is about to save (read from
	 * the account, not WC()->customer), after Woo set the order's values on it.
	 *
	 * Which values are the link's is the trace's matching rule (Urumi_Agentic_Handoff_Trace::
	 * links_fields(): a name field by field, a location whole, either location, canonical), against
	 * the copy's hashes while it is there and the trace record after it ended, an order's end
	 * included (a declined card, then the retry). Those fields are set back to what the account held
	 * before this checkout (WC_Data::get_data(), which setters do not change), and so are the profile
	 * first and last name Woo fills from a billing name that is the link's, with the display name
	 * built from them. Everything else saves as Woo saves it: a name or a location the shopper typed,
	 * company, email, phone. The order keeps the address it was placed with, and the session customer
	 * the link's values.
	 *
	 * Not for a guest, nor for an account Woo created in this request (it has nothing saved; Woo
	 * saves the order's details there). Never throws: on a failure the account is saved as Woo would.
	 *
	 * @param mixed $customer The account's WC_Customer.
	 * @return void
	 */
	public static function keep_account_address( $customer ) {
		try {
			if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'get_id' ) ) || ! is_callable( array( $customer, 'get_data' ) ) ) {
				return;
			}
			$id = $customer->get_id();
			if ( ! is_int( $id ) || $id <= 0 || isset( self::$created[ $id ] ) ) {
				return;
			}
			$session = self::session();
			$copy    = null === $session ? null : self::copy_of( $session->get( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY ) );
			$fields  = Urumi_Agentic_Handoff_Trace::links_fields( $customer, null !== $copy && is_array( $copy['hashes'] ?? null ) ? $copy['hashes'] : array() );
			if ( ! $fields ) {
				return;
			}
			$saved = $customer->get_data();
			if ( ! is_array( $saved ) ) {
				return;
			}
			$props = array();
			foreach ( $fields as $field ) {
				$parts   = explode( '_', $field, 2 );
				$address = is_array( $saved[ $parts[0] ] ?? null ) ? $saved[ $parts[0] ] : array();
				if ( is_string( $address[ $parts[1] ] ?? null ) ) {
					$props[ $field ] = $address[ $parts[1] ];
				}
			}
			// Classic fills an empty profile name from the billing name, and a display name that is an
			// email from the profile name (11.2.1 `class-wc-checkout.php:1294-1304`).
			foreach ( array( 'first_name', 'last_name' ) as $own ) {
				if ( in_array( 'billing_' . $own, $fields, true ) ) {
					foreach ( array( $own, 'display_name' ) as $profile ) {
						if ( is_string( $saved[ $profile ] ?? null ) ) {
							$props[ $profile ] = $saved[ $profile ];
						}
					}
				}
			}
			foreach ( $props as $field => $value ) {
				if ( is_callable( array( $customer, 'get_' . $field ) ) && is_callable( array( $customer, 'set_' . $field ) )
					&& $value !== $customer->{'get_' . $field}( 'edit' ) ) {
					$customer->{'set_' . $field}( $value );
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Store API checkout (`woocommerce_store_api_checkout_update_customer_from_request`, the checkout
	 * and the pay-for-order routes, 8.2 to 11.2): from here on in this request, an account customer
	 * the Store API saves goes through keep_account_address_on_save(). The action fires before the
	 * order's addresses are copied to the account (OrderController::sync_customer_data_with_order(),
	 * which has no filter of its own).
	 *
	 * @return void
	 */
	public static function start_store_api_checkout() {
		self::$store_api_checkout = true;
	}

	/**
	 * Before Woo saves any customer (`woocommerce_before_customer_object_save`, WC_Data::save()): during
	 * a Store API checkout, an account customer (not WC()->customer, not one saved to the session) goes
	 * through keep_account_address(). Never throws.
	 *
	 * @param mixed $customer   WC_Customer being saved.
	 * @param mixed $data_store Its WC_Data_Store.
	 * @return void
	 */
	public static function keep_account_address_on_save( $customer, $data_store = null ) {
		if ( ! self::$store_api_checkout ) {
			return;
		}
		try {
			if ( ! is_object( $customer ) || self::customer() === $customer ) {
				return;
			}
			if ( is_object( $data_store ) && is_callable( array( $data_store, 'get_current_class_name' ) )
				&& false !== stripos( (string) $data_store->get_current_class_name(), 'session' ) ) {
				return;
			}
			self::keep_account_address( $customer );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * `woocommerce_created_customer`: Woo created this account in this request (a checkout that
	 * registers the shopper). keep_account_address() leaves its save to Woo.
	 *
	 * @param mixed $customer_id The new user's id.
	 * @return void
	 */
	public static function created_customer( $customer_id ) {
		if ( is_numeric( $customer_id ) && (int) $customer_id > 0 ) {
			self::$created[ (int) $customer_id ] = true;
		}
	}

	/**
	 * The session's pre-fill copy, or null when there is none, it is corrupt or it has expired.
	 *
	 * @param object $session Woo session.
	 * @param int    $now     Unix seconds.
	 * @return array{v: int, fields: array<string, string>, before: array<string, string>, customer_id: int, expires_at: int, hint: bool, rate: string, own_email?: bool}|null
	 */
	private static function prefill_copy( $session, $now ) {
		$copy = self::copy_of( $session->get( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY ) );
		if ( null === $copy || $now >= $copy['expires_at'] || $copy['expires_at'] > $now + Urumi_Agentic_Handoff_Hint::TTL ) {
			return null;
		}
		return $copy;
	}

	/**
	 * A stored pre-fill copy if it has this version's shape, expired or not; null otherwise.
	 *
	 * @param mixed $copy What the session holds.
	 * @return array{v: int, fields: array<string, string>, before: array<string, string>, customer_id: int, expires_at: int, hint: bool, rate: string, own_email?: bool}|null
	 */
	private static function copy_of( $copy ) {
		if ( ! is_array( $copy ) || self::PREFILL_VERSION !== ( $copy['v'] ?? null ) || ! is_array( $copy['fields'] ?? null ) || ! is_array( $copy['before'] ?? null )
			|| ! is_int( $copy['customer_id'] ?? null ) || ! is_int( $copy['expires_at'] ?? null )
			|| ! is_bool( $copy['hint'] ?? null ) || ! is_string( $copy['rate'] ?? null )
			|| ( isset( $copy['hashes'] ) && ! is_array( $copy['hashes'] ) ) || ( isset( $copy['opened'] ) && ! is_int( $copy['opened'] ) )
			|| ( isset( $copy['own_email'] ) && ! is_bool( $copy['own_email'] ) ) ) {
			return null;
		}
		return $copy;
	}

	/**
	 * Ends the hand-off's pre-fill copy; every end goes through here (the hint forgotten or used up,
	 * the cart emptied or its last line removed, a new link, expiry, an order placed). The copy is
	 * deleted first. Then, unless an order used it (Urumi_Agentic_Handoff_Hint::END_ORDER_PLACED), the
	 * trace is written (Urumi_Agentic_Handoff_Trace: hashes only, for a checkout tab left open across
	 * this end) and the customer is put back (put_back()): what the link wrote and the shopper has not
	 * changed since returns to its value from just before the link. Without that, a week-long
	 * logged-in session (48 hours for a guest) would pre-fill a later, ordinary checkout with the
	 * link's address, with no line saying so. An order placed makes the trace `ordered` instead
	 * (Urumi_Agentic_Handoff_Trace::order_placed(), from the copy when there is one): nothing is put
	 * back or named, and the account save still knows the link's values (keep_account_address()).
	 *
	 * A copy of another version or shape is just deleted (it has no `before`). Hooked on
	 * Urumi_Agentic_Handoff_Hint::FORGET_ACTION. Never throws (it runs on visitor requests).
	 *
	 * @param mixed $reason One of Urumi_Agentic_Handoff_Hint's END_* or this class's END_* constants.
	 * @return void
	 */
	public static function end_copy( $reason = Urumi_Agentic_Handoff_Hint::END_FORGOTTEN ) {
		try {
			$session = self::session();
			if ( null === $session ) {
				return;
			}
			$stored = $session->get( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY );
			$copy   = null === $stored ? null : self::copy_of( $stored );
			if ( Urumi_Agentic_Handoff_Hint::END_ORDER_PLACED === $reason ) {
				// The order used the details: nothing the link wrote is put back again later either. A
				// declined card leaves the cart, and its retry saves the customer again (R2). Never throws.
				Urumi_Agentic_Handoff_Trace::order_placed( $copy );
			}
			if ( null === $stored ) {
				return;
			}
			$session->set( Urumi_Agentic_Handoff_Hint::PREFILL_SESSION_KEY, null );
			if ( Urumi_Agentic_Handoff_Hint::END_ORDER_PLACED !== $reason && null !== $copy ) {
				// A checkout tab left open across this end may post the link's values back: the trace
				// names them on the next checkout render or puts them back off the checkout
				// (Urumi_Agentic_Handoff_Trace). Written first: it never throws.
				Urumi_Agentic_Handoff_Trace::write( $copy['customer_id'], $copy['hashes'] ?? array(), $copy['before'], $copy['opened'] ?? time() );
				self::put_back( $copy );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * `woocommerce_cart_item_removed`: removing the cart's last line (the cart page's remove link, a
	 * quantity of 0, the Store API's `DELETE /cart/items/{key}`, all through WC_Cart::remove_cart_item(),
	 * which empty_cart() does not call) ends the hand-off as emptying the cart does: the hint goes and the
	 * copy ends (Hint::forget(), END_CART_EMPTIED). Never throws.
	 *
	 * @param mixed $key  The removed line's key.
	 * @param mixed $cart The cart.
	 * @return void
	 */
	public static function item_removed( $key = null, $cart = null ) {
		unset( $key );
		try {
			if ( is_object( $cart ) && is_callable( array( $cart, 'get_cart_contents' ) ) && ! $cart->get_cart_contents() ) {
				( new Urumi_Agentic_Handoff_Hint() )->forget( Urumi_Agentic_Handoff_Hint::END_CART_EMPTIED );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A checkout order was placed (`woocommerce_checkout_order_processed`, or the Store API's
	 * `woocommerce_store_api_checkout_order_processed`), tagged or not: it used the pre-fill, so the
	 * copy ends without putting anything back. Both fire before payment, and before Woo empties the cart.
	 *
	 * @return void
	 */
	public static function order_placed() {
		self::end_copy( Urumi_Agentic_Handoff_Hint::END_ORDER_PLACED );
	}

	/**
	 * Puts back what a copy wrote, for the customer it was written to (any other customer is left
	 * alone: Woo does not show it that session's fields). A field goes back to its `before` value only
	 * while it still holds what the link wrote; a field the shopper changed since stays. Names, email
	 * and phones go field by field. A location (billing or shipping) goes back as a unit, as it was
	 * written (Q5): only when every location field the link wrote for it is unchanged, so a street the
	 * shopper typed is never mixed with the old `address_2` or `state`.
	 *
	 * The cleared-location marker follows (put_back_cleared()), and the customer is saved (to the Woo
	 * session) when anything changed.
	 *
	 * @param array $copy From copy_of().
	 * @return void
	 */
	private static function put_back( #[\SensitiveParameter] array $copy ) {
		$customer = self::customer();
		$id       = null !== $customer && is_callable( array( $customer, 'get_id' ) ) ? $customer->get_id() : null;
		if ( ! is_int( $id ) || $id !== $copy['customer_id'] ) {
			return;
		}
		// Woo 9.0-10.8: a field the link cleared reads as the account's again until this runs.
		self::keep_cleared_location();
		$fields = $copy['fields'];
		$names  = array();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$location = array();
			foreach ( self::LOCATION_FIELDS as $name ) {
				if ( isset( $fields[ $type . '_' . $name ] ) ) {
					$location[] = $type . '_' . $name;
				}
			}
			foreach ( $location as $field ) {
				if ( ! self::still_written( $customer, $fields, $field ) && ! self::defaulted_state( $customer, $fields, $field ) ) {
					continue 2;
				}
			}
			$names = array_merge( $names, $location );
		}
		foreach ( array_keys( $fields ) as $field ) {
			if ( ! self::is_location_field( $field ) && self::still_written( $customer, $fields, $field ) ) {
				$names[] = $field;
			}
		}
		$values = array();
		foreach ( $names as $field ) {
			if ( isset( $copy['before'][ $field ] ) ) {
				$values[ $field ] = $copy['before'][ $field ];
			}
		}
		self::restore( $customer, $id, $values, true );
	}

	/**
	 * Sets fields back on the session customer (put_back(), and Urumi_Agentic_Handoff_Trace when the
	 * link's values are in the session customer again after the copy ended). A field without a
	 * setter, or whose setter throws, is
	 * skipped. For a logged-in customer the cleared-location marker follows (put_back_cleared()). Saves
	 * when asked and anything was set.
	 *
	 * @param object                $customer WC_Customer.
	 * @param int                   $id       Its id (0 for a guest).
	 * @param array<string, string> $values   Field => value.
	 * @param bool                  $save     Whether to save the customer.
	 * @return void
	 */
	public static function restore( $customer, $id, #[\SensitiveParameter] array $values, $save ) {
		$restored = array();
		foreach ( $values as $field => $value ) {
			if ( ! is_string( $field ) || ! is_string( $value ) || 1 !== preg_match( '/^(billing|shipping)_[a-z0-9_]+\z/', $field ) || ! is_callable( array( $customer, 'set_' . $field ) ) ) {
				continue;
			}
			try {
				$customer->{'set_' . $field}( $value );
				$restored[ $field ] = $value;
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
		if ( ! $restored ) {
			return;
		}
		if ( is_int( $id ) && $id > 0 ) {
			self::put_back_cleared( $id, $restored );
		}
		if ( $save ) {
			$customer->save();
		}
	}

	/**
	 * After put_back() for a logged-in customer: the cleared-location marker names exactly the restored
	 * location fields that are `""` again, besides what it named for fields not restored. A field that
	 * got a value back leaves it (Woo shows that value); one that is `""` again keeps or joins it, so
	 * on Woo 9.0-10.8 the account's saved value does not come back where the customer showed `""`
	 * before the link (keep_cleared_location() and checkout_value() then hold it, as before the link).
	 * Never throws.
	 *
	 * @param int                   $id       The customer id.
	 * @param array<string, string> $restored Field => the value put back.
	 * @return void
	 */
	private static function put_back_cleared( $id, array $restored ) {
		try {
			$session = self::session();
			if ( null === $session || $id <= 0 ) {
				return;
			}
			$marker = self::cleared_marker( $session );
			$keep   = null !== $marker && $id === $marker['customer_id'] ? $marker['fields'] : array();
			foreach ( $restored as $field => $value ) {
				if ( ! self::is_location_field( $field ) ) {
					continue;
				}
				$keep = array_values( array_diff( $keep, array( $field ) ) );
				if ( '' === $value ) {
					$keep[] = $field;
				}
			}
			$session->set(
				self::CLEARED_SESSION_KEY,
				$keep ? array(
					'v'           => 1,
					'customer_id' => $id,
					'fields'      => array_values( array_unique( $keep ) ),
				) : null
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A location to write, or null when the address has no `address_1` or a field is not usable.
	 *
	 * @param array $address The link's address.
	 * @return array<string, string>|null
	 */
	private static function location( array $address ) {
		$location = array();
		foreach ( self::LOCATION_FIELDS as $name ) {
			$value = self::clean( $address[ $name ] ?? '' );
			if ( null === $value ) {
				return null;
			}
			$location[ $name ] = $value;
		}
		if ( '' === $location['address_1'] ) {
			return null;
		}
		$woo       = WC();
		$countries = is_object( $woo ) ? ( $woo->countries ?? null ) : null;
		if ( '' === $location['country'] || ! is_object( $countries ) || ! $countries->country_exists( $location['country'] ) ) {
			return null;
		}
		return $location;
	}

	/**
	 * One link value cleaned for Woo: wc_clean()'d, or null when it is not a string or is longer
	 * than MAX_FIELD_BYTES.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function clean( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_FIELD_BYTES ) {
			return null;
		}
		return (string) wc_clean( $value );
	}

	/**
	 * WC()->session, or null.
	 *
	 * @return object|null
	 */
	private static function session() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		return is_object( $session ) ? $session : null;
	}

	/**
	 * WC()->customer, or null.
	 *
	 * @return object|null
	 */
	private static function customer() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$customer    = is_object( $woocommerce ) ? ( $woocommerce->customer ?? null ) : null;
		return is_object( $customer ) ? $customer : null;
	}

	/**
	 * Keys of the cart's items (cart item key => item), or null when the cart can't be read. Never throws.
	 *
	 * @param mixed $cart WC()->cart.
	 * @return string[]|null
	 */
	private static function cart_keys( $cart ) {
		try {
			if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_cart_contents' ) ) ) {
				return null;
			}
			$contents = $cart->get_cart_contents();
			return is_array( $contents ) ? array_map( 'strval', array_keys( $contents ) ) : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether Subscriptions' one-subscription and no-mixed-cart rules apply: the plugin is loaded and its
	 * "multiple purchase" option is not `yes` (WC_Subscriptions_Cart_Validator::maybe_empty_cart()).
	 * False when anything here throws. Never throws.
	 *
	 * @return bool
	 */
	private static function subscription_rules() {
		try {
			if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! is_callable( array( 'WC_Subscriptions_Product', 'is_subscription' ) ) ) {
				return false;
			}
			$prefix = class_exists( 'WC_Subscriptions_Admin' ) && isset( WC_Subscriptions_Admin::$option_prefix ) && is_string( WC_Subscriptions_Admin::$option_prefix )
				? WC_Subscriptions_Admin::$option_prefix
				: 'woocommerce_subscriptions';
			return 'yes' !== get_option( $prefix . '_multiple_purchase', 'no' );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Gives each earlier added line whose cart key this line's step removed (it was in `$before`, it is
	 * not in `$after`) the reason for this step, unless it has one already. The step is the line's
	 * validation filter and its add_to_cart(), so the removal can come from either. With either read
	 * missing nothing is recorded, and reconcile() still finds the removal (GENERIC). Never throws.
	 *
	 * @param string[]|null $before     Cart keys before the line (cart_keys()).
	 * @param string[]|null $after      Cart keys after it.
	 * @param int           $index      The line's index.
	 * @param int           $product_id The line's product id (the parent, as Subscriptions checks it).
	 * @param array         $results    Added lines so far, by index (add_line() results).
	 * @param array         $reasons    Reasons so far, by index; updated.
	 * @param bool          $rules      subscription_rules().
	 * @return void
	 */
	private static function record_reasons( $before, $after, $index, $product_id, array $results, array &$reasons, $rules ) {
		if ( null === $before || null === $after ) {
			return;
		}
		$gone = array_diff( $before, $after );
		if ( ! $gone ) {
			return;
		}
		foreach ( $results as $earlier => $result ) {
			if ( $earlier < $index && ! isset( $reasons[ $earlier ] ) && '' !== $result['key'] && in_array( $result['key'], $gone, true ) ) {
				$reasons[ $earlier ] = self::cause( $product_id, $result['product_id'], $rules );
			}
		}
	}

	/**
	 * Why adding a line took an earlier one out, as far as we can tell: with Subscriptions' rules on, a
	 * subscription and a product of the other kind is MIXED and two subscriptions is ONE_SUBSCRIPTION.
	 * Anything else is GENERIC: no Subscriptions, two plain products (another plugin), or the multiple
	 * purchase option `yes`. That option can still remove S1 when S2 is added (the payment-gateway
	 * branch, when no gateway supports several subscriptions), which we do not name: GENERIC. Never throws.
	 *
	 * @param int  $adding  Product id of the line being added.
	 * @param int  $earlier Product id of the line it removed.
	 * @param bool $rules   subscription_rules().
	 * @return string One of the REMOVED_* reasons.
	 */
	private static function cause( $adding, $earlier, $rules ) {
		if ( $rules ) {
			try {
				$adding_sub  = (bool) WC_Subscriptions_Product::is_subscription( $adding );
				$earlier_sub = (bool) WC_Subscriptions_Product::is_subscription( $earlier );
				if ( $adding_sub !== $earlier_sub ) {
					return self::REMOVED_MIXED;
				}
				if ( $adding_sub ) {
					return self::REMOVED_ONE_SUBSCRIPTION;
				}
			} catch ( Throwable $e ) {
				return self::REMOVED_GENERIC;
			}
		}
		return self::REMOVED_GENERIC;
	}

	/**
	 * Step 3b: what the lines left in the cart. A line is in the cart when its key is, or when any item
	 * has its product and variation (with a quantity above 0): a plugin can take an item out and add it
	 * again under a new key, and the hint matches by ids anyway. Lines that share ids share presence, so
	 * a removed line's product leaves `added` only when no line with its ids is in the cart. A line that
	 * is not is `removed`, with the reason recorded at its removal step or GENERIC.
	 *
	 * `reduced` is for the log only: per product in the cart, what its added lines asked for minus what
	 * the cart holds, when positive. Woo's clamp of a product sold individually to 1 is expected, so it
	 * does not count. Lines removed and added again count both: [A,S,A] (A x1 each) gives reduced=1.
	 *
	 * When the cart can't be read the add results stand as they were (nothing removed, nothing
	 * reduced): a wrong "removed" would hide a line that is in the cart from the hint. Never throws.
	 *
	 * @param mixed $cart    WC()->cart.
	 * @param array $results Added lines by index: product_id, variation_id, key, quantity.
	 * @param array $reasons Removal reasons by index.
	 * @return array{added: array, removed: array<int, string>, reduced: int}
	 */
	private static function reconcile( $cart, array $results, array $reasons ) {
		$as_added = array();
		foreach ( $results as $result ) {
			$as_added[ $result['product_id'] . ':' . $result['variation_id'] ] = array(
				'product_id'   => $result['product_id'],
				'variation_id' => $result['variation_id'],
			);
		}
		$unread = array(
			'added'   => $as_added,
			'removed' => array(),
			'reduced' => 0,
		);
		try {
			$contents = is_object( $cart ) && is_callable( array( $cart, 'get_cart_contents' ) ) ? $cart->get_cart_contents() : null;
			if ( ! is_array( $contents ) ) {
				return $unread;
			}
			$keys    = array();
			$in_cart = array();
			foreach ( $contents as $key => $item ) {
				$quantity = is_array( $item ) ? (int) ( $item['quantity'] ?? 0 ) : 0;
				if ( $quantity <= 0 ) {
					continue;
				}
				$keys[ (string) $key ] = true;
				$ids                   = (int) ( $item['product_id'] ?? 0 ) . ':' . (int) ( $item['variation_id'] ?? 0 );
				$in_cart[ $ids ]       = ( $in_cart[ $ids ] ?? 0 ) + $quantity;
			}
			$added   = array();
			$removed = array();
			$asked   = array();
			foreach ( $results as $index => $result ) {
				$ids = $result['product_id'] . ':' . $result['variation_id'];
				if ( ( '' !== $result['key'] && isset( $keys[ $result['key'] ] ) ) || isset( $in_cart[ $ids ] ) ) {
					$added[ $ids ] = $as_added[ $ids ];
					$asked[ $ids ] = ( $asked[ $ids ] ?? 0 ) + $result['quantity'];
				} else {
					$removed[ $index ] = $reasons[ $index ] ?? self::REMOVED_GENERIC;
				}
			}
			$reduced = 0;
			foreach ( $asked as $ids => $quantity ) {
				$held = $in_cart[ $ids ] ?? 0;
				if ( $held > 0 && $quantity > $held && ! ( 1 === $held && self::sold_individually( $added[ $ids ] ) ) ) {
					$reduced += $quantity - $held;
				}
			}
			return array(
				'added'   => $added,
				'removed' => $removed,
				'reduced' => $reduced,
			);
		} catch ( Throwable $e ) {
			return $unread;
		}
	}

	/**
	 * Whether a product (the variation when there is one) is sold individually. False when unknown.
	 *
	 * @param array{product_id: int, variation_id: int} $ids Ids.
	 * @return bool
	 */
	private static function sold_individually( array $ids ) {
		try {
			$product = wc_get_product( $ids['variation_id'] > 0 ? $ids['variation_id'] : $ids['product_id'] );
			return is_object( $product ) && is_callable( array( $product, 'is_sold_individually' ) ) && $product->is_sold_individually();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * The notice markers for the removed lines' reasons, once each, in the Notice's print order.
	 *
	 * @param array<int, string> $removed Reasons by index.
	 * @return string[]
	 */
	private static function reason_markers( array $removed ) {
		$markers = array();
		foreach ( self::REASON_MARKERS as $reason => $marker ) {
			if ( in_array( $reason, $removed, true ) ) {
				$markers[] = $marker;
			}
		}
		return $markers;
	}

	/**
	 * The session's `wc_notices` now, for the notice windows in add_line(): an array (no notices is
	 * `array()`), or null when it can't be read or is not an array. Never throws.
	 *
	 * @return array|null
	 */
	private static function notices_now() {
		try {
			$session = self::session();
			$notices = null === $session ? null : $session->get( 'wc_notices', array() );
			if ( null === $notices ) {
				return array();
			}
			return is_array( $notices ) ? $notices : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Adds to `$foreign` the notices appended in a window, per type. When a type's entries before the
	 * window are not the first entries after it (wc_clear_notices(), or an entry edited in place: whole
	 * entries are compared, `notice` and `data`), nothing from the window is kept, for any type. Nothing
	 * either when a read failed.
	 *
	 * @param array      $foreign Notices kept so far, by type; updated.
	 * @param array|null $before  notices_now() before the window.
	 * @param array|null $after   notices_now() after it.
	 * @return void
	 */
	private static function keep_appended( array &$foreign, $before, $after ) {
		if ( ! is_array( $before ) || ! is_array( $after ) ) {
			return;
		}
		$appended = array();
		foreach ( $before as $type => $entries ) {
			$now = $after[ $type ] ?? null;
			if ( ! is_array( $entries ) || ! is_array( $now ) ) {
				if ( $now !== $entries ) {
					return;
				}
				continue;
			}
			$entries = array_values( $entries );
			$now     = array_values( $now );
			if ( array_slice( $now, 0, count( $entries ) ) !== $entries ) {
				return;
			}
			$appended[ $type ] = array_slice( $now, count( $entries ) );
		}
		foreach ( $after as $type => $entries ) {
			if ( ! array_key_exists( $type, $before ) && is_array( $entries ) ) {
				$appended[ $type ] = array_values( $entries );
			}
		}
		foreach ( $appended as $type => $entries ) {
			foreach ( $entries as $entry ) {
				$foreign[ $type ][] = $entry;
			}
		}
	}

	/**
	 * Step 8's `wc_notices`: the notices from before the link, then the ones other plugins added during
	 * step 3, per type, without an entry whose type and text are already there (Subscriptions repeats
	 * its notice on [S1,S2,S3]). With none added it is exactly what was there before.
	 *
	 * @param mixed $before  The session's `wc_notices` before step 3.
	 * @param array $foreign Notices other plugins added, by type.
	 * @return mixed
	 */
	private static function merged_notices( $before, array $foreign ) {
		if ( ! $foreign ) {
			return $before;
		}
		$merged = is_array( $before ) ? $before : array();
		$seen   = array();
		foreach ( $merged as $type => $entries ) {
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				$seen[ self::notice_id( $type, $entry ) ] = true;
			}
		}
		foreach ( $foreign as $type => $entries ) {
			foreach ( $entries as $entry ) {
				$id = self::notice_id( $type, $entry );
				if ( null !== $id && isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ (string) $id ] = true;
				if ( ! isset( $merged[ $type ] ) || ! is_array( $merged[ $type ] ) ) {
					$merged[ $type ] = array();
				}
				$merged[ $type ][] = $entry;
			}
		}
		return $merged;
	}

	/**
	 * A notice's type and text, for de-duplication; null for a text that is not a string or number.
	 *
	 * @param mixed $type  Notice type.
	 * @param mixed $entry Woo notice entry (`notice`, `data`) or, from old Woo, the text.
	 * @return string|null
	 */
	private static function notice_id( $type, $entry ) {
		$text = is_array( $entry ) ? ( $entry['notice'] ?? null ) : $entry;
		return is_scalar( $text ) ? $type . "\0" . $text : null;
	}

	/**
	 * H5: whether no line of the link can be added, and why. Null as soon as one line can be, or when the
	 * check throws for a line (a throw counts as "can add": the link then takes today's path, where
	 * add_line() skips that line on its own). Otherwise the checked lines' counts by REASON_*: an empty
	 * array for a link with no lines. Lines past MAX_LINES are never added, so they are not checked.
	 * Never throws.
	 *
	 * @param array $lines The link's lines.
	 * @return array<string, int>|null
	 */
	private static function refusals( array $lines ) {
		$counts = array();
		foreach ( array_slice( $lines, 0, self::MAX_LINES ) as $line ) {
			try {
				$reason = self::refusal( is_array( $line ) ? $line : array() );
			} catch ( Throwable $e ) {
				return null;
			}
			if ( null === $reason ) {
				return null;
			}
			$counts[ $reason ] = ( $counts[ $reason ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * H5's kept path: no line can be added, so nothing is changed. Not the cart, the draft orders, the
	 * customer, an earlier link's hint, pre-fill copy, trace, coupon or rate, and not Woo's notices (no
	 * add_to_cart() ran, so none was raised). Only our none-added notice is set: what the cart holds now,
	 * then the names. An unknown channel is still counted: that measures links opened, not carts built.
	 * Never throws.
	 *
	 * @param array  $payload The payload.
	 * @param object $cart    WC()->cart.
	 * @param array  $lines   The link's lines.
	 * @param array  $reasons refusals().
	 * @param int    $now     Unix seconds.
	 * @return array{added: int, skipped: int, removed: int, reduced: int, kept: bool, reasons: array<string, int>}
	 */
	private static function keep( #[\SensitiveParameter] array $payload, $cart, array $lines, array $reasons, $now ) {
		$empty = false;
		try {
			$empty = is_object( $cart ) && is_callable( array( $cart, 'is_empty' ) ) && $cart->is_empty();
		} catch ( Throwable $e ) {
			// Unknown: the notice then says the cart still holds what it had.
			$empty = false;
		}
		$names = array();
		foreach ( $lines as $line ) {
			$names[] = self::left_out_name( is_array( $line ) ? $line : array() );
		}
		Urumi_Agentic_Handoff_Notice::add(
			Urumi_Agentic_Handoff_Notice::KIND_NONE_ADDED,
			array_merge( array( $empty ? Urumi_Agentic_Handoff_Notice::CART_EMPTY_MARKER : Urumi_Agentic_Handoff_Notice::CART_KEPT_MARKER ), array_values( array_unique( $names ) ) ),
			$now
		);
		try {
			$channel = is_string( $payload['channel'] ?? null ) ? $payload['channel'] : '';
			if ( ! in_array( $channel, Urumi_Agentic_Settings::CHANNELS, true ) ) {
				Urumi_Agentic_Settings::count_unknown_channel();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return array(
			'added'   => 0,
			'skipped' => count( $lines ),
			'removed' => 0,
			'reduced' => 0,
			'kept'    => true,
			'reasons' => $reasons,
		);
	}

	/**
	 * H5: whether today's add path (add_line()) could add this line to an empty cart, checked without
	 * adding it. A throw counts as "can add". See refusal().
	 *
	 * @param array $line The link's line.
	 * @return bool
	 */
	private static function can_add( array $line ) {
		try {
			return null === self::refusal( $line );
		} catch ( Throwable $e ) {
			return true;
		}
	}

	/**
	 * Why a line can't be added, or null when it can. Read-only: it calls no cart method but is_empty()
	 * and runs no `woocommerce_add_to_cart_validation` or `woocommerce_add_cart_item_data` filter (a
	 * validator may empty the cart). It runs the checks of add_line() before Woo is called (line_target(), the
	 * password guard), then the refusals WC_Cart::add_to_cart() makes on an empty cart, in Woo's order:
	 * the quantity filter, a quantity of 0 or less, the sold-individually quantity, purchasable, in
	 * stock, enough stock for the filtered quantity. A price change never refuses a line; no price at all
	 * does, through is_purchasable(), as in Woo. Refusals that only a validation filter makes (Min/Max
	 * Quantities, Subscriptions' rules) are not seen: such a link takes today's path. Throws what the
	 * product or a filter throws.
	 *
	 * The check runs before the cart is emptied, so it sees the shopper's cart, while today's add path
	 * sees an empty one. is_purchasable() can read the cart: Subscriptions' WCS_Limiter::is_purchasable(),
	 * which a subscription variation calls directly (no filter), says false for a variation of a limited
	 * variable subscription when the cart holds another variation of it and mixed checkout is on. So a
	 * not-purchasable answer is trusted only when the cart is empty (cart_is_empty()); on a non-empty or
	 * unknown cart it counts as "can add" unless a stock read refuses the line. The quantity, structure
	 * and stock reads and the password guard read no cart in Woo; the stock answers Subscriptions bases on
	 * the cart (its renewal stock manager's in-stock and backorders filters) only ever loosen, so they can
	 * only send a line to today's path.
	 *
	 * @param array $line The link's line.
	 * @return string|null One of REASON_*.
	 */
	private static function refusal( array $line ) {
		$quantity = (int) ( $line['quantity'] ?? 0 );
		if ( $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			return self::REASON_QUANTITY;
		}
		$target = self::line_target( $line );
		if ( null === $target ) {
			return self::REASON_STRUCTURE;
		}
		// As add_line(): Woo's validation callback refuses a password-protected product.
		if ( function_exists( 'post_password_required' ) && post_password_required( $target['product_id'] ) ) {
			return self::REASON_PURCHASABLE;
		}
		$product  = $target['product'];
		$quantity = self::filtered_quantity( $quantity, $target['product_id'], $target['variation_id'] );
		if ( $quantity <= 0 ) {
			return self::REASON_QUANTITY;
		}
		if ( $product->is_sold_individually() ) {
			// No item data: the link carries none, and woocommerce_add_cart_item_data is not run here.
			$quantity = apply_filters( 'woocommerce_add_to_cart_sold_individually_quantity', 1, $quantity, $target['product_id'], $target['variation_id'], array() );
		}
		// Trusted only on an empty cart (see above): otherwise a "no" may be the shopper's cart talking,
		// and the line goes on to the stock reads, which do not read the cart.
		if ( ! $product->is_purchasable() && self::cart_is_empty() ) {
			return self::REASON_PURCHASABLE;
		}
		if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) ) {
			return self::REASON_STOCK;
		}
		return null;
	}

	/**
	 * Whether the cart is empty now, read as WC_Cart::is_empty() reads it. False when it can't be read,
	 * so a doubtful answer counts as "can add". Never throws.
	 *
	 * @return bool
	 */
	private static function cart_is_empty() {
		try {
			$woocommerce = function_exists( 'WC' ) ? WC() : null;
			$cart        = is_object( $woocommerce ) ? ( $woocommerce->cart ?? null ) : null;
			return is_object( $cart ) && is_callable( array( $cart, 'is_empty' ) ) && true === $cart->is_empty();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * `woocommerce_add_to_cart_quantity` with exactly the arguments WC_Cart::add_to_cart() passes: the
	 * variation id too from Woo 11.0 (its betas and release candidates included), not before. A callback
	 * that needs the third argument then fails here as it fails in Woo. An unknown version gets two.
	 *
	 * @param int $quantity     The link's quantity.
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id, 0 for none.
	 * @return mixed What the filter gives.
	 */
	private static function filtered_quantity( $quantity, $product_id, $variation_id ) {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$version     = is_object( $woocommerce ) && is_string( $woocommerce->version ?? null ) ? $woocommerce->version : ( defined( 'WC_VERSION' ) ? (string) WC_VERSION : '' );
		if ( '' !== $version && version_compare( $version, '11.0.0-dev', '>=' ) ) {
			return apply_filters( 'woocommerce_add_to_cart_quantity', $quantity, $product_id, $variation_id );
		}
		return apply_filters( 'woocommerce_add_to_cart_quantity', $quantity, $product_id );
	}

	/**
	 * What a line names, when it is a line add_line() would send to Woo: ids and quantity in bounds, a
	 * published product, and for a variation a published parent that is its own and no "any"
	 * attribute (the link carries no attribute values). A variable product without its variation, or a
	 * variation id sent as the product id, is refused: the hint matches a variation line by its
	 * variation id. Shared by add_line() and the H5 check, so both refuse the same lines. Null when not;
	 * throws what Woo throws.
	 *
	 * @param array $line `product_id`, `variation_id`, `quantity` (ints).
	 * @return array{product: object, product_id: int, variation_id: int, quantity: int, attributes: array}|null
	 */
	private static function line_target( array $line ) {
		$product_id   = (int) ( $line['product_id'] ?? 0 );
		$variation_id = (int) ( $line['variation_id'] ?? 0 );
		$quantity     = (int) ( $line['quantity'] ?? 0 );
		if ( $product_id <= 0 || $variation_id < 0 || $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			return null;
		}
		$attributes = array();
		$product    = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( ! is_object( $product ) || 'publish' !== $product->get_status() ) {
			return null;
		}
		if ( $variation_id > 0 ) {
			$parent = wc_get_product( $product_id );
			if ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id
				|| ! is_object( $parent ) || 'publish' !== $parent->get_status() ) {
				return null;
			}
			// The link carries no attribute values, so a variation with an "any" attribute
			// (an empty value) can't be rebuilt; Woo would refuse it anyway.
			$attributes = wc_get_product_variation_attributes( $variation_id );
			foreach ( $attributes as $value ) {
				if ( '' === (string) $value ) {
					return null;
				}
			}
		} elseif ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
			// A variable product needs its variation, and a variation id sent as the product id could
			// never match the hint (which matches a variation line by its variation id).
			return null;
		}
		return array(
			'product'      => $product,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
			'attributes'   => $attributes,
		);
	}

	/**
	 * Adds one line, or returns null when it can't be added.
	 *
	 * A password-protected product is refused before Woo's validation filter, as Woo's own callback there
	 * (wc_protected_product_add_to_cart()) would refuse it, so that core notice is never added and every
	 * notice the filter adds is another plugin's. Those are kept in `$foreign`, a refusal's reasons too;
	 * so are notices from add_to_cart()'s hooks when it adds the line (Woo adds none on success). When
	 * add_to_cart() refuses, its notice is Woo's own and is dropped: the left-out line names the item.
	 *
	 * @param array $line    `product_id`, `variation_id`, `quantity` (ints).
	 * @param array $foreign Notices other plugins added, by type; updated.
	 * @return array{product_id: int, variation_id: int, key: string}|null The cart item key add_to_cart()
	 *         gave (`''` when a plugin made it return something else).
	 */
	private static function add_line( array $line, array &$foreign ) {
		try {
			$target = self::line_target( $line );
			if ( null === $target ) {
				return null;
			}
			$product_id   = $target['product_id'];
			$variation_id = $target['variation_id'];
			$quantity     = $target['quantity'];
			$attributes   = $target['attributes'];
			// Woo refuses a password-protected product in its validation filter, with a notice of its own.
			// Checked first, so that filter's notices are all other plugins'. The parent id, as Woo checks.
			// refusal() checks the same.
			if ( function_exists( 'post_password_required' ) && post_password_required( $product_id ) ) {
				return null;
			}
			$notices = self::notices_now();
			$valid   = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $attributes );
			self::keep_appended( $foreign, $notices, self::notices_now() );
			if ( ! $valid ) {
				return null;
			}
			$notices = self::notices_now();
			$key     = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );
			if ( false === $key ) {
				return null;
			}
			self::keep_appended( $foreign, $notices, self::notices_now() );
		} catch ( Throwable $e ) {
			return null;
		}
		return array(
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'key'          => is_string( $key ) ? $key : '',
		);
	}

	/**
	 * What the notice calls a line that was left out: the product's name from the store, only
	 * for a published product (never a draft's name, never text from the link).
	 *
	 * @param array $line The link's line.
	 * @return string
	 */
	private static function left_out_name( array $line ) {
		try {
			$product_id = (int) ( $line['product_id'] ?? 0 );
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
				// A variation id sent as the product id has no name of its own to show: its parent may be a draft.
				if ( is_object( $product ) && 'publish' === $product->get_status() && ! $product->is_type( 'variation' ) ) {
					$name = (string) $product->get_name();
					if ( '' !== $name ) {
						return $name;
					}
				}
			}
		} catch ( Throwable $e ) {
			return Urumi_Agentic_Handoff_Notice::GONE_MARKER;
		}
		return Urumi_Agentic_Handoff_Notice::GONE_MARKER;
	}
}
