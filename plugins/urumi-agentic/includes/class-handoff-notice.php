<?php
/**
 * The one-off message after a hand-off link (pl7-plan.md section 5.5).
 *
 * Woo's own session notices are not enough: every Store API call on the Cart
 * and Checkout blocks clears them, so a notice added during the POST can vanish
 * before the shopper sees it. This keeps its own flag in the Woo session and
 * prints it on the classic cart and checkout and in front of the Cart and
 * Checkout blocks.
 *
 * PL-7b adds what the link wrote that the shopper should check: the contact details (name, email,
 * phone) and the delivery address, both on the checkout page only. Those two are never stored here:
 * they are built from the live pre-fill copy on every checkout render (LIVE_FILTER). After the copy
 * ended, the trace (Urumi_Agentic_Handoff_Trace) gives the ended-case lines through the same filter
 * when the link's values are in the session customer again, with a "Remove these details" form, and
 * a one-shot line when it took them out off the checkout. When the link's email was left out because
 * this checkout can create an account, a line asking for the shopper's own comes the same live way (R1);
 * when it was taken out later, at an add to cart with no live copy to carry that line, a stored
 * one-shot line says so.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and prints the hand-off notice.
 */
class Urumi_Agentic_Handoff_Notice {

	const SESSION_KEY = 'urumi_agentic_handoff_notice';
	const TTL         = 600;

	const KIND_REFUSED  = 'refused';
	const KIND_LEFT_OUT = 'left_out';
	// H5: a valid link none of whose lines could be added; the cart was left as it was.
	const KIND_NONE_ADDED = 'none_added';
	// PL-7b: what else a link could not do, and what it did that the shopper should check.
	const KIND_COUPON      = 'coupon';
	const KIND_SHIPPING    = 'shipping_method';
	const KIND_LOGIN_MERGE = 'login_merge';
	const KIND_DELIVERY    = 'delivery_address';
	const KIND_CONTACT     = 'contact_details';
	// R1: the link's email was left out for a guest whose checkout can create an account. A fixed text.
	const KIND_OWN_EMAIL = 'own_email';
	// R1, an account needed after the open: the link's email was taken out at an add to cart while no
	// live copy could carry the own-email line. Stored, one-shot, a fixed text.
	const KIND_OWN_EMAIL_CLEARED = 'own_email_cleared';
	// After the copy ended (Urumi_Agentic_Handoff_Trace): the link's values named again, the date the
	// link was opened (printed inside those two lines only), and what a put-back off the checkout took out.
	const KIND_DELIVERY_ENDED = 'delivery_address_ended';
	const KIND_CONTACT_ENDED  = 'contact_details_ended';
	const KIND_OPENED         = 'opened_on';
	const KIND_REVERTED       = 'details_removed';

	/**
	 * What LIVE_FILTER gives as KIND_OWN_EMAIL's one value (a live kind needs one). Never printed.
	 */
	const OWN_EMAIL_MARKER = 'yes';

	/**
	 * The kinds, in the order they print. The contact details come first after a refusal: they are
	 * the line that names where the order mail and texts will go (T1b). The request for the shopper's
	 * own email follows them, as it is about the same field, and so does the line saying the link's
	 * email was taken out (KIND_OWN_EMAIL_CLEARED). KIND_OPENED is not a line of its own.
	 * A link none of whose lines could be added prints right after a refusal: both say the cart was
	 * not filled from the link.
	 */
	const KINDS = array( self::KIND_REFUSED, self::KIND_NONE_ADDED, self::KIND_REVERTED, self::KIND_CONTACT, self::KIND_OWN_EMAIL, self::KIND_OWN_EMAIL_CLEARED, self::KIND_CONTACT_ENDED, self::KIND_LEFT_OUT, self::KIND_COUPON, self::KIND_SHIPPING, self::KIND_LOGIN_MERGE, self::KIND_DELIVERY, self::KIND_DELIVERY_ENDED );

	/**
	 * Kinds whose values a later add() replaces instead of merging into (one count; one link's names
	 * and the state of the cart then).
	 */
	const REPLACING_KINDS = array( self::KIND_LOGIN_MERGE, self::KIND_NONE_ADDED );

	/**
	 * Kinds printed on the checkout page only, and never stored: they are built again on every
	 * checkout render from the live pre-fill copy, or after it ended from the trace (LIVE_FILTER), so
	 * they show for as long as the link's details can be in the form (T1b), and the own-email line for
	 * as long as the guest's copy lives (R1).
	 */
	const CHECKOUT_ONLY_KINDS = array( self::KIND_DELIVERY, self::KIND_CONTACT, self::KIND_OWN_EMAIL, self::KIND_DELIVERY_ENDED, self::KIND_CONTACT_ENDED, self::KIND_OPENED, self::KIND_REVERTED );

	/**
	 * The ended-case kinds: printed with the "Remove these details" action.
	 */
	const ENDED_KINDS = array( self::KIND_DELIVERY_ENDED, self::KIND_CONTACT_ENDED );

	/**
	 * Filter that gives the checkout-only kinds at render: `array<kind, string[]>`, given `array()` and
	 * the time. Urumi_Agentic_Handoff_Cart::live_lines() is hooked on it.
	 */
	const LIVE_FILTER = 'urumi_agentic_handoff_live_lines';

	const SURFACE_CART     = 'cart';
	const SURFACE_CHECKOUT = 'checkout';

	/**
	 * Stored in place of a product name the shopper can't be told (gone, or not published); the
	 * notice swaps it for translated text when it is printed.
	 */
	const GONE_MARKER = "\x1Furumi-agentic:gone";

	/**
	 * Stored once among the left-out values when a line was in the cart and a store plugin removed it
	 * while a later line was added (Urumi_Agentic_Handoff_Cart::rebuild()); each prints one sentence
	 * after the names, never as a name. Subscriptions' rules first (a subscription and other products,
	 * or two subscriptions), then any other removal. A value starting with \x1F that is not one of these
	 * or GONE_MARKER (a later version's) is not printed.
	 */
	const MIXED_CART_MARKER       = "\x1Furumi-agentic:mixed";
	const ONE_SUBSCRIPTION_MARKER = "\x1Furumi-agentic:one-sub";
	const REMOVED_MARKER          = "\x1Furumi-agentic:removed";

	/**
	 * The reason markers, in the order their sentences print.
	 */
	const REASON_MARKERS = array( self::MIXED_CART_MARKER, self::ONE_SUBSCRIPTION_MARKER, self::REMOVED_MARKER );

	/**
	 * Stored first among KIND_NONE_ADDED's values: what the cart held when the link was opened (or
	 * reopened). The rest are names. Without one, the notice says the cart still holds what it had:
	 * it never says the cart is empty when it may not be.
	 */
	const CART_EMPTY_MARKER = "\x1Furumi-agentic:cart-empty";
	const CART_KEPT_MARKER  = "\x1Furumi-agentic:cart-kept";

	/**
	 * The cart-state markers.
	 */
	const CART_MARKERS = array( self::CART_EMPTY_MARKER, self::CART_KEPT_MARKER );

	const MAX_VALUES = 20;
	const MAX_CHARS  = 120;

	/**
	 * Registers the print hooks. Cheap: nothing runs until a block or a template renders.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'print_classic' ), 5, 0 );
		// The classic cart page shows no `before_cart` for an empty cart (a refused link just emptied it).
		add_action( 'woocommerce_cart_is_empty', array( __CLASS__, 'print_classic' ), 5, 0 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'print_classic_checkout' ), 5, 0 );
		add_filter( 'render_block_woocommerce/cart', array( __CLASS__, 'prepend_to_block' ), 10, 1 );
		add_filter( 'render_block_woocommerce/checkout', array( __CLASS__, 'prepend_to_checkout_block' ), 10, 1 );
	}

	/**
	 * Adds a kind (and values) to the flag, replacing nothing else in it. Best effort: never throws,
	 * so no trace carrying the values (a session handler's own frame) leaves it.
	 *
	 * @param string   $kind   One of KINDS.
	 * @param string[] $values Raw strings, escaped when printed.
	 * @param int      $now    Unix seconds.
	 * @return void
	 */
	public static function add( $kind, #[\SensitiveParameter] array $values, $now ) {
		try {
			$session = self::session();
			if ( null === $session || ! in_array( $kind, self::KINDS, true ) || in_array( $kind, self::CHECKOUT_ONLY_KINDS, true ) ) {
				return;
			}
			$current                   = self::read( $session, (int) $now );
			$clean                     = self::clean_values( $kind, $values );
			$previous                  = in_array( $kind, self::REPLACING_KINDS, true ) ? array() : ( $current['kinds'][ $kind ] ?? array() );
			$current['kinds'][ $kind ] = array_values( array_unique( array_merge( $previous, $clean ) ) );
			$session->set(
				self::SESSION_KEY,
				array(
					'kinds'   => $current['kinds'],
					'expires' => (int) $now + self::TTL,
				)
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Values as a kind stores or prints them: non-empty strings, at most MAX_VALUES, each shortened. The
	 * left-out kind's reason markers go first, so a cut list never keeps names and drops their sentence;
	 * so does the none-added kind's cart marker (only the first one given).
	 *
	 * @param string $kind   One of KINDS.
	 * @param array  $values Raw values.
	 * @return string[]
	 */
	private static function clean_values( $kind, #[\SensitiveParameter] array $values ) {
		if ( self::KIND_LEFT_OUT === $kind || self::KIND_NONE_ADDED === $kind ) {
			$known   = self::KIND_LEFT_OUT === $kind ? self::REASON_MARKERS : self::CART_MARKERS;
			$markers = array();
			$rest    = array();
			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || ! in_array( $value, $known, true ) ) {
					$rest[] = $value;
				} elseif ( ! in_array( $value, $markers, true ) && ( self::KIND_LEFT_OUT === $kind || ! $markers ) ) {
					$markers[] = $value;
				}
			}
			$values = array_merge( $markers, $rest );
		}
		$clean = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== $value && count( $clean ) < self::MAX_VALUES ) {
				$clean[] = self::shorten( $value, self::KIND_CONTACT === $kind || self::KIND_CONTACT_ENDED === $kind );
			}
		}
		return $clean;
	}

	/**
	 * The checkout-only kinds for this render, from LIVE_FILTER, cleaned as add() cleans. Empty when
	 * nothing is hooked or the hook gives something else. Never throws.
	 *
	 * @param int $now Unix seconds.
	 * @return array<string, string[]>
	 */
	public static function live( $now ) {
		try {
			$given = apply_filters( self::LIVE_FILTER, array(), (int) $now );
			$live  = array();
			foreach ( self::CHECKOUT_ONLY_KINDS as $kind ) {
				if ( is_array( $given ) && is_array( $given[ $kind ] ?? null ) ) {
					$values = array_values( array_unique( self::clean_values( $kind, $given[ $kind ] ) ) );
					if ( $values ) {
						$live[ $kind ] = $values;
					}
				}
			}
			return $live;
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * A value cut to MAX_CHARS characters, ending in an ellipsis when it was cut. An email keeps its
	 * domain (`head…@domain`); when the domain alone is too long, the end is kept instead, so a
	 * padded subdomain can never push the real domain out of sight.
	 *
	 * @param string $value    Raw value.
	 * @param bool   $is_email Whether the value may be an email (contact details).
	 * @return string
	 */
	private static function shorten( #[\SensitiveParameter] $value, $is_email ) {
		$mb     = function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' );
		$length = $mb ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length <= self::MAX_CHARS ) {
			return $value;
		}
		$dots = $mb ? "\u{2026}" : '...';
		$room = self::MAX_CHARS - ( $mb ? 1 : 3 );
		$cut  = static function ( $text, $start, $count = null ) use ( $mb ) {
			return $mb ? mb_substr( $text, $start, $count, 'UTF-8' ) : substr( $text, $start, $count );
		};
		if ( $is_email && is_email( $value ) ) {
			$at     = strrpos( $value, '@' );
			$domain = substr( $value, (int) $at );
			$size   = $mb ? mb_strlen( $domain, 'UTF-8' ) : strlen( $domain );
			if ( $size <= $room - 1 ) {
				return $cut( substr( $value, 0, (int) $at ), 0, $room - $size ) . $dots . $domain;
			}
			return $dots . $cut( $value, -$room );
		}
		return $cut( $value, 0, $room ) . $dots;
	}

	/**
	 * The flag's kinds, if there is a live flag, and clears it. Every stored kind prints on the cart and
	 * on the checkout page alike; the checkout-only kinds are never stored (live()).
	 *
	 * @param int $now Unix seconds.
	 * @return array<string, string[]> Kind => values.
	 */
	public static function take( $now ) {
		$session = self::session();
		if ( null === $session ) {
			return array();
		}
		$current = self::read( $session, (int) $now );
		if ( null !== $session->get( self::SESSION_KEY ) ) {
			$session->set( self::SESSION_KEY, null );
		}
		return $current['kinds'];
	}

	/**
	 * The plain paragraphs for some kinds. Every stored value is escaped here, once.
	 *
	 * @param array<string, string[]> $kinds Kind => values.
	 * @return string[] HTML paragraphs' inner HTML.
	 */
	public static function paragraphs( array $kinds ) {
		$out = array();
		foreach ( self::KINDS as $kind ) {
			if ( ! isset( $kinds[ $kind ] ) || ! is_array( $kinds[ $kind ] ) ) {
				continue;
			}
			if ( self::KIND_CONTACT === $kind && $kinds[ $kind ] ) {
				$out[] = sprintf(
					/* translators: %s: name, email and phone, comma-separated. Already escaped. */
					esc_html__( 'Contact details from your assistant: %s. Check them before you pay.', 'urumi-agentic' ),
					implode( ', ', array_map( 'esc_html', $kinds[ $kind ] ) )
				);
			} elseif ( self::KIND_OWN_EMAIL === $kind ) {
				$out[] = esc_html__( 'Enter your own email address: this checkout can create an account with it.', 'urumi-agentic' );
			} elseif ( self::KIND_OWN_EMAIL_CLEARED === $kind ) {
				$out[] = esc_html__( 'We removed the email address your assistant\'s link filled in, because this order can now create an account. Enter your email address to continue.', 'urumi-agentic' );
			} elseif ( self::KIND_REFUSED === $kind ) {
				$out[] = esc_html__( 'We couldn\'t open the link from your assistant (it may have expired or is not valid for this store), so your cart was not filled from it. Ask your assistant for a new link.', 'urumi-agentic' );
			} elseif ( self::KIND_NONE_ADDED === $kind ) {
				$out[] = self::none_added_paragraph( $kinds[ $kind ] );
			} elseif ( self::KIND_LEFT_OUT === $kind && $kinds[ $kind ] ) {
				$paragraph = self::left_out_paragraph( $kinds[ $kind ] );
				if ( '' !== $paragraph ) {
					$out[] = $paragraph;
				}
			} elseif ( self::KIND_COUPON === $kind && $kinds[ $kind ] ) {
				$out[] = sprintf(
					/* translators: %s: the coupon code from the assistant. Already escaped. */
					esc_html__( 'The code %s couldn\'t be applied.', 'urumi-agentic' ),
					implode( ', ', array_map( 'esc_html', $kinds[ $kind ] ) )
				);
			} elseif ( self::KIND_SHIPPING === $kind ) {
				$out[] = esc_html__( 'The delivery option your assistant chose isn\'t available for this address, so the store\'s default option was selected.', 'urumi-agentic' );
			} elseif ( self::KIND_LOGIN_MERGE === $kind ) {
				$count = 0;
				foreach ( $kinds[ $kind ] as $value ) {
					if ( 1 === preg_match( '/^[0-9]{1,6}\z/', $value ) ) {
						$count = max( $count, (int) $value );
					}
				}
				if ( $count > 0 ) {
					$out[] = esc_html(
						sprintf(
							/* translators: %d: number of items. */
							_n(
								'%d item saved in your account was added to your cart; remove it if you don\'t want it.',
								'%d items saved in your account were added to your cart; remove any you don\'t want.',
								$count,
								'urumi-agentic'
							),
							$count
						)
					);
				}
			} elseif ( self::KIND_DELIVERY === $kind && $kinds[ $kind ] ) {
				$out[] = sprintf(
					/* translators: %s: name, town and postcode, country. Already escaped. */
					esc_html__( 'Delivery address from your assistant: %s. Check it before you pay.', 'urumi-agentic' ),
					implode( ', ', array_map( 'esc_html', $kinds[ $kind ] ) )
				);
			} elseif ( in_array( $kind, self::ENDED_KINDS, true ) && $kinds[ $kind ] ) {
				$out[] = self::ended_paragraph( $kind, $kinds[ $kind ], $kinds[ self::KIND_OPENED ][0] ?? '' );
			} elseif ( self::KIND_REVERTED === $kind && $kinds[ $kind ] ) {
				$delivery = in_array( Urumi_Agentic_Handoff_Trace::REVERTED_DELIVERY, $kinds[ $kind ], true );
				$contact  = in_array( Urumi_Agentic_Handoff_Trace::REVERTED_CONTACT, $kinds[ $kind ], true );
				if ( $delivery && $contact ) {
					$out[] = esc_html__( 'We removed delivery and contact details that an earlier link from your assistant had filled in. Enter your own.', 'urumi-agentic' );
				} elseif ( $delivery ) {
					$out[] = esc_html__( 'We removed delivery details that an earlier link from your assistant had filled in. Enter your own.', 'urumi-agentic' );
				} elseif ( $contact ) {
					$out[] = esc_html__( 'We removed contact details that an earlier link from your assistant had filled in. Enter your own.', 'urumi-agentic' );
				}
			}
		}
		return $out;
	}

	/**
	 * The names among stored values, escaped: GONE_MARKER as translated text, and no other value starting
	 * with \x1F (a marker, known or a later version's).
	 *
	 * @param string[] $values Stored values.
	 * @return string[] HTML.
	 */
	private static function names( array $values ) {
		$names = array();
		foreach ( $values as $value ) {
			$value = (string) $value;
			if ( self::GONE_MARKER === $value ) {
				$names[] = esc_html__( 'an item that is no longer available', 'urumi-agentic' );
			} elseif ( "\x1F" !== substr( $value, 0, 1 ) ) {
				$names[] = esc_html( $value );
			}
		}
		return $names;
	}

	/**
	 * The none-added line: that nothing from the link could be added, the names when there are any
	 * (none for a link with no lines, or on a reopen), and what the cart holds. Only CART_EMPTY_MARKER
	 * says the cart is empty; with any other marker or none, the cart still holds what it had.
	 *
	 * @param string[] $values Stored values.
	 * @return string HTML.
	 */
	private static function none_added_paragraph( array $values ) {
		$names = self::names( $values );
		$html  = $names
			? sprintf(
				/* translators: %s: comma-separated names. Already escaped. */
				esc_html__( 'None of the items from your assistant\'s link could be added (%s).', 'urumi-agentic' ),
				implode( ', ', $names )
			)
			: esc_html__( 'None of the items from your assistant\'s link could be added.', 'urumi-agentic' );
		$empty = in_array( self::CART_EMPTY_MARKER, $values, true ) && ! in_array( self::CART_KEPT_MARKER, $values, true );
		return $html . ' ' . ( $empty
			? esc_html__( 'Your cart is empty.', 'urumi-agentic' )
			: esc_html__( 'Your cart still holds the items you had before, not the ones from the link.', 'urumi-agentic' ) );
	}

	/**
	 * The left-out line: the names (GONE_MARKER as translated text), then one sentence per reason marker
	 * stored, in REASON_MARKERS order. Any other value starting with \x1F is not printed. '' when no name
	 * is left: a reason alone says nothing.
	 *
	 * @param string[] $values Stored values.
	 * @return string HTML.
	 */
	private static function left_out_paragraph( array $values ) {
		$names = self::names( $values );
		if ( ! $names ) {
			return '';
		}
		$html = sprintf(
			/* translators: %s: comma-separated names. Already escaped. */
			esc_html__( 'Some items from your assistant couldn\'t be added to your cart: %s.', 'urumi-agentic' ),
			implode( ', ', $names )
		);
		$sentences = array(
			self::MIXED_CART_MARKER       => __( 'Your cart can hold either subscriptions or other products, not both.', 'urumi-agentic' ),
			self::ONE_SUBSCRIPTION_MARKER => __( 'Your cart can hold only one subscription at a time.', 'urumi-agentic' ),
			self::REMOVED_MARKER          => __( 'The store removed some of them when other items were added.', 'urumi-agentic' ),
		);
		foreach ( self::REASON_MARKERS as $marker ) {
			if ( in_array( $marker, $values, true ) ) {
				$html .= ' ' . esc_html( $sentences[ $marker ] );
			}
		}
		return $html;
	}

	/**
	 * One ended-case line: the delivery address or the contact details a link filled in, after its
	 * pre-fill copy ended, with the date it was opened.
	 *
	 * @param string   $kind   KIND_DELIVERY_ENDED or KIND_CONTACT_ENDED.
	 * @param string[] $values Raw values.
	 * @param string   $date   The date the link was opened, raw; '' when unknown.
	 * @return string HTML.
	 */
	private static function ended_paragraph( $kind, array $values, $date ) {
		$list = implode( ', ', array_map( 'esc_html', $values ) );
		if ( '' === $date ) {
			return self::KIND_DELIVERY_ENDED === $kind
				/* translators: %s: name, town and postcode, country. Already escaped. */
				? sprintf( esc_html__( 'Delivery address filled in by an earlier link from your assistant: %s. Check it before you pay.', 'urumi-agentic' ), $list )
				/* translators: %s: name, email and phone, comma-separated. Already escaped. */
				: sprintf( esc_html__( 'Contact details filled in by an earlier link from your assistant: %s. Check them before you pay.', 'urumi-agentic' ), $list );
		}
		return self::KIND_DELIVERY_ENDED === $kind
			/* translators: 1: the date the link was opened, 2: name, town and postcode, country. Already escaped. */
			? sprintf( esc_html__( 'Delivery address filled in by a link your assistant sent on %1$s: %2$s. Check it before you pay.', 'urumi-agentic' ), esc_html( $date ), $list )
			/* translators: 1: the date the link was opened, 2: name, email and phone, comma-separated. Already escaped. */
			: sprintf( esc_html__( 'Contact details filled in by a link your assistant sent on %1$s: %2$s. Check them before you pay.', 'urumi-agentic' ), esc_html( $date ), $list );
	}

	/**
	 * Classic cart (and its empty-cart page): prints Woo's info notice markup.
	 *
	 * @return void
	 */
	public static function print_classic() {
		$html = self::html( 'classic', self::SURFACE_CART );
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html()'d parts.
		}
	}

	/**
	 * Classic checkout: prints Woo's info notice markup, checkout-only kinds included.
	 *
	 * @return void
	 */
	public static function print_classic_checkout() {
		$html = self::html( 'classic', self::SURFACE_CHECKOUT );
		if ( '' !== $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html()'d parts.
		}
	}

	/**
	 * Cart block: puts the notice in front of the block's own markup, as a sibling, so React
	 * hydrating the block does not remove it.
	 *
	 * @param mixed $content The block's HTML.
	 * @return mixed
	 */
	public static function prepend_to_block( $content ) {
		$html = self::html( 'blocks', self::SURFACE_CART );
		return ( '' !== $html && is_string( $content ) ) ? $html . $content : $content;
	}

	/**
	 * Checkout block: as prepend_to_block(), checkout-only kinds included.
	 *
	 * @param mixed $content The block's HTML.
	 * @return mixed
	 */
	public static function prepend_to_checkout_block( $content ) {
		$html = self::html( 'blocks', self::SURFACE_CHECKOUT );
		return ( '' !== $html && is_string( $content ) ) ? $html . $content : $content;
	}

	/**
	 * The notice's HTML for one checkout style: what the flag holds, taken (cleared), and on the
	 * checkout page the live checkout-only kinds too, which are built again on every render; '' when
	 * there is nothing. With an ended-case line, the "Remove these details" form follows the lines.
	 *
	 * @param string $style   `classic` or `blocks`.
	 * @param string $surface SURFACE_CART or SURFACE_CHECKOUT.
	 * @return string
	 */
	public static function html( $style, $surface = self::SURFACE_CHECKOUT ) {
		try {
			$now   = time();
			$kinds = self::take( $now );
			if ( self::SURFACE_CHECKOUT === $surface ) {
				$kinds = self::live( $now ) + $kinds;
			}
			$paragraphs = self::paragraphs( $kinds );
			$form       = array_intersect_key( $kinds, array_flip( self::ENDED_KINDS ) ) && class_exists( 'Urumi_Agentic_Handoff_Trace' ) ? Urumi_Agentic_Handoff_Trace::remove_form() : '';
		} catch ( Throwable $e ) {
			return '';
		}
		if ( ! $paragraphs ) {
			return '';
		}
		if ( 'blocks' === $style ) {
			return '<div class="wc-block-components-notice-banner is-info urumi-agentic-notice" role="status"><div class="wc-block-components-notice-banner__content">'
				. '<p>' . implode( '</p><p>', $paragraphs ) . '</p>' . $form . '</div></div>';
		}
		return '<div class="woocommerce-info urumi-agentic-notice" role="status"><p>' . implode( '</p><p>', $paragraphs ) . '</p>' . $form . '</div>';
	}

	/**
	 * The live flag, or an empty one.
	 *
	 * @param object $session Woo session.
	 * @param int    $now     Unix seconds.
	 * @return array{kinds: array<string, string[]>, expires: int}
	 */
	private static function read( $session, $now ) {
		$stored  = $session->get( self::SESSION_KEY );
		$kinds   = array();
		$expires = 0;
		if ( is_array( $stored ) && is_int( $stored['expires'] ?? null ) && $now < $stored['expires'] && is_array( $stored['kinds'] ?? null ) ) {
			$expires = $stored['expires'];
			foreach ( self::KINDS as $kind ) {
				// A checkout-only kind in a flag stored by an earlier version is not printed: those are live.
				if ( ! in_array( $kind, self::CHECKOUT_ONLY_KINDS, true ) && isset( $stored['kinds'][ $kind ] ) && is_array( $stored['kinds'][ $kind ] ) ) {
					$kinds[ $kind ] = array_values( array_filter( $stored['kinds'][ $kind ], 'is_string' ) );
				}
			}
		}
		return array(
			'kinds'   => $kinds,
			'expires' => $expires,
		);
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
