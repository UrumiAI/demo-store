<?php
/**
 * The hand-off trace (pl7-plan.md section 5.2, "Ending the copy"): keyed hashes of what a link wrote
 * to the session customer, kept after the copy ends without an order, so the link's values are never
 * on a checkout again without a line naming them.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One trace record in the Woo session: `{v, customer_id, opened, named, hashes, reviewed, reverted}`,
 * `ordered` once an order has used the link's values, and `account` once a new link has written
 * over an earlier link's record.
 *
 * - `hashes` maps a field name to a keyed hash of the value the link wrote, read back from the
 *   session customer: the names, the email and the phones, and each location field
 *   (`billing_address_1` ... `shipping_country`) the link filled with a non-empty value. When the
 *   link wrote one location only, the other location's fields are hashed with the written location's
 *   values too (the mirror): the classic checkout copies billing to shipping when the shopper ships
 *   to the billing address, and the Checkout block copies shipping to billing by default. Nothing
 *   readable: no value, no `before`. The key is derived from the site's auth salt, so a hash can't
 *   be checked against a guess without it.
 * - A location (`billing`, `shipping`) is the link's when every field recorded for it still holds
 *   the link's value, whatever its other fields hold now: Woo may fill a field the link left empty
 *   (9.0-10.8 show the account's saved value for a `""` in the session; a billing state gets the
 *   store's default). It is recorded only with enough to tell an address: its `address_1`, or else
 *   at least two fields (a link that filled only a country must not make every address in that
 *   country the link's). The cost: a shopper whose own address has every value the link filled
 *   counts as the link's.
 * - `opened`: when the link was opened (Unix seconds), for the lines. `named`: whether a checkout
 *   page view after the ending has printed the ended-case lines or the one-shot line; one bit for
 *   the session, not per field. "Remove these details" sets it back. `reviewed`: the keys the
 *   classic `update_order_review` has put back once. `reverted`: what a put-back (off the checkout,
 *   or the classic `update_order_review`) took out, for the one-shot line on the next checkout page
 *   view (`delivery`, `contact`).
 * - The pre-fill copy holds the hashes while it lives. end_copy() writes the record when the copy
 *   ends without an order, leaving out a field whose pre-link value is the link's own.
 * - It lives as long as the Woo session and is never spent by a push or a render. A new link that
 *   writes replaces it (clears it, all but `account`; its own hashes wait in its copy). An order
 *   placed makes it `ordered` (order_placed()): from then on it is never named or put back (the
 *   order used the values), and only the account save reads it (links_fields(), R2).
 * - `account`: the hashes of each earlier link's record a new link has replaced, ordered or not,
 *   one set per record (carried()). One rule: every link's hashes keep its values out of the
 *   account save for the rest of the Woo session. An order leaves the link's values in the session
 *   customer; so does a shopper who keeps values a stale tab posted back and the checkout named.
 *   A new link that writes only part of them (shipping only, names only), or ends recording
 *   nothing (its values equal what was there), leaves the rest there. clear() and write() keep
 *   this set, so those values are still not saved to the account. Only links_fields() reads it: it
 *   never names, puts back or matches for the ended case. It lives as long as the Woo session, a
 *   login included. No call drops it, because no case needs to: the session's end takes it. The
 *   cost: a value the shopper types equal to an earlier link's is not saved to the account for
 *   the rest of the session.
 *
 * What happens when the session customer holds a value the record knows (it "matches"):
 * - classic `update_order_review`: put back, once per entry, and the page is told to reload; the
 *   reloaded checkout says so once;
 * - Store API `cart/update-customer` and checkout POSTs: nothing (Woo's Blocks client discards the
 *   addresses in that answer, so a put-back there can't stick; a placed order clears the record);
 * - a fresh checkout render while the copy is not live: delivery and contact lines name the values,
 *   with a "Remove these details" action, and `named` is set;
 * - a fresh front-end render that is not the checkout, while `named` is unset: put back, and the
 *   next checkout render says so once (and sets `named`: what the shopper types from then on is
 *   theirs, and is named on the checkout if it matches).
 * A prefetch or prerender request (speculation rules, hover prefetch) does none of this: it may
 * never be shown.
 *
 * Putting back gives the pre-link value: the account's saved value for a logged-in customer (read
 * from user meta then), `""` for a guest; a location goes back whole (all six fields). An entry
 * whose pre-link value is the link's own (the account was saved with it) does not match.
 *
 * The account save (R2, pl7-plan.md section 5.2): when an order saves a logged-in customer's names
 * and addresses to the account, links_fields() tells which of them are the link's, by the same rule,
 * from the live copy's hashes, this record (ordered or not) and its `account` sets. Those are not
 * saved; the rest are. The record outlives the order for that: after a declined card the cart
 * stays, and the retry saves the customer again after the first attempt ended the copy. And an
 * earlier link's hashes outlive a new link (`account`): its values may still be in the session
 * customer, and a later order would save what the new link did not write over.
 */
class Urumi_Agentic_Handoff_Trace {

	const SESSION_KEY = 'urumi_agentic_handoff_trace';

	/**
	 * 3: location fields hashed one by one. An older record (2: one hash per whole location, never
	 * deployed) is dropped.
	 */
	const VERSION = 3;

	/**
	 * Hex characters kept of each HMAC-SHA256.
	 */
	const HASH_CHARS = 32;

	/**
	 * Most hash sets `account` keeps; the oldest goes first. Each is one earlier link in this
	 * session, so a real session never comes near it; it only bounds the session's size.
	 */
	const ACCOUNT_SETS = 16;

	/**
	 * Fields traced one by one.
	 */
	const FIELDS = array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone', 'shipping_first_name', 'shipping_last_name', 'shipping_phone' );

	/**
	 * A location's fields, without their billing_/shipping_ prefix.
	 */
	const LOCATION = array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * What a put-back off the checkout can take out, for the one-shot line (`reverted`).
	 */
	const REVERTED_DELIVERY = 'delivery';
	const REVERTED_CONTACT  = 'contact';

	/**
	 * The "Remove these details" action: its wc-ajax endpoint and its nonce action.
	 */
	const REMOVE_ACTION = 'urumi_agentic_remove_details';

	/**
	 * Whether this request posts the classic checkout form's address (`update_order_review`).
	 *
	 * @var bool
	 */
	private static $review = false;

	/**
	 * Registers the checks.
	 *
	 * @return void
	 */
	public static function boot() {
		self::$review = false;
		// Classic update_order_review: fires before it sets the posted address; the check runs when it
		// saves the customer, so the response and the session carry the put-back values.
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'arm_review' ), 10, 0 );
		add_action( 'woocommerce_before_customer_object_save', array( __CLASS__, 'before_customer_save' ), 10, 1 );
		// A front-end page that is not the checkout: after Woo's own redirects (10), before any output.
		add_action( 'template_redirect', array( __CLASS__, 'front_end_render' ), 20, 0 );
		// "Remove these details" (?wc-ajax=urumi_agentic_remove_details): a session and cart are loaded.
		add_action( 'wc_ajax_' . self::REMOVE_ACTION, array( __CLASS__, 'on_remove_details' ), 10, 0 );
	}

	/**
	 * `woocommerce_checkout_update_order_review`: this request posts the classic form's address.
	 *
	 * @return void
	 */
	public static function arm_review() {
		self::$review = true;
	}

	/**
	 * `woocommerce_before_customer_object_save`, while update_order_review runs: puts back on the
	 * session customer being saved (Woo saves it right after) and asks the classic checkout to reload,
	 * so the stale form shows the put-back values instead of posting the link's again; `reverted` gets
	 * what went, so the reloaded checkout says so. Once per entry (`reviewed`), so a value typed again
	 * on purpose in the classic form stays. Never throws.
	 *
	 * @param mixed $customer The customer being saved.
	 * @return void
	 */
	public static function before_customer_save( $customer ) {
		if ( ! self::$review ) {
			return;
		}
		try {
			$woocommerce = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $woocommerce ) || ! is_object( $customer ) || ( $woocommerce->customer ?? null ) !== $customer ) {
				return;
			}
			$record = self::record();
			if ( null === $record ) {
				return;
			}
			$plan = self::plan( $customer, $record, $record['reviewed'] );
			if ( ! $plan['keys'] ) {
				return;
			}
			$record['reviewed'] = array_values( array_unique( array_merge( $record['reviewed'], $plan['keys'] ) ) );
			$record['reverted'] = array_values( array_unique( array_merge( $record['reverted'], self::kinds_of( $plan['keys'] ) ) ) );
			self::store( $record );
			Urumi_Agentic_Handoff_Cart::restore( $customer, $plan['id'], $plan['values'], false );
			if ( is_object( $woocommerce->session ?? null ) ) {
				$woocommerce->session->set( 'reload_checkout', true );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * `template_redirect` 20: on a fresh front-end page GET that is not the checkout (a product, the
	 * cart, the shop, home), put_back_off_checkout(). It runs on every front-end request, so it reads
	 * the record first (one session read) and asks what the page is only when there is one to act on.
	 * Never throws.
	 *
	 * @return void
	 */
	public static function front_end_render() {
		try {
			$record = self::record();
			if ( null === $record || $record['named'] || ! self::is_off_checkout_render() ) {
				return;
			}
			$woocommerce = function_exists( 'WC' ) ? WC() : null;
			$customer    = is_object( $woocommerce ) ? ( $woocommerce->customer ?? null ) : null;
			if ( is_object( $customer ) ) {
				self::put_back_off_checkout( $customer );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * While the record is not named: every matching entry is put back and saved, nothing is spent, and
	 * the next checkout render says what was taken out (`reverted`). A payment button off the checkout
	 * (PayPal's card button on a product or cart page builds its order from the session customer) then
	 * never gets the link's address unannounced. Once named, the values were shown on a checkout and
	 * stay. Never throws.
	 *
	 * @param object $customer The session customer.
	 * @return string[] The keys put back.
	 */
	public static function put_back_off_checkout( $customer ) {
		try {
			$record = self::record();
			if ( null === $record || $record['named'] ) {
				return array();
			}
			$plan = self::plan( $customer, $record, array() );
			if ( ! $plan['keys'] ) {
				return array();
			}
			$record['reverted'] = array_values( array_unique( array_merge( $record['reverted'], self::kinds_of( $plan['keys'] ) ) ) );
			self::store( $record );
			Urumi_Agentic_Handoff_Cart::restore( $customer, $plan['id'], $plan['values'], true );
			return $plan['keys'];
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * Whether this render is a real page view of the checkout, the only render allowed to set `named`
	 * and take the one-shot line: a front-end GET (not admin, ajax, REST, cron) that has reached the
	 * template, whose queried page is the checkout (is_checkout(), or the queried page holds the
	 * Checkout block or shortcode), and that is not rendering for the document head or an excerpt
	 * (an SEO plugin building a description renders the content and throws it away; a render the
	 * shopper never sees must not count as naming), and that is not a prefetch or prerender
	 * (is_speculative()). False when it can't tell.
	 *
	 * @return bool
	 */
	private static function is_checkout_page_view() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never stored.
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! function_exists( 'is_checkout' ) || self::is_speculative() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( ! did_action( 'template_redirect' ) || doing_action( 'wp_head' ) ) {
			return false;
		}
		foreach ( array( 'get_the_excerpt', 'wp_trim_excerpt', 'the_excerpt', 'get_the_excerpt_embed', 'wp_head' ) as $hook ) {
			if ( doing_filter( $hook ) ) {
				return false;
			}
		}
		if ( is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_queried_object_id' ) ) {
			$id = (int) get_queried_object_id();
			return $id > 0 && ( has_block( 'woocommerce/checkout', $id ) || has_shortcode( (string) get_post_field( 'post_content', $id ), 'woocommerce_checkout' ) );
		}
		return false;
	}

	/**
	 * Whether this request is a fresh front-end page GET that is not the checkout: not admin, ajax
	 * (wc-ajax included), REST, cron, a feed, robots.txt, the favicon, a trackback, an embed or a 404,
	 * and neither Woo's checkout (is_checkout(), which covers its order-received and pay endpoints) nor
	 * another page holding the Checkout block or shortcode (WC 8.2's is_checkout() does not see a
	 * block on a page that is not Woo's checkout page), and not a prefetch or prerender
	 * (is_speculative()). False when it can't tell.
	 *
	 * @return bool
	 */
	private static function is_off_checkout_render() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never stored.
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! function_exists( 'is_checkout' ) || self::is_speculative() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		foreach ( array( 'is_feed', 'is_robots', 'is_favicon', 'is_trackback', 'is_embed', 'is_404' ) as $conditional ) {
			if ( function_exists( $conditional ) && $conditional() ) {
				return false;
			}
		}
		if ( is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_queried_object_id' ) ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 && ( has_block( 'woocommerce/checkout', $id ) || has_shortcode( (string) get_post_field( 'post_content', $id ), 'woocommerce_checkout' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether this request is a prefetch or a prerender the browser makes on its own: WordPress 6.8+
	 * prints speculation rules (prefetch by default) and some plugins prefetch a link on hover. Such a
	 * request may never be shown, so it must not change the session. Told by its `Sec-Purpose` or
	 * `Purpose` header holding `prefetch` or `prerender` (any case; Chrome sends `prefetch;prerender`
	 * for a prerender), or Firefox's `X-Moz: prefetch`.
	 *
	 * @return bool
	 */
	private static function is_speculative() {
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never stored.
			$value = $_SERVER[ $header ] ?? '';
			if ( is_string( $value ) && ( false !== stripos( $value, 'prefetch' ) || false !== stripos( $value, 'prerender' ) ) ) {
				return true;
			}
		}
		// A browser that says where the response goes and says it is not a top-level document (a
		// hidden iframe, a fetch) is not showing the page to the shopper. Absent header: unknown, so
		// counted as shown (older browsers and curl send none).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never stored.
		$dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
		return is_string( $dest ) && '' !== $dest && 'document' !== strtolower( trim( $dest ) );
	}

	/**
	 * The checkout render's lines while the copy is not live (Urumi_Agentic_Handoff_Cart::live_lines()
	 * asks): the one-shot line about a put-back, taken, and `named` set (the shopper has read that the
	 * details were removed, so what they type from then on is theirs: it is not put back off the
	 * checkout again, and is named here if it matches); and when the session customer holds values the
	 * record knows, the delivery and contact lines built from its current values, the date the link
	 * was opened, and `named` set. Only a real checkout page view (is_checkout_page_view()) takes the
	 * line or sets `named`. Never throws.
	 *
	 * @param mixed  $lines    What an earlier filter gave (kind => values).
	 * @param object $customer The session customer.
	 * @return mixed
	 */
	public static function ended_lines( $lines, $customer ) {
		try {
			if ( ! is_array( $lines ) || ! is_object( $customer ) ) {
				return $lines;
			}
			$record = self::record();
			if ( null === $record ) {
				return $lines;
			}
			$real    = self::is_checkout_page_view();
			$changed = false;
			if ( $record['reverted'] ) {
				$lines[ Urumi_Agentic_Handoff_Notice::KIND_REVERTED ] = $record['reverted'];
				if ( $real ) {
					$record['reverted'] = array();
					$record['named']    = true;
					$changed            = true;
				}
			}
			$plan  = self::plan( $customer, $record, array() );
			$named = self::lines_for( $customer, $plan['keys'] );
			if ( $named ) {
				$lines = $named + $lines;
				$lines[ Urumi_Agentic_Handoff_Notice::KIND_OPENED ] = array( self::date_of( $record['opened'] ) );
				if ( $real ) {
					$changed         = $changed || ! $record['named'];
					$record['named'] = true;
				}
			}
			if ( $changed ) {
				self::store( $record );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $lines;
	}

	/**
	 * The ended-case lines for the matching keys, read from the customer now: the delivery line for
	 * the shipping location (else billing) when it matches; the contact line for the billing name
	 * (while either part matches), a shipping name the delivery line does not show, the email and the
	 * phones.
	 *
	 * @param object   $customer WC_Customer.
	 * @param string[] $keys     Matching keys.
	 * @return array<string, string[]>
	 */
	private static function lines_for( $customer, array $keys ) {
		$keys     = array_flip( $keys );
		$lines    = array();
		$delivery = null;
		foreach ( array( 'shipping', 'billing' ) as $type ) {
			if ( isset( $keys[ $type ] ) ) {
				$delivery = $type;
				$summary  = Urumi_Agentic_Handoff_Cart::delivery_summary( $customer, $type );
				if ( $summary ) {
					$lines[ Urumi_Agentic_Handoff_Notice::KIND_DELIVERY_ENDED ] = $summary;
				}
				break;
			}
		}
		$contact = array();
		$name    = static function ( $type ) use ( $customer ) {
			return trim( self::read( $customer, $type . '_first_name' ) . ' ' . self::read( $customer, $type . '_last_name' ) );
		};
		if ( isset( $keys['billing_first_name'] ) || isset( $keys['billing_last_name'] ) ) {
			$contact[] = $name( 'billing' );
		}
		if ( ( isset( $keys['shipping_first_name'] ) || isset( $keys['shipping_last_name'] ) ) && 'shipping' !== $delivery ) {
			$contact[] = $name( 'shipping' );
		}
		foreach ( array( 'billing_email', 'billing_phone', 'shipping_phone' ) as $field ) {
			// The link writes its phone to both; the shipping one shows only when billing's does not.
			if ( isset( $keys[ $field ] ) && ( 'shipping_phone' !== $field || ! isset( $keys['billing_phone'] ) ) ) {
				$contact[] = (string) self::read( $customer, $field );
			}
		}
		$contact = array_values( array_unique( array_filter( $contact, 'strlen' ) ) );
		if ( $contact ) {
			$lines[ Urumi_Agentic_Handoff_Notice::KIND_CONTACT_ENDED ] = $contact;
		}
		return $lines;
	}

	/**
	 * The date the link was opened, in the site's date format and time zone.
	 *
	 * @param int $opened Unix seconds.
	 * @return string
	 */
	private static function date_of( $opened ) {
		$format = function_exists( 'get_option' ) ? get_option( 'date_format', 'F j, Y' ) : 'F j, Y';
		$format = is_string( $format ) && '' !== $format ? $format : 'F j, Y';
		$date   = function_exists( 'wp_date' ) ? wp_date( $format, $opened ) : gmdate( $format, $opened );
		return is_string( $date ) ? $date : gmdate( 'Y-m-d', $opened );
	}

	/**
	 * The "Remove these details" form the notice prints after the ended-case lines: a POST to this
	 * plugin's wc-ajax endpoint with a nonce tied to the Woo session, and the page to return to.
	 * Escaped; '' when it can't be built. Never throws.
	 *
	 * @return string
	 */
	public static function remove_form() {
		try {
			if ( ! function_exists( 'wp_create_nonce' ) ) {
				return '';
			}
			$action = class_exists( 'WC_AJAX' ) && is_callable( array( 'WC_AJAX', 'get_endpoint' ) ) ? (string) WC_AJAX::get_endpoint( self::REMOVE_ACTION ) : home_url( '/?wc-ajax=' . self::REMOVE_ACTION );
			$return = wc_get_checkout_url();
			if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_permalink' ) ) {
				$permalink = get_permalink();
				$return    = is_string( $permalink ) && '' !== $permalink ? $permalink : $return;
			}
			return '<form class="urumi-agentic-remove-details" method="post" action="' . esc_url( $action ) . '">'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::nonce_action() ) ) . '" />'
				. '<input type="hidden" name="return" value="' . esc_url( $return ) . '" />'
				. '<button type="submit" class="button">' . esc_html__( 'Remove these details', 'urumi-agentic' ) . '</button></form>';
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * `wc_ajax_urumi_agentic_remove_details`: answers remove_details() and ends the request (Woo's
	 * wc-ajax would otherwise wp_die() with an empty body).
	 *
	 * @return void
	 */
	public static function on_remove_details() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- remove_details() checks the nonce; values are compared, never stored.
		$response = self::remove_details( is_array( $_POST ) ? wp_unslash( $_POST ) : array(), (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
		if ( 303 === $response['status'] ) {
			wp_safe_redirect( $response['location'], 303 );
			exit;
		}
		wp_die( esc_html__( 'These details could not be removed. Go back to checkout and try again.', 'urumi-agentic' ), '', array( 'response' => (int) $response['status'] ) );
	}

	/**
	 * The "Remove these details" POST: with a valid nonce, every matching entry is put back (as off the
	 * checkout, whatever `named` is) and `named` is set back, so values a stale tab pushes again are
	 * put back off the checkout again until a checkout names them. The answer is a 303 to the page the
	 * form was on (checkout when that is not this site's). Without a nonce, 403 and nothing changes;
	 * another method, 405.
	 *
	 * @param array  $post   The POST fields, unslashed.
	 * @param string $method The request method.
	 * @return array{status: int, location?: string}
	 */
	public static function remove_details( array $post, $method ) {
		if ( 'POST' !== $method ) {
			return array( 'status' => 405 );
		}
		$nonce = is_string( $post['_wpnonce'] ?? null ) ? $post['_wpnonce'] : '';
		if ( '' === $nonce || ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, self::nonce_action() ) ) {
			return array( 'status' => 403 );
		}
		try {
			$record      = self::record();
			$woocommerce = function_exists( 'WC' ) ? WC() : null;
			$customer    = is_object( $woocommerce ) ? ( $woocommerce->customer ?? null ) : null;
			if ( null !== $record && is_object( $customer ) ) {
				$plan = self::plan( $customer, $record, array() );
				if ( $plan['keys'] ) {
					Urumi_Agentic_Handoff_Cart::restore( $customer, $plan['id'], $plan['values'], true );
				}
				if ( $record['named'] ) {
					$record['named'] = false;
					self::store( $record );
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		$fallback = wc_get_checkout_url();
		$return   = is_string( $post['return'] ?? null ) ? trim( $post['return'] ) : '';
		return array(
			'status'   => 303,
			'location' => '' === $return ? $fallback : wp_validate_redirect( $return, $fallback ),
		);
	}

	/**
	 * The nonce action of "Remove these details": tied to the Woo session's customer id, so a guest's
	 * nonce (WordPress gives every logged-out visitor user id 0) works in that session only.
	 *
	 * @return string
	 */
	private static function nonce_action() {
		$session = self::session();
		$id      = null !== $session && is_callable( array( $session, 'get_customer_id' ) ) ? $session->get_customer_id() : '';
		return self::REMOVE_ACTION . '|' . ( is_scalar( $id ) ? (string) $id : '' );
	}

	/**
	 * The hashes of what a link wrote, read back from the customer just after the write, per field:
	 * the names, the email and the phones; and each location field the link filled with a non-empty
	 * value that reads back non-empty (a field the link left `""` is not recorded, whatever Woo shows
	 * for it later). `shipping_phone` only with `billing_phone`: without it, the account kept its own
	 * phone and the mirrored one is the account's, not the link's. When the link wrote one location
	 * only, its fields are also hashed under the other location's names (the mirror), except a field
	 * the other location already holds with the same value (the shopper's own). The evidence rule
	 * (enough()) is applied when the record is written. Never throws.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $written  What the link wrote (field => value).
	 * @return array<string, string> Field => hash.
	 */
	public static function hashes_of( $customer, #[\SensitiveParameter] array $written ) {
		$hashes = array();
		try {
			$written = array_filter( $written, 'is_string' );
			foreach ( self::FIELDS as $field ) {
				if ( ! isset( $written[ $field ] ) || ( 'shipping_phone' === $field && ! isset( $written['billing_phone'] ) ) ) {
					continue;
				}
				$value = self::read( $customer, $field );
				if ( null !== $value ) {
					$hashes[ $field ] = self::hash( $field, self::canonical( $field, $value ) );
				}
			}
			$locations = array();
			foreach ( array_keys( self::types() ) as $type ) {
				$country = (string) self::read( $customer, $type . '_country' );
				foreach ( self::LOCATION as $name ) {
					$field = $type . '_' . $name;
					if ( ! isset( $written[ $field ] ) ) {
						continue;
					}
					$locations[ $type ] = $locations[ $type ] ?? array();
					$value              = self::read( $customer, $field );
					if ( '' !== trim( $written[ $field ] ) && null !== $value && '' !== self::canonical( $name, $value, $country ) ) {
						$locations[ $type ][ $name ] = self::canonical( $name, $value, $country );
					}
				}
			}
			foreach ( $locations as $type => $values ) {
				foreach ( $values as $name => $canonical ) {
					$hashes[ $type . '_' . $name ] = self::hash( $type . '_' . $name, $canonical );
				}
			}
			if ( 1 === count( $locations ) ) {
				$type    = (string) key( $locations );
				$other   = 'billing' === $type ? 'shipping' : 'billing';
				$country = (string) self::read( $customer, $type . '_country' );
				foreach ( $locations[ $type ] as $name => $canonical ) {
					$held = self::read( $customer, $other . '_' . $name );
					if ( null === $held || self::canonical( $name, $held, $country ) !== $canonical ) {
						$hashes[ $other . '_' . $name ] = self::hash( $other . '_' . $name, $canonical );
					}
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return $hashes;
	}

	/**
	 * Writes the record when a copy ends without an order, not yet named. A field whose pre-link
	 * value (the copy's `before`) hashes the same as the link's is left out: there is nothing to put
	 * back, and a shopper whose own value it is must not lose it. Then a location without enough
	 * fields left to tell an address (enough()) is left out whole. Nothing at all leaves the session
	 * empty of a record, but for `account` (clear()). With `$ordered`, the record is written
	 * `ordered` (order_placed()). The new record keeps `account`, and adds to it the hashes of the
	 * record it replaces, ordered or not (carried()). Never throws.
	 *
	 * @param int                   $customer_id Who the copy was written to.
	 * @param array<string, string> $hashes      The copy's hashes.
	 * @param array<string, string> $before      The copy's pre-link values (read here, never stored).
	 * @param int                   $opened      When the link was opened (Unix seconds).
	 * @param bool                  $ordered     Whether an order used the values.
	 * @return void
	 */
	public static function write( $customer_id, array $hashes, #[\SensitiveParameter] array $before, $opened, $ordered = false ) {
		try {
			$keep = array();
			foreach ( $hashes as $key => $hash ) {
				if ( ! self::is_key( $key ) || ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-f]{' . self::HASH_CHARS . '}\z/', $hash ) ) {
					continue;
				}
				$was = self::from_values( $key, $before );
				if ( null !== $was && hash_equals( $hash, self::hash( $key, $was ) ) ) {
					continue;
				}
				$keep[ $key ] = $hash;
			}
			foreach ( self::grouped( $keep ) as $key => $entries ) {
				if ( isset( self::types()[ $key ] ) && ! self::enough( $entries ) ) {
					$keep = array_diff_key( $keep, $entries );
				}
			}
			if ( ! $keep ) {
				self::clear();
				return;
			}
			$account = self::carried( self::stored(), $keep );
			$record  = array(
				'v'           => self::VERSION,
				'customer_id' => (int) $customer_id,
				'opened'      => (int) $opened,
				'named'       => false,
				'hashes'      => $keep,
				'reviewed'    => array(),
				'reverted'    => array(),
			);
			if ( $ordered ) {
				$record['ordered'] = true;
			}
			if ( $account ) {
				$record['account'] = $account;
			}
			self::store( $record );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Drops the record (a new link writes, or a copy ends with nothing to record), all but `account`.
	 * That stays, with the hashes of the record dropped here added to it, ordered or not (carried()),
	 * in a record without `hashes`, which nothing names or puts back from. With nothing to keep, the
	 * session is left without a record. Never throws.
	 *
	 * @return void
	 */
	public static function clear() {
		try {
			$session = self::session();
			if ( null === $session || null === $session->get( self::SESSION_KEY ) ) {
				return;
			}
			$account = self::carried( self::stored(), array() );
			if ( ! $account ) {
				$session->set( self::SESSION_KEY, null );
				return;
			}
			self::store(
				array(
					'v'           => self::VERSION,
					'customer_id' => 0,
					'opened'      => 0,
					'named'       => false,
					'hashes'      => array(),
					'reviewed'    => array(),
					'reverted'    => array(),
					'account'     => $account,
				)
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The `account` sets a record that replaces this one keeps: this one's own, and its `hashes`,
	 * ordered or not. One rule: every link's hashes keep its values out of the account save for the
	 * rest of the Woo session. An order leaves the values in the session customer; so does a shopper
	 * who keeps values a stale tab posted back after the ending put them back, and a new link that
	 * writes only part of them leaves the rest there. These sets never name or put back. A repeat is
	 * left out, and so is a set equal to the new record's `hashes` (links_fields() reads those
	 * anyway). At most ACCOUNT_SETS, the newest.
	 *
	 * @param array|null            $stored From stored().
	 * @param array<string, string> $hashes The new record's hashes.
	 * @return array<int, array<string, string>>
	 */
	private static function carried( $stored, array $hashes ) {
		if ( null === $stored ) {
			return array();
		}
		$sets = $stored['account'] ?? array();
		if ( $stored['hashes'] ) {
			$sets[] = $stored['hashes'];
		}
		$kept = array();
		foreach ( $sets as $set ) {
			ksort( $set );
			$kept[ self::set_key( $set ) ] = $set;
		}
		ksort( $hashes );
		unset( $kept[ self::set_key( $hashes ) ] );
		return array_slice( array_values( $kept ), -self::ACCOUNT_SETS );
	}

	/**
	 * A hash set as one string, to tell repeats apart.
	 *
	 * @param array<string, string> $set Field => hash, sorted by field.
	 * @return string
	 */
	private static function set_key( array $set ) {
		$parts = array();
		foreach ( $set as $field => $hash ) {
			$parts[] = $field . '=' . $hash;
		}
		return implode( ',', $parts );
	}

	/**
	 * An order used the link's values (Urumi_Agentic_Handoff_Cart::end_copy()): the record becomes
	 * `ordered`. With the copy that just ended, it is written from the copy's hashes, every field
	 * kept (no pre-link exclusion: a field the account already held is still the link's value, and
	 * leaving it out could leave a location short of enough()); without one, the record already
	 * there is marked. Nothing is put back or named from an ordered record; links_fields() reads it
	 * for as long as the session lives: when a new link writes, its hashes move to `account`
	 * (carried()). A record with no `hashes` (only `account`) is not marked. Never throws.
	 *
	 * @param array|null $copy The ended copy (customer_id, hashes, opened), or null.
	 * @return void
	 */
	public static function order_placed( $copy ) {
		try {
			if ( is_array( $copy ) ) {
				$hashes = is_array( $copy['hashes'] ?? null ) ? $copy['hashes'] : array();
				$opened = is_int( $copy['opened'] ?? null ) ? $copy['opened'] : time();
				self::write( (int) ( $copy['customer_id'] ?? 0 ), $hashes, array(), $opened, true );
				return;
			}
			$record = self::stored();
			if ( null !== $record && $record['hashes'] && empty( $record['ordered'] ) ) {
				$record['ordered'] = true;
				self::store( $record );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * R2: which of an account customer's names and location fields hold the link's values, by the
	 * matching rule above, against the live copy's hashes (`$live`), the record (ordered or not) and
	 * each of its `account` sets, one source at a time (a location's recorded fields all come from
	 * one write). The record's `customer_id` is not read: a guest's link still counts after the guest
	 * logs in, the direction that keeps the link's values out of the account.
	 *
	 * - A name (`billing_first_name` ... `shipping_last_name`) is the link's when it holds the value
	 *   the link wrote for that name in either location.
	 * - A location is the link's, all six fields, when it holds every recorded field of either
	 *   location the link wrote (enough() of them; its own country for state and postcode).
	 *
	 * Either location, because Woo copies one to the other: the classic checkout's shipping address
	 * is the billing one unless "Ship to a different address" is checked, and the Checkout block's
	 * billing address is the shipping one while "Use same address for billing" is. Values are
	 * compared canonical (canonical()): both checkouts reformat postcode and state, and the Store API
	 * encodes entities. Anything else is not the link's, a location the shopper edited included.
	 * Never throws; on a failure, nothing is the link's.
	 *
	 * @param object                $customer The account customer about to be saved.
	 * @param array<string, string> $live     The live copy's hashes.
	 * @return string[] Field names.
	 */
	public static function links_fields( $customer, array $live = array() ) {
		try {
			$sources = array();
			$live    = array_filter(
				$live,
				static function ( $hash, $key ) {
					return self::is_key( $key ) && is_string( $hash ) && 1 === preg_match( '/^[0-9a-f]{' . self::HASH_CHARS . '}\z/', $hash );
				},
				ARRAY_FILTER_USE_BOTH
			);
			if ( $live ) {
				$sources[] = $live;
			}
			$record = self::stored();
			if ( null !== $record ) {
				$sources[] = $record['hashes'];
				foreach ( $record['account'] ?? array() as $set ) {
					$sources[] = $set;
				}
			}
			$fields = array();
			foreach ( $sources as $hashes ) {
				$groups = self::grouped( $hashes );
				foreach ( array_keys( self::types() ) as $type ) {
					foreach ( array( 'first_name', 'last_name' ) as $name ) {
						$value = self::read( $customer, $type . '_' . $name );
						foreach ( array_keys( self::types() ) as $source ) {
							$key = $source . '_' . $name;
							if ( null !== $value && isset( $hashes[ $key ] ) && self::holds( array( $key => $value ), array( $key => $hashes[ $key ] ) ) ) {
								$fields[] = $type . '_' . $name;
								break;
							}
						}
					}
					foreach ( array_keys( self::types() ) as $source ) {
						$entries = $groups[ $source ] ?? array();
						if ( ! $entries || ! self::enough( $entries ) ) {
							continue;
						}
						$values = array();
						foreach ( self::LOCATION as $name ) {
							$value = self::read( $customer, $type . '_' . $name );
							if ( null !== $value ) {
								$values[ $source . '_' . $name ] = $value;
							}
						}
						if ( self::holds( $values, $entries ) ) {
							$fields = array_merge( $fields, self::location_fields( $type ) );
							break;
						}
					}
				}
			}
			return array_values( array_unique( $fields ) );
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * R1, an account needed after the open (pl7-plan.md section 5.2): whether an email is one a link
	 * wrote in this session, by its hash in the live copy's hashes (`$live`), the record (ordered or
	 * not) or any `account` set. Canonical, so case and spacing do not matter. False for `''`, a value
	 * that is not a string, and on any failure. Never throws.
	 *
	 * @param mixed                 $email An email.
	 * @param array<string, string> $live  The live copy's hashes.
	 * @return bool
	 */
	public static function links_email( #[\SensitiveParameter] $email, array $live = array() ) {
		return '' !== self::email_source( $email, $live );
	}

	/**
	 * Where links_email() found the email: `copy`, `record` or `account`, or `''` when it is not a
	 * link's. The copy is checked first, then the record, then the `account` sets. With no copy hash
	 * and no record it returns `''` before hashing (one session read). Never throws.
	 *
	 * @param mixed                 $email An email.
	 * @param array<string, string> $live  The live copy's hashes.
	 * @return string
	 */
	public static function email_source( #[\SensitiveParameter] $email, array $live = array() ) {
		try {
			if ( ! is_string( $email ) || '' === trim( $email ) ) {
				return '';
			}
			// Nothing to match (no live hash, no record): return before hashing, the common case.
			$record = self::stored();
			if ( ! is_string( $live['billing_email'] ?? null ) && null === $record ) {
				return '';
			}
			$hash = self::hash( 'billing_email', self::canonical( 'billing_email', $email ) );
			if ( is_string( $live['billing_email'] ?? null ) && hash_equals( $live['billing_email'], $hash ) ) {
				return 'copy';
			}
			if ( null === $record ) {
				return '';
			}
			if ( is_string( $record['hashes']['billing_email'] ?? null ) && hash_equals( $record['hashes']['billing_email'], $hash ) ) {
				return 'record';
			}
			foreach ( $record['account'] ?? array() as $set ) {
				if ( is_string( $set['billing_email'] ?? null ) && hash_equals( $set['billing_email'], $hash ) ) {
					return 'account';
				}
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return '';
	}

	/**
	 * A hash set without the `billing_email` entry when it is this email's hash; as it was otherwise.
	 *
	 * @param array<string, string> $hashes Field => hash.
	 * @param string                $email  An email.
	 * @return array<string, string>
	 */
	public static function without_email( array $hashes, #[\SensitiveParameter] $email ) {
		if ( ! is_string( $email ) || ! is_string( $hashes['billing_email'] ?? null ) ) {
			return $hashes;
		}
		if ( hash_equals( $hashes['billing_email'], self::hash( 'billing_email', self::canonical( 'billing_email', $email ) ) ) ) {
			unset( $hashes['billing_email'] );
		}
		return $hashes;
	}

	/**
	 * R1: uses up a link's email after the registration check refused it once
	 * (Urumi_Agentic_Handoff_Cart::registration_errors()). Removes from the record and its `account`
	 * sets only the `billing_email` entries whose hash is this email's. stored()'s rules still hold
	 * afterwards: a set left empty is dropped, and a record left with no `hashes` and no `account` is
	 * cleared. Another link's different email stays. Never throws.
	 *
	 * @param mixed $email An email.
	 * @return void
	 */
	public static function forget_email( #[\SensitiveParameter] $email ) {
		try {
			$session = self::session();
			$record  = self::stored();
			if ( null === $session || null === $record || ! is_string( $email ) || '' === trim( $email ) ) {
				return;
			}
			$hashes = self::without_email( $record['hashes'], $email );
			$sets   = array();
			foreach ( $record['account'] ?? array() as $set ) {
				$set = self::without_email( $set, $email );
				if ( $set ) {
					$sets[] = $set;
				}
			}
			$account = $record['account'] ?? array();
			if ( $record['hashes'] === $hashes && $account === $sets ) {
				return;
			}
			if ( ! $hashes && ! $sets ) {
				$session->set( self::SESSION_KEY, null );
				return;
			}
			$record['hashes'] = $hashes;
			if ( ! isset( $hashes['billing_email'] ) ) {
				$record['reviewed'] = array_values( array_diff( $record['reviewed'], array( 'billing_email' ) ) );
			}
			unset( $record['account'] );
			if ( $sets ) {
				$record['account'] = $sets;
			}
			self::store( $record );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Puts back every traced field or location the customer now holds with the link's value; nothing
	 * is spent. Saves only when asked. Never throws.
	 *
	 * @param object $customer The session customer.
	 * @param bool   $save     Whether to save it.
	 * @return string[] The keys put back.
	 */
	public static function put_back( $customer, $save ) {
		try {
			$record = self::record();
			if ( null === $record ) {
				return array();
			}
			$plan = self::plan( $customer, $record, array() );
			if ( $plan['keys'] ) {
				Urumi_Agentic_Handoff_Cart::restore( $customer, $plan['id'], $plan['values'], $save );
			}
			return $plan['keys'];
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * Which entries match the customer now, and the values that put them back. An entry is a field
	 * (the names, the email, the phones) or a location (`billing`, `shipping`): a location matches
	 * when it has enough recorded fields (enough()) and every one of them holds the link's value now,
	 * whatever its other fields hold. The pre-link value is the account's saved one for a logged-in
	 * customer (read from user meta now, through a fresh WC_Customer; `""` when it can't be read) and
	 * `""` for a guest; a location's is all six fields. It acts on whoever the session customer is
	 * now: a guest who logged in keeps the session, and the link's values are still not theirs. An
	 * entry whose pre-link value matches by the same rule (the account's own value is the link's)
	 * does not match: there is nothing to put back or name.
	 *
	 * @param object   $customer The session customer.
	 * @param array    $record   From record().
	 * @param string[] $skip     Entries left out.
	 * @return array{id: int, keys: string[], values: array<string, string>}
	 */
	private static function plan( $customer, array $record, array $skip ) {
		$id      = is_callable( array( $customer, 'get_id' ) ) ? $customer->get_id() : null;
		$id      = is_int( $id ) && $id > 0 ? $id : 0;
		$keys    = array();
		$values  = array();
		$account = false;
		foreach ( self::grouped( $record['hashes'] ) as $key => $entries ) {
			$location = isset( self::types()[ $key ] );
			if ( in_array( $key, $skip, true ) || ( $location && ! self::enough( $entries ) ) || ! self::holds( self::values_of( $customer, $entries ), $entries ) ) {
				continue;
			}
			if ( $id > 0 && false === $account ) {
				$account = self::account( $id );
			}
			$restore = array();
			foreach ( $location ? self::location_fields( $key ) : array( $key ) as $field ) {
				$restore[ $field ] = $id > 0 && null !== $account ? (string) ( self::read( $account, $field ) ?? '' ) : '';
			}
			if ( self::holds( $restore, $entries ) ) {
				continue;
			}
			$keys[] = $key;
			$values = array_merge( $values, $restore );
		}
		return array(
			'id'     => $id,
			'keys'   => $keys,
			'values' => $values,
		);
	}

	/**
	 * What a put-back took out, for the one-shot line: a location or a shipping field is delivery, a
	 * billing field contact.
	 *
	 * @param string[] $keys Keys put back.
	 * @return string[]
	 */
	private static function kinds_of( array $keys ) {
		$kinds = array();
		foreach ( $keys as $key ) {
			$kinds[] = isset( self::types()[ $key ] ) || 0 === strpos( $key, 'shipping_' ) ? self::REVERTED_DELIVERY : self::REVERTED_CONTACT;
		}
		return array_values( array_unique( $kinds ) );
	}

	/**
	 * One value in the form both sides are compared in. Every value: HTML entities decoded (the Store
	 * API runs wp_kses() over addresses, which turns `&` into `&amp;`), trimmed, inner whitespace
	 * collapsed. Then by field: email and country lowercased; state turned from a name into its code
	 * where the country has a list (the Store API does that) and lowercased; postcode uppercased with
	 * spaces and hyphens removed (wc_format_postcode() adds them), and Latvia's `LV` prefix dropped;
	 * phone reduced to its digits and a leading `+`.
	 *
	 * @param string $field   A field name (with or without its billing_/shipping_ prefix).
	 * @param string $value   The value.
	 * @param string $country The location's country, for state and postcode.
	 * @return string
	 */
	public static function canonical( $field, $value, $country = '' ) {
		$name  = (string) preg_replace( '/^(billing|shipping)_/', '', (string) $field );
		$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$clean = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( null === $clean ? (string) preg_replace( '/\s+/', ' ', $value ) : $clean );
		switch ( $name ) {
			case 'email':
			case 'country':
				return self::lower( $value );
			case 'state':
				return self::lower( self::state_code( $value, $country ) );
			case 'postcode':
				$postcode = (string) preg_replace( '/[\s\-]+/', '', self::upper( $value ) );
				return 'LV' === self::upper( $country ) && 0 === strpos( $postcode, 'LV' ) ? substr( $postcode, 2 ) : $postcode;
			case 'phone':
				return ( 0 === strpos( $value, '+' ) ? '+' : '' ) . preg_replace( '/\D+/', '', $value );
			default:
				return $value;
		}
	}

	/**
	 * The keyed hash of a canonical value: the first HASH_CHARS hex characters of HMAC-SHA256 over the
	 * key and the value, keyed with the site's auth salt.
	 *
	 * @param string $key       Field name.
	 * @param string $canonical From canonical().
	 * @return string
	 */
	private static function hash( $key, $canonical ) {
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return substr( hash_hmac( 'sha256', $key . "\n" . $canonical, $salt . '|' . self::SESSION_KEY ), 0, self::HASH_CHARS );
	}

	/**
	 * Whether every recorded field of an entry holds the link's value in these values (canonical,
	 * then hashed). A field missing from them does not hold.
	 *
	 * @param array<string, string> $values  Field => value, with a location field's country.
	 * @param array<string, string> $entries Field => recorded hash, of one entry.
	 * @return bool
	 */
	private static function holds( #[\SensitiveParameter] array $values, array $entries ) {
		foreach ( $entries as $field => $hash ) {
			$now = self::from_values( $field, $values );
			if ( null === $now || ! hash_equals( $hash, self::hash( $field, $now ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The customer's current values of an entry's fields, and of each location field's country.
	 *
	 * @param object                $customer WC_Customer.
	 * @param array<string, string> $entries  Field => hash.
	 * @return array<string, string>
	 */
	private static function values_of( $customer, array $entries ) {
		$values = array();
		foreach ( array_keys( $entries ) as $field ) {
			$parts = self::location_field( $field );
			foreach ( null === $parts ? array( $field ) : array( $field, $parts[0] . '_country' ) as $name ) {
				$value = self::read( $customer, $name );
				if ( null !== $value ) {
					$values[ $name ] = $value;
				}
			}
		}
		return $values;
	}

	/**
	 * The record's hashes by entry: a field the record keeps on its own under its name, a location
	 * field under `billing` or `shipping`.
	 *
	 * @param array<string, string> $hashes Field => hash.
	 * @return array<string, array<string, string>> Entry => (field => hash).
	 */
	private static function grouped( array $hashes ) {
		$groups = array();
		foreach ( $hashes as $field => $hash ) {
			$parts = self::location_field( (string) $field );
			$groups[ null === $parts ? (string) $field : $parts[0] ][ $field ] = $hash;
		}
		return $groups;
	}

	/**
	 * The evidence rule: whether a location's recorded fields are enough to tell an address, its
	 * `address_1`, or else at least two fields. A country alone, or a state alone, is not: it would
	 * make every address there the link's.
	 *
	 * @param array<string, string> $entries Field => hash, of one location.
	 * @return bool
	 */
	private static function enough( array $entries ) {
		$names = array();
		foreach ( array_keys( $entries ) as $field ) {
			$parts = self::location_field( (string) $field );
			if ( null !== $parts ) {
				$names[ $parts[1] ] = true;
			}
		}
		return isset( $names['address_1'] ) || count( $names ) >= 2;
	}

	/**
	 * The canonical value of one field from field values: a location field's state and postcode with
	 * that location's country from the same values (`""` without it). Null when the value is missing.
	 *
	 * @param string $field  Field name.
	 * @param array  $values Field => value.
	 * @return string|null
	 */
	private static function from_values( $field, #[\SensitiveParameter] array $values ) {
		if ( ! isset( $values[ $field ] ) || ! is_string( $values[ $field ] ) ) {
			return null;
		}
		$parts   = self::location_field( $field );
		$country = null === $parts ? '' : ( $values[ $parts[0] . '_country' ] ?? '' );
		return self::canonical( $field, $values[ $field ], is_string( $country ) ? $country : '' );
	}

	/**
	 * A location field's location and name (`billing_city` => `billing`, `city`), or null.
	 *
	 * @param string $field Field name.
	 * @return array{0: string, 1: string}|null
	 */
	private static function location_field( $field ) {
		foreach ( array_keys( self::types() ) as $type ) {
			$name = substr( $field, strlen( $type ) + 1 );
			if ( 0 === strpos( $field, $type . '_' ) && in_array( $name, self::LOCATION, true ) ) {
				return array( $type, $name );
			}
		}
		return null;
	}

	/**
	 * The record the checkout and the pages act on: null when there is none, it is corrupt, it is
	 * `ordered` (an order used the values; only links_fields() reads it then), or it has no `hashes`
	 * (only `account`). Its `account` is never acted on, only kept when the record is stored again.
	 *
	 * @return array{v: int, customer_id: int, opened: int, named: bool, hashes: array<string, string>, reviewed: string[], reverted: string[], account?: array<int, array<string, string>>}|null
	 */
	private static function record() {
		$record = self::stored();
		return null === $record || ! empty( $record['ordered'] ) || ! $record['hashes'] ? null : $record;
	}

	/**
	 * The record as stored, ordered or not, or null when there is none or it is corrupt (an older
	 * version included). It has `hashes`, or `account`, or both; `account`, when there, is a list of
	 * non-empty field => hash sets.
	 *
	 * @return array{v: int, customer_id: int, opened: int, named: bool, hashes: array<string, string>, reviewed: string[], reverted: string[], ordered?: bool, account?: array<int, array<string, string>>}|null
	 */
	private static function stored() {
		$session = self::session();
		$record  = null === $session ? null : $session->get( self::SESSION_KEY );
		if ( ! is_array( $record ) || self::VERSION !== ( $record['v'] ?? null ) || ! is_int( $record['customer_id'] ?? null ) || ! is_int( $record['opened'] ?? null )
			|| ! is_bool( $record['named'] ?? null ) || ! is_array( $record['hashes'] ?? null )
			|| ! is_array( $record['reviewed'] ?? null ) || ! is_array( $record['reverted'] ?? null )
			|| ( isset( $record['ordered'] ) && ! is_bool( $record['ordered'] ) )
			|| ( array_key_exists( 'account', $record ) && ( ! is_array( $record['account'] ) || ! $record['account'] || ! array_is_list( $record['account'] ) ) )
			|| ( ! $record['hashes'] && ! isset( $record['account'] ) ) ) {
			return null;
		}
		foreach ( $record['account'] ?? array() as $set ) {
			if ( ! is_array( $set ) || ! $set ) {
				return null;
			}
		}
		foreach ( array_merge( array( $record['hashes'] ), $record['account'] ?? array() ) as $set ) {
			foreach ( $set as $key => $hash ) {
				if ( ! self::is_key( $key ) || ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-f]{' . self::HASH_CHARS . '}\z/', $hash ) ) {
					return null;
				}
			}
		}
		foreach ( $record['reviewed'] as $key ) {
			if ( ! is_string( $key ) || ! ( in_array( $key, self::FIELDS, true ) || isset( self::types()[ $key ] ) ) ) {
				return null;
			}
		}
		foreach ( $record['reverted'] as $kind ) {
			if ( self::REVERTED_DELIVERY !== $kind && self::REVERTED_CONTACT !== $kind ) {
				return null;
			}
		}
		return $record;
	}

	/**
	 * Saves the record.
	 *
	 * @param array $record The record.
	 * @return void
	 */
	private static function store( array $record ) {
		$session = self::session();
		if ( null !== $session ) {
			$session->set( self::SESSION_KEY, $record );
		}
	}

	/**
	 * The account's own customer, read from user meta (not the session), or null.
	 *
	 * @param int $id User id.
	 * @return object|null
	 */
	private static function account( $id ) {
		try {
			return class_exists( 'WC_Customer' ) ? new WC_Customer( $id ) : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether a field is one the record may hash: a traced field or a location field.
	 *
	 * @param mixed $key Key.
	 * @return bool
	 */
	private static function is_key( $key ) {
		return is_string( $key ) && ( in_array( $key, self::FIELDS, true ) || null !== self::location_field( $key ) );
	}

	/**
	 * The location keys.
	 *
	 * @return array<string, true>
	 */
	private static function types() {
		return array(
			'billing'  => true,
			'shipping' => true,
		);
	}

	/**
	 * A location's field names.
	 *
	 * @param string $type `billing` or `shipping`.
	 * @return string[]
	 */
	private static function location_fields( $type ) {
		$fields = array();
		foreach ( self::LOCATION as $name ) {
			$fields[] = $type . '_' . $name;
		}
		return $fields;
	}

	/**
	 * A state as its code, when the country has a list of states and the value is one of their names.
	 *
	 * @param string $state   State.
	 * @param string $country Country code.
	 * @return string
	 */
	private static function state_code( $state, $country ) {
		if ( '' === $state || '' === $country ) {
			return $state;
		}
		$woo       = function_exists( 'WC' ) ? WC() : null;
		$countries = is_object( $woo ) ? ( $woo->countries ?? null ) : null;
		$states    = is_object( $countries ) && is_callable( array( $countries, 'get_states' ) ) ? $countries->get_states( self::upper( $country ) ) : null;
		if ( ! is_array( $states ) ) {
			return $state;
		}
		$wanted = self::upper( $state );
		foreach ( $states as $code => $name ) {
			if ( is_string( $name ) && self::upper( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) === $wanted ) {
				return (string) $code;
			}
		}
		return $state;
	}

	/**
	 * One customer field as a string; null when there is no getter or it throws.
	 *
	 * @param object $customer WC_Customer.
	 * @param string $field    Field name.
	 * @return string|null
	 */
	private static function read( $customer, $field ) {
		try {
			if ( ! is_object( $customer ) || ! is_callable( array( $customer, 'get_' . $field ) ) ) {
				return null;
			}
			$value = $customer->{'get_' . $field}();
			return is_scalar( $value ) || null === $value ? (string) $value : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Lowercase, multibyte where available.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function lower( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Uppercase, multibyte where available.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function upper( $value ) {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
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
}
