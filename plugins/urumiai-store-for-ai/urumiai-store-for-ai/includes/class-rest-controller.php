<?php
/**
 * UrumiAI Store for AI — WordPress REST binding
 *
 * The only HTTP surface for agents. It authenticates, decodes, hands the array
 * to the framework-agnostic server, and encodes the reply. No business logic
 * lives here, which is why the rest of the plugin is testable without
 * WordPress.
 *
 * Route: POST /wp-json/urumiai-store-for-ai/v1/rpc
 *
 * AUTHENTICATION. A shared bearer token, compared with hash_equals() so the
 * comparison is constant-time. When no token is configured the route refuses
 * every request rather than defaulting to open: an unauthenticated endpoint
 * that can mutate a cart is worse than a broken one, and "we forgot to set it"
 * is the likeliest configuration mistake.
 *
 * NOT EXERCISED BY THE LOCAL TEST SUITE (no WordPress on this host). The suite
 * pins its structure only.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Rest_Controller {

    const NAMESPACE_V1 = 'urumiai-store-for-ai/v1';
    const ROUTE        = '/rpc';

    /**
     * Register the REST route.
     *
     * @return void
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register routes with the REST server.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            self::NAMESPACE_V1,
            self::ROUTE,
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_rpc'),
                'permission_callback' => array($this, 'check_permission'),
            )
        );
    }

    /**
     * The configured shared secret, or '' when unset.
     *
     * @return string
     */
    private function configured_token(): string {
        if (defined('URUMIAI_STORE_FOR_AI_TOKEN') && is_string(URUMIAI_STORE_FOR_AI_TOKEN)) {
            return URUMIAI_STORE_FOR_AI_TOKEN;
        }
        return (string) get_option('urumiai_store_for_ai_token', '');
    }

    /**
     * Authenticate the request.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return bool|WP_Error
     */
    public function check_permission($request) {
        $configured = $this->configured_token();

        if ('' === $configured) {
            return new WP_Error(
                'urumiai_store_for_ai_not_configured',
                'The store-for-AI endpoint has no access token configured.',
                array('status' => 503)
            );
        }

        $header = (string) $request->get_header('authorization');
        if (0 !== stripos($header, 'Bearer ')) {
            return new WP_Error(
                'urumiai_store_for_ai_unauthorized',
                'A Bearer token is required.',
                array('status' => 401)
            );
        }

        $presented = substr($header, 7);
        if (!hash_equals($configured, $presented)) {
            return new WP_Error(
                'urumiai_store_for_ai_unauthorized',
                'Invalid access token.',
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Build the server for this request.
     *
     * Falls back to the fixture adapter when WooCommerce is absent, so the
     * endpoint is explorable on a site that has not installed WooCommerce yet.
     * The response says which adapter answered, so nobody mistakes sample data
     * for their real catalog.
     *
     * The session store is ALWAYS the transient one, even behind the fixture:
     * sessions have to outlive a request, and the in-memory store is a test
     * double whose handles are a predictable counter.
     *
     * @return array {server: Urumiai_Store_For_Ai_Server, adapter: string}
     */
    private function build_server(): array {
        if (Urumiai_Store_For_Ai_Woocommerce_Adapter::is_available()) {
            $adapter = new Urumiai_Store_For_Ai_Woocommerce_Adapter();
            $name    = 'woocommerce';
        } else {
            $adapter = new Urumiai_Store_For_Ai_Fixture_Adapter();
            $name    = 'fixture';
        }

        /**
         * Filter the safety policy configuration for this store.
         *
         * @param array $config See Urumiai_Store_For_Ai_Safety_Policy::defaults().
         */
        $config = apply_filters('urumiai_store_for_ai_policy', Urumiai_Store_For_Ai_Safety_Policy::defaults());

        return array(
            'server'  => Urumiai_Store_For_Ai_Server::create(
                $adapter,
                new Urumiai_Store_For_Ai_Transient_Session_Store(),
                is_array($config) ? $config : array()
            ),
            'adapter' => $name,
        );
    }

    /**
     * Handle a JSON-RPC POST.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     */
    public function handle_rpc($request) {
        $built    = $this->build_server();
        $response = $built['server']->handle_raw((string) $request->get_body());

        if (null === $response) {
            // A notification-only payload: nothing to say.
            return new WP_REST_Response(null, 204);
        }

        $rest = new WP_REST_Response($response, 200);
        $rest->header('X-Urumiai-Store-For-Ai-Adapter', $built['adapter']);
        return $rest;
    }
}
