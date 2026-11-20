<?php
/**
 * Opens a hand-off link token (contract.md section 5; pl7-plan.md section 3).
 *
 * The PHP twin of Lane 1's Go `handoff.Open`
 * (agents/agentic-service/internal/contract/handoff/handoff.go): the same
 * checks, in the same order, with the same refusal codes, proven against
 * Lane 1's vectors (tests/fixtures/contract/handoff-vectors.v1.json).
 *
 * Nothing here logs, and nothing here touches WordPress state: it is pure.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hand-off token format, decryption and payload checks.
 *
 * Layout: token = "1." + key_id + "." + base64url_nopad( nonce(12) || ciphertext || tag(16) )
 * and aad = "urumi-handoff.1." + key_id + "." + store slug
 */
class Urumi_Agentic_Handoff_Token {

	const FORMAT_VERSION  = '1';
	const PAYLOAD_VERSION = 1;
	const NONCE_BYTES     = 12;
	const TAG_BYTES       = 16;
	const KEY_BYTES       = 32;
	const TTL             = 86400;
	const MAX_CLOCK_SKEW  = 60;
	const AAD_PREFIX      = 'urumi-handoff.1.';
	const CIPHER          = 'aes-256-gcm';

	/**
	 * Plugin-only cap on the token's length. Go has none; a 100-line cart is about 7 KB.
	 */
	const MAX_TOKEN_CHARS = 16384;

	/**
	 * JSON nesting allowed. The payload needs 3 (root, cart, line).
	 */
	const MAX_JSON_DEPTH = 32;

	// Results. The first seven are Lane 1's codes; `unavailable` is the plugin's own.
	const OK                  = 'ok';
	const TAMPERED            = 'tampered';
	const UNKNOWN_KEY         = 'unknown_key';
	const UNSUPPORTED_VERSION = 'unsupported_version';
	const WRONG_STORE         = 'wrong_store';
	const BAD_LIFETIME        = 'bad_lifetime';
	const EXPIRED             = 'expired';
	const UNAVAILABLE         = 'unavailable';

	const CODES = array(
		self::OK,
		self::TAMPERED,
		self::UNKNOWN_KEY,
		self::UNSUPPORTED_VERSION,
		self::WRONG_STORE,
		self::BAD_LIFETIME,
		self::EXPIRED,
		self::UNAVAILABLE,
	);

	/**
	 * The refusals of a token that decrypted under this store's key and AAD and decoded as JSON: the
	 * steps after decrypt and decode in open(). "Authenticated" means a holder of this store's key
	 * sealed it, not that the link is this shopper's own. Only these may change a cart
	 * (pl7-plan.md section 2.4). Every other refusal comes from input anyone can send: `tampered`
	 * (which also covers a token that decrypted but holds bad JSON, so it fails safe to no-write;
	 * do not split it out) and `unknown_key`. An allowlist: a code added later writes nothing.
	 */
	const AUTHENTICATED_REFUSALS = array(
		self::UNSUPPORTED_VERSION,
		self::WRONG_STORE,
		self::BAD_LIFETIME,
		self::EXPIRED,
	);

	/**
	 * `\z`, not `$`: PCRE's `$` also matches before a trailing newline, Go's does not.
	 */
	const KEY_ID_PATTERN = '/^k[0-9]{1,6}\z/';
	const BASE64URL      = '/^[A-Za-z0-9_-]*\z/';

	/**
	 * The fields of an address, in contract order.
	 */
	const ADDRESS_FIELDS = array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * Payload fields in contract (Go struct) order, and the kind of each.
	 */
	const FIELDS = array(
		'v'               => 'int',
		'store'           => 'string',
		'session_id'      => 'string',
		'channel'         => 'string',
		'issued_at'       => 'int',
		'expires_at'      => 'int',
		'cart'            => 'cart',
		'coupon'          => 'string',
		'shipping_method' => 'string',
		'email'           => 'string',
		'phone'           => 'string',
		'billing'         => 'address',
		'shipping'        => 'address',
	);

	const LINE_FIELDS = array( 'product_id', 'variation_id', 'quantity' );

	/**
	 * Opens a token, in Go's order:
	 *   0. at most MAX_TOKEN_CHARS (plugin only)                     tampered
	 *   1. format: "1.", a key id, ".", strict unpadded base64url
	 *      of at least nonce + tag + 1 bytes                          tampered
	 *   2. the key id is one the store holds (only that key is tried) unknown_key
	 *      ... or the key ring cannot be read right now               unavailable
	 *   3. decrypt with the AAD, then JSON with Go's type rules       tampered
	 *   4. v == 1                                                     unsupported_version
	 *   5. store matches                                              wrong_store
	 *   6. issued_at <= now + 60 and
	 *      issued_at <= expires_at <= issued_at + 86400               bad_lifetime
	 *   7. now < expires_at                                           expired
	 *
	 * @param string   $token   The token.
	 * @param string   $store   This store's slug.
	 * @param int      $now     Unix seconds.
	 * @param callable $key_for `fn( string $key_id ): array{status: 'ok'|'absent'|'unavailable', key: ?string}`,
	 *                          normally Urumi_Agentic_Secrets::handoff_key_lookup().
	 * @return array{code: string, payload: array|null, nonce_hash: string|null}
	 */
	public static function open( #[\SensitiveParameter] string $token, string $store, int $now, callable $key_for ): array {
		$parsed = self::parse( $token );
		if ( null === $parsed ) {
			return self::refuse( self::TAMPERED );
		}

		// A throw from the lookup is an infrastructure fault. Caught here so its stack
		// trace (PHP 8.1 ignores #[\SensitiveParameter]) never carries the token.
		try {
			$found = call_user_func( $key_for, $parsed['key_id'] );
		} catch ( Throwable $e ) {
			return self::refuse( self::UNAVAILABLE );
		}
		$found = is_array( $found ) ? $found : array( 'status' => 'unavailable' );
		if ( 'absent' === ( $found['status'] ?? null ) ) {
			return self::refuse( self::UNKNOWN_KEY );
		}
		if ( 'ok' !== ( $found['status'] ?? null ) || ! is_string( $found['key'] ?? null ) || self::KEY_BYTES !== strlen( $found['key'] ) ) {
			return self::refuse( self::UNAVAILABLE );
		}
		if ( ! self::cipher_available() ) {
			return self::refuse( self::UNAVAILABLE );
		}

		$plain = self::decrypt( $parsed['raw'], $found['key'], self::AAD_PREFIX . $parsed['key_id'] . '.' . $store );
		if ( null === $plain ) {
			return self::refuse( self::TAMPERED );
		}

		$payload = self::decode( $plain );
		if ( null === $payload ) {
			return self::refuse( self::TAMPERED );
		}
		if ( self::PAYLOAD_VERSION !== $payload['v'] ) {
			return self::refuse( self::UNSUPPORTED_VERSION );
		}
		if ( $payload['store'] !== $store ) {
			return self::refuse( self::WRONG_STORE );
		}
		// Compare, never subtract (int overflow). Short-circuit order matters:
		// issued_at + TTL is only computed once issued_at <= now + skew.
		if ( $payload['issued_at'] > $now + self::MAX_CLOCK_SKEW
			|| $payload['expires_at'] < $payload['issued_at']
			|| $payload['expires_at'] > $payload['issued_at'] + self::TTL
		) {
			return self::refuse( self::BAD_LIFETIME );
		}
		if ( $now >= $payload['expires_at'] ) {
			return self::refuse( self::EXPIRED );
		}

		return array(
			'code'       => self::OK,
			'payload'    => $payload,
			'nonce_hash' => self::hash_nonce( $parsed['raw'] ),
		);
	}

	/**
	 * Lowercase hex sha256 of the token's 12 nonce bytes, from the format alone
	 * (no key, no decryption), or null when the format is wrong. The single-use
	 * record and the same-session re-open check both use it.
	 *
	 * @param string $token The token.
	 * @return string|null
	 */
	public static function nonce_hash( #[\SensitiveParameter] string $token ): ?string {
		$parsed = self::parse( $token );
		return null === $parsed ? null : self::hash_nonce( $parsed['raw'] );
	}

	/**
	 * Steps 0 and 1: the format.
	 *
	 * @param string $token The token.
	 * @return array{key_id: string, raw: string}|null
	 */
	private static function parse( #[\SensitiveParameter] string $token ): ?array {
		if ( strlen( $token ) > self::MAX_TOKEN_CHARS ) {
			return null;
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || self::FORMAT_VERSION !== $parts[0] || 1 !== preg_match( self::KEY_ID_PATTERN, $parts[1] ) ) {
			return null;
		}
		$raw = self::base64url_decode( $parts[2] );
		if ( null === $raw || strlen( $raw ) < self::NONCE_BYTES + self::TAG_BYTES + 1 ) {
			return null;
		}
		return array(
			'key_id' => $parts[1],
			'raw'    => $raw,
		);
	}

	/**
	 * Strict unpadded base64url, as Go's `base64.RawURLEncoding.Strict()`: only
	 * the URL-safe alphabet, no padding, and no non-zero trailing bits (the
	 * decoded bytes must re-encode to exactly the input).
	 *
	 * Accepted divergence: Go's decoder skips `\r` and `\n` anywhere in the data,
	 * even in Strict mode; PHP refuses them. Lane 1's Seal never writes them.
	 *
	 * @param string $text Encoded text.
	 * @return string|null Bytes.
	 */
	private static function base64url_decode( #[\SensitiveParameter] string $text ): ?string {
		$length = strlen( $text );
		// Defensive: a length of 1 mod 4 could not decode below anyway (it would
		// need three `=`), but no input of that shape should reach base64_decode().
		if ( 1 !== preg_match( self::BASE64URL, $text ) || 1 === $length % 4 ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- token encoding, not obfuscation.
		$raw = base64_decode( strtr( $text, '-_', '+/' ) . str_repeat( '=', ( 4 - $length % 4 ) % 4 ), true );
		if ( false === $raw ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- canonical-form check.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ) === $text ? $raw : null;
	}

	/**
	 * Step 3: AES-256-GCM. The tag is always exactly the last 16 bytes:
	 * openssl_decrypt() would accept a shorter one and weaken the check.
	 *
	 * @param string $raw Nonce || ciphertext || tag (at least 29 bytes).
	 * @param string $key 32-byte key.
	 * @param string $aad Additional data.
	 * @return string|null Plaintext.
	 */
	private static function decrypt( #[\SensitiveParameter] string $raw, #[\SensitiveParameter] string $key, string $aad ): ?string {
		$tag = substr( $raw, -self::TAG_BYTES );
		// Unreachable (parse() requires 29+ bytes), kept so this function can
		// never hand openssl_decrypt() a short tag, whoever calls it.
		if ( self::TAG_BYTES !== strlen( $tag ) ) {
			return null;
		}
		$plain = openssl_decrypt(
			substr( $raw, self::NONCE_BYTES, -self::TAG_BYTES ),
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			substr( $raw, 0, self::NONCE_BYTES ),
			$tag,
			$aad
		);
		return false === $plain ? null : $plain;
	}

	/**
	 * Step 3b: JSON with Go's `json.Unmarshal` rules for the typed payload. An
	 * absent or `null` field takes Go's zero value; a present field of the wrong
	 * type, a non-integer number, a root that is not an object, or JSON PHP
	 * cannot decode is refused (null). Unknown keys are ignored.
	 *
	 * No JSON_BIGINT_AS_STRING: an integer beyond int64 decodes to a float, which
	 * both the int and the string rules refuse, as Go does (with the flag it
	 * would become a string and pass as a string field).
	 *
	 * Accepted divergences, where PHP is stricter or differs on inputs Go's Seal
	 * never makes (pl7-plan.md section 3.1): keys are matched case-sensitively;
	 * invalid UTF-8, unpaired surrogates and keys starting with NUL are refused;
	 * a duplicated object replaces rather than merges; nesting deeper than 32 is
	 * refused (Go allows about 10000).
	 *
	 * @param string $plain Plaintext.
	 * @return array|null The payload in contract field order.
	 */
	private static function decode( #[\SensitiveParameter] string $plain ): ?array {
		try {
			$data = json_decode( $plain, false, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			return null;
		}
		if ( null === $data ) {
			$data = new stdClass();
		}
		if ( ! $data instanceof stdClass ) {
			return null;
		}

		$payload = array();
		foreach ( self::FIELDS as $name => $kind ) {
			$value = property_exists( $data, $name ) ? $data->$name : null;
			switch ( $kind ) {
				case 'int':
					$value = self::int_value( $value );
					break;
				case 'string':
					$value = self::string_value( $value );
					break;
				case 'cart':
					$value = self::cart_value( $value );
					break;
				default:
					$value = self::address_value( $value );
			}
			if ( null === $value ) {
				return null;
			}
			$payload[ $name ] = $value;
		}
		return $payload;
	}

	/**
	 * A JSON integer (Go int64), 0 for absent/null, null when it is anything else.
	 *
	 * @param mixed $value Decoded value.
	 * @return int|null
	 */
	private static function int_value( $value ): ?int {
		if ( null === $value ) {
			return 0;
		}
		return is_int( $value ) ? $value : null;
	}

	/**
	 * A JSON string, "" for absent/null, null when it is anything else.
	 *
	 * @param mixed $value Decoded value.
	 * @return string|null
	 */
	private static function string_value( $value ): ?string {
		if ( null === $value ) {
			return '';
		}
		return is_string( $value ) ? $value : null;
	}

	/**
	 * The cart: an array of line objects; a `null` line is a zero line (as Go).
	 *
	 * @param mixed $value Decoded value.
	 * @return array|null
	 */
	private static function cart_value( $value ): ?array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$cart = array();
		foreach ( $value as $line ) {
			$line = null === $line ? new stdClass() : $line;
			if ( ! $line instanceof stdClass ) {
				return null;
			}
			$out = array();
			foreach ( self::LINE_FIELDS as $name ) {
				$out[ $name ] = self::int_value( property_exists( $line, $name ) ? $line->$name : null );
				if ( null === $out[ $name ] ) {
					return null;
				}
			}
			$cart[] = $out;
		}
		return $cart;
	}

	/**
	 * An address object with its 8 string fields ("" when absent or null).
	 *
	 * @param mixed $value Decoded value.
	 * @return array|null
	 */
	private static function address_value( $value ): ?array {
		$value = null === $value ? new stdClass() : $value;
		if ( ! $value instanceof stdClass ) {
			return null;
		}
		$address = array();
		foreach ( self::ADDRESS_FIELDS as $name ) {
			$address[ $name ] = self::string_value( property_exists( $value, $name ) ? $value->$name : null );
			if ( null === $address[ $name ] ) {
				return null;
			}
		}
		return $address;
	}

	/**
	 * Whether this PHP can do AES-256-GCM at all (an infrastructure fault, not a bad link).
	 *
	 * @return bool
	 */
	private static function cipher_available(): bool {
		static $available = null;
		if ( null === $available ) {
			$available = function_exists( 'openssl_decrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
		}
		return $available;
	}

	/**
	 * Lowercase hex sha256 of the 12 nonce bytes.
	 *
	 * @param string $raw Decoded token bytes.
	 * @return string
	 */
	private static function hash_nonce( #[\SensitiveParameter] string $raw ): string {
		return hash( 'sha256', substr( $raw, 0, self::NONCE_BYTES ) );
	}

	/**
	 * A refusal result.
	 *
	 * @param string $code One of CODES other than OK.
	 * @return array{code: string, payload: null, nonce_hash: null}
	 */
	private static function refuse( string $code ): array {
		return array(
			'code'       => $code,
			'payload'    => null,
			'nonce_hash' => null,
		);
	}
}
