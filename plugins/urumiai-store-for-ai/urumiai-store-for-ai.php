<?php
/**
 * Plugin Name: UrumiAI Store for AI
 * Plugin URI: https://urumi.ai
 * Description: Exposes a WooCommerce store to AI agents as an MCP-style tool surface. An agent opens a shopping session, browses the catalog and fills a cart; the shopper opens one claim link and only has to pay. The agent can never place an order or take payment.
 * Author: Urumi AI
 * Version: 0.1.0
 * Author URI: https://urumi.ai/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 *
 * @package Urumiai_Store_For_Ai
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('URUMIAI_STORE_FOR_AI_VERSION', '0.1.0');
define('URUMIAI_STORE_FOR_AI_PATH', dirname(__FILE__) . '/urumiai-store-for-ai/');
define('URUMIAI_STORE_FOR_AI_URL', plugins_url('urumiai-store-for-ai/', __FILE__));

/**
 * UrumiAI Store for AI Plugin
 *
 * Standalone. Depends on no other Urumi plugin.
 *
 * ARCHITECTURE. The MCP core (class-server.php and everything it calls) is
 * framework-agnostic PHP: it takes a decoded JSON-RPC array and returns a
 * response array. WordPress appears in exactly four files —
 * class-rest-controller.php (route + auth), class-woocommerce-adapter.php (the
 * store binding), class-transient-session-store.php (session persistence) and
 * class-checkout-handoff.php (the storefront claim link). Everything else runs,
 * and is tested, without WordPress.
 *
 * THE SESSION IS THE HANDLE. An agent calls create_session once and then
 * addresses every cart operation by `session_id`. The underlying WooCommerce
 * cart token is never serialized to the agent, so the only thing it holds is a
 * handle this plugin issued and can expire.
 *
 * SAFETY. The agent-facing surface stops at the cart. There is no order
 * placement, payment, refund or coupon tool — not disabled, absent. Checkout is
 * a single-use claim URL handed to a human, which adopts the agent's cart into
 * that person's browser and leaves them on a pre-filled checkout. See
 * class-safety-policy.php, class-checkout-handoff.php and README.md.
 *
 * @since 0.1.0
 */
final class Urumiai_Store_For_Ai_Plugin {

    /**
     * Plugin instance
     *
     * @var Urumiai_Store_For_Ai_Plugin
     */
    private static $instance = null;

    /**
     * REST controller instance
     *
     * @var Urumiai_Store_For_Ai_Rest_Controller
     */
    private $rest_controller;

    /**
     * Checkout handoff instance
     *
     * @var Urumiai_Store_For_Ai_Checkout_Handoff
     */
    private $checkout_handoff;

    /**
     * Get plugin instance (Singleton pattern)
     *
     * @return Urumiai_Store_For_Ai_Plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - private to enforce singleton
     */
    private function __construct() {
        self::load_core();
        $this->load_wordpress_bindings();
        $this->init_modules();
    }

    /**
     * Load the framework-agnostic MCP core.
     *
     * Static and public so the CLI harness can boot the exact same core the
     * plugin ships, without WordPress and without the WooCommerce adapter.
     *
     * @return void
     */
    public static function load_core() {
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-adapter.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-session-store.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-memory-session-store.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-serializer.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-safety-policy.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-sessions.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-fixture-adapter.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-tools.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-resources.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-server.php';
    }

    /**
     * Load the files that need WordPress.
     *
     * Kept out of load_core() so the CLI harness never touches them.
     *
     * @return void
     */
    private function load_wordpress_bindings() {
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-woocommerce-adapter.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-transient-session-store.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-rest-controller.php';
        require_once URUMIAI_STORE_FOR_AI_PATH . 'includes/class-checkout-handoff.php';
    }

    /**
     * Initialize plugin modules
     */
    private function init_modules() {
        $this->rest_controller = new Urumiai_Store_For_Ai_Rest_Controller();
        $this->rest_controller->init();

        $this->checkout_handoff = new Urumiai_Store_For_Ai_Checkout_Handoff();
        $this->checkout_handoff->init();
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version() {
        return URUMIAI_STORE_FOR_AI_VERSION;
    }
}

/**
 * Bootstrap the store-for-AI surface.
 *
 * Deferred to `muplugins_loaded` so WooCommerce detection, the REST route and
 * the storefront claim hook are all registered after the mu-plugin include
 * loop, consistent with the platform's other mu-plugins. As an ordinary plugin
 * the hook has already fired by load time, so the bootstrap runs directly.
 *
 * @return Urumiai_Store_For_Ai_Plugin
 */
function urumiai_store_for_ai() {
    return Urumiai_Store_For_Ai_Plugin::get_instance();
}

if (did_action('muplugins_loaded')) {
    urumiai_store_for_ai();
} else {
    add_action('muplugins_loaded', 'urumiai_store_for_ai');
}
