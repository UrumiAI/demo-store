<?php
/**
 * CLI harness for the urumiai-store-for-ai server (driven by store-for-ai.test.js).
 *
 * Boots the REAL shipped loader and the REAL MCP core under a minimal
 * WordPress shim, wires it to the fixture adapter and the in-memory session
 * store, then runs a session: each line of stdin is one JSON-RPC request, each
 * line of stdout is its response (a notification prints nothing). One process
 * per session keeps session and cart state across calls, so a whole shopping
 * lifecycle — create_session, browse, fill, hand off — is one invocation.
 *
 * The WooCommerce adapter, transient session store, REST controller and
 * checkout handoff are NOT loaded here — they need WordPress. Everything else
 * is the shipped code, unmodified.
 *
 * Usage:
 *   php store-cli.php [--config='{"cart_enabled":false}'] [--clock=1758240000]
 *
 * --clock pins the clock for the whole session. --clock-steps advances it: a
 * comma-separated list of offsets consumed one per clock read is unusable, so
 * instead --advance=N adds N seconds to the fixed clock after each REQUEST,
 * which is what session and claim expiry tests need.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

// --- Minimal WordPress shims -------------------------------------------------

define('ABSPATH', __DIR__ . '/');

if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') {
        return 'https://example.test/wp-content/mu-plugins/' . ltrim($path, '/');
    }
}

$GLOBALS['__actions'] = array();
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['__actions'][$hook][] = $callback;
        return true;
    }
}
if (!function_exists('did_action')) {
    function did_action($hook) {
        // Nothing has fired: the loader must take the add_action branch and
        // never construct the singleton, which would pull in WordPress files.
        return 0;
    }
}

// --- Boot the real plugin core ----------------------------------------------

$loader = dirname(__DIR__, 2) . '/urumiai-store-for-ai.php';
if (!file_exists($loader)) {
    fwrite(STDERR, "loader not found: {$loader}\n");
    exit(2);
}
require_once $loader;

Urumiai_Store_For_Ai_Plugin::load_core();

// --- Options -----------------------------------------------------------------

$config  = array();
$fixed   = null;
$advance = 0;

foreach (array_slice($argv, 1) as $arg) {
    if (0 === strpos($arg, '--config=')) {
        $decoded = json_decode(substr($arg, strlen('--config=')), true);
        if (is_array($decoded)) {
            $config = $decoded;
        }
    } elseif (0 === strpos($arg, '--clock=')) {
        $fixed = (int) substr($arg, strlen('--clock='));
    } elseif (0 === strpos($arg, '--advance=')) {
        $advance = (int) substr($arg, strlen('--advance='));
    }
}

// A mutable clock cell: pinned when --clock is given, stepped by --advance
// after each request so expiry can be tested without sleeping.
$now   = null === $fixed ? time() : $fixed;
$clock = function () use (&$now) {
    return $now;
};

// Wired by hand rather than through Server::create() so the harness keeps a
// handle on the session layer. Redeeming a claim is what the STOREFRONT does
// when a shopper opens the checkout link — there is deliberately no MCP tool
// for it — so the suite needs a way in that is not the agent surface.
$adapter  = new Urumiai_Store_For_Ai_Fixture_Adapter();
$store    = new Urumiai_Store_For_Ai_Memory_Session_Store();
$policy   = new Urumiai_Store_For_Ai_Safety_Policy($config);
$sessions = new Urumiai_Store_For_Ai_Sessions($adapter, $store, $policy, $clock);

$server = new Urumiai_Store_For_Ai_Server(
    new Urumiai_Store_For_Ai_Tools($adapter, $sessions, $policy),
    new Urumiai_Store_For_Ai_Resources($adapter, $sessions)
);

/**
 * Stand in for the storefront claim hook (class-checkout-handoff.php).
 *
 * Returns what that hook would act on: the redeemed session and the basket the
 * shopper would land on checkout holding, or the refusal that sent them to the
 * cart page with a notice instead.
 *
 * @param Urumiai_Store_For_Ai_Sessions $sessions Session layer.
 * @param Urumiai_Store_For_Ai_Adapter  $adapter  Store adapter.
 * @param string                        $token    Claim token.
 * @return array
 */
function urumiai_cli_redeem($sessions, $adapter, string $token): array {
    try {
        $redeemed = $sessions->redeem_claim($token);
    } catch (Urumiai_Store_For_Ai_Session_Exception $e) {
        return array('ok' => false, 'error' => $e->get_reason(), 'message' => $e->getMessage());
    }

    $cart = $adapter->get_cart($redeemed['cart_token']);

    return array(
        'ok'      => true,
        'session' => Urumiai_Store_For_Ai_Serializer::session($redeemed['session']),
        'cart'    => null === $cart ? null : Urumiai_Store_For_Ai_Serializer::cart($cart),
    );
}

// --- Session loop ------------------------------------------------------------

$in = fopen('php://stdin', 'r');
while (false !== ($line = fgets($in))) {
    $line = trim($line);
    if ('' === $line) {
        continue;
    }

    $decoded = json_decode($line, true);

    // Control channel: anything the storefront does rather than the agent.
    if (is_array($decoded) && isset($decoded['__control'])) {
        if ('redeem_claim' === $decoded['__control']) {
            $result = urumiai_cli_redeem($sessions, $adapter, (string) $decoded['token']);
        } else {
            $result = array('ok' => false, 'error' => 'unknown_control');
        }
        echo json_encode($result, JSON_UNESCAPED_SLASHES), PHP_EOL;
        $now += $advance;
        continue;
    }

    $response = $server->handle_raw($line);
    if (null !== $response) {
        echo json_encode($response, JSON_UNESCAPED_SLASHES), PHP_EOL;
    }

    $now += $advance;
}
fclose($in);
