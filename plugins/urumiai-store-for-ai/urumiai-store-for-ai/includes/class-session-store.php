<?php
/**
 * UrumiAI Store for AI — Session store contract
 *
 * Persistence for the two records the session layer owns. Split from
 * class-sessions.php (which holds the lifecycle RULES) so the rules are tested
 * once and run identically over process memory and over WordPress transients.
 *
 * CANONICAL SESSION
 *   id           opaque handle; the ONLY thing an agent ever holds
 *   cart_token   internal adapter handle; never serialized to an agent
 *   state        'open' | 'handed_off' | 'closed'
 *   created_at, updated_at, expires_at   unix timestamps
 *
 * CANONICAL CLAIM
 *   token        opaque single-use handle carried in the checkout URL
 *   session_id   the session it hands off
 *   expires_at   unix timestamp; deliberately much shorter than a session
 *   claimed_at   unix timestamp, or null while unclaimed
 *
 * A session carries no customer identity — no email, no name, no address, no
 * user id. There is nowhere in this shape to put one, which is the point: a
 * store that never holds the data cannot leak it.
 *
 * EXPIRY IS THE STORE'S JOB ONLY INSOFAR AS IT MAY EVICT. Implementations may
 * drop expired records early, but must not treat a still-present expired record
 * as an error — class-sessions.php decides what expiry means.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

interface Urumiai_Store_For_Ai_Session_Store {

    /**
     * Persist a session record, creating or replacing it.
     *
     * @param array $session Canonical session.
     * @return void
     */
    public function put_session(array $session): void;

    /**
     * Read a session record.
     *
     * @param string $session_id Session id.
     * @return array|null Canonical session, or null when absent/evicted.
     */
    public function get_session(string $session_id): ?array;

    /**
     * Delete a session record.
     *
     * Must not throw for an unknown id.
     *
     * @param string $session_id Session id.
     * @return void
     */
    public function delete_session(string $session_id): void;

    /**
     * Persist a claim record, creating or replacing it.
     *
     * @param array $claim Canonical claim.
     * @return void
     */
    public function put_claim(array $claim): void;

    /**
     * Read a claim record.
     *
     * @param string $claim_token Claim token.
     * @return array|null Canonical claim, or null when absent/evicted.
     */
    public function get_claim(string $claim_token): ?array;

    /**
     * Delete a claim record.
     *
     * Must not throw for an unknown token.
     *
     * @param string $claim_token Claim token.
     * @return void
     */
    public function delete_claim(string $claim_token): void;

    /**
     * Generate an unguessable opaque handle.
     *
     * Lives on the store because the WordPress implementation should use
     * wp_generate_password()/random_bytes() while the in-memory one wants a
     * deterministic counter the tests can predict.
     *
     * @param string $prefix Short human-readable prefix, e.g. 'sess' or 'claim'.
     * @return string
     */
    public function generate_handle(string $prefix): string;
}
