<?php
/**
 * UrumiAI Store for AI — Transient-backed session store
 *
 * The production session store. Sessions and claims are WordPress transients,
 * so they survive between REST requests, expire on their own if this plugin
 * never gets to clean them up, and land in the object cache on hosts that have
 * one.
 *
 * NOT EXERCISED BY THE LOCAL TEST SUITE (no WordPress on this host). The suite
 * runs the in-memory store instead and pins this class's shape only. Treat the
 * behavior here as unverified until it runs against a real site.
 *
 * WHY TRANSIENTS AND NOT A TABLE. A session is small, short-lived and
 * disposable; losing one costs an agent a `create_session` call and nothing
 * else. That is exactly the contract a transient offers, and it means this
 * plugin ships no schema and no migration.
 *
 * HANDLES ARE RANDOM HERE, unlike the in-memory store's predictable counter.
 * A guessable session id would let one caller address another shopper basket.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Transient_Session_Store implements Urumiai_Store_For_Ai_Session_Store {

    const SESSION_PREFIX = 'urumiai_sfa_s_';
    const CLAIM_PREFIX   = 'urumiai_sfa_c_';

    /**
     * Floor for a transient TTL, in seconds.
     *
     * A record whose own expires_at has already passed is still written with a
     * short life rather than none: set_transient() treats 0 as "never expires",
     * which is the opposite of what an expired record wants.
     */
    const MIN_TTL = 60;

    /**
     * Seconds to keep a record past its own expiry.
     *
     * The session layer decides what expiry MEANS; letting the row outlive it
     * slightly means Urumiai_Store_For_Ai_Sessions gets to return "expired"
     * rather than the blanker "unknown".
     */
    const GRACE = 300;

    /**
     * Derive a transient key.
     *
     * @param string $prefix Key prefix.
     * @param string $handle Session id or claim token.
     * @return string
     */
    private function key(string $prefix, string $handle): string {
        // Hash rather than concatenate: handles are caller-supplied on read,
        // and this keeps the key within the option-name length limit and free
        // of anything that is not [a-f0-9].
        return $prefix . md5($handle);
    }

    /**
     * Transient lifetime for a record, from its own expires_at.
     *
     * @param array $record Session or claim record.
     * @return int Seconds.
     */
    private function ttl(array $record): int {
        $remaining = (int) $record['expires_at'] - time() + self::GRACE;
        return max(self::MIN_TTL, $remaining);
    }

    /**
     * @inheritDoc
     */
    public function put_session(array $session): void {
        set_transient($this->key(self::SESSION_PREFIX, $session['id']), $session, $this->ttl($session));
    }

    /**
     * @inheritDoc
     */
    public function get_session(string $session_id): ?array {
        $stored = get_transient($this->key(self::SESSION_PREFIX, $session_id));
        return is_array($stored) ? $stored : null;
    }

    /**
     * @inheritDoc
     */
    public function delete_session(string $session_id): void {
        delete_transient($this->key(self::SESSION_PREFIX, $session_id));
    }

    /**
     * @inheritDoc
     */
    public function put_claim(array $claim): void {
        set_transient($this->key(self::CLAIM_PREFIX, $claim['token']), $claim, $this->ttl($claim));
    }

    /**
     * @inheritDoc
     */
    public function get_claim(string $claim_token): ?array {
        $stored = get_transient($this->key(self::CLAIM_PREFIX, $claim_token));
        return is_array($stored) ? $stored : null;
    }

    /**
     * @inheritDoc
     */
    public function delete_claim(string $claim_token): void {
        delete_transient($this->key(self::CLAIM_PREFIX, $claim_token));
    }

    /**
     * @inheritDoc
     *
     * 32 characters from wp_generate_password()'s alphanumeric alphabet, which
     * draws on the CSPRNG. Guessing one is not a realistic attack.
     */
    public function generate_handle(string $prefix): string {
        return $prefix . '_' . wp_generate_password(32, false, false);
    }
}
