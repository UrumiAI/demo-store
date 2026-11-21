<?php
/**
 * The static hand-off page and the message pages (pl7-plan.md section 2.2).
 *
 * Pure: every method returns `array{status: int, headers: array<string, string>, body: string}`
 * and sends nothing. Urumi_Agentic_Handoff_Endpoint sends it.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the pages the hand-off link shows.
 *
 * The main page is identical for every visitor: no nonce, user, cookie, session
 * or request data, only the site name (escaped) and fixed strings. Its inline
 * script reads and clears the URL fragment first thing; a hash of that script
 * and of the style goes in the Content-Security-Policy header.
 */
class Urumi_Agentic_Handoff_Page {

	const MESSAGE_FORBIDDEN   = 'forbidden';
	const MESSAGE_UNAVAILABLE = 'unavailable';
	const MESSAGE_REFUSED     = 'refused';
	const MESSAGE_USED        = 'used';

	/**
	 * The most characters a token may have (the server's cap, Urumi_Agentic_Handoff_Token::MAX_TOKEN_CHARS).
	 */
	const CLIENT_MAX_CHARS = 16384;

	/**
	 * The browser's shape check, as regex source (the same in JavaScript and PCRE): the leading
	 * token, `1.`, a key id, `.`, then at least 39 base64url characters (the length of the 29-byte
	 * minimum). Anything after it is ignored when it starts with a character that is neither
	 * base64url nor `=`, so a tracking tail a platform appends after the fragment (`?utm_source=…`,
	 * `&…`, `#…`) or a sentence period (`TOKEN.`, `TOKEN.)`) does not break the link. The lookahead
	 * makes the match end only there: backtracking can never return a shorter prefix of the token,
	 * and padding (`TOKEN=`) is still refused. The script then checks the match is at most
	 * CLIENT_MAX_CHARS long, so an over-long token is refused, not cut. A prefilter only: the server
	 * decides, and its format check is strict.
	 */
	const CLIENT_PATTERN = '^1\.k[0-9]{1,6}\.[A-Za-z0-9_-]{39,}(?![A-Za-z0-9_=-])';

	/**
	 * The start of an `urumi_handoff` value whose `#` a platform percent-encoded (pl7-plan.md section 17), as
	 * regex source (the same in JavaScript and PCRE): `1#` (the link's `%23`, decoded once by PHP and by
	 * URLSearchParams) or `1%23` (`%2523`, encoded twice). Exactly `1` first, so `01#`, ` 1#` or `1x#` are
	 * not hand-offs. Neither `#` nor `%23` has a letter, so no case flag is needed. Encoded three times or
	 * more is not recognised.
	 */
	const ENCODED_HASH_PATTERN = '^1(?:#|%23)';

	/**
	 * The inline script, byte for byte (its sha256 is in the CSP header). First element in
	 * `<head>`, synchronous. The token lives in a closure, never on window, and goes into the
	 * form only when the shopper submits. The raw fragment is matched first; only when that fails
	 * is it decoded once (a platform that percent-encoded the token) and matched again, so a
	 * decoded tail can never lengthen a match. A decode that throws (a malformed escape, invalid
	 * UTF-8) leaves no token. Double encoding of the token is not supported.
	 *
	 * When the fragment holds no token and the last `urumi_handoff` value (the one PHP reads) starts
	 * with ENCODED_HASH_PATTERN, the rest of that value is matched the same way (pl7-plan.md
	 * section 17). Either way the URL is replaced before anything is parsed: the fragment is dropped,
	 * and an encoded value is cut back to `?urumi_handoff=1`, so neither the token nor a tail stays
	 * in the address bar, in history or in the Referer of the POST.
	 */
	const SCRIPT = <<<'JS'
(function () {
	var token = '';
	var hash = location.hash;
	var query = '';
	try { query = new URLSearchParams(location.search).getAll('urumi_handoff').pop() || ''; } catch (e) {}
	var lead = /^1(?:#|%23)/.exec(query);
	if (hash.length > 1 || lead) {
		try { history.replaceState(null, '', location.pathname + (lead ? '?urumi_handoff=1' : location.search)); } catch (e) {}
	}
	var shape = /^1\.k[0-9]{1,6}\.[A-Za-z0-9_-]{39,}(?![A-Za-z0-9_=-])/;
	function leading(text) {
		var found = shape.exec(text);
		if (!found) {
			try { found = shape.exec(decodeURIComponent(text)); } catch (e) { found = null; }
		}
		return found && found[0].length <= 16384 ? found[0] : '';
	}
	token = hash.length > 1 ? leading(hash.slice(1)) : '';
	if (token === '' && lead) { token = leading(query.slice(lead[0].length)); }
	query = '';
	function byId(id) { return document.getElementById(id); }
	function setHidden(id, hidden) { var el = byId(id); if (el) { el.hidden = hidden; } }
	document.addEventListener('DOMContentLoaded', function () {
		setHidden('fallback', true);
		if (token === '') { setHidden('again', false); return; }
		setHidden('go', false);
		var form = byId('form');
		var button = byId('continue');
		if (!form) { return; }
		form.addEventListener('submit', function (event) {
			if (token === '') { event.preventDefault(); return; }
			form.elements.token.value = token;
			token = '';
			setTimeout(function () { if (button) { button.disabled = true; } }, 0);
		});
	});
	window.addEventListener('pageshow', function (event) {
		if (!event.persisted) { return; }
		token = '';
		var form = byId('form');
		if (form && form.elements.token) { form.elements.token.value = ''; }
		setHidden('go', true);
		setHidden('again', false);
	});
})();
JS;

	/**
	 * The inline style, byte for byte (its sha256 is in the CSP header).
	 */
	const STYLE = 'html{background:#fff;color:#1d2327}body{font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;padding:24px}main,#fallback{max-width:32rem;margin:10vh auto 0}h1{font-size:1.4rem;line-height:1.3;margin:0 0 .75rem}p{margin:0 0 1rem}[hidden]{display:none!important}button,a.button{display:inline-block;font:inherit;padding:.65rem 1.25rem;border:0;border-radius:4px;background:#1d2327;color:#fff;cursor:pointer;text-decoration:none}button[disabled]{opacity:.6;cursor:default}a{color:inherit}ul{padding:0;list-style:none}li{margin:0 0 .75rem}';

	/**
	 * The page that holds the form. Shown for GET and HEAD.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function main_page() {
		return self::in_site_locale(
			static function () {
				$site   = get_bloginfo( 'name' );
				$action = esc_url( home_url( '/?urumi_handoff=1' ) );
				$body   = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
					. '<script data-cfasync="false">' . self::SCRIPT . '</script>'
					. self::head( sprintf( /* translators: %s: site name. */ __( 'Continue to checkout · %s', 'urumi-agentic' ), $site ) )
					. '</head><body>'
					. '<p id="fallback">' . esc_html__( 'If nothing appears below, open the link again from your assistant, in a browser with JavaScript on.', 'urumi-agentic' ) . '</p>'
					. '<main id="go" hidden><h1>' . esc_html__( 'Continue to checkout', 'urumi-agentic' ) . '</h1>'
					. '<p>' . esc_html( sprintf( /* translators: %s: site name. */ __( 'This replaces your cart on %s with the items from your assistant.', 'urumi-agentic' ), $site ) ) . '</p>'
					. '<form id="form" method="post" action="' . $action . '"><input type="hidden" name="token" value="">'
					. '<button id="continue" type="submit">' . esc_html__( 'Continue to checkout', 'urumi-agentic' ) . '</button></form></main>'
					. '<main id="again" hidden><h1>' . esc_html__( 'Open the link again', 'urumi-agentic' ) . '</h1>'
					. '<p>' . esc_html__( 'This page no longer has your link. Open the link again from your assistant.', 'urumi-agentic' ) . '</p></main>'
					. '<noscript><p>' . esc_html__( 'This link needs JavaScript. Turn it on and open the link again.', 'urumi-agentic' ) . '</p></noscript>'
					. '</body></html>';
				return array(
					'status'  => 200,
					'headers' => self::headers( true ),
					'body'    => $body,
				);
			}
		);
	}

	/**
	 * A message page: no script, no form.
	 *
	 * @param string                $kind  One of the MESSAGE_* constants.
	 * @param array<string, string> $links Link text => URL, for the kinds that link somewhere.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function message_page( $kind, array $links = array() ) {
		return self::in_site_locale(
			static function () use ( $kind, $links ) {
				$site = get_bloginfo( 'name' );
				switch ( $kind ) {
					case self::MESSAGE_FORBIDDEN:
						$status = 403;
						$title  = __( 'This link can\'t be opened from here', 'urumi-agentic' );
						$text   = __( 'Open the link again from your assistant, in the same browser tab.', 'urumi-agentic' );
						break;
					case self::MESSAGE_UNAVAILABLE:
						$status = 503;
						$title  = __( 'We can\'t open this link right now', 'urumi-agentic' );
						$text   = __( 'Try again in a minute. Your cart has not been changed.', 'urumi-agentic' );
						break;
					case self::MESSAGE_USED:
						$status = 200;
						$title  = __( 'This link has already been opened', 'urumi-agentic' );
						$text   = __( 'Ask your assistant for a new one.', 'urumi-agentic' );
						break;
					default:
						$kind   = self::MESSAGE_REFUSED;
						$status = 200;
						$title  = __( 'This link can\'t be used', 'urumi-agentic' );
						$text   = __( 'It may have expired or is not valid for this store. Ask your assistant for a new one.', 'urumi-agentic' );
				}
				$items = '';
				foreach ( $links as $label => $url ) {
					$items .= '<li><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
				}
				$body    = '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
					. self::head( $title . ' · ' . $site )
					. '</head><body><main id="' . esc_attr( $kind ) . '"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p>'
					. ( '' === $items ? '' : '<ul>' . $items . '</ul>' )
					. '</main></body></html>';
				$headers = self::headers( false );
				if ( 503 === $status ) {
					$headers['Retry-After'] = '60';
				}
				return array(
					'status'  => $status,
					'headers' => $headers,
					'body'    => $body,
				);
			}
		);
	}

	/**
	 * The headers every page of this endpoint sends.
	 *
	 * @param bool $with_script Whether the page carries the inline script.
	 * @return array<string, string>
	 */
	public static function headers( $with_script ) {
		$policy = "default-src 'none'; ";
		if ( $with_script ) {
			$policy .= "script-src '" . self::csp_hash( self::SCRIPT ) . "'; ";
		}
		// No form-action on purpose (pl7-plan.md section 2.2): it also covers redirect hops.
		$policy .= "style-src '" . self::csp_hash( self::STYLE ) . "'; base-uri 'none'; frame-ancestors 'none'";
		return array(
			'Content-Type'               => 'text/html; charset=UTF-8',
			'Cache-Control'              => 'no-store, no-transform',
			'Content-Security-Policy'    => $policy,
			'Referrer-Policy'            => 'same-origin',
			'X-Robots-Tag'               => 'noindex, nofollow',
			'X-Content-Type-Options'     => 'nosniff',
			'Cross-Origin-Opener-Policy' => 'same-origin',
		);
	}

	/**
	 * A CSP source expression for inline content: `sha256-<base64>` (without the quotes).
	 *
	 * @param string $content The exact text between the tags.
	 * @return string
	 */
	public static function csp_hash( $content ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a CSP source expression, not obfuscation.
		return 'sha256-' . base64_encode( hash( 'sha256', $content, true ) );
	}

	/**
	 * The rest of `<head>` after the script.
	 *
	 * @param string $title Page title, unescaped.
	 * @return string
	 */
	private static function head( $title ) {
		return '<meta charset="utf-8"><meta name="referrer" content="same-origin">'
			. '<meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width">'
			. '<title>' . esc_html( $title ) . '</title><style>' . self::STYLE . '</style>';
	}

	/**
	 * Runs $render in the site's locale, so a logged-in user's profile language
	 * can't make two versions of the page.
	 *
	 * @param callable $render Returns the page.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function in_site_locale( $render ) {
		$switched = switch_to_locale( get_locale() );
		try {
			return $render();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
