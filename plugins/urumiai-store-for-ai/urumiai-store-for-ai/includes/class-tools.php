<?php
/**
 * UrumiAI Store for AI — Tool surface
 *
 * The thirteen tools an agent may call, and nothing else.
 *
 *   session   create_session, get_session, end_session
 *   read      search_products, get_product, list_categories, get_cart
 *   cart      add_to_cart, update_cart_item, remove_cart_item, clear_cart
 *   checkout  create_checkout_handoff, get_handoff_status
 *
 * There is no fourteenth tool. Order placement, payment capture, refunds and
 * coupon creation are absent from this file and from the plugin, so no
 * configuration, filter or prompt can reach them. `create_checkout_handoff`
 * returns a URL for a human to open; it creates nothing.
 *
 * SESSION_ID IS THE ONLY HANDLE. Every cart operation takes `session_id`. The
 * adapter's cart token is resolved server-side and never serialized, so an
 * agent cannot address a cart it was not given, and a leaked transcript carries
 * a handle this plugin can expire.
 *
 * Every mutating handler:
 *   - checks its capability tier,
 *   - resolves the session (refusing an expired, closed or already-claimed one),
 *   - checks the rate limit,
 *   - replays a matching idempotency key instead of applying twice,
 *   - simulates and discards when dry_run is set,
 *   - validates the resulting cart against the caps BEFORE committing,
 *   - writes an audit entry either way.
 *
 * A handler returns an MCP tool result: {content: [...], isError: bool} with a
 * structuredContent payload. Store and session refusals (out of stock, expired
 * session, cap exceeded) come back as isError results, not JSON-RPC errors —
 * the call was well-formed, the store simply said no, and an agent should read
 * and act on that rather than treat the server as broken.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Tools {

    /** The tool needs no session. */
    const SESSION_NONE = 'none';

    /** The tool reads a session. */
    const SESSION_READ = 'read';

    /** The tool writes to a session's cart. */
    const SESSION_WRITE = 'write';

    /**
     * Store adapter.
     *
     * @var Urumiai_Store_For_Ai_Adapter
     */
    private $adapter;

    /**
     * Session lifecycle.
     *
     * @var Urumiai_Store_For_Ai_Sessions
     */
    private $sessions;

    /**
     * Safety policy.
     *
     * @var Urumiai_Store_For_Ai_Safety_Policy
     */
    private $policy;

    /**
     * Constructor.
     *
     * @param Urumiai_Store_For_Ai_Adapter       $adapter  Store adapter.
     * @param Urumiai_Store_For_Ai_Sessions      $sessions Session lifecycle.
     * @param Urumiai_Store_For_Ai_Safety_Policy $policy   Safety policy.
     */
    public function __construct(
        Urumiai_Store_For_Ai_Adapter $adapter,
        Urumiai_Store_For_Ai_Sessions $sessions,
        Urumiai_Store_For_Ai_Safety_Policy $policy
    ) {
        $this->adapter  = $adapter;
        $this->sessions = $sessions;
        $this->policy   = $policy;
    }

    /**
     * Tool definitions, in MCP `tools/list` shape plus private `_` keys.
     *
     * Kept identical to schema/tools.json; the test suite pins them together so
     * the published schema cannot drift from the implementation.
     *
     * @return array
     */
    public function definitions(): array {
        $session_arg = array(
            'type'        => 'string',
            'description' => 'Session id from create_session.',
        );
        $idempotency_arg = array(
            'type'        => 'string',
            'description' => 'Repeat-safe key; a retry with the same key returns the first result instead of applying twice.',
        );
        $dry_run_arg = array(
            'type'        => 'boolean',
            'description' => 'Validate only; change nothing.',
        );

        return array(
            // --- session ---------------------------------------------------
            array(
                'name'        => 'create_session',
                'title'       => 'Create shopping session',
                'description' => 'Open a shopping session for one shopper and return its session_id, plus the store profile. Every other cart tool takes this session_id. Call this first.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_SESSION,
                '_session'    => self::SESSION_NONE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array('idempotency_key' => $idempotency_arg),
                    'required'   => array(),
                ),
            ),
            array(
                'name'        => 'get_session',
                'title'       => 'Get session',
                'description' => 'Read a session: its state, when it expires, and the cart behind it.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_SESSION,
                '_session'    => self::SESSION_READ,
                '_mutating'   => false,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array('session_id' => $session_arg),
                    'required'   => array('session_id'),
                ),
            ),
            array(
                'name'        => 'end_session',
                'title'       => 'End session',
                'description' => 'Close a session and discard its cart. Safe to call twice.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_SESSION,
                '_session'    => self::SESSION_NONE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array('session_id' => $session_arg),
                    'required'   => array('session_id'),
                ),
            ),

            // --- read ------------------------------------------------------
            array(
                'name'        => 'search_products',
                'title'       => 'Search products',
                'description' => 'Search the merchant catalog. Returns only published, purchasable products. Prices are integers in the currency minor unit.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_READ,
                '_session'    => self::SESSION_NONE,
                '_mutating'   => false,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query'           => array('type' => 'string', 'description' => 'Free-text search over name and description.'),
                        'category'        => array('type' => 'string', 'description' => 'Category slug.'),
                        'min_price_minor' => array('type' => 'integer', 'description' => 'Minimum price, minor units.'),
                        'max_price_minor' => array('type' => 'integer', 'description' => 'Maximum price, minor units.'),
                        'in_stock_only'   => array('type' => 'boolean', 'description' => 'Exclude out-of-stock products.'),
                        'limit'           => array('type' => 'integer', 'description' => 'Page size (default 20).'),
                        'cursor'          => array('type' => 'string', 'description' => 'Opaque cursor from a previous call.'),
                    ),
                    'required'   => array(),
                ),
            ),
            array(
                'name'        => 'get_product',
                'title'       => 'Get product',
                'description' => 'Fetch one product by numeric id or slug, including variations and stock.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_READ,
                '_session'    => self::SESSION_NONE,
                '_mutating'   => false,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'product' => array('type' => 'string', 'description' => 'Numeric product id or slug.'),
                    ),
                    'required'   => array('product'),
                ),
            ),
            array(
                'name'        => 'list_categories',
                'title'       => 'List categories',
                'description' => 'List public product categories with product counts.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_READ,
                '_session'    => self::SESSION_NONE,
                '_mutating'   => false,
                'inputSchema' => array('type' => 'object', 'properties' => array(), 'required' => array()),
            ),
            array(
                'name'        => 'get_cart',
                'title'       => 'Get cart',
                'description' => 'Read the current contents of a session cart.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_READ,
                '_session'    => self::SESSION_READ,
                '_mutating'   => false,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array('session_id' => $session_arg),
                    'required'   => array('session_id'),
                ),
            ),

            // --- cart ------------------------------------------------------
            array(
                'name'        => 'add_to_cart',
                'title'       => 'Add to cart',
                'description' => 'Add a product to the session cart. Does not purchase anything.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_CART,
                '_session'    => self::SESSION_WRITE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'session_id'      => $session_arg,
                        'product_id'      => array('type' => 'integer', 'description' => 'Numeric product id.'),
                        'quantity'        => array('type' => 'integer', 'description' => 'Quantity to add (default 1).'),
                        'variation_id'    => array('type' => 'integer', 'description' => 'Variation id, required for variable products.'),
                        'idempotency_key' => $idempotency_arg,
                        'dry_run'         => $dry_run_arg,
                    ),
                    'required'   => array('session_id', 'product_id'),
                ),
            ),
            array(
                'name'        => 'update_cart_item',
                'title'       => 'Update cart item',
                'description' => 'Set the quantity of an existing cart line.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_CART,
                '_session'    => self::SESSION_WRITE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'session_id'      => $session_arg,
                        'item_key'        => array('type' => 'string', 'description' => 'Line key from the cart.'),
                        'quantity'        => array('type' => 'integer', 'description' => 'New quantity (1 or more).'),
                        'idempotency_key' => $idempotency_arg,
                        'dry_run'         => $dry_run_arg,
                    ),
                    'required'   => array('session_id', 'item_key', 'quantity'),
                ),
            ),
            array(
                'name'        => 'remove_cart_item',
                'title'       => 'Remove cart item',
                'description' => 'Remove a line from the session cart entirely.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_CART,
                '_session'    => self::SESSION_WRITE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'session_id'      => $session_arg,
                        'item_key'        => array('type' => 'string', 'description' => 'Line key from the cart.'),
                        'idempotency_key' => $idempotency_arg,
                        'dry_run'         => $dry_run_arg,
                    ),
                    'required'   => array('session_id', 'item_key'),
                ),
            ),
            array(
                'name'        => 'clear_cart',
                'title'       => 'Clear cart',
                'description' => 'Remove every line from the session cart, leaving the session open.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_CART,
                '_session'    => self::SESSION_WRITE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'session_id'      => $session_arg,
                        'idempotency_key' => $idempotency_arg,
                        'dry_run'         => $dry_run_arg,
                    ),
                    'required'   => array('session_id'),
                ),
            ),

            // --- checkout --------------------------------------------------
            array(
                'name'        => 'create_checkout_handoff',
                'title'       => 'Create checkout handoff',
                'description' => 'Mint a single-use checkout URL for a HUMAN to open. It loads the cart you built into that person browser and leaves them on checkout to pay. It places no order, takes no payment and reserves no stock. Once the person opens it, the cart is frozen to you.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_CHECKOUT,
                '_session'    => self::SESSION_WRITE,
                '_mutating'   => true,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array('session_id' => $session_arg),
                    'required'   => array('session_id'),
                ),
            ),
            array(
                'name'        => 'get_handoff_status',
                'title'       => 'Get handoff status',
                'description' => 'Report whether a checkout link has been opened yet, or has expired. Returns no order, customer or payment information.',
                '_tier'       => Urumiai_Store_For_Ai_Safety_Policy::TIER_CHECKOUT,
                '_session'    => self::SESSION_NONE,
                '_mutating'   => false,
                'inputSchema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'handoff_token' => array('type' => 'string', 'description' => 'Token returned by create_checkout_handoff.'),
                    ),
                    'required'   => array('handoff_token'),
                ),
            ),
        );
    }

    /**
     * Public tool list, with the private `_` keys stripped.
     *
     * @return array
     */
    public function public_definitions(): array {
        $out = array();
        foreach ($this->definitions() as $definition) {
            unset($definition['_tier'], $definition['_session'], $definition['_mutating']);
            $out[] = $definition;
        }
        return $out;
    }

    /**
     * Find a definition by name.
     *
     * @param string $name Tool name.
     * @return array|null
     */
    private function definition(string $name): ?array {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }
        return null;
    }

    /**
     * Build a successful tool result.
     *
     * @param array $payload Structured payload.
     * @return array
     */
    private function ok(array $payload): array {
        return array(
            'content'           => array(array(
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            )),
            'structuredContent' => $payload,
            'isError'           => false,
        );
    }

    /**
     * Build an error tool result.
     *
     * @param string $message Human-readable reason.
     * @param string $code    Machine-readable code.
     * @return array
     */
    private function fail(string $message, string $code = 'tool_error'): array {
        $payload = array('error' => $code, 'message' => $message);
        return array(
            'content'           => array(array('type' => 'text', 'text' => $message)),
            'structuredContent' => $payload,
            'isError'           => true,
        );
    }

    /**
     * Dispatch a tool call.
     *
     * @param string $name Tool name.
     * @param array  $args Arguments.
     * @return array MCP tool result.
     */
    public function call(string $name, array $args): array {
        $definition = $this->definition($name);
        if (null === $definition) {
            return $this->fail(sprintf('unknown tool "%s"', $name), 'unknown_tool');
        }

        $tier = $definition['_tier'];
        if (!$this->policy->tier_enabled($tier)) {
            $this->policy->audit($name, $args, 'denied', 'tier disabled: ' . $tier);
            return $this->fail(
                sprintf('the "%s" capability is disabled for this store', $tier),
                'capability_disabled'
            );
        }

        foreach ($definition['inputSchema']['required'] as $required) {
            if (!array_key_exists($required, $args)) {
                return $this->fail(sprintf('missing required argument "%s"', $required), 'invalid_arguments');
            }
        }

        if (!empty($definition['_mutating'])) {
            $session_id = isset($args['session_id']) ? (string) $args['session_id'] : '';
            $limit      = $this->policy->check_rate_limit($session_id, $this->sessions->now());
            if (null !== $limit) {
                $this->policy->audit($name, $args, 'denied', $limit);
                return $this->fail($limit, 'rate_limited');
            }

            $key    = isset($args['idempotency_key']) ? (string) $args['idempotency_key'] : '';
            $replay = $this->policy->replay($key);
            if (null !== $replay) {
                $this->policy->audit($name, $args, 'ok', 'idempotent replay');
                return $replay;
            }
        }

        try {
            // Resolve the session once, here, so no handler can forget to.
            $session = null;
            if (self::SESSION_READ === $definition['_session']) {
                $session = $this->sessions->resolve((string) $args['session_id']);
            } elseif (self::SESSION_WRITE === $definition['_session']) {
                $session = $this->sessions->resolve_for_write((string) $args['session_id']);
            }

            switch ($name) {
                case 'create_session':
                    return $this->create_session($args);
                case 'get_session':
                    return $this->get_session($session);
                case 'end_session':
                    return $this->end_session($args);
                case 'search_products':
                    return $this->search_products($args);
                case 'get_product':
                    return $this->get_product($args);
                case 'list_categories':
                    return $this->list_categories();
                case 'get_cart':
                    return $this->get_cart($session);
                case 'add_to_cart':
                    return $this->add_to_cart($session, $args);
                case 'update_cart_item':
                    return $this->update_cart_item($session, $args);
                case 'remove_cart_item':
                    return $this->remove_cart_item($session, $args);
                case 'clear_cart':
                    return $this->clear_cart($session, $args);
                case 'create_checkout_handoff':
                    return $this->create_checkout_handoff($session, $args);
                case 'get_handoff_status':
                    return $this->get_handoff_status($args);
            }
        } catch (Urumiai_Store_For_Ai_Session_Exception $e) {
            $this->policy->audit($name, $args, 'error', $e->getMessage());
            return $this->fail($e->getMessage(), $e->get_reason());
        } catch (Urumiai_Store_For_Ai_Adapter_Exception $e) {
            $this->policy->audit($name, $args, 'error', $e->getMessage());
            return $this->fail($e->getMessage(), 'store_refused');
        }

        return $this->fail(sprintf('unhandled tool "%s"', $name), 'unknown_tool');
    }

    // --- Session tier --------------------------------------------------------

    /**
     * Handle create_session.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function create_session(array $args): array {
        $session = $this->sessions->open();

        $result = $this->ok(array(
            'session_id' => $session['id'],
            'session'    => Urumiai_Store_For_Ai_Serializer::session($session),
            'store'      => Urumiai_Store_For_Ai_Serializer::store_profile($this->adapter->get_store_profile()),
            'notice'     => 'Pass this session_id to every cart tool. This session can build a basket but cannot buy it.',
        ));

        $this->remember_result($args, $result);
        $this->policy->audit('create_session', $args, 'ok');
        return $result;
    }

    /**
     * Handle get_session.
     *
     * @param array $session Resolved session.
     * @return array
     */
    private function get_session(array $session): array {
        return $this->ok(array(
            'session' => Urumiai_Store_For_Ai_Serializer::session($session),
            'cart'    => $this->serialize_cart($session),
        ));
    }

    /**
     * Handle end_session.
     *
     * Deliberately does NOT go through resolve(): ending an already-expired or
     * unknown session is a success, because an agent cleaning up should not
     * have to reason about whether cleanup is still needed.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function end_session(array $args): array {
        $closed = $this->sessions->close((string) $args['session_id']);

        $result = $this->ok(array(
            'session_id' => (string) $args['session_id'],
            'closed'     => $closed,
            'state'      => Urumiai_Store_For_Ai_Sessions::STATE_CLOSED,
        ));

        $this->remember_result($args, $result);
        $this->policy->audit('end_session', $args, 'ok', $closed ? 'closed' : 'already gone');
        return $result;
    }

    // --- Read tier -----------------------------------------------------------

    /**
     * Handle search_products.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function search_products(array $args): array {
        $result = $this->adapter->search_products($args);

        $items = array();
        foreach ($result['items'] as $product) {
            $items[] = Urumiai_Store_For_Ai_Serializer::product_summary($product);
        }

        return $this->ok(array(
            'products'    => $items,
            'total'       => (int) $result['total'],
            'next_cursor' => $result['next_cursor'],
        ));
    }

    /**
     * Handle get_product.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function get_product(array $args): array {
        $product = $this->adapter->get_product((string) $args['product']);
        if (null === $product) {
            return $this->fail(
                sprintf('no purchasable product matches "%s"', (string) $args['product']),
                'not_found'
            );
        }
        return $this->ok(array('product' => Urumiai_Store_For_Ai_Serializer::product_detail($product)));
    }

    /**
     * Handle list_categories.
     *
     * @return array
     */
    private function list_categories(): array {
        return $this->ok(array(
            'categories' => Urumiai_Store_For_Ai_Serializer::categories($this->adapter->list_categories()),
        ));
    }

    /**
     * Handle get_cart.
     *
     * @param array $session Resolved session.
     * @return array
     */
    private function get_cart(array $session): array {
        return $this->ok(array(
            'session_id' => $session['id'],
            'cart'       => $this->serialize_cart($session),
        ));
    }

    // --- Cart tier -----------------------------------------------------------

    /**
     * Handle add_to_cart.
     *
     * @param array $session Resolved session.
     * @param array $args    Arguments.
     * @return array
     */
    private function add_to_cart(array $session, array $args): array {
        $quantity = isset($args['quantity']) ? (int) $args['quantity'] : 1;

        $quantity_error = $this->policy->check_quantity($quantity);
        if (null !== $quantity_error) {
            $this->policy->audit('add_to_cart', $args, 'denied', $quantity_error);
            return $this->fail($quantity_error, 'limit_exceeded');
        }

        return $this->mutate($session, $args, 'add_to_cart', function ($token) use ($args, $quantity) {
            return $this->adapter->add_cart_line(
                $token,
                (int) $args['product_id'],
                $quantity,
                isset($args['variation_id']) ? (int) $args['variation_id'] : 0
            );
        });
    }

    /**
     * Handle update_cart_item.
     *
     * @param array $session Resolved session.
     * @param array $args    Arguments.
     * @return array
     */
    private function update_cart_item(array $session, array $args): array {
        $quantity = (int) $args['quantity'];

        $quantity_error = $this->policy->check_quantity($quantity);
        if (null !== $quantity_error) {
            $this->policy->audit('update_cart_item', $args, 'denied', $quantity_error);
            return $this->fail($quantity_error, 'limit_exceeded');
        }

        return $this->mutate($session, $args, 'update_cart_item', function ($token) use ($args, $quantity) {
            return $this->adapter->update_cart_line($token, (string) $args['item_key'], $quantity);
        });
    }

    /**
     * Handle remove_cart_item.
     *
     * @param array $session Resolved session.
     * @param array $args    Arguments.
     * @return array
     */
    private function remove_cart_item(array $session, array $args): array {
        return $this->mutate($session, $args, 'remove_cart_item', function ($token) use ($args) {
            return $this->adapter->remove_cart_line($token, (string) $args['item_key']);
        });
    }

    /**
     * Handle clear_cart.
     *
     * @param array $session Resolved session.
     * @param array $args    Arguments.
     * @return array
     */
    private function clear_cart(array $session, array $args): array {
        return $this->mutate($session, $args, 'clear_cart', function ($token) {
            return $this->adapter->clear_cart($token);
        });
    }

    // --- Checkout tier -------------------------------------------------------

    /**
     * Handle create_checkout_handoff.
     *
     * Mints a single-use, short-lived claim URL. It does not create an order,
     * reserve stock, or contact a payment gateway — a human opens the URL, the
     * cart follows them into their browser, and they decide.
     *
     * @param array $session Resolved session.
     * @param array $args    Arguments.
     * @return array
     */
    private function create_checkout_handoff(array $session, array $args): array {
        $cart = $this->adapter->get_cart($session['cart_token']);

        if (null === $cart) {
            return $this->fail('the cart behind this session is no longer available', 'not_found');
        }
        if (empty($cart['lines'])) {
            return $this->fail('cannot hand off an empty cart', 'empty_cart');
        }

        $minted  = $this->sessions->mint_claim($session);
        $claim   = $minted['claim'];
        $session = $minted['session'];

        $this->policy->audit('create_checkout_handoff', $args, 'ok');

        return $this->ok(array(
            'checkout_url'   => $this->adapter->get_claim_url($claim['token']),
            'handoff_token'  => $claim['token'],
            'expires_at'     => (int) $claim['expires_at'],
            'session'        => Urumiai_Store_For_Ai_Serializer::session($session),
            'cart'           => Urumiai_Store_For_Ai_Serializer::cart($cart),
            'requires_human' => true,
            'notice'         => 'Give this URL to the shopper and stop here. It works once, it expires, and opening it only loads the basket and shows checkout. No order has been placed and no payment has been taken.',
        ));
    }

    /**
     * Handle get_handoff_status.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function get_handoff_status(array $args): array {
        $status = $this->sessions->claim_status((string) $args['handoff_token']);
        if (null === $status) {
            return $this->fail('unknown or already-consumed checkout link', 'not_found');
        }
        return $this->ok(array('handoff' => Urumiai_Store_For_Ai_Serializer::handoff($status)));
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Apply a cart mutation under the caps, the dry-run rule and the audit log.
     *
     * The shared spine of every cart-tier tool: snapshot, apply, check the caps
     * against the RESULT, roll back on violation or dry run, and only then
     * commit. Written once so no handler can implement it slightly differently.
     *
     * @param array    $session Resolved session.
     * @param array    $args    Tool arguments.
     * @param string   $tool    Tool name, for the audit trail.
     * @param callable $apply   Receives the cart token, returns the updated cart.
     * @return array MCP tool result.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception Propagated to call() for uniform handling.
     */
    private function mutate(array $session, array $args, string $tool, callable $apply): array {
        $token  = $session['cart_token'];
        $before = $this->adapter->get_cart($token);

        if (null === $before) {
            return $this->fail('the cart behind this session is no longer available', 'not_found');
        }

        $cart = call_user_func($apply, $token);

        $cap_error = $this->policy->check_cart($cart);
        if (null !== $cap_error) {
            $this->restore($token, $before);
            $this->policy->audit($tool, $args, 'denied', $cap_error);
            return $this->fail($cap_error, 'limit_exceeded');
        }

        if (!empty($args['dry_run'])) {
            $this->restore($token, $before);
            $this->policy->audit($tool, $args, 'dry-run', 'validated, not applied');
            return $this->ok(array(
                'session_id'    => $session['id'],
                'dry_run'       => true,
                'would_be_cart' => Urumiai_Store_For_Ai_Serializer::cart($cart),
            ));
        }

        $this->sessions->touch($session);

        $result = $this->ok(array(
            'session_id' => $session['id'],
            'cart'       => Urumiai_Store_For_Ai_Serializer::cart($cart),
        ));

        $this->remember_result($args, $result);
        $this->policy->audit($tool, $args, 'ok');
        return $result;
    }

    /**
     * Roll a cart back to a previous snapshot.
     *
     * Used by dry runs and by cap violations. Empties the cart and replays the
     * snapshot's lines through the adapter's own API, so no adapter internals
     * are reached into.
     *
     * CAVEAT, which matters on a live store: replaying a line is a fresh add,
     * so a store that derives line keys per insertion (WooCommerce does) will
     * hand back DIFFERENT keys than the caller saw before. An agent that reads
     * a cart, dry-runs a change, then acts on the keys from before the dry run
     * can therefore miss. The fix is on the agent's side and is cheap — use the
     * keys from the most recent cart you were given — and it is the price of
     * rolling back through the public API instead of reaching into the store.
     *
     * @param string $token    Cart token.
     * @param array  $snapshot Cart state to restore.
     * @return void
     */
    private function restore(string $token, array $snapshot) {
        try {
            $this->adapter->clear_cart($token);
        } catch (Urumiai_Store_For_Ai_Adapter_Exception $e) {
            // The cart vanished underneath us; there is nothing left to undo.
            return;
        }

        foreach ($snapshot['lines'] as $line) {
            try {
                $this->adapter->add_cart_line(
                    $token,
                    (int) $line['product_id'],
                    (int) $line['quantity'],
                    (int) $line['variation_id']
                );
            } catch (Urumiai_Store_For_Ai_Adapter_Exception $e) {
                // Stock changed underneath us; the line is simply not restored.
            }
        }
    }

    /**
     * Serialize a session's cart, tolerating a cart the store has dropped.
     *
     * @param array $session Resolved session.
     * @return array|null
     */
    private function serialize_cart(array $session): ?array {
        $cart = $this->adapter->get_cart($session['cart_token']);
        return null === $cart ? null : Urumiai_Store_For_Ai_Serializer::cart($cart);
    }

    /**
     * Store a result against its idempotency key, when one was supplied.
     *
     * @param array $args   Tool arguments.
     * @param array $result Result to replay.
     * @return void
     */
    private function remember_result(array $args, array $result) {
        if (!empty($args['idempotency_key'])) {
            $this->policy->remember((string) $args['idempotency_key'], $result);
        }
    }
}
