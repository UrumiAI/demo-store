<?php
/**
 * Signed HTTPS client for plugin → service calls A1–A4 (contract.md section 4).
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends signed calls to the service and returns a plain result array.
 *
 * Every result has the same shape:
 *   - `ok`      true for a 2xx or a 304
 *   - `status`  HTTP status, 0 when the call never got an answer
 *   - `headers` lower-cased response headers
 *   - `body`    raw response body (empty when the answer was over the cap)
 *   - `data`    decoded JSON body, or null
 *   - `error`   null, or `{code, message}` from the service or the transport
 */
class Urumi_Agentic_Client {

	const API_VERSION = 'v1';
	const TIMEOUT     = 10;

	/**
	 * A4 runs in the shared Action Scheduler runner: a slow service must not
	 * hold up every other store job in the batch.
	 */
	const ORDER_EVENT_TIMEOUT = 5;

	/**
	 * Largest answer read from A1 and A4, in bytes. Their answers are a few
	 * hundred bytes; a bigger one is refused, never decoded.
	 */
	const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Largest A3 (feed status) answer, in bytes. The contract does not cap the rows
	 * the service lists yet, so a store with many left-out items can send a big
	 * answer. To drop to MAX_RESPONSE_BYTES once the contract caps the rows.
	 */
	const MAX_FEED_STATUS_BYTES = 8388608;

	/**
	 * Where the slug, URL and request secret come from.
	 *
	 * @var Urumi_Agentic_Secrets
	 */
	private $secrets;

	/**
	 * Returns the current Unix time. Injected so tests can pin the timestamp.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Builds a client over the mounted Secret.
	 *
	 * @param Urumi_Agentic_Secrets|null $secrets Secret source.
	 * @param callable|null              $clock   Returns Unix seconds.
	 */
	public function __construct( $secrets = null, $clock = null ) {
		$this->secrets = $secrets ? $secrets : new Urumi_Agentic_Secrets();
		$this->clock   = $clock ? $clock : 'time';
	}

	/**
	 * A1: tells the service which versions are running. Sent on activation, on
	 * update and once a day.
	 *
	 * @param string $checkout `blocks` or `classic`.
	 * @return array
	 */
	public function check_in( $checkout ) {
		return $this->request(
			'POST',
			'check-in',
			array(
				'plugin_version' => URUMI_AGENTIC_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'checkout'       => $checkout,
			)
		);
	}

	/**
	 * A2: fetches the last good feed. A 304 means the stored file is current.
	 * No size cap here: the feed sync (PL-5) owns its own limit.
	 *
	 * @param string|null $etag ETag of the stored file.
	 * @return array
	 */
	public function get_feed( $etag = null ) {
		$headers = $etag ? array( 'If-None-Match' => $etag ) : array();
		return $this->request( 'GET', 'feed', null, $headers, self::TIMEOUT, null );
	}

	/**
	 * A3: feed status for the merchant screen. Capped at MAX_FEED_STATUS_BYTES:
	 * the service may list many rows until the contract caps them.
	 *
	 * @param int $timeout Seconds to wait. The merchant screen passes a short
	 *                     one so a slow service cannot hold the page.
	 * @return array
	 */
	public function get_feed_status( $timeout = self::TIMEOUT ) {
		return $this->request( 'GET', 'feed/status', null, array(), (int) $timeout, self::MAX_FEED_STATUS_BYTES );
	}

	/**
	 * A4: one order status event. The service ignores a repeated `event_id`.
	 *
	 * @param array $event Event fields from contract.md A4.
	 * @return array
	 */
	public function post_order_event( array $event ) {
		return $this->request( 'POST', 'order-events', $event, array(), self::ORDER_EVENT_TIMEOUT );
	}

	/**
	 * Sends one signed call to `/{api version}/stores/{slug}/{route}`. Private: the
	 * A1–A4 methods are the API, so every route and header is one the contract names.
	 *
	 * An answer over $max_bytes is refused with `response_too_large` for any status:
	 * the status is kept, the body is dropped and nothing is decoded. WordPress is
	 * asked to stop reading at $max_bytes + 1 (`limit_response_size`; cURL cuts the
	 * decoded body there without an error), and the size is checked here as well,
	 * because a `pre_http_request` filter answers without that limit. The rest of a
	 * long answer is still read and thrown away, within the timeout. Residual: on the
	 * fsockopen fallback only, a small gzip body is inflated without a cap.
	 *
	 * @param string     $method    HTTP method.
	 * @param string     $route     Route under the store, e.g. `feed/status`.
	 * @param array|null $payload   JSON body, or null for none.
	 * @param array      $headers   Extra request headers.
	 * @param int        $timeout   Seconds to wait for the answer.
	 * @param int|null   $max_bytes Largest answer accepted, or null for no cap here.
	 * @return array
	 */
	private function request( $method, $route, $payload = null, array $headers = array(), $timeout = self::TIMEOUT, $max_bytes = self::MAX_RESPONSE_BYTES ) {
		$store  = $this->secrets->store_slug();
		$base   = $this->secrets->service_url();
		$secret = $this->secrets->current_request_secret();
		if ( null === $store || null === $base || null === $secret ) {
			return self::result( 0, array(), '', 'not_configured', 'The agentic Secret is missing or invalid.' );
		}

		// $base is origin-only (Secrets::service_url), so the URL path is exactly
		// what is signed and what the service sees.
		$path = '/' . self::API_VERSION . '/stores/' . rawurlencode( $store ) . '/' . $route;
		$url  = $base . $path;
		$body = null === $payload ? '' : wp_json_encode( $payload );
		if ( false === $body ) {
			return self::result( 0, array(), '', 'encode_failed', 'The request body could not be encoded as JSON.' );
		}
		$timestamp = (int) call_user_func( $this->clock );
		$method    = strtoupper( $method );

		$headers['X-Urumi-Agentic-Plugin']    = URUMI_AGENTIC_VERSION;
		$headers['X-Urumi-Agentic-Store']     = $store;
		$headers['X-Urumi-Agentic-Timestamp'] = (string) $timestamp;
		$headers['X-Urumi-Agentic-Key-Id']    = $secret['id'];
		$headers['X-Urumi-Agentic-Signature'] = Urumi_Agentic_Signer::sign( $secret['key'], $method, $path, $timestamp, $store, $body );
		$headers['Accept']                    = 'application/json';
		if ( null !== $payload ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'body'        => null === $payload ? null : $body,
			'timeout'     => max( 1, (int) $timeout ),
			// A redirect would be sent unsigned to wherever it points.
			'redirection' => 0,
			// Cloudflare's WAF blocks default library user agents (CLAUDE.md).
			'user-agent'  => 'urumi-agentic-plugin/' . URUMI_AGENTIC_VERSION,
		);
		if ( null !== $max_bytes ) {
			// One byte over the cap, so an answer over it can be told from one at it.
			$args['limit_response_size'] = (int) $max_bytes + 1;
		}
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return self::result( 0, array(), '', 'transport_error', $response->get_error_message() );
		}

		$status        = (int) wp_remote_retrieve_response_code( $response );
		$response_body = (string) wp_remote_retrieve_body( $response );
		$response_hdrs = array();
		// WordPress returns a case-insensitive dictionary object, not an array.
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			// A repeated header comes back as an array; keep the last value.
			$response_hdrs[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}

		if ( null !== $max_bytes && self::too_large( $response_body, $response_hdrs, (int) $max_bytes ) ) {
			return self::result( $status, $response_hdrs, '', 'response_too_large', sprintf( 'The service answer is over %d bytes.', $max_bytes ) );
		}

		if ( ( $status >= 200 && $status < 300 ) || 304 === $status ) {
			return self::result( $status, $response_hdrs, $response_body );
		}
		// WordPress returns a 3xx as-is when 'redirection' is 0. A redirect means the
		// service URL or the route in front of it is wrong, not a network blip.
		if ( $status >= 300 && $status < 400 ) {
			return self::result( $status, $response_hdrs, $response_body, 'redirect_refused', 'The service answered with a redirect.' );
		}

		$decoded = json_decode( $response_body, true );
		$code    = is_array( $decoded ) && isset( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : 'http_' . $status;
		$message = is_array( $decoded ) && isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';
		return self::result( $status, $response_hdrs, $response_body, $code, $message );
	}

	/**
	 * Whether an answer is over the cap: the body as read, or a numeric
	 * `content-length` the server declared.
	 *
	 * @param string $body      Body as read.
	 * @param array  $headers   Lower-cased response headers.
	 * @param int    $max_bytes Cap.
	 * @return bool
	 */
	private static function too_large( $body, array $headers, $max_bytes ) {
		if ( strlen( $body ) > $max_bytes ) {
			return true;
		}
		$length = trim( (string) ( $headers['content-length'] ?? '' ) );
		return '' !== $length && ctype_digit( $length ) && (float) $length > $max_bytes;
	}

	/**
	 * Builds the result array.
	 *
	 * @param int         $status  HTTP status, 0 for none.
	 * @param array       $headers Lower-cased response headers.
	 * @param string      $body    Raw body.
	 * @param string|null $code    Error code, or null on success.
	 * @param string      $message Error message.
	 * @return array
	 */
	private static function result( $status, array $headers, $body, $code = null, $message = '' ) {
		$data = json_decode( $body, true );
		return array(
			'ok'      => null === $code,
			'status'  => $status,
			'headers' => $headers,
			'body'    => $body,
			'data'    => is_array( $data ) ? $data : null,
			'error'   => null === $code ? null : array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
