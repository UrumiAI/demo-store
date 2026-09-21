<?php
/**
 * UrumiAI Store for AI — In-memory session store
 *
 * Process-lifetime session persistence. Two jobs, the same two the fixture
 * adapter has:
 *
 *   1. It is what the test suite runs against, so the REAL session rules,
 *      tools, serializer and safety policy are exercised end to end on a
 *      machine with no WordPress.
 *   2. It documents the contract by example.
 *
 * Handles are a deterministic counter (`sess_000001`), NOT random, so a test
 * can name the session it just created. That is precisely why this class must
 * never run in production: the handles would be guessable. The REST controller
 * selects class-transient-session-store.php whenever WordPress is present.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Memory_Session_Store implements Urumiai_Store_For_Ai_Session_Store {

    /**
     * Sessions by id.
     *
     * @var array
     */
    private $sessions = array();

    /**
     * Claims by token.
     *
     * @var array
     */
    private $claims = array();

    /**
     * Per-prefix counters backing deterministic handle generation.
     *
     * @var array
     */
    private $sequence = array();

    /**
     * @inheritDoc
     */
    public function put_session(array $session): void {
        $this->sessions[$session['id']] = $session;
    }

    /**
     * @inheritDoc
     */
    public function get_session(string $session_id): ?array {
        return isset($this->sessions[$session_id]) ? $this->sessions[$session_id] : null;
    }

    /**
     * @inheritDoc
     */
    public function delete_session(string $session_id): void {
        unset($this->sessions[$session_id]);
    }

    /**
     * @inheritDoc
     */
    public function put_claim(array $claim): void {
        $this->claims[$claim['token']] = $claim;
    }

    /**
     * @inheritDoc
     */
    public function get_claim(string $claim_token): ?array {
        return isset($this->claims[$claim_token]) ? $this->claims[$claim_token] : null;
    }

    /**
     * @inheritDoc
     */
    public function delete_claim(string $claim_token): void {
        unset($this->claims[$claim_token]);
    }

    /**
     * @inheritDoc
     *
     * Deterministic by design; see the class docblock.
     */
    public function generate_handle(string $prefix): string {
        $this->sequence[$prefix] = isset($this->sequence[$prefix]) ? $this->sequence[$prefix] + 1 : 1;
        return sprintf('%s_%06d', $prefix, $this->sequence[$prefix]);
    }
}
