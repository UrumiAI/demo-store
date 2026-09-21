<?php
/**
 * UrumiAI Store for AI — Session lifecycle
 *
 * The rules that make `session_id` the only handle an agent holds, and the
 * reason the checkout handoff works at all.
 *
 * THE PROBLEM THIS SOLVES. An agent builds a cart over HTTP against a store
 * session the shopper's browser has never seen. Handing that person a bare
 * checkout URL gives them an empty cart. So a session here owns an internal
 * cart token, and hands off by minting a single-use CLAIM that a storefront
 * hook redeems to adopt the cart into that visitor's browser.
 *
 * STATES, and the one guardrail worth its weight:
 *
 *   open        the agent is shopping; cart writes allowed
 *   handed_off  a claim URL exists but nobody has opened it; writes allowed,
 *               because a shopper may well ask for one more thing
 *   claimed     a person has opened the link and is at checkout. Cart writes
 *               are REFUSED from here on. An agent quietly changing the basket
 *               while someone is entering card details is exactly the failure
 *               this plugin exists to make impossible.
 *   closed      ended; nothing works
 *
 * Expiry is evaluated here, not in the store, so both stores behave alike: a
 * session past `expires_at` is gone whether or not the backend evicted it.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Sessions {

    const STATE_OPEN       = 'open';
    const STATE_HANDED_OFF = 'handed_off';
    const STATE_CLAIMED    = 'claimed';
    const STATE_CLOSED     = 'closed';

    /**
     * Store adapter.
     *
     * @var Urumiai_Store_For_Ai_Adapter
     */
    private $adapter;

    /**
     * Session persistence.
     *
     * @var Urumiai_Store_For_Ai_Session_Store
     */
    private $store;

    /**
     * Safety policy, consulted for the two TTLs.
     *
     * @var Urumiai_Store_For_Ai_Safety_Policy
     */
    private $policy;

    /**
     * Injectable clock.
     *
     * @var callable
     */
    private $clock;

    /**
     * Constructor.
     *
     * @param Urumiai_Store_For_Ai_Adapter       $adapter Store adapter.
     * @param Urumiai_Store_For_Ai_Session_Store $store   Session persistence.
     * @param Urumiai_Store_For_Ai_Safety_Policy $policy  Safety policy.
     * @param callable|null                      $clock   Returns a unix timestamp.
     */
    public function __construct(
        Urumiai_Store_For_Ai_Adapter $adapter,
        Urumiai_Store_For_Ai_Session_Store $store,
        Urumiai_Store_For_Ai_Safety_Policy $policy,
        ?callable $clock = null
    ) {
        $this->adapter = $adapter;
        $this->store   = $store;
        $this->policy  = $policy;
        $this->clock   = null === $clock ? function () { return time(); } : $clock;
    }

    /**
     * Current time, through the injected clock.
     *
     * @return int
     */
    public function now(): int {
        return (int) call_user_func($this->clock);
    }

    /**
     * Open a shopping session, with an empty cart behind it.
     *
     * @return array Canonical session.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception When the store cannot open a cart.
     */
    public function open(): array {
        $now = $this->now();
        $ttl = (int) $this->policy->get('session_ttl_sec');

        $session = array(
            'id'         => $this->store->generate_handle('sess'),
            'cart_token' => $this->adapter->create_cart(),
            'state'      => self::STATE_OPEN,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + $ttl,
        );

        $this->store->put_session($session);
        return $session;
    }

    /**
     * Resolve a session for reading.
     *
     * @param string $session_id Session id.
     * @return array Canonical session.
     * @throws Urumiai_Store_For_Ai_Session_Exception When unknown, expired or closed.
     */
    public function resolve(string $session_id): array {
        $session = $this->store->get_session($session_id);

        if (null === $session) {
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'unknown session; call create_session first',
                'session_not_found'
            );
        }

        if (self::STATE_CLOSED === $session['state']) {
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'this session has been closed',
                'session_closed'
            );
        }

        if ($this->now() >= (int) $session['expires_at']) {
            // Evict rather than leave a tombstone the next call trips over.
            $this->forget($session);
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'this session has expired; call create_session to start a new one',
                'session_expired'
            );
        }

        return $session;
    }

    /**
     * Resolve a session for a cart WRITE.
     *
     * Identical to resolve() plus the claimed-session guardrail: once a person
     * has opened the checkout link, the basket is theirs, not the agent's.
     *
     * @param string $session_id Session id.
     * @return array Canonical session.
     * @throws Urumiai_Store_For_Ai_Session_Exception When unknown, expired, closed or claimed.
     */
    public function resolve_for_write(string $session_id): array {
        $session = $this->resolve($session_id);

        if (self::STATE_CLAIMED === $session['state']) {
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'a person has opened the checkout link for this session; the cart can no longer be changed',
                'session_claimed'
            );
        }

        return $session;
    }

    /**
     * Mark a session as touched and persist it.
     *
     * @param array $session Canonical session.
     * @return array The persisted session.
     */
    public function touch(array $session): array {
        $session['updated_at'] = $this->now();
        $this->store->put_session($session);
        return $session;
    }

    /**
     * Move a session to a new state and persist it.
     *
     * @param array  $session Canonical session.
     * @param string $state   One of the STATE_* constants.
     * @return array The persisted session.
     */
    public function set_state(array $session, string $state): array {
        $session['state']      = $state;
        $session['updated_at'] = $this->now();
        $this->store->put_session($session);
        return $session;
    }

    /**
     * Close a session and release its cart.
     *
     * Idempotent: closing an unknown or already-closed session is a success,
     * because an agent retrying a cleanup should not have to reason about it.
     *
     * @param string $session_id Session id.
     * @return bool Whether a live session was actually closed.
     */
    public function close(string $session_id): bool {
        $session = $this->store->get_session($session_id);
        if (null === $session || self::STATE_CLOSED === $session['state']) {
            return false;
        }

        $this->forget($session);
        return true;
    }

    /**
     * Drop a session's cart and its record.
     *
     * @param array $session Canonical session.
     * @return void
     */
    private function forget(array $session) {
        $this->adapter->destroy_cart($session['cart_token']);
        $this->store->delete_session($session['id']);
    }

    /**
     * Mint a single-use claim for a session.
     *
     * The claim TTL is deliberately far shorter than the session TTL: the URL
     * is meant to be opened now, by the person the agent is talking to.
     *
     * @param array $session Canonical session.
     * @return array {claim: array, session: array}
     */
    public function mint_claim(array $session): array {
        $now = $this->now();

        $claim = array(
            'token'      => $this->store->generate_handle('claim'),
            'session_id' => $session['id'],
            'expires_at' => $now + (int) $this->policy->get('handoff_ttl_sec'),
            'claimed_at' => null,
        );

        $this->store->put_claim($claim);
        $session = $this->set_state($session, self::STATE_HANDED_OFF);

        return array('claim' => $claim, 'session' => $session);
    }

    /**
     * Read a claim's status without consuming it.
     *
     * @param string $claim_token Claim token.
     * @return array|null {state, claimed, expires_at, claimed_at}, or null when unknown.
     */
    public function claim_status(string $claim_token): ?array {
        $claim = $this->store->get_claim($claim_token);
        if (null === $claim) {
            return null;
        }

        $claimed = null !== $claim['claimed_at'];
        $expired = !$claimed && $this->now() >= (int) $claim['expires_at'];

        if ($claimed) {
            $state = 'claimed';
        } elseif ($expired) {
            $state = 'expired';
        } else {
            $state = 'pending';
        }

        return array(
            'state'      => $state,
            'claimed'    => $claimed,
            'expires_at' => (int) $claim['expires_at'],
            'claimed_at' => $claim['claimed_at'],
        );
    }

    /**
     * Redeem a claim: validate it, consume it, and return the session behind it.
     *
     * Called by the storefront hook when a person opens the link, never by an
     * agent. Single use — the claim is stamped `claimed_at` on success, and a
     * stamped claim is refused, so a leaked or bookmarked URL is worth nothing
     * on the second try.
     *
     * @param string $claim_token Claim token.
     * @return array {session: array, cart_token: string}
     * @throws Urumiai_Store_For_Ai_Session_Exception When unknown, expired, already used, or the session is gone.
     */
    public function redeem_claim(string $claim_token): array {
        $claim = $this->store->get_claim($claim_token);

        if (null === $claim) {
            throw new Urumiai_Store_For_Ai_Session_Exception('unknown checkout link', 'claim_not_found');
        }
        if (null !== $claim['claimed_at']) {
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'this checkout link has already been used',
                'claim_already_used'
            );
        }
        if ($this->now() >= (int) $claim['expires_at']) {
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'this checkout link has expired',
                'claim_expired'
            );
        }

        $session = $this->store->get_session($claim['session_id']);
        if (null === $session || self::STATE_CLOSED === $session['state']) {
            throw new Urumiai_Store_For_Ai_Session_Exception(
                'the basket behind this link is no longer available',
                'session_not_found'
            );
        }

        // Stamp before binding: a claim that got this far is spent, whatever
        // happens next. Replay is the risk worth being strict about, and the
        // `claimed_at !== null` check above is what enforces single use.
        //
        // The record is stamped rather than deleted so get_handoff_status can
        // still answer the question an agent actually asks — "did they open
        // it?" — instead of conflating a used link with a bogus one. Its own
        // TTL reaps it.
        $claim['claimed_at'] = $this->now();
        $this->store->put_claim($claim);

        $session = $this->set_state($session, self::STATE_CLAIMED);

        return array('session' => $session, 'cart_token' => $session['cart_token']);
    }
}

/**
 * A session-layer refusal: unknown, expired, closed or already-claimed.
 *
 * Carries a machine-readable code so a tool handler can hand the agent
 * something to branch on rather than a string to pattern-match.
 *
 * @since 0.1.0
 */
class Urumiai_Store_For_Ai_Session_Exception extends Exception {

    /**
     * Machine-readable reason.
     *
     * @var string
     */
    private $reason;

    /**
     * Constructor.
     *
     * @param string $message Human-readable message.
     * @param string $reason  Machine-readable code.
     */
    public function __construct(string $message, string $reason = 'session_error') {
        parent::__construct($message);
        $this->reason = $reason;
    }

    /**
     * Machine-readable reason code.
     *
     * @return string
     */
    public function get_reason(): string {
        return $this->reason;
    }
}
