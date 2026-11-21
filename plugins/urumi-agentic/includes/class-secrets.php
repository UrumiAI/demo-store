<?php
/**
 * Per-store secrets, read from the `{slug}-agentic` Kubernetes Secret.
 *
 * The Secret is mounted as files (contract.md section 3), never stored in the
 * DB: the replica copies every table, so a key in `wp_options` would be
 * readable by every replica user. Kubernetes refreshes the mounted files in
 * running pods, so a key rotation needs no restart.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the store slug, service URL and key rings from the mounted Secret.
 *
 * Build at most one instance per request or background job, and only when a
 * call to the service or a hand-off open needs it, never on ordinary page loads.
 * An instance caches what it read (so a long-lived static would miss a key
 * rotation), and the first read clears the worker's whole stat cache.
 */
class Urumi_Agentic_Secrets {

	const DEFAULT_DIR = '/etc/urumi-agentic';

	const FILE_STORE_SLUG      = 'store_slug';
	const FILE_SERVICE_URL     = 'service_url';
	const FILE_REQUEST_SECRETS = 'request_secrets.json';
	const FILE_HANDOFF_KEYS    = 'handoff_keys.json';
	const KEY_BYTES            = 32;
	// `D`: `$` must not also match before a trailing newline (as Go's regexp, contract section 3).
	const STORE_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*$/D';
	const KEY_ID_PATTERN     = '/^k[0-9]{1,6}$/D';
	// DNS name with at least two labels; the last label starts with a letter, so an IP is refused.
	const HOST_PATTERN = '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]([a-z0-9-]*[a-z0-9])?$/D';
	// 32 bytes in standard base64 with padding (RFC 4648 section 4): 43 characters then "=".
	const KEY_PATTERN           = '/^[A-Za-z0-9+\/]{43}=$/D';
	const MAX_SECRET_FILE_BYTES = 16384;

	/**
	 * Directory the Secret is mounted at.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * File contents already read by this instance, so one request reads each file once.
	 * A rotation only has to show up between requests.
	 *
	 * @var array<string, string|null>
	 */
	private $cache = array();

	/**
	 * Directory the files are actually read from, resolved once per instance.
	 *
	 * @var string|null
	 */
	private $data_dir = null;

	/**
	 * Reads from `URUMI_AGENTIC_SECRETS_DIR` when defined, else the default mount.
	 *
	 * @param string|null $dir Directory override (tests).
	 */
	public function __construct( $dir = null ) {
		if ( null === $dir ) {
			$dir = defined( 'URUMI_AGENTIC_SECRETS_DIR' ) ? URUMI_AGENTIC_SECRETS_DIR : self::DEFAULT_DIR;
		}
		$this->dir = rtrim( (string) $dir, '/' );
	}

	/**
	 * Whether everything a signed call needs is present and valid.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return null !== $this->store_slug()
			&& null !== $this->service_url()
			&& null !== $this->current_request_secret();
	}

	/**
	 * The store slug, or null when missing or malformed.
	 *
	 * @return string|null
	 */
	public function store_slug() {
		$slug = $this->read( self::FILE_STORE_SLUG );
		return ( null !== $slug && preg_match( self::STORE_SLUG_PATTERN, $slug ) ) ? $slug : null;
	}

	/**
	 * The service origin, `https://host`, or null. Only HTTPS is accepted (tenant
	 * pods may only send out on 80/443), and only scheme and host: the signed path
	 * must be exactly what the service sees, so nothing may add a path, port,
	 * query or credentials (contract.md section 3).
	 *
	 * @return string|null
	 */
	public function service_url() {
		$url = $this->read( self::FILE_SERVICE_URL );
		// wp_parse_url drops an empty "?" or "#", so refuse them in the raw text.
		if ( null === $url || strpbrk( $url, '?#' ) ) {
			return null;
		}
		$parts = wp_parse_url( rtrim( $url, '/' ) );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( array_diff( array_keys( $parts ), array( 'scheme', 'host' ) ) || ! preg_match( self::HOST_PATTERN, $host ) ) {
			return null;
		}
		return 'https://' . $host;
	}

	/**
	 * The request secret the plugin signs with.
	 *
	 * @return array{id: string, key: string}|null Key id and raw 32-byte key.
	 */
	public function current_request_secret() {
		$ring = $this->key_ring( self::FILE_REQUEST_SECRETS );
		if ( null === $ring || ! isset( $ring['keys'][ $ring['current'] ] ) ) {
			return null;
		}
		return array(
			'id'  => $ring['current'],
			'key' => $ring['keys'][ $ring['current'] ],
		);
	}

	/**
	 * Hand-off key lookup results (pl7-plan.md section 3.3).
	 */
	const LOOKUP_OK          = 'ok';
	const LOOKUP_ABSENT      = 'absent';
	const LOOKUP_UNAVAILABLE = 'unavailable';

	/**
	 * A hand-off key by id. The token names the key that sealed it, so during a
	 * rotation the plugin tries only that one (contract.md section 5).
	 *
	 * Three answers, because "this store does not hold that key" refuses a link
	 * while "the ring can't be used right now" must only ask for a retry:
	 *   - `ok`: the ring is usable and holds a valid 32-byte key for the id;
	 *   - `absent`: the ring is usable and has no such id (or the id is not `k<digits>`);
	 *   - `unavailable`: the file is missing, empty, oversized, unreadable (the
	 *     `..data` race), not a `{current, keys}` object, has no valid key at all,
	 *     names a `current` that is not a valid key in it, or names this id with
	 *     a key that is not strict base64 of 32 bytes.
	 * A dangling `current` is a deliberate fail-safe: opening never uses
	 * `current`, but such a file is mid-write or wrong, and the cost is a 503 the
	 * shopper can retry, never a burnt link.
	 *
	 * @param string $key_id Key id from the token.
	 * @return array{status: string, key: string|null}
	 */
	public function handoff_key_lookup( $key_id ) {
		$raw  = $this->read( self::FILE_HANDOFF_KEYS );
		$data = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || ! is_string( $data['current'] ?? null ) || ! is_array( $data['keys'] ?? null ) ) {
			return self::lookup( self::LOOKUP_UNAVAILABLE );
		}
		$ring = $this->key_ring( self::FILE_HANDOFF_KEYS );
		if ( null === $ring || ! $ring['keys'] || ! isset( $ring['keys'][ $ring['current'] ] ) ) {
			return self::lookup( self::LOOKUP_UNAVAILABLE );
		}
		if ( ! is_string( $key_id ) || ! preg_match( self::KEY_ID_PATTERN, $key_id ) ) {
			return self::lookup( self::LOOKUP_ABSENT );
		}
		if ( isset( $ring['keys'][ $key_id ] ) ) {
			return self::lookup( self::LOOKUP_OK, $ring['keys'][ $key_id ] );
		}
		// Named in the file but skipped by key_ring(): its key is malformed.
		return array_key_exists( $key_id, $data['keys'] ) ? self::lookup( self::LOOKUP_UNAVAILABLE ) : self::lookup( self::LOOKUP_ABSENT );
	}

	/**
	 * A lookup answer.
	 *
	 * @param string      $status One of the LOOKUP_* values.
	 * @param string|null $key    Raw key for `ok`.
	 * @return array{status: string, key: string|null}
	 */
	private static function lookup( $status, $key = null ) {
		return array(
			'status' => $status,
			'key'    => $key,
		);
	}

	/**
	 * Parses a key ring file: `{"current": "k2", "keys": {"k2": "<base64>", ...}}`.
	 * Keys that are not exactly 32 bytes of strict standard base64, or whose id is
	 * not `k<digits>`, are skipped.
	 *
	 * @param string $file File name in the Secret.
	 * @return array{current: string, keys: array<string, string>}|null
	 */
	private function key_ring( $file ) {
		$raw  = $this->read( $file );
		$data = null === $raw ? null : json_decode( $raw, true );
		if ( ! is_array( $data ) || ! is_string( $data['current'] ?? null ) || ! is_array( $data['keys'] ?? null ) ) {
			return null;
		}

		$keys = array();
		foreach ( $data['keys'] as $id => $encoded ) {
			// json_decode turns an id like "1" into an int key, so compare as a string.
			$id = (string) $id;
			if ( ! preg_match( self::KEY_ID_PATTERN, $id ) || ! is_string( $encoded ) ) {
				continue;
			}
			if ( ! preg_match( self::KEY_PATTERN, $encoded ) ) {
				continue;
			}
			$key = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key material, not obfuscation.
			if ( false !== $key && self::KEY_BYTES === strlen( $key ) ) {
				$keys[ $id ] = $key;
			}
		}

		return array(
			'current' => $data['current'],
			'keys'    => $keys,
		);
	}

	/**
	 * Reads one trimmed file from the Secret, or null when absent, empty or oversized.
	 *
	 * @param string $file File name.
	 * @return string|null
	 */
	private function read( $file ) {
		if ( array_key_exists( $file, $this->cache ) ) {
			return $this->cache[ $file ];
		}

		// One read, no is_readable()/filesize() first: a missing or half-rotated
		// file is just "not configured", never a warning that names the path.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- local mounted Secret, not a URL.
		$contents = @file_get_contents( $this->data_dir() . '/' . $file, false, null, 0, self::MAX_SECRET_FILE_BYTES + 1 );
		if ( false === $contents || strlen( $contents ) > self::MAX_SECRET_FILE_BYTES ) {
			$contents = '';
		}
		$contents             = trim( $contents );
		$this->cache[ $file ] = '' === $contents ? null : $contents;
		return $this->cache[ $file ];
	}

	/**
	 * Where to read the files from, after a rotation too.
	 *
	 * Kubelet rotates a Secret from outside PHP: it repoints the `..data` symlink
	 * to a new timestamped directory and deletes the old one. A PHP-FPM worker's
	 * realpath cache (realpath_cache_ttl, 120s by default) would keep resolving
	 * `{dir}/{file}` to the deleted directory, and a per-path clearstatcache()
	 * does not help. So the whole cache is cleared once per instance, and the
	 * current `..data` target is read directly.
	 *
	 * Accepted: if kubelet swaps and deletes the old directory in the microseconds
	 * between readlink() and the reads, this one instance reads "not configured".
	 * The next request or job is fine, and A4 sends retry.
	 *
	 * @return string
	 */
	private function data_dir() {
		if ( null === $this->data_dir ) {
			clearstatcache( true );
			$target         = @readlink( $this->dir . '/..data' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- absent outside Kubernetes.
			$this->data_dir = ( is_string( $target ) && '' !== $target && false === strpos( $target, '/' ) )
				? $this->dir . '/' . $target
				: $this->dir;
		}
		return $this->data_dir;
	}
}
