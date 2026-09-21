<?php
/**
 * UrumiAI Store for AI — Resource surface
 *
 * Resources are the read-only, addressable half of MCP: an agent can fetch one
 * by URI without a tool call, and a client can show a human what was read.
 *
 *   store://store/profile        name, url, currency, locale
 *   store://catalog/products     first page of the catalog
 *   store://catalog/categories   category list
 *   store://policies/shipping    published shipping policy
 *   store://policies/returns     published returns policy
 *   store://session/{id}         one session and its cart, by session id
 *
 * Everything here is read-only and passes through the same allowlist serializer
 * as the tools, so a resource read cannot leak a field a tool would have
 * stripped — including the internal cart token.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Resources {

    const URI_PREFIX     = 'store://';
    const SESSION_PREFIX = 'store://session/';

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
     * Constructor.
     *
     * @param Urumiai_Store_For_Ai_Adapter  $adapter  Store adapter.
     * @param Urumiai_Store_For_Ai_Sessions $sessions Session lifecycle.
     */
    public function __construct(
        Urumiai_Store_For_Ai_Adapter $adapter,
        Urumiai_Store_For_Ai_Sessions $sessions
    ) {
        $this->adapter  = $adapter;
        $this->sessions = $sessions;
    }

    /**
     * Static resource list, in MCP `resources/list` shape.
     *
     * store://session/{id} is deliberately absent: it is a template, not a
     * fixed resource, and listing sessions would mean enumerating other
     * people's baskets.
     *
     * @return array
     */
    public function definitions(): array {
        return array(
            array(
                'uri'         => 'store://store/profile',
                'name'        => 'Store profile',
                'description' => 'Merchant name, storefront URL, currency and locale.',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'store://catalog/products',
                'name'        => 'Product catalog',
                'description' => 'First page of published, purchasable products.',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'store://catalog/categories',
                'name'        => 'Product categories',
                'description' => 'Public product categories with counts.',
                'mimeType'    => 'application/json',
            ),
            array(
                'uri'         => 'store://policies/shipping',
                'name'        => 'Shipping policy',
                'description' => 'The merchant published shipping policy.',
                'mimeType'    => 'text/plain',
            ),
            array(
                'uri'         => 'store://policies/returns',
                'name'        => 'Returns policy',
                'description' => 'The merchant published returns policy.',
                'mimeType'    => 'text/plain',
            ),
        );
    }

    /**
     * Resource templates, in MCP `resources/templates/list` shape.
     *
     * @return array
     */
    public function templates(): array {
        return array(
            array(
                'uriTemplate' => 'store://session/{session_id}',
                'name'        => 'Session cart',
                'description' => 'One shopping session and the cart behind it.',
                'mimeType'    => 'application/json',
            ),
        );
    }

    /**
     * Read one resource by URI.
     *
     * @param string $uri Resource URI.
     * @return array|null MCP resource contents entry, or null when unknown.
     */
    public function read(string $uri): ?array {
        if ('store://store/profile' === $uri) {
            return $this->json(
                $uri,
                Urumiai_Store_For_Ai_Serializer::store_profile($this->adapter->get_store_profile())
            );
        }

        if ('store://catalog/products' === $uri) {
            $result = $this->adapter->search_products(array('limit' => 20));
            $items  = array();
            foreach ($result['items'] as $product) {
                $items[] = Urumiai_Store_For_Ai_Serializer::product_summary($product);
            }
            return $this->json($uri, array(
                'products'    => $items,
                'total'       => (int) $result['total'],
                'next_cursor' => $result['next_cursor'],
            ));
        }

        if ('store://catalog/categories' === $uri) {
            return $this->json($uri, array(
                'categories' => Urumiai_Store_For_Ai_Serializer::categories($this->adapter->list_categories()),
            ));
        }

        if ('store://policies/shipping' === $uri) {
            $policies = $this->adapter->get_policies();
            return $this->text($uri, isset($policies['shipping']) ? (string) $policies['shipping'] : '');
        }

        if ('store://policies/returns' === $uri) {
            $policies = $this->adapter->get_policies();
            return $this->text($uri, isset($policies['returns']) ? (string) $policies['returns'] : '');
        }

        if (0 === strpos($uri, self::SESSION_PREFIX)) {
            $session_id = rawurldecode(substr($uri, strlen(self::SESSION_PREFIX)));

            try {
                $session = $this->sessions->resolve($session_id);
            } catch (Urumiai_Store_For_Ai_Session_Exception $e) {
                // An unreadable session is an unknown URI, not a server fault.
                return null;
            }

            $cart = $this->adapter->get_cart($session['cart_token']);

            return $this->json($uri, array(
                'session' => Urumiai_Store_For_Ai_Serializer::session($session),
                'cart'    => null === $cart ? null : Urumiai_Store_For_Ai_Serializer::cart($cart),
            ));
        }

        return null;
    }

    /**
     * Build a JSON resource contents entry.
     *
     * @param string $uri     Resource URI.
     * @param array  $payload Payload.
     * @return array
     */
    private function json(string $uri, array $payload): array {
        return array(
            'uri'      => $uri,
            'mimeType' => 'application/json',
            'text'     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Build a plain-text resource contents entry.
     *
     * @param string $uri  Resource URI.
     * @param string $body Body text.
     * @return array
     */
    private function text(string $uri, string $body): array {
        return array('uri' => $uri, 'mimeType' => 'text/plain', 'text' => $body);
    }
}
