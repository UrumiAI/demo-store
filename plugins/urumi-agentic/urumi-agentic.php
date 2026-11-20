<?php
/**
 * Plugin Name: Urumi Agentic Checkout
 * Plugin URI: https://urumi.ai
 * Description: Lets shoppers find and buy from this store inside AI agents. Managed by Urumi.
 * Version: 0.1.0
 * Author: Urumi
 * Author URI: https://urumi.ai
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: urumi-agentic
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A second copy of the plugin (e.g. one left in mu-plugins) must not fatal on redefinition.
if ( defined( 'URUMI_AGENTIC_VERSION' ) ) {
	return;
}

define( 'URUMI_AGENTIC_VERSION', '0.1.0' );
define( 'URUMI_AGENTIC_PLUGIN_FILE', __FILE__ );
define( 'URUMI_AGENTIC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-settings.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-secrets.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-signer.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-client.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-check-in.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-signals.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-hint.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-order-tagger.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-token.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-uses.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-order-events-breaker.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-order-events.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-privacy.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-admin.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-page.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-notice.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-trace.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-cart.php';
require_once URUMI_AGENTIC_PLUGIN_DIR . 'includes/class-handoff-endpoint.php';

register_activation_hook( __FILE__, array( 'Urumi_Agentic_Settings', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Urumi_Agentic_Check_In', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'Urumi_Agentic_Handoff_Uses', 'deactivate' ) );

// Declare HPOS and Blocks checkout support: every order and checkout feature
// must work on both order stores and both checkouts (plugin-plan.md).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', URUMI_AGENTIC_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', URUMI_AGENTIC_PLUGIN_FILE, true );
		}
	}
);

// Keep the stored version current after an update. The service reads it from
// the replica (contract.md section 6).
add_action( 'plugins_loaded', array( 'Urumi_Agentic_Settings', 'maybe_upgrade' ) );

// A1 check-in (contract.md section 4): queued through Action Scheduler once per
// version change, plus a daily recurring action. Nothing is sent in a web request.
Urumi_Agentic_Check_In::boot();

// Tag agent orders (PL-3). Registers on `woocommerce_loaded`, or at once if
// WooCommerce loaded first.
Urumi_Agentic_Order_Tagger::boot();

// Hand-off single-use table: daily cleanup (PL-7). No query on an ordinary request.
Urumi_Agentic_Handoff_Uses::boot();

// The hand-off link (PL-7): move a POSTed token out of $_POST and $_REQUEST now, while the
// file loads, then answer the page at `init` and the POST at `wp_loaded`.
Urumi_Agentic_Handoff_Endpoint::capture_token();
Urumi_Agentic_Handoff_Endpoint::boot();
Urumi_Agentic_Handoff_Notice::boot();
// PL-7b: the pre-fill's login re-apply (wp_loaded 16) and the classic "ship to a different address" default.
Urumi_Agentic_Handoff_Cart::boot();
// PL-7b: after a hand-off ended without an order, the link's values are never on a checkout again unnamed.
Urumi_Agentic_Handoff_Trace::boot();

// Order status events to the service (PL-8, contract A4): seq written in the
// same save as the status, one Action Scheduler job per change.
Urumi_Agentic_Order_Events::boot();
register_deactivation_hook( __FILE__, array( 'Urumi_Agentic_Order_Events', 'unschedule' ) );

// Personal-data export and erasure (H6): the tags go into Woo's order export, and
// the session id goes when Woo removes an order's personal data. Plus policy text.
Urumi_Agentic_Privacy::boot();

// Merchant screen, channel switches and the agent order filter (PL-4). Admin only.
Urumi_Agentic_Admin::boot();
