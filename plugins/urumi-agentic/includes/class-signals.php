<?php
/**
 * Works out which agent an order came from (plugin-plan.md, PL-3).
 *
 * Pure functions over plain arrays, so every rule is unit tested without
 * WordPress or WooCommerce. The order tagger feeds them the request headers,
 * the submitted Woo attribution values and the hand-off hint.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the strongest signal into an order tag, or null for an ordinary order.
 *
 * Strongest first: a hand-off link the order matches, then an agent request
 * header, then Woo's order attribution. Headers and attribution are
 * unverified and only give a `referred` tag.
 */
class Urumi_Agentic_Signals {

	/**
	 * Longer header or attribution values are ignored, not truncated.
	 */
	const MAX_LENGTH = 512;

	/**
	 * Exact `Signature-Agent` values (RFC 9421 signed agents) => channel.
	 */
	const SIGNATURE_AGENTS = array(
		'https://chatgpt.com'    => 'chatgpt',
		'https://agent.bot.goog' => 'google',
	);

	/**
	 * Case-sensitive user agent tokens => channel.
	 *
	 * Agents that are not on the service's signed allowlist map to `other`.
	 * Only tokens their makers document for user-initiated fetches are listed:
	 *  - `Claude-Agent/`: Anthropic-hosted browsers acting for a user
	 *    (support.claude.com/en/articles/18234133, "match on the Claude-Agent token");
	 *  - `Claude-User`: Claude fetching a page a user asked about
	 *    (support.claude.com/en/articles/8896518; no full string is published,
	 *    so the bare token is matched);
	 *  - `Perplexity-User/`: Perplexity fetching for a user
	 *    (docs.perplexity.ai/guides/bots).
	 * Grok, Copilot and Meta AI document no user agent of their own, so they
	 * are recognised by attribution only.
	 */
	const USER_AGENT_TOKENS = array(
		'ChatGPT-User/'    => 'chatgpt',
		'Google-Agent'     => 'google',
		'Claude-Agent/'    => 'other',
		'Claude-User'      => 'other',
		'Perplexity-User/' => 'other',
	);

	/**
	 * `utm_source` values (normalised) => channel, for source type `utm`.
	 * Matched exactly, so a look-alike value is not an agent.
	 */
	const UTM_SOURCES = array(
		'chatgpt'               => 'chatgpt',
		'chatgpt.com'           => 'chatgpt',
		'chat.openai.com'       => 'chatgpt',
		'claude.ai'             => 'other',
		'grok.com'              => 'other',
		'perplexity.ai'         => 'other',
		'copilot.microsoft.com' => 'other',
		'meta.ai'               => 'other',
	);

	/**
	 * Referrer hosts (normalised, a leading `www.` removed) => channel, for source type `referral`.
	 * Matched exactly: neither `evilclaude.ai` nor `claude.ai.evil.com` is `claude.ai`.
	 */
	const REFERRAL_HOSTS = array(
		'chatgpt.com'           => 'chatgpt',
		'chat.openai.com'       => 'chatgpt',
		'gemini.google.com'     => 'google',
		'claude.ai'             => 'other',
		'grok.com'              => 'other',
		'perplexity.ai'         => 'other',
		'copilot.microsoft.com' => 'other',
		'meta.ai'               => 'other',
	);

	/**
	 * The Woo attribution fields PL-3 reads, unprefixed.
	 */
	const ATTRIBUTION_FIELDS = array( 'source_type', 'utm_source', 'referrer' );

	/**
	 * The order tag for the strongest signal.
	 *
	 * @param array|null $hint        A hand-off hint the order matches, or null.
	 * @param array      $server      Request headers in `$_SERVER` form.
	 * @param array      $attribution Unprefixed Woo attribution values.
	 * @return array{channel: string, mode: string, signal: string, session: string|null}|null
	 */
	public static function resolve( $hint, array $server, array $attribution ) {
		if ( is_array( $hint ) ) {
			return array(
				'channel' => (string) $hint['channel'],
				'mode'    => 'handoff',
				'signal'  => 'handoff_link',
				'session' => (string) $hint['session_id'],
			);
		}

		$found = self::from_headers( $server );
		if ( null === $found ) {
			$found = self::from_attribution( $attribution );
		}
		if ( null === $found ) {
			return null;
		}
		return array(
			'channel' => $found['channel'],
			'mode'    => 'referred',
			'signal'  => $found['signal'],
			'session' => null,
		);
	}

	/**
	 * An agent named by the request headers: `Signature-Agent` first, then the
	 * user agent.
	 *
	 * @param array $server Request headers in `$_SERVER` form.
	 * @return array{channel: string, signal: string}|null
	 */
	public static function from_headers( array $server ) {
		$signature_agent = self::header( $server, 'HTTP_SIGNATURE_AGENT' );
		if ( null !== $signature_agent ) {
			foreach ( self::signature_agent_values( $signature_agent ) as $value ) {
				if ( isset( self::SIGNATURE_AGENTS[ $value ] ) ) {
					return self::found( self::SIGNATURE_AGENTS[ $value ], 'agent_header' );
				}
			}
		}

		$user_agent = self::header( $server, 'HTTP_USER_AGENT' );
		if ( null !== $user_agent ) {
			foreach ( self::USER_AGENT_TOKENS as $token => $channel ) {
				if ( false !== strpos( $user_agent, $token ) ) {
					return self::found( $channel, 'agent_header' );
				}
			}
		}
		return null;
	}

	/**
	 * The values in a `Signature-Agent` header: every quoted string (a bare
	 * Structured Fields string or the members of a dictionary), or the whole
	 * trimmed value when nothing is quoted.
	 *
	 * @param string $header Header value.
	 * @return string[]
	 */
	public static function signature_agent_values( $header ) {
		if ( preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $header, $matches ) ) {
			return array_map( 'stripslashes', $matches[1] );
		}
		$bare = trim( $header );
		return '' === $bare ? array() : array( $bare );
	}

	/**
	 * An agent named by Woo's order attribution.
	 *
	 * @param array $values Unprefixed attribution values (`source_type`, `utm_source`, `referrer`).
	 * @return array{channel: string, signal: string}|null
	 */
	public static function from_attribution( array $values ) {
		$type = self::normalise( $values['source_type'] ?? null );

		if ( 'utm' === $type ) {
			$source = self::normalise( $values['utm_source'] ?? null );
			if ( null !== $source && isset( self::UTM_SOURCES[ $source ] ) ) {
				return self::found( self::UTM_SOURCES[ $source ], 'utm' );
			}
		}

		if ( 'referral' === $type ) {
			$referrer = self::normalise( $values['referrer'] ?? null );
			$host     = null === $referrer ? null : wp_parse_url( $referrer, PHP_URL_HOST );
			$host     = is_string( $host ) && 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
			if ( is_string( $host ) && isset( self::REFERRAL_HOSTS[ $host ] ) ) {
				return self::found( self::REFERRAL_HOSTS[ $host ], 'referral' );
			}
		}
		return null;
	}

	/**
	 * The attribution fields from a classic checkout's POST, unprefixed.
	 *
	 * Woo writes classic attribution only after the order's creation save, so
	 * the submitted fields are read instead of order meta. The prefix follows
	 * Woo's `wc_order_attribution_tracking_field_prefix` filter.
	 *
	 * @param array $post The submitted fields.
	 * @return array<string, string>
	 */
	public static function attribution_from_post( array $post ) {
		/** This filter is documented in WooCommerce's OrderAttributionMeta trait. */
		$prefix = trim( (string) apply_filters( 'wc_order_attribution_tracking_field_prefix', 'wc_order_attribution_' ), '_' ) . '_';

		$values = array();
		foreach ( self::ATTRIBUTION_FIELDS as $field ) {
			if ( isset( $post[ $prefix . $field ] ) && is_string( $post[ $prefix . $field ] ) ) {
				$values[ $field ] = wp_unslash( $post[ $prefix . $field ] );
			}
		}
		return $values;
	}

	/**
	 * An attribution value as compared: trimmed, URL-decoded, lower case.
	 * Missing, `(none)`, over-long or non-string values are null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function normalise( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_LENGTH ) {
			return null;
		}
		$value = strtolower( rawurldecode( trim( $value ) ) );
		return ( '' === $value || '(none)' === $value ) ? null : $value;
	}

	/**
	 * One request header, or null when it is missing, not a string or too long.
	 *
	 * @param array  $server Request headers in `$_SERVER` form.
	 * @param string $name   `HTTP_*` key.
	 * @return string|null
	 */
	private static function header( array $server, $name ) {
		$value = $server[ $name ] ?? null;
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_LENGTH ) {
			return null;
		}
		return $value;
	}

	/**
	 * A found channel and the signal that proved it.
	 *
	 * @param string $channel Channel.
	 * @param string $signal  Signal.
	 * @return array{channel: string, signal: string}
	 */
	private static function found( $channel, $signal ) {
		return array(
			'channel' => $channel,
			'signal'  => $signal,
		);
	}
}
