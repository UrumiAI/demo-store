<?php
/**
 * The hand-off hint: what PL-7 remembers in the Woo session so PL-3 can tag
 * the order `handoff` (contract.md section 5, step 4).
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps one hand-off hint in the shopper's Woo session.
 *
 * The hint is bound to the cart lines the hand-off link added, so it tags an
 * order only while the order still holds at least one of them. It lasts at
 * most four hours, is cleared when the cart is emptied, and is used up once
 * the tagged order has left draft.
 *
 * PL-7 rules:
 *   - call remember() after the cart is rebuilt, with only the items actually added;
 *   - when remember() returns false, still redirect to checkout as usual. The
 *     order just is not tagged `handoff`. Never show an error and never block.
 */
class Urumi_Agentic_Handoff_Hint {

	const SESSION_KEY = 'urumi_agentic_handoff';
	const VERSION     = 1;
	const TTL         = 4 * HOUR_IN_SECONDS;
	const MAX_ITEMS   = 100;

	/**
	 * The pre-fill copy the hand-off keeps for the login re-apply (PL-7b, Urumi_Agentic_Handoff_Cart).
	 * It lives and dies with the hint: forget() ends it too.
	 */
	const PREFILL_SESSION_KEY = 'urumi_agentic_handoff_prefill';

	/**
	 * Fired by forget() while a pre-fill copy is in the session, with the reason. The cart listens and
	 * ends the copy (Urumi_Agentic_Handoff_Cart::end_copy()), which puts the customer back unless an
	 * order used it. This class never calls the cart itself.
	 */
	const FORGET_ACTION = 'urumi_agentic_handoff_forget';

	/**
	 * Why a hand-off ended (forget()'s reason). Only END_ORDER_PLACED keeps what the link wrote to the
	 * customer: the order used it. A refused link has no ending of its own: it empties a cart a hand-off
	 * built (END_CART_EMPTIED) and otherwise changes nothing.
	 */
	const END_ORDER_PLACED = 'order_placed';
	const END_CART_EMPTIED = 'cart_emptied';
	const END_FORGOTTEN    = 'forgotten';

	const CHANNEL_PATTERN = '/^[a-z][a-z0-9_]{0,31}\z/';
	const SESSION_PATTERN = '/^[A-Za-z0-9_-]{1,128}\z/';

	/**
	 * Returns the current Unix time. Injected so tests can pin it.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Builds a hint store over WC()->session.
	 *
	 * @param callable|null $clock Returns Unix seconds.
	 */
	public function __construct( $clock = null ) {
		$this->clock = $clock ? $clock : 'time';
	}

	/**
	 * Remembers a hand-off for the current shopper.
	 *
	 * @param string $channel    Channel, e.g. `chatgpt`.
	 * @param string $session_id The service's session id.
	 * @param array  $items      Lines added: `product_id` (int > 0) and `variation_id` (int >= 0) each.
	 * @param int    $expires_at The token's expiry, Unix seconds.
	 * @return bool False for bad input, an expired token or no Woo session.
	 */
	public function remember( string $channel, string $session_id, array $items, int $expires_at ): bool {
		$items = self::valid_items( $items );
		if ( null === $items || ! self::valid_ids( $channel, $session_id ) ) {
			return false;
		}

		$opened_at = (int) call_user_func( $this->clock );
		$expires   = min( $opened_at + self::TTL, $expires_at );
		if ( $expires <= $opened_at ) {
			return false;
		}

		$session = self::session();
		if ( null === $session ) {
			return false;
		}
		// A fresh guest has no session cookie yet, and Woo drops the data at
		// the end of the request unless it has one.
		if ( method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
			$session->set_customer_session_cookie( true );
		}

		$session->set(
			self::SESSION_KEY,
			array(
				'v'          => self::VERSION,
				'channel'    => $channel,
				'session_id' => $session_id,
				'items'      => $items,
				'opened_at'  => $opened_at,
				'expires_at' => $expires,
			)
		);
		return true;
	}

	/**
	 * The current hint, or null when there is none, it is corrupt or it has expired.
	 *
	 * @param int $now Unix seconds.
	 * @return array|null
	 */
	public function get( int $now ): ?array {
		$session = self::session();
		if ( null === $session ) {
			return null;
		}
		$hint = $session->get( self::SESSION_KEY );
		if ( ! is_array( $hint )
			|| self::VERSION !== ( $hint['v'] ?? null )
			|| ! is_string( $hint['channel'] ?? null )
			|| ! is_string( $hint['session_id'] ?? null )
			|| ! self::valid_ids( $hint['channel'], $hint['session_id'] )
			|| ! is_array( $hint['items'] ?? null )
			|| null === self::valid_items( $hint['items'] )
			|| ! is_int( $hint['opened_at'] ?? null )
			|| ! is_int( $hint['expires_at'] ?? null )
		) {
			return null;
		}
		if ( $hint['expires_at'] > $hint['opened_at'] + self::TTL || $now >= $hint['expires_at'] ) {
			return null;
		}
		return $hint;
	}

	/**
	 * Whether the order still holds at least one of the hint's items.
	 *
	 * An item with a `variation_id` matches only a line with that exact
	 * variation; another variation of the same parent does not match. An item
	 * with `variation_id` 0 matches a line with that product id (the parent,
	 * for a variation line).
	 *
	 * @param WC_Order $order The order being tagged.
	 * @param array    $hint  A hint from get().
	 * @return bool
	 */
	public function matches( WC_Order $order, array $hint ): bool {
		$items = $hint['items'] ?? array();
		if ( ! is_array( $items ) || ! $items ) {
			return false;
		}

		$products   = array();
		$variations = array();
		foreach ( $order->get_items() as $line ) {
			if ( ! is_object( $line ) || ! method_exists( $line, 'get_product_id' ) || ! method_exists( $line, 'get_variation_id' ) ) {
				continue;
			}
			$products[ (int) $line->get_product_id() ]     = true;
			$variations[ (int) $line->get_variation_id() ] = true;
		}
		unset( $variations[0] );

		foreach ( $items as $item ) {
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			if ( $variation_id > 0 ? isset( $variations[ $variation_id ] ) : isset( $products[ (int) ( $item['product_id'] ?? 0 ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Clears the hint, and with it the hand-off's pre-fill copy (PL-7b): both go when the hint is
	 * used up or forgotten. While there is a copy, FORGET_ACTION fires first with the reason, so the
	 * cart can put the customer back; whatever a listener does, the copy is gone afterwards. Does
	 * nothing without a Woo session.
	 *
	 * @param string $reason One of the END_* constants.
	 * @return void
	 */
	public function forget( string $reason = self::END_FORGOTTEN ): void {
		$session = self::session();
		if ( null !== $session ) {
			$session->set( self::SESSION_KEY, null );
			if ( null !== $session->get( self::PREFILL_SESSION_KEY ) ) {
				try {
					do_action( self::FORGET_ACTION, $reason );
				} catch ( Throwable $e ) {
					unset( $e );
				}
				if ( null !== $session->get( self::PREFILL_SESSION_KEY ) ) {
					$session->set( self::PREFILL_SESSION_KEY, null );
				}
			}
		}
	}

	/**
	 * WC()->session, or null when Woo or its session is not loaded.
	 *
	 * @return object|null
	 */
	private static function session() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		return is_object( $session ) ? $session : null;
	}

	/**
	 * Whether the channel and session id have the allowed shape.
	 *
	 * @param string $channel    Channel.
	 * @param string $session_id Session id.
	 * @return bool
	 */
	private static function valid_ids( $channel, $session_id ) {
		return 1 === preg_match( self::CHANNEL_PATTERN, $channel ) && 1 === preg_match( self::SESSION_PATTERN, $session_id );
	}

	/**
	 * The items cut down to their two ids, or null when any is invalid.
	 *
	 * @param array $items Items.
	 * @return array<int, array{product_id: int, variation_id: int}>|null
	 */
	private static function valid_items( array $items ) {
		if ( ! $items || count( $items ) > self::MAX_ITEMS ) {
			return null;
		}
		$valid = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item )
				|| ! is_int( $item['product_id'] ?? null ) || $item['product_id'] <= 0
				|| ! is_int( $item['variation_id'] ?? null ) || $item['variation_id'] < 0
			) {
				return null;
			}
			$valid[] = array(
				'product_id'   => $item['product_id'],
				'variation_id' => $item['variation_id'],
			);
		}
		return $valid;
	}
}
