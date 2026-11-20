<?php
/**
 * Feed status for the merchant screen: the A3 call, a short cache and the panel.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches contract A3 only when the merchant screen is opened, keeps the result
 * in a short transient and prints it.
 *
 * The transient may live in the object cache (Redis), which is flushed on a
 * deploy. That is fine: the next view just asks the service again.
 */
class Urumi_Agentic_Feed_Status {

	const TRANSIENT = 'urumi_agentic_feed_status';

	/** Seconds a good answer (or the empty "no build yet" state) is reused. */
	const TTL = 300;

	/** Seconds a failed call is remembered, so a slow service is not asked on every reload. */
	const ERROR_TTL = 60;

	/** Seconds the screen waits for the service (the client default is 10). */
	const TIMEOUT = 5;

	/** How many left-out items and price mismatches are listed. */
	const LIST_LIMIT = 20;

	/** Characters kept of any one text value from the service in the cache. */
	const MAX_TEXT = 200;

	const KIND_OK             = 'ok';
	const KIND_NO_BUILD       = 'no_build';
	const KIND_NOT_CONFIGURED = 'not_configured';
	const KIND_ERROR          = 'error';

	/**
	 * Codes that mean this store is not connected to the service. Refresh will not
	 * help; Urumi support has to fix the connection. (`unknown_key` is left out: it
	 * can be momentary while keys are rotated.)
	 */
	const NOT_CONNECTED_CODES = array( 'not_configured', 'unknown_store', 'bad_signature' );

	/**
	 * Why a feed was held (contract A3 `hold_reason`).
	 *
	 * @return array<string, string>
	 */
	private static function hold_reasons() {
		return array(
			'spot_check'   => __( 'Too many of the items checked against the live store differed from the feed.', 'urumi-agentic' ),
			'shrink_guard' => __( 'The feed would have lost too many products compared with the last one.', 'urumi-agentic' ),
		);
	}

	/**
	 * Why a build was skipped (`skip_reason`). The service may send others.
	 *
	 * @return array<string, string>
	 */
	private static function skip_reasons() {
		return array(
			'replica_lagging'       => __( "The store's database copy was behind, so no build was made.", 'urumi-agentic' ),
			'store_api_unreachable' => __( "The service could not reach the store's checkout API.", 'urumi-agentic' ),
			'feed_empty'            => __( 'The build found no products to list.', 'urumi-agentic' ),
			'tax_display_differs'   => __( 'Prices are shown with different tax settings in the shop and in the checkout.', 'urumi-agentic' ),
		);
	}

	/**
	 * The feed status to show: the cached result, or a fresh A3 call.
	 *
	 * The result is `{kind, data, code, message, fetched_at}`. `kind` is one of the
	 * KIND_ constants; `data` is the printed fields of the A3 body for `ok` (see
	 * slim()), otherwise null.
	 *
	 * Only the merchant screen calls this, after its capability check. A3 answers
	 * can be up to 8 MiB and decoding one can take several times that in memory,
	 * so on a cache miss the limit is raised to the admin one (WP_MAX_MEMORY_LIMIT)
	 * first: WordPress raises it for `manage_options` only, and a shop manager
	 * lacks that.
	 *
	 * @param Urumi_Agentic_Client|null $client Client, for tests.
	 * @return array
	 */
	public static function get( $client = null ) {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['kind'] ) ) {
			return $cached;
		}

		$client = $client ? $client : new Urumi_Agentic_Client();
		wp_raise_memory_limit( 'admin' );
		$model = self::from_result( $client->get_feed_status( self::TIMEOUT ) );

		// A missing Secret costs nothing to re-check, so it is never cached: the
		// merchant sees the fix on the next view.
		if ( 'not_configured' !== $model['code'] ) {
			// Only a good answer or the empty state is kept for the full time; any failure is
			// remembered briefly.
			$good = in_array( $model['kind'], array( self::KIND_OK, self::KIND_NO_BUILD ), true );
			set_transient( self::TRANSIENT, $model, $good ? self::TTL : self::ERROR_TTL );
		}
		return $model;
	}

	/**
	 * Drops the cached result, so the next view asks the service again.
	 *
	 * @return void
	 */
	public static function refresh() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Turns a client result into the screen's model.
	 *
	 * @param array $result Result of Urumi_Agentic_Client::get_feed_status().
	 * @return array
	 */
	public static function from_result( array $result ) {
		$model = array(
			'kind'       => self::KIND_ERROR,
			'data'       => null,
			'code'       => '',
			'message'    => '',
			'fetched_at' => time(),
		);

		if ( ! empty( $result['ok'] ) ) {
			if ( is_array( $result['data'] ?? null ) ) {
				$model['kind'] = self::KIND_OK;
				$model['data'] = self::slim( $result['data'] );
				return $model;
			}
			$model['code']    = 'invalid_response';
			$model['message'] = __( 'The service answered with something that is not a feed status.', 'urumi-agentic' );
			return $model;
		}

		$code             = self::cut( $result['error']['code'] ?? '' );
		$model['code']    = $code;
		$model['message'] = self::cut( $result['error']['message'] ?? '' );
		if ( in_array( $code, self::NOT_CONNECTED_CODES, true ) ) {
			$model['kind'] = self::KIND_NOT_CONFIGURED;
		} elseif ( 404 === (int) ( $result['status'] ?? 0 ) && 'no_build' === $code ) {
			$model['kind'] = self::KIND_NO_BUILD;
		}
		if ( self::KIND_ERROR === $model['kind'] && 'transport_error' === $code ) {
			// The raw message can name internal hosts: log it, show a generic line.
			self::log( 'Feed status call failed: ' . $model['message'] );
			$model['message'] = '';
		} elseif ( 'response_too_large' === $code ) {
			self::log( 'Feed status call failed: ' . $code . ' (status ' . (int) ( $result['status'] ?? 0 ) . ')' );
		}
		return $model;
	}

	/**
	 * The fields of an A3 body that the panel prints, and nothing else, so the
	 * transient stays small (about 100,000 characters at worst, far less for real
	 * answers) whatever the service sent. Lists
	 * keep their first LIST_LIMIT rows plus a `*_count` of the rows the service
	 * sent. `items_left_out_truncated` is the service's flag only ("it listed only
	 * some of them"); cutting to LIST_LIMIT here never sets it.
	 *
	 * @param array $data Decoded A3 body.
	 * @return array
	 */
	private static function slim( array $data ) {
		$total     = $data['items_total'] ?? null;
		$left_out  = self::rows_of( $data['items_left_out'] ?? null );
		$mismatch  = self::rows_of( $data['price_mismatches'] ?? null );
		$left_rows = array();
		foreach ( array_slice( $left_out, 0, self::LIST_LIMIT ) as $item ) {
			$missing     = is_array( $item['missing'] ?? null ) ? array_values( array_filter( $item['missing'], 'is_scalar' ) ) : array();
			$left_rows[] = array(
				'item_id' => self::cut( $item['item_id'] ?? '' ),
				'missing' => array_map( array( __CLASS__, 'cut' ), array_slice( $missing, 0, self::LIST_LIMIT ) ),
			);
		}
		$mismatch_rows = array();
		foreach ( array_slice( $mismatch, 0, self::LIST_LIMIT ) as $item ) {
			$mismatch_rows[] = array(
				'item_id'    => self::cut( $item['item_id'] ?? '' ),
				'feed_price' => self::cut( $item['feed_price'] ?? '' ),
				'site_price' => self::cut( $item['site_price'] ?? '' ),
			);
		}
		return array(
			'state'                    => self::cut( $data['state'] ?? '' ),
			'hold_reason'              => self::cut( $data['hold_reason'] ?? '' ),
			'skip_reason'              => self::cut( $data['skip_reason'] ?? '' ),
			'last_build_at'            => self::cut( $data['last_build_at'] ?? '' ),
			'items_total'              => is_int( $total ) || is_float( $total ) ? $total : null,
			'items_left_out'           => $left_rows,
			'items_left_out_count'     => count( $left_out ),
			'items_left_out_truncated' => ! empty( $data['items_left_out_truncated'] ),
			'price_mismatches'         => $mismatch_rows,
			'price_mismatches_count'   => count( $mismatch ),
		);
	}

	/**
	 * A value from the service as plain text, at most MAX_TEXT characters.
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @return string
	 */
	private static function cut( $value ) {
		return mb_substr( self::text( $value ), 0, self::MAX_TEXT );
	}

	/**
	 * Writes a line to the WooCommerce log (source `urumi-agentic`), or the PHP log without Woo.
	 *
	 * @param string $line Text.
	 * @return void
	 */
	private static function log( $line ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $line, array( 'source' => 'urumi-agentic' ) );
			return;
		}
		error_log( 'urumi-agentic: ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Prints the status panel. Every value from the service is escaped.
	 *
	 * @param array $model From get().
	 * @return void
	 */
	public static function render( array $model ) {
		switch ( $model['kind'] ?? '' ) {
			case self::KIND_OK:
				self::render_status( (array) $model['data'] );
				break;
			case self::KIND_NO_BUILD:
				echo '<p>' . esc_html__( 'No product feed has been built yet. The first build runs after a channel is switched on and the service has read that setting, so check back later.', 'urumi-agentic' ) . '</p>';
				break;
			case self::KIND_NOT_CONFIGURED:
				echo '<p><strong>' . esc_html__( 'Not connected.', 'urumi-agentic' ) . '</strong> ';
				echo esc_html__( 'This store is not connected to the agentic checkout service, so there is no feed status to show. Refreshing will not help; please contact Urumi support.', 'urumi-agentic' ) . '</p>';
				if ( '' !== self::text( $model['code'] ?? '' ) ) {
					echo '<p class="description">' . esc_html( self::text( $model['code'] ) ) . '</p>';
				}
				break;
			default:
				echo '<p><strong>' . esc_html__( 'The feed status could not be loaded.', 'urumi-agentic' ) . '</strong> ';
				echo esc_html__( 'Try again with Refresh in a moment.', 'urumi-agentic' ) . '</p>';
				$detail = trim( self::text( $model['code'] ?? '' ) . ' ' . self::text( $model['message'] ?? '' ) );
				if ( '' !== $detail ) {
					echo '<p class="description">' . esc_html( $detail ) . '</p>';
				}
		}

		if ( ! empty( $model['fetched_at'] ) ) {
			/* translators: %s: date and time in the store's time zone. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Checked %s.', 'urumi-agentic' ), self::format_time( (int) $model['fetched_at'] ) ) ) . '</p>';
		}
	}

	/**
	 * Prints a good A3 answer.
	 *
	 * @param array $data Cached A3 fields (or, from an older cache, the raw body).
	 * @return void
	 */
	private static function render_status( array $data ) {
		$reason  = self::reason_text( self::hold_reasons(), $data['hold_reason'] ?? null );
		$skipped = self::reason_text( self::skip_reasons(), $data['skip_reason'] ?? null );
		$built   = self::text( $data['last_build_at'] ?? '' );
		$total   = $data['items_total'] ?? null;

		echo '<table class="widefat striped" style="max-width:48em"><tbody>';
		self::row( __( 'Last build', 'urumi-agentic' ), '' === $built ? __( 'Not available', 'urumi-agentic' ) : self::format_time( $built ) );
		self::row( __( 'State', 'urumi-agentic' ), self::state_text( self::text( $data['state'] ?? '' ) ) );
		if ( '' !== $reason ) {
			self::row( __( 'Why it was held', 'urumi-agentic' ), $reason );
		}
		if ( '' !== $skipped ) {
			self::row( __( 'Why it was skipped', 'urumi-agentic' ), $skipped );
		}
		self::row( __( 'Items in the feed', 'urumi-agentic' ), is_int( $total ) || is_float( $total ) ? number_format_i18n( (int) $total ) : __( 'Not available', 'urumi-agentic' ) );
		echo '</tbody></table>';

		self::render_left_out( $data );
		self::render_mismatches( $data );
	}

	/**
	 * Prints the items left out of the feed, with what each one is missing.
	 *
	 * @param array $data Cached A3 fields (or, from an older cache, the raw body).
	 * @return void
	 */
	private static function render_left_out( array $data ) {
		$items = self::rows_of( $data['items_left_out'] ?? null );
		echo '<h3>' . esc_html__( 'Items left out', 'urumi-agentic' ) . '</h3>';
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No items were left out.', 'urumi-agentic' ) . '</p>';
			return;
		}

		$count = self::count_of( $data, 'items_left_out_count', $items );
		if ( ! empty( $data['items_left_out_truncated'] ) ) {
			/* translators: %d: number of items listed by the service. */
			$summary = sprintf( __( 'At least %d items were left out of the feed because they are missing required fields. The service listed only some of them.', 'urumi-agentic' ), $count );
		} else {
			/* translators: %d: number of items. */
			$summary = sprintf( _n( '%d item was left out of the feed because it is missing required fields.', '%d items were left out of the feed because they are missing required fields.', $count, 'urumi-agentic' ), $count );
		}
		echo '<p>' . esc_html( $summary ) . '</p>';

		echo '<table class="widefat striped" style="max-width:48em"><thead><tr><th>' . esc_html__( 'Item', 'urumi-agentic' ) . '</th><th>' . esc_html__( 'Missing', 'urumi-agentic' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $items, 0, self::LIST_LIMIT ) as $item ) {
			$missing = is_array( $item['missing'] ?? null ) ? array_map( array( __CLASS__, 'text' ), array_values( $item['missing'] ) ) : array();
			echo '<tr><td><code>' . esc_html( self::text( $item['item_id'] ?? '' ) ) . '</code></td><td>' . esc_html( implode( ', ', $missing ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_more( $count );
	}

	/**
	 * Prints products whose feed price differs from the site price.
	 *
	 * @param array $data Cached A3 fields (or, from an older cache, the raw body).
	 * @return void
	 */
	private static function render_mismatches( array $data ) {
		$items = self::rows_of( $data['price_mismatches'] ?? null );
		echo '<h3>' . esc_html__( 'Price mismatches', 'urumi-agentic' ) . '</h3>';
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No price mismatches.', 'urumi-agentic' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:48em"><thead><tr><th>' . esc_html__( 'Item', 'urumi-agentic' ) . '</th><th>' . esc_html__( 'Price in the feed', 'urumi-agentic' ) . '</th><th>' . esc_html__( 'Price on the site', 'urumi-agentic' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $items, 0, self::LIST_LIMIT ) as $item ) {
			$site = self::text( $item['site_price'] ?? '' );
			echo '<tr><td><code>' . esc_html( self::text( $item['item_id'] ?? '' ) ) . '</code></td><td>' . esc_html( self::text( $item['feed_price'] ?? '' ) ) . '</td><td>';
			// The service sends "" when it could not read the site price.
			echo esc_html( '' === $site ? __( 'Not available', 'urumi-agentic' ) : $site ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_more( self::count_of( $data, 'price_mismatches_count', $items ) );
	}

	/**
	 * How many rows the service sent: the cached count, or the rows themselves in
	 * a model cached by an older version (the raw body, which has no count).
	 *
	 * @param array  $data  Cached A3 fields.
	 * @param string $key   The count's key.
	 * @param array  $items The rows held.
	 * @return int
	 */
	private static function count_of( array $data, $key, array $items ) {
		return is_int( $data[ $key ] ?? null ) ? $data[ $key ] : count( $items );
	}

	/**
	 * Says how many listed rows were not shown.
	 *
	 * @param int $count Rows the service sent.
	 * @return void
	 */
	private static function render_more( $count ) {
		if ( $count > self::LIST_LIMIT ) {
			/* translators: %d: number of rows not shown. */
			echo '<p class="description">' . esc_html( sprintf( __( '%d more not shown.', 'urumi-agentic' ), $count - self::LIST_LIMIT ) ) . '</p>';
		}
	}

	/**
	 * Prints one label and value row.
	 *
	 * @param string $label Label.
	 * @param string $value Plain text value.
	 * @return void
	 */
	private static function row( $label, $value ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * A reason code as text. A known code reads "explanation (code)"; any other
	 * code is shown as it came, so a newer service never shows a blank.
	 *
	 * @param array<string, string> $known Code => explanation.
	 * @param mixed                 $code  Code from the service, or null.
	 * @return string
	 */
	private static function reason_text( array $known, $code ) {
		$code = self::text( $code );
		if ( '' === $code ) {
			return '';
		}
		return isset( $known[ $code ] ) ? sprintf( '%s (%s)', $known[ $code ], $code ) : $code;
	}

	/**
	 * The state as text: known states are spelled out, others shown as they came.
	 *
	 * @param string $state State from the service.
	 * @return string
	 */
	private static function state_text( $state ) {
		$known = array(
			'published' => __( 'Published', 'urumi-agentic' ),
			'held'      => __( 'Held: the new feed was not published', 'urumi-agentic' ),
			'skipped'   => __( 'Skipped: no new feed was built', 'urumi-agentic' ),
		);
		if ( '' === $state ) {
			return __( 'Not available', 'urumi-agentic' );
		}
		return $known[ $state ] ?? $state;
	}

	/**
	 * A timestamp in the store's time zone. Falls back to the raw text.
	 *
	 * @param int|string $time Unix seconds, or an RFC 3339 string.
	 * @return string
	 */
	private static function format_time( $time ) {
		$stamp = is_int( $time ) ? $time : strtotime( (string) $time );
		if ( false === $stamp ) {
			return (string) $time;
		}
		return wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $stamp );
	}

	/**
	 * A value from the service as plain text. Arrays and objects become "".
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @return string
	 */
	private static function text( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The objects in a decoded JSON list. Anything else is ignored.
	 *
	 * @param mixed $value Any decoded JSON value.
	 * @return array<int, array>
	 */
	private static function rows_of( $value ) {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}
}
