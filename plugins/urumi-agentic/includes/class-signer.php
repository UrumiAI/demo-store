<?php
/**
 * Request signing for plugin → service calls (contract.md section 3).
 *
 * Pure functions with no WordPress calls, so the Go service can be checked
 * against the same known-answer vectors.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the string to sign and the HMAC-SHA256 signature.
 */
class Urumi_Agentic_Signer {

	/**
	 * The exact string to sign: method, path with query, timestamp, store slug
	 * and the lowercase hex SHA-256 of the body, joined by "\n" with no
	 * trailing newline. An empty body hashes the empty string.
	 *
	 * @param string $method    HTTP method.
	 * @param string $path      Path plus query string exactly as sent.
	 * @param int    $timestamp Unix seconds, same as X-Urumi-Agentic-Timestamp.
	 * @param string $store     Store slug, same as X-Urumi-Agentic-Store.
	 * @param string $body      Raw request body.
	 * @return string
	 */
	public static function string_to_sign( $method, $path, $timestamp, $store, $body ) {
		return implode(
			"\n",
			array(
				strtoupper( $method ),
				$path,
				(string) $timestamp,
				$store,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Lowercase hex HMAC-SHA256 of the string to sign.
	 *
	 * @param string $key       Raw request secret.
	 * @param string $method    HTTP method.
	 * @param string $path      Path plus query string exactly as sent.
	 * @param int    $timestamp Unix seconds.
	 * @param string $store     Store slug.
	 * @param string $body      Raw request body.
	 * @return string
	 */
	public static function sign( $key, $method, $path, $timestamp, $store, $body ) {
		return hash_hmac( 'sha256', self::string_to_sign( $method, $path, $timestamp, $store, $body ), $key );
	}
}
