<?php
/**
 * UrumiAI Store for AI — Safety policy
 *
 * The guardrails around an autonomous caller. Three ideas:
 *
 * 1. CAPABILITY TIERS. `session` and `read` are always on — without them the
 *    surface does nothing at all. `cart` and `checkout` are independently
 *    switchable, so a merchant can run browse-only, or browse-and-build without
 *    the handoff. There is no `order` tier: placing orders, capturing payment,
 *    issuing refunds and creating coupons are not implemented anywhere in this
 *    plugin, so no configuration can enable them. An agent fills a cart; a
 *    human pays. That boundary is structural, not a setting.
 *
 * 2. CAPS. An agent that miscounts, loops, or is prompt-injected should hit a
 *    ceiling long before it does damage: per-line quantity, distinct lines per
 *    cart, optional subtotal ceiling, and a per-session call rate limit.
 *
 * 3. ACCOUNTABILITY. Every mutating call is recorded, and every mutating call
 *    accepts an idempotency key so a retried request cannot silently double a
 *    quantity.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Safety_Policy {

    const TIER_SESSION  = 'session';
    const TIER_READ     = 'read';
    const TIER_CART     = 'cart';
    const TIER_CHECKOUT = 'checkout';

    /**
     * Effective configuration.
     *
     * @var array
     */
    private $config;

    /**
     * Audit trail of mutating calls, oldest first.
     *
     * @var array
     */
    private $audit = array();

    /**
     * Idempotency key => recorded result.
     *
     * @var array
     */
    private $idempotency = array();

    /**
     * Call timestamps per session, for rate limiting.
     *
     * @var array
     */
    private $call_log = array();

    /**
     * Defaults. Conservative but usable: an agent can assemble a normal basket
     * and would have to try hard to build an abusive one.
     *
     * @return array
     */
    public static function defaults(): array {
        return array(
            'cart_enabled'          => true,
            'checkout_enabled'      => true,
            'max_line_quantity'     => 10,
            'max_lines'             => 20,
            // 0 disables the subtotal ceiling. In minor units.
            'max_subtotal_minor'    => 0,
            // Mutating calls allowed per session per window.
            'rate_limit_calls'      => 60,
            'rate_limit_window_sec' => 60,
            // How long an idle shopping session stays usable: one day.
            'session_ttl_sec'       => 86400,
            // How long a checkout claim URL stays redeemable. Deliberately
            // short — it is handed to a person who is in the conversation now.
            'handoff_ttl_sec'       => 900,
        );
    }

    /**
     * Constructor.
     *
     * @param array $config Overrides merged over defaults().
     */
    public function __construct(array $config = array()) {
        $this->config = array_merge(self::defaults(), $config);
    }

    /**
     * Read one configuration value.
     *
     * @param string $key     Config key.
     * @param mixed  $default Fallback when absent.
     * @return mixed
     */
    public function get(string $key, $default = null) {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    /**
     * Is a capability tier enabled?
     *
     * @param string $tier One of the TIER_* constants.
     * @return bool
     */
    public function tier_enabled(string $tier): bool {
        if (self::TIER_SESSION === $tier || self::TIER_READ === $tier) {
            return true;
        }
        if (self::TIER_CART === $tier) {
            return (bool) $this->config['cart_enabled'];
        }
        if (self::TIER_CHECKOUT === $tier) {
            return (bool) $this->config['checkout_enabled'];
        }
        // Unknown tier (including anything order/payment shaped): denied.
        return false;
    }

    /**
     * Validate a requested quantity against the per-line cap.
     *
     * @param int $quantity Requested quantity.
     * @return string|null Error message, or null when acceptable.
     */
    public function check_quantity(int $quantity): ?string {
        if ($quantity < 1) {
            return 'quantity must be at least 1';
        }
        $max = (int) $this->config['max_line_quantity'];
        if ($max > 0 && $quantity > $max) {
            return sprintf('quantity %d exceeds the per-item limit of %d', $quantity, $max);
        }
        return null;
    }

    /**
     * Validate a cart against the line-count and subtotal ceilings.
     *
     * Called with the cart as it WOULD be after the mutation, so a violation
     * blocks the write rather than reporting it afterwards.
     *
     * @param array $cart Canonical cart.
     * @return string|null Error message, or null when acceptable.
     */
    public function check_cart(array $cart): ?string {
        $lines = isset($cart['lines']) && is_array($cart['lines']) ? $cart['lines'] : array();

        $max_lines = (int) $this->config['max_lines'];
        if ($max_lines > 0 && count($lines) > $max_lines) {
            return sprintf('cart would hold %d distinct items, over the limit of %d', count($lines), $max_lines);
        }

        $max_subtotal = (int) $this->config['max_subtotal_minor'];
        $subtotal     = isset($cart['subtotal_minor']) ? (int) $cart['subtotal_minor'] : 0;
        if ($max_subtotal > 0 && $subtotal > $max_subtotal) {
            return sprintf('cart subtotal %d exceeds the limit of %d (minor units)', $subtotal, $max_subtotal);
        }

        return null;
    }

    /**
     * Record a mutating call and enforce the per-session rate limit.
     *
     * @param string $session_id Session id ('' when none yet).
     * @param int    $now        Unix timestamp; injectable so tests are not wall-clock bound.
     * @return string|null Error message when the limit is hit, else null.
     */
    public function check_rate_limit(string $session_id, int $now): ?string {
        $limit  = (int) $this->config['rate_limit_calls'];
        $window = (int) $this->config['rate_limit_window_sec'];
        if ($limit <= 0 || $window <= 0) {
            return null;
        }

        $key     = '' === $session_id ? '(anonymous)' : $session_id;
        $history = isset($this->call_log[$key]) ? $this->call_log[$key] : array();

        // Drop anything outside the window.
        $cutoff  = $now - $window;
        $history = array_values(array_filter($history, function ($stamp) use ($cutoff) {
            return $stamp > $cutoff;
        }));

        if (count($history) >= $limit) {
            $this->call_log[$key] = $history;
            return sprintf('rate limit reached: %d calls per %d seconds', $limit, $window);
        }

        $history[]            = $now;
        $this->call_log[$key] = $history;
        return null;
    }

    /**
     * Look up a previously recorded idempotency key.
     *
     * @param string $key Idempotency key.
     * @return array|null Stored result, or null when unseen.
     */
    public function replay(string $key): ?array {
        if ('' === $key) {
            return null;
        }
        return isset($this->idempotency[$key]) ? $this->idempotency[$key] : null;
    }

    /**
     * Record the result of a mutating call against its idempotency key.
     *
     * @param string $key    Idempotency key ('' to skip).
     * @param array  $result Result payload to replay on a retry.
     * @return void
     */
    public function remember(string $key, array $result) {
        if ('' !== $key) {
            $this->idempotency[$key] = $result;
        }
    }

    /**
     * Append an audit entry.
     *
     * @param string $tool    Tool name.
     * @param array  $args    Arguments as received.
     * @param string $outcome 'ok', 'denied', 'error' or 'dry-run'.
     * @param string $detail  Optional human-readable note.
     * @return void
     */
    public function audit(string $tool, array $args, string $outcome, string $detail = '') {
        $this->audit[] = array(
            'tool'    => $tool,
            'args'    => $args,
            'outcome' => $outcome,
            'detail'  => $detail,
        );
    }

    /**
     * Read the audit trail.
     *
     * @return array
     */
    public function get_audit(): array {
        return $this->audit;
    }
}
