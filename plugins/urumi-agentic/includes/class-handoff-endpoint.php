<?php
/**
 * The hand-off link's request handling (contract.md section 5; pl7-plan.md section 2).
 *
 * GET and HEAD answer a static page at `init` (before Woo starts a session); the
 * POST runs at `wp_loaded` (after the cart is loaded, before Woo's form handler).
 * Both end the request. The decisions are in methods that return a response array
 * and send nothing, so unit tests can run them; send() does the output.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routing, the same-origin check, the POST flow, the outcome log and the redirect.
 */
class Urumi_Agentic_Handoff_Endpoint {

	const QUERY_ARG    = 'urumi_handoff';
	const SESSION_USED = 'urumi_agentic_handoff_used';

	/**
	 * Hashes of opened tokens are kept in the session this long past the token's expiry,
	 * so Back + Continue still lands on checkout.
	 */
	const USED_GRACE = 86400;
	const USED_MAX   = 20;

	const LOG_SOURCE = 'urumi-agentic';

	/**
	 * The token the request carried, moved out of $_POST at file load. Never logged.
	 *
	 * @var string|null
	 */
	private static $token = null;

	/**
	 * Moves the POSTed token out of $_POST and $_REQUEST, as early as the plugin's file
	 * loads, so plugins that load after this one and WordPress's rebuilt $_REQUEST never
	 * see it. This does not hide it from what runs earlier (pl7-plan.md section 2.3):
	 * single use is the control.
	 *
	 * @return void
	 */
	public static function capture_token() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- an unauthenticated link, checked by Origin and by the token itself; the token is opened, not sanitized.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '1' !== ( $_GET[ self::QUERY_ARG ] ?? null ) ) {
			return;
		}
		if ( ! self::is_handoff_request( $_SERVER, $_GET ) ) {
			return;
		}
		$value = $_POST['token'] ?? null;
		if ( is_string( $value ) && strlen( $value ) <= Urumi_Agentic_Handoff_Token::MAX_TOKEN_CHARS ) {
			self::$token = $value;
		}
		unset( $_POST['token'], $_REQUEST['token'] );
		// phpcs:enable
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		// After after_setup_theme (no early translation notice), before Woo's own init at 0
		// starts the session.
		add_action( 'init', array( __CLASS__, 'on_init' ), -1, 0 );
		// After the cart is loaded from the session (10), before Woo's form handler (20).
		add_action( 'wp_loaded', array( __CLASS__, 'on_wp_loaded' ), 15, 0 );
	}

	/**
	 * Whether this request is for the hand-off URL: `?urumi_handoff=1` at the home path, on a
	 * front-end request. Other query parameters are allowed.
	 *
	 * @param array $server $_SERVER.
	 * @param array $get    $_GET.
	 * @return bool
	 */
	public static function is_handoff_request( array $server, array $get ) {
		return '1' === ( $get[ self::QUERY_ARG ] ?? null ) && self::is_home_front_end( $server, $get );
	}

	/**
	 * Whether this request is for the hand-off URL with its `#` percent-encoded by a platform:
	 * `?urumi_handoff=1%23<token>` (PHP reads `1#<token>`) or `1%2523<token>` (PHP reads `1%23<token>`),
	 * at the home path, on a front-end request (pl7-plan.md section 17). The token is then in the query
	 * string. It gets the same static page at `init`, before the theme or any other script on the home
	 * page can read the URL; the page's script takes the token from the query. The server never reads,
	 * logs or echoes the rest of the value, except in the Location of the canonical-host 308 (the link
	 * must keep working on the home host; the response is no-store).
	 *
	 * @param array $server $_SERVER.
	 * @param array $get    $_GET.
	 * @return bool
	 */
	public static function is_encoded_hash_request( array $server, array $get ) {
		$value = $get[ self::QUERY_ARG ] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/' . Urumi_Agentic_Handoff_Page::ENCODED_HASH_PATTERN . '/', $value ) ) {
			return false;
		}
		return self::is_home_front_end( $server, $get );
	}

	/**
	 * Whether `init` answers this request: GET, HEAD and other methods but POST to the hand-off URL, and
	 * every method to the encoded-`#` URL (a POST there is not the page's form, so it gets the 405).
	 *
	 * @param array $server $_SERVER.
	 * @param array $get    $_GET.
	 * @return bool
	 */
	public static function answers_at_init( array $server, array $get ) {
		if ( self::is_handoff_request( $server, $get ) ) {
			return 'POST' !== ( $server['REQUEST_METHOD'] ?? '' );
		}
		return self::is_encoded_hash_request( $server, $get );
	}

	/**
	 * Whether the request is a front-end request for the home path (not admin, AJAX, cron, REST or WP-CLI).
	 *
	 * @param array $server $_SERVER.
	 * @param array $get    $_GET.
	 * @return bool
	 */
	private static function is_home_front_end( array $server, array $get ) {
		if ( isset( $get['rest_route'] ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'DOING_CRON' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		$request = wp_parse_url( (string) ( $server['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$home    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return is_string( $request ) && '' !== $request && rtrim( $request, '/' ) === rtrim( (string) $home, '/' );
	}

	/**
	 * GET, HEAD and any other method but POST (init, priority -1); any method to the encoded-`#` URL.
	 *
	 * @return void
	 */
	public static function on_init() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- compared, never stored.
		if ( ! self::answers_at_init( $_SERVER, $_GET ) ) {
			return;
		}
		$response = self::get_response( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- compared, never stored.
		if ( self::is_encoded_hash_request( $_SERVER, $_GET ) ) {
			if ( 405 === $response['status'] ) {
				// The encoded URL takes no POST: the page's form posts to the plain URL.
				$response['headers']['Allow'] = 'GET, HEAD';
			}
			self::scrub_encoded_token();
		}
		if ( 200 === $response['status'] ) {
			header_remove( 'Set-Cookie' );
		}
		self::send( $response, 'HEAD' === $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * After the answer to an encoded-`#` request is built: the token leaves $_GET, $_REQUEST and the
	 * request line in $_SERVER, so code that runs from here to the end of the request (a shutdown
	 * logger, an APM or error reporter) reads `?urumi_handoff=1` instead. What ran before `init` -1 may
	 * already have seen it (pl7-plan.md section 17); upstream logs hold it anyway.
	 *
	 * @return void
	 */
	private static function scrub_encoded_token() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- overwritten, never read.
		$_GET[ self::QUERY_ARG ] = '1';
		if ( isset( $_REQUEST[ self::QUERY_ARG ] ) ) {
			$_REQUEST[ self::QUERY_ARG ] = '1';
		}
		$path                    = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$_SERVER['REQUEST_URI']  = ( is_string( $path ) && '' !== $path ? $path : '/' ) . '?' . self::QUERY_ARG . '=1';
		$_SERVER['QUERY_STRING'] = self::QUERY_ARG . '=1';
		// phpcs:enable
	}

	/**
	 * The answer to a non-POST request: the page, the canonical-host redirect or 405.
	 *
	 * @param array $server $_SERVER.
	 * @return array{status: int, headers: array<string, string>, body: string, location?: string}
	 */
	public static function get_response( array $server ) {
		$method = $server['REQUEST_METHOD'] ?? 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return array(
				'status'  => 405,
				'headers' => array(
					'Allow'         => 'GET, HEAD, POST',
					'Cache-Control' => 'no-store',
				),
				'body'    => '',
			);
		}
		// The GET exits at init, so redirect_canonical never runs: send the browser to the
		// home host ourselves, or the page's POST would not be same-origin.
		$canonical = self::canonical_target( $server, home_url() );
		if ( null !== $canonical ) {
			return array(
				'status'   => 308,
				'headers'  => array( 'Cache-Control' => 'no-store' ),
				'body'     => '',
				'location' => $canonical,
			);
		}
		return Urumi_Agentic_Handoff_Page::main_page();
	}

	/**
	 * Where a request on another host should go: the home scheme, host and port with the same
	 * path and query. Null when the host already is the home host (case-insensitive, with
	 * port). The scheme is not compared: the tenant forces HTTPS on, so a check could only loop.
	 * Browsers keep the URL fragment over a redirect that has none of its own.
	 * An encoded-`#` link (is_encoded_hash_request()) keeps its query, token included: the redirect only
	 * moves it to the home host, where the page takes it out of the address bar.
	 *
	 * @param array  $server $_SERVER.
	 * @param string $home   home_url().
	 * @return string|null
	 */
	public static function canonical_target( array $server, $home ) {
		$parts = wp_parse_url( $home );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! is_string( $server['HTTP_HOST'] ?? null ) || '' === $server['HTTP_HOST'] ) {
			return null;
		}
		$scheme    = strtolower( $parts['scheme'] );
		$authority = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$default   = 'https' === $scheme ? ':443' : ':80';
		$wanted    = self::strip_suffix( $authority, $default );
		$have      = self::strip_suffix( strtolower( trim( $server['HTTP_HOST'] ) ), $default );
		if ( $have === $wanted ) {
			return null;
		}
		$path  = wp_parse_url( (string) ( $server['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$query = preg_replace( '/[^\x21-\x7e]/', '', (string) ( $server['QUERY_STRING'] ?? '' ) );
		return $scheme . '://' . $wanted . ( is_string( $path ) && '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * The POST (wp_loaded, priority 15). Always ends the request.
	 *
	 * @return void
	 */
	public static function on_wp_loaded() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification -- compared, never stored.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! self::is_handoff_request( $_SERVER, $_GET ) ) {
			return;
		}
		$token       = self::$token;
		self::$token = null;
		$result      = self::handle_post( $_SERVER, $token, time() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $result['no_write'] ) {
			self::drop_session_save();
		}
		self::send( $result['response'], false );
	}

	/**
	 * The POST flow with its single outcome log line. A throw outside the claimed rebuild is
	 * an unavailable page that changes nothing.
	 *
	 * @param array                      $server  $_SERVER.
	 * @param string|null                $token   The captured token, or null for none.
	 * @param int                        $now     Unix seconds.
	 * @param Urumi_Agentic_Secrets|null $secrets The Secret reader (tests).
	 * @return array{response: array, outcome: string, added: int, skipped: int, removed: int, reduced: int, no_write: bool, reasons: array<string, int>}
	 */
	public static function handle_post( array $server, #[\SensitiveParameter] $token, $now, $secrets = null ) {
		try {
			$result = self::post_response( $server, $token, $now, $secrets );
		} catch ( Throwable $e ) {
			// Only the class name. A trace can hold the arguments of its frames, the token among them, unless
			// zend.exception_ignore_args is on; #[\SensitiveParameter] (PHP 8.2+) redacts the token parameters, and
			// PHP 8.1 ignores the attribute, so nothing here ever prints or logs the throwable itself.
			$result = self::result( Urumi_Agentic_Handoff_Page::message_page( Urumi_Agentic_Handoff_Page::MESSAGE_UNAVAILABLE ), 'failed:' . self::class_label( $e ), true );
		}
		self::log_outcome( $result['outcome'], $result['added'], $result['skipped'], $result['removed'], $result['reduced'], $result['reasons'] );
		return $result;
	}

	/**
	 * The POST flow (pl7-plan.md section 2.4).
	 *
	 * @param array                      $server $_SERVER.
	 * @param string|null                $token  The captured token, or null for none.
	 * @param int                        $now    Unix seconds.
	 * @param Urumi_Agentic_Secrets|null $secrets The Secret reader (tests).
	 * @return array{response: array, outcome: string, added: int, skipped: int, removed: int, reduced: int, no_write: bool}
	 */
	public static function post_response( array $server, #[\SensitiveParameter] $token, $now, $secrets = null ) {
		// 1. Same origin, before anything is touched.
		if ( ! self::origin_allowed( $server, home_url() ) ) {
			return self::result( Urumi_Agentic_Handoff_Page::message_page( Urumi_Agentic_Handoff_Page::MESSAGE_FORBIDDEN ), 'forbidden', true );
		}

		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$cart        = is_object( $woocommerce ) ? ( $woocommerce->cart ?? null ) : null;
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		if ( ! is_object( $cart ) || ! is_object( $session ) ) {
			return self::unavailable( 'unavailable' );
		}
		$token = is_string( $token ) ? $token : '';

		// 2. Same session re-open: checkout, nothing changed. Before the claim, so Back + Continue
		// long after the token expired never touches the cart that link built. A link that left the
		// cart as it was (H5, `k`) goes to the cart again and, once open() shows this store sealed it
		// (expired is fine), says so again.
		$hash    = '' === $token ? null : Urumi_Agentic_Handoff_Token::nonce_hash( $token );
		$entry   = null === $hash ? null : self::opened_entry( $session, $hash, $now );
		$secrets = $secrets ? $secrets : new Urumi_Agentic_Secrets();
		if ( null !== $entry && ! empty( $entry['k'] ) ) {
			// The nonce hash comes from the format alone, so anyone holding a copy of the link can match
			// it: a changed tail or interior byte, a truncation that keeps the nonce, another key id. Only a
			// token this store's key sealed may write (OK, or an authenticated refusal such as `expired`:
			// Back + Continue after the link expired still says so again). Anything else, including a key
			// ring that cannot be read right now, goes to the cart and writes nothing. Its outcome names the
			// code, so a copy anyone can send logs as the refusal it is (debug), not as a reopen.
			$code = self::open_code( $token, $secrets, $now );
			if ( Urumi_Agentic_Handoff_Token::UNAVAILABLE === $code ) {
				return self::result( self::redirect( self::kept_url() ), 'unavailable', true );
			}
			if ( Urumi_Agentic_Handoff_Token::OK !== $code && ! in_array( $code, Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS, true ) ) {
				return self::result( self::redirect( self::kept_url() ), 'refused:' . $code, true );
			}
			// Kept reopen: save the session (no_write = false) so the none-added notice survives the
			// redirect. This writes the whole Woo session back, not only our key. That is safe here because
			// this request ran no cart method, and the session data was loaded from the server at the start
			// of this request (the cookie only names it). A stale cookie jar therefore writes back the
			// current cart, not an old one. The only remaining risk is a concurrent request writing between
			// our load and our save, which every page view that adds a Woo notice already has.
			// `used` keeps no_write = true because it has nothing to save.
			Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_NONE_ADDED, array( self::cart_marker( $cart ) ), $now );
			self::save_session( $session );
			return self::result( self::redirect( self::kept_url() ), 'reopened', false );
		}
		if ( null !== $entry ) {
			return self::result( self::redirect( wc_get_checkout_url() ), 'reopened', true );
		}

		// 3. Open the token.
		$store = $secrets->store_slug();
		if ( null === $store ) {
			return self::unavailable( 'unavailable' );
		}
		$opened = Urumi_Agentic_Handoff_Token::open( $token, $store, $now, array( $secrets, 'handoff_key_lookup' ) );
		if ( Urumi_Agentic_Handoff_Token::UNAVAILABLE === $opened['code'] ) {
			return self::unavailable( 'unavailable' );
		}
		if ( Urumi_Agentic_Handoff_Token::OK !== $opened['code'] ) {
			return self::refused( $session, $cart, $opened['code'], $now );
		}

		// 4. Claim: single use, atomic.
		$claim = Urumi_Agentic_Handoff_Uses::claim( (string) $opened['nonce_hash'], (int) $opened['payload']['expires_at'] );
		if ( Urumi_Agentic_Handoff_Uses::USED === $claim ) {
			// Nothing is written: the losing request of a same-browser race holds a stale copy
			// of the session, and saving it would wipe the cart the winner built.
			$links = array(
				__( 'Go to checkout', 'urumi-agentic' ) => wc_get_checkout_url(),
				__( 'Go to cart', 'urumi-agentic' )     => wc_get_cart_url(),
			);
			return self::result( Urumi_Agentic_Handoff_Page::message_page( Urumi_Agentic_Handoff_Page::MESSAGE_USED, $links ), 'used', true );
		}
		if ( Urumi_Agentic_Handoff_Uses::CLAIMED !== $claim ) {
			return self::unavailable( 'db_error' );
		}

		// 5. Claimed. Inside the try, so a throw anywhere after the claim is a partial rebuild and a
		// redirect to checkout, never a "try again" page on a link that is already used. The used-list
		// entry and the session cookie are the first things written, so a throw or a fatal later still
		// leaves this browser able to re-open the link.
		$outcome = 'opened';
		$added   = 0;
		$skipped = 0;
		$removed = 0;
		$reduced = 0;
		$kept    = false;
		$reasons = array();
		try {
			self::record_open( $session, (string) $opened['nonce_hash'], (int) $opened['payload']['expires_at'], $now );
			if ( method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
				$session->set_customer_session_cookie( true );
			}
			$counts  = Urumi_Agentic_Handoff_Cart::rebuild( $opened['payload'], new Urumi_Agentic_Handoff_Hint(), $now );
			$added   = $counts['added'];
			$skipped = $counts['skipped'];
			// Lines a store plugin removed are in `skipped` too; a lowered quantity is not a left-out line.
			$removed = (int) ( $counts['removed'] ?? 0 );
			$reduced = (int) ( $counts['reduced'] ?? 0 );
			$outcome = $skipped > 0 ? 'opened_partial' : 'opened';
			if ( ! empty( $counts['kept'] ) ) {
				// H5: no line could be added and nothing was changed. The link is used all the same; its
				// entry remembers the kept cart, so a reopen goes to the cart too (step 2).
				$kept    = true;
				$outcome = 'opened_none';
				$reasons = is_array( $counts['reasons'] ?? null ) ? $counts['reasons'] : array();
				self::mark_kept( $session, (string) $opened['nonce_hash'], $now );
			}
		} catch ( Throwable $e ) {
			// The link stays used (the contract's partial rebuild); the shopper still goes to checkout.
			$outcome = 'failed:' . self::class_label( $e );
		} finally {
			self::save_session( $session );
		}
		return self::result( self::redirect( $kept ? self::kept_url() : wc_get_checkout_url() ), $outcome, false, $added, $skipped, $removed, $reduced, $reasons );
	}

	/**
	 * Where a link that left the cart as it was sends the shopper: the cart page, where the notice and
	 * the unchanged cart are. With no cart page (wc_get_cart_url() would give the home page), checkout,
	 * as before; the notice prints there too.
	 *
	 * @return string
	 */
	private static function kept_url() {
		try {
			if ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'cart' ) > 0 ) {
				return wc_get_cart_url();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return wc_get_checkout_url();
	}

	/**
	 * The none-added notice's cart marker for the cart now. Not empty when it can't be read: the notice
	 * never says the cart is empty when it may not be.
	 *
	 * @param object $cart Woo cart.
	 * @return string
	 */
	private static function cart_marker( $cart ) {
		try {
			if ( is_callable( array( $cart, 'is_empty' ) ) && $cart->is_empty() ) {
				return Urumi_Agentic_Handoff_Notice::CART_EMPTY_MARKER;
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
		return Urumi_Agentic_Handoff_Notice::CART_KEPT_MARKER;
	}

	/**
	 * A link that failed a check (not "unavailable").
	 *
	 * Only a refusal of a token sealed with this store's key (Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS:
	 * genuine, but expired, for another store, a bad lifetime or a newer version) may change a session: the cart is
	 * left alone unless a hand-off built it (then it is emptied, which ends that hand-off as any emptied cart does)
	 * and the cart page shows a notice. Anything else (`tampered`, `unknown_key`: input anyone can send, including a
	 * mangled link) and any refusal without a session cookie answers the message page and writes nothing, like the
	 * 403: a POST that proves nothing must not empty a cart, and must not save a stale copy of the session over a
	 * cart another request just built.
	 *
	 * @param object $session Woo session.
	 * @param object $cart    Woo cart.
	 * @param string $code    The refusal code.
	 * @param int    $now     Unix seconds.
	 * @return array
	 */
	private static function refused( $session, $cart, $code, $now ) {
		$outcome     = 'refused:' . $code;
		$has_session = method_exists( $session, 'has_session' ) && $session->has_session();
		if ( ! $has_session || ! in_array( $code, Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS, true ) ) {
			$links = array();
			if ( $has_session ) {
				// A plain link: following it writes nothing, and the cart it shows is the one the shopper had.
				$links[ __( 'Go to cart', 'urumi-agentic' ) ] = wc_get_cart_url();
			}
			$links[ __( 'Go to the shop', 'urumi-agentic' ) ] = wc_get_page_permalink( 'shop' );
			return self::result( Urumi_Agentic_Handoff_Page::message_page( Urumi_Agentic_Handoff_Page::MESSAGE_REFUSED, $links ), $outcome, true );
		}
		$hint = new Urumi_Agentic_Handoff_Hint();
		try {
			if ( null !== $hint->get( $now ) ) {
				// A cart a hand-off built goes. Emptying it is the hand-off's "cart emptied" end (the tagger
				// forgets the hint on woocommerce_cart_emptied, which ends the pre-fill copy); again here in
				// case nothing listened. Otherwise the refusal ends nothing: an expired hint is already inert,
				// and its copy ends on the next front-end request (Urumi_Agentic_Handoff_Cart).
				$cart->empty_cart();
				$hint->forget( Urumi_Agentic_Handoff_Hint::END_CART_EMPTIED );
			}
			Urumi_Agentic_Handoff_Notice::add( Urumi_Agentic_Handoff_Notice::KIND_REFUSED, array(), $now );
		} finally {
			self::save_session( $session );
		}
		return self::result( self::redirect( wc_get_cart_url() ), $outcome, false );
	}

	/**
	 * Step 1: Sec-Fetch-Site `same-origin`; or no Sec-Fetch-Site and an Origin equal to the
	 * origin of home_url(). `Origin: null`, `same-site`, `cross-site`, `none` and neither
	 * header are all refused.
	 *
	 * @param array  $server $_SERVER.
	 * @param string $home   home_url().
	 * @return bool
	 */
	public static function origin_allowed( array $server, $home ) {
		if ( array_key_exists( 'HTTP_SEC_FETCH_SITE', $server ) ) {
			return 'same-origin' === $server['HTTP_SEC_FETCH_SITE'];
		}
		$origin = $server['HTTP_ORIGIN'] ?? null;
		if ( ! is_string( $origin ) || 1 !== preg_match( '#^https?://[^/?\#@]+\z#i', $origin ) ) {
			return false;
		}
		$have = self::origin_of( $origin );
		return null !== $have && self::origin_of( $home ) === $have;
	}

	/**
	 * `scheme://host[:port]`, lower case, default port dropped; null when there is no scheme or host.
	 *
	 * @param string $url A URL.
	 * @return string|null
	 */
	private static function origin_of( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}
		return $scheme . '://' . strtolower( $parts['host'] ) . ( null !== $port ? ':' . $port : '' );
	}

	/**
	 * The code open() gives the token: OK or an Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS
	 * code when this store's key sealed it, `tampered` or `unknown_key` for a copy it did not, and
	 * `unavailable` when the store slug or the key ring cannot be read.
	 *
	 * @param string                $token   The captured token.
	 * @param Urumi_Agentic_Secrets $secrets The Secret reader.
	 * @param int                   $now     Unix seconds.
	 * @return string
	 */
	private static function open_code( #[\SensitiveParameter] $token, $secrets, $now ) {
		$store = $secrets->store_slug();
		if ( null === $store ) {
			return Urumi_Agentic_Handoff_Token::UNAVAILABLE;
		}
		return Urumi_Agentic_Handoff_Token::open( $token, $store, $now, array( $secrets, 'handoff_key_lookup' ) )['code'];
	}

	/**
	 * This nonce hash's entry in the session's used list (and not too old), or null.
	 *
	 * @param object $session Woo session.
	 * @param string $hash    Nonce hash.
	 * @param int    $now     Unix seconds.
	 * @return array{h: string, x: int, k?: int}|null
	 */
	private static function opened_entry( $session, $hash, $now ) {
		foreach ( self::used_entries( $session, $now ) as $entry ) {
			if ( hash_equals( $entry['h'], $hash ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Marks this link's used-list entry `k` (H5: no line could be added and the cart was kept), so a
	 * reopen goes to the cart. Never throws: when it fails, a reopen goes to checkout as before.
	 *
	 * @param object $session Woo session.
	 * @param string $hash    Nonce hash.
	 * @param int    $now     Unix seconds.
	 * @return void
	 */
	private static function mark_kept( $session, $hash, $now ) {
		try {
			$list = self::used_entries( $session, $now );
			foreach ( $list as $i => $entry ) {
				if ( hash_equals( $entry['h'], $hash ) ) {
					$list[ $i ]['k'] = 1;
				}
			}
			$session->set( self::SESSION_USED, $list );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Adds a nonce hash to the session's used list: newest first, at most USED_MAX, entries
	 * dropped once a day past their expiry.
	 *
	 * @param object $session Woo session.
	 * @param string $hash    Nonce hash.
	 * @param int    $expires Token expiry.
	 * @param int    $now     Unix seconds.
	 * @return void
	 */
	private static function record_open( $session, $hash, $expires, $now ) {
		$list = array_merge(
			array(
				array(
					'h' => $hash,
					'x' => $expires,
				),
			),
			self::used_entries( $session, $now )
		);
		$session->set( self::SESSION_USED, array_slice( $list, 0, self::USED_MAX ) );
	}

	/**
	 * The valid, unexpired entries of the used list. `k` (a link that kept the cart) is carried only as
	 * the int 1; anything else there is dropped.
	 *
	 * @param object $session Woo session.
	 * @param int    $now     Unix seconds.
	 * @return array<int, array{h: string, x: int, k?: int}>
	 */
	private static function used_entries( $session, $now ) {
		$stored  = $session->get( self::SESSION_USED );
		$entries = array();
		if ( ! is_array( $stored ) ) {
			return $entries;
		}
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['h'] ?? null ) && is_int( $entry['x'] ?? null ) && $now < $entry['x'] + self::USED_GRACE ) {
				$clean = array(
					'h' => $entry['h'],
					'x' => $entry['x'],
				);
				if ( 1 === ( $entry['k'] ?? null ) ) {
					$clean['k'] = 1;
				}
				$entries[] = $clean;
			}
		}
		return $entries;
	}

	/**
	 * Saves the Woo session now, not only at shutdown. A save that fails must not change the answer.
	 *
	 * @param object $session Woo session.
	 * @return void
	 */
	private static function save_session( $session ) {
		try {
			if ( method_exists( $session, 'save_data' ) ) {
				$session->save_data();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A 503 page: nothing was changed, try again.
	 *
	 * @param string $outcome Outcome label.
	 * @return array
	 */
	private static function unavailable( $outcome ) {
		return self::result( Urumi_Agentic_Handoff_Page::message_page( Urumi_Agentic_Handoff_Page::MESSAGE_UNAVAILABLE ), $outcome, true );
	}

	/**
	 * A 303 with no body.
	 *
	 * @param string $location Target.
	 * @return array
	 */
	private static function redirect( $location ) {
		return array(
			'status'   => 303,
			'headers'  => array( 'Cache-Control' => 'no-store' ),
			'body'     => '',
			'location' => $location,
			'woo'      => true,
		);
	}

	/**
	 * The shape post_response() returns.
	 *
	 * @param array  $response The response.
	 * @param string $outcome  Outcome label.
	 * @param bool   $no_write Whether this response must leave the Woo session unsaved.
	 * @param int    $added    Lines added.
	 * @param int    $skipped  Lines left out.
	 * @param int    $removed  Of those, lines a store plugin removed from the cart while later lines were added.
	 * @param int    $reduced  Units below what the link asked for, on lines that were added (log only).
	 * @param array  $reasons  H5's kept path only: the checked lines by refusal code (log only).
	 * @return array
	 */
	private static function result( array $response, $outcome, $no_write, $added = 0, $skipped = 0, $removed = 0, $reduced = 0, array $reasons = array() ) {
		return array(
			'response' => $response,
			'outcome'  => $outcome,
			'added'    => $added,
			'skipped'  => $skipped,
			'removed'  => $removed,
			'reduced'  => $reduced,
			'no_write' => $no_write,
			'reasons'  => $reasons,
		);
	}

	/**
	 * A class name that is safe to log.
	 *
	 * @param Throwable $e The throwable.
	 * @return string
	 */
	private static function class_label( Throwable $e ) {
		return preg_replace( '/[^A-Za-z0-9_\\\\]/', '', get_class( $e ) );
	}

	/**
	 * Exactly one line per POST, through WooCommerce's logger. Never the token, session id, nonce
	 * hash, product ids, names or contact details. Outcomes anyone can cause without a valid key
	 * (`forbidden` and every refusal not in Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS; see
	 * is_unauthenticated_outcome()) are debug level. That does not stop
	 * them filling the log (WooCommerce writes debug lines unless a threshold is set); it only lets a
	 * store raise the threshold to drop them. ` removed=<n>` and then ` reduced=<n>` are appended only
	 * when not 0, so the common line stays `hand-off <outcome> added=<n> skipped=<n>`. On `opened_none`
	 * only, ` reasons=<code>:<n>,...` follows: why the check refused the lines, by refusal code
	 * (Urumi_Agentic_Handoff_Cart::REASON_*) in alphabetical order, never a product. A link with no
	 * lines has none.
	 *
	 * @param string $outcome Outcome label.
	 * @param int    $added   Lines added.
	 * @param int    $skipped Lines left out.
	 * @param int    $removed Of those, lines a store plugin removed while later lines were added.
	 * @param int    $reduced Units below what the link asked for, on lines that were added.
	 * @param array  $reasons The kept path's refusal counts by code.
	 * @return void
	 */
	private static function log_outcome( $outcome, $added, $skipped, $removed = 0, $reduced = 0, array $reasons = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		try {
			$message = sprintf( 'hand-off %s added=%d skipped=%d', $outcome, $added, $skipped );
			if ( (int) $removed > 0 ) {
				$message .= sprintf( ' removed=%d', $removed );
			}
			if ( (int) $reduced > 0 ) {
				$message .= sprintf( ' reduced=%d', $reduced );
			}
			if ( 'opened_none' === $outcome ) {
				$message .= self::reasons_field( $reasons );
			}
			wc_get_logger()->log(
				self::is_unauthenticated_outcome( $outcome ) ? 'debug' : 'info',
				$message,
				array( 'source' => self::LOG_SOURCE )
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * ` reasons=<code>:<n>,...` for the log line, or '' when there is none. Only codes of lowercase
	 * letters and underscores with positive counts are written.
	 *
	 * @param array $reasons Counts by code.
	 * @return string
	 */
	private static function reasons_field( array $reasons ) {
		ksort( $reasons );
		$parts = array();
		foreach ( $reasons as $code => $count ) {
			if ( is_string( $code ) && 1 === preg_match( '/^[a-z_]{1,32}$/', $code ) && is_int( $count ) && $count > 0 ) {
				$parts[] = $code . ':' . $count;
			}
		}
		return $parts ? ' reasons=' . implode( ',', $parts ) : '';
	}

	/**
	 * Whether any visitor can cause this outcome without holding a hand-off key: `forbidden`, and a refusal whose
	 * code is not in Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS. Derived from the same list as the write
	 * rule in refused(), so a refusal code added later logs at debug by default, which pairs with its writing
	 * nothing by default.
	 *
	 * @param string $outcome Outcome label.
	 * @return bool
	 */
	private static function is_unauthenticated_outcome( $outcome ) {
		if ( 'forbidden' === $outcome ) {
			return true;
		}
		return 0 === strpos( $outcome, 'refused:' ) && ! in_array( substr( $outcome, strlen( 'refused:' ) ), Urumi_Agentic_Handoff_Token::AUTHENTICATED_REFUSALS, true );
	}

	/**
	 * Stops Woo saving the session at shutdown: a response that must change nothing.
	 *
	 * @return void
	 */
	private static function drop_session_save() {
		$woocommerce = function_exists( 'WC' ) ? WC() : null;
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		if ( is_object( $session ) ) {
			remove_action( 'shutdown', array( $session, 'save_data' ), 20 );
		}
	}

	/**
	 * Sends a response and ends the request.
	 *
	 * @param array $response Response.
	 * @param bool  $head     Whether to leave out the body (HEAD).
	 * @return never
	 */
	private static function send( array $response, $head ) {
		if ( isset( $response['location'] ) ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
			self::redirect_to( $response );
			exit;
		}
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( ! $head ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Urumi_Agentic_Handoff_Page builds it from escaped parts.
		}
		exit;
	}

	/**
	 * Sends the Location of a redirect response. Only the POST's own redirects, which go to Woo URLs,
	 * get the cart fallback: the GET's canonical-host 308 runs at `init` before Woo has a cart, and its
	 * target is always the home host, so it must not call Woo.
	 *
	 * @param array $response A response with a `location`.
	 * @return void
	 */
	public static function redirect_to( array $response ) {
		if ( ! empty( $response['woo'] ) ) {
			self::safe_redirect( $response['location'], $response['status'] );
			return;
		}
		wp_safe_redirect( $response['location'], $response['status'], 'Urumi Agentic' );
	}

	/**
	 * Redirects to a Woo URL with wp_safe_redirect(). When a plugin moves the checkout URL off this host,
	 * wp_safe_redirect() would fall back to wp-admin; for this redirect only, the fallback is the cart
	 * page (or the home page if that is off-host too).
	 *
	 * @param string $location Target.
	 * @param int    $status   Status code.
	 * @return void
	 */
	public static function safe_redirect( $location, $status ) {
		$fallback = static function () {
			return wp_validate_redirect( wc_get_cart_url(), home_url( '/' ) );
		};
		add_filter( 'wp_safe_redirect_fallback', $fallback, PHP_INT_MAX, 0 );
		try {
			wp_safe_redirect( $location, $status, 'Urumi Agentic' );
		} finally {
			remove_filter( 'wp_safe_redirect_fallback', $fallback, PHP_INT_MAX );
		}
	}

	/**
	 * Removes a trailing suffix.
	 *
	 * @param string $text   Text.
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private static function strip_suffix( $text, $suffix ) {
		return substr( $text, -strlen( $suffix ) ) === $suffix ? substr( $text, 0, -strlen( $suffix ) ) : $text;
	}
}
