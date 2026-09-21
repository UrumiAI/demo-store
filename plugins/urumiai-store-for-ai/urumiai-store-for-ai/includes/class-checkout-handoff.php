<?php
/**
 * UrumiAI Store for AI — Storefront checkout handoff
 *
 * The half of the handoff that runs in the shopper's browser, and the reason
 * the whole plugin is useful rather than merely safe.
 *
 * THE PROBLEM. An agent builds a cart over the REST endpoint, in a WooCommerce
 * session that exists only on the server. The shopper's browser has never seen
 * it. Sending them to /checkout would show an empty cart and waste everyone's
 * time.
 *
 * WHAT THIS DOES. It answers `?urumiai_claim=<token>` on the front end:
 * redeems the single-use claim, copies the agent's basket into the visitor's
 * OWN WooCommerce cart, and redirects to checkout. The shopper arrives with the
 * right things in the basket and one thing left to do — pay.
 *
 * WHY COPY, NOT HIJACK. The obvious shortcut is to point the visitor's session
 * at the agent's customer id. WooCommerce does not actually support that —
 * WC_Session_Handler exposes no public way to rebind an initialized session —
 * and even where it can be forced it entangles two sessions, fights the session
 * handler and cache layer a host substitutes, and leaves the shopper on a
 * session they do not own. So the agent's basket is a line list this plugin
 * owns, and claiming it replays those lines through the public cart API: a
 * handful of add_to_cart() calls that leave the visitor in their own session,
 * with their own cookie, where checkout, taxes and shipping behave normally.
 *
 * WHAT IT CANNOT DO. It places no order, selects no payment method and submits
 * no form. It redirects to checkout and stops. The person decides.
 *
 * NOT EXERCISED BY THE LOCAL TEST SUITE (no WordPress on this host). The claim
 * RULES it depends on — single use, expiry, session freezing — are tested
 * against class-sessions.php; the WordPress plumbing here is shape-checked
 * only. Treat it as unverified until it runs against a real store.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Checkout_Handoff {

    /**
     * Register the storefront hook.
     *
     * `template_redirect` is late enough that WooCommerce's session and cart
     * are initialized for this visitor, and early enough to redirect before a
     * byte of the page is sent.
     *
     * @return void
     */
    public function init() {
        add_action('template_redirect', array($this, 'maybe_claim'), 5);
    }

    /**
     * The claim token on this request, or '' when there is none.
     *
     * @return string
     */
    private function requested_token(): string {
        $var = Urumiai_Store_For_Ai_Woocommerce_Adapter::CLAIM_QUERY_VAR;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the
        // claim token IS the credential: single-use, short-lived and issued by
        // this plugin. A nonce would be bound to a session the shopper does not
        // have yet, which is the whole problem being solved here.
        if (!isset($_GET[$var])) {
            return '';
        }

        return sanitize_text_field(rawurldecode(wp_unslash($_GET[$var])));
    }

    /**
     * Redeem a claim and send the shopper to a filled checkout.
     *
     * @return void
     */
    public function maybe_claim() {
        $token = $this->requested_token();
        if ('' === $token) {
            return;
        }

        if (!Urumiai_Store_For_Ai_Woocommerce_Adapter::is_available() || !WC()->cart) {
            $this->bail('This store is not ready to accept a shared basket.');
            return;
        }

        $adapter  = new Urumiai_Store_For_Ai_Woocommerce_Adapter();
        $policy   = new Urumiai_Store_For_Ai_Safety_Policy($this->policy_config());
        $sessions = new Urumiai_Store_For_Ai_Sessions(
            $adapter,
            new Urumiai_Store_For_Ai_Transient_Session_Store(),
            $policy
        );

        try {
            $redeemed = $sessions->redeem_claim($token);
        } catch (Urumiai_Store_For_Ai_Session_Exception $e) {
            $this->bail($e->getMessage());
            return;
        }

        $lines = $this->read_agent_lines($adapter, $redeemed['cart_token']);
        $this->fill_visitor_cart($lines);

        /**
         * Fires once a shopper has adopted an agent-built basket.
         *
         * @param array $lines   Canonical cart lines copied in.
         * @param array $session The session that was handed off.
         */
        do_action('urumiai_store_for_ai_basket_claimed', $lines, $redeemed['session']);

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Effective safety policy for this store.
     *
     * @return array
     */
    private function policy_config(): array {
        /** This filter is documented in includes/class-rest-controller.php */
        $config = apply_filters('urumiai_store_for_ai_policy', Urumiai_Store_For_Ai_Safety_Policy::defaults());
        return is_array($config) ? $config : array();
    }

    /**
     * Read the agent's basket.
     *
     * The agent's cart is a line list this plugin owns (see
     * class-woocommerce-adapter.php), NOT a WooCommerce session, so reading it
     * cannot disturb the visitor's own session. An earlier version tried to
     * rebind WC()->session to the agent's customer id and restore it
     * afterwards; WC_Session_Handler has no public setter for that, so the
     * rebind silently did nothing and the cart never survived a request.
     *
     * @param Urumiai_Store_For_Ai_Woocommerce_Adapter $adapter    Live adapter.
     * @param string                                   $cart_token Agent cart token.
     * @return array Canonical cart lines.
     */
    private function read_agent_lines($adapter, string $cart_token): array {
        $cart = $adapter->get_cart($cart_token);
        return (null === $cart || empty($cart['lines'])) ? array() : $cart['lines'];
    }

    /**
     * Replace the visitor's cart with the agent's basket.
     *
     * Replace rather than merge: the shopper asked for what they discussed with
     * the agent, and silently adding to a basket they had forgotten about is a
     * good way to sell somebody something they did not agree to buy.
     *
     * A line WooCommerce now refuses (sold out since the agent added it) is
     * skipped with a notice rather than aborting the handoff — arriving with
     * three of four items and a message beats arriving with nothing.
     *
     * @param array $lines Canonical cart lines.
     * @return void
     */
    private function fill_visitor_cart(array $lines) {
        WC()->cart->empty_cart();

        $skipped = 0;
        foreach ($lines as $line) {
            $added = WC()->cart->add_to_cart(
                (int) $line['product_id'],
                (int) $line['quantity'],
                (int) $line['variation_id']
            );
            if (false === $added) {
                $skipped++;
            }
        }

        if ($skipped > 0 && function_exists('wc_add_notice')) {
            wc_add_notice(
                sprintf(
                    /* translators: %d: number of items that could not be added. */
                    _n(
                        '%d item is no longer available and was left out of your basket.',
                        '%d items are no longer available and were left out of your basket.',
                        $skipped,
                        'urumiai-store-for-ai'
                    ),
                    $skipped
                ),
                'notice'
            );
        }
    }

    /**
     * Send the shopper to the cart page with an explanation.
     *
     * A dead or spent link is an ordinary thing — a bookmark, a second click,
     * a conversation resumed tomorrow — so it gets a message, not a 403.
     *
     * @param string $message Reason to show.
     * @return void
     */
    private function bail(string $message) {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($message, 'error');
        }

        $destination = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/');
        wp_safe_redirect($destination);
        exit;
    }
}
