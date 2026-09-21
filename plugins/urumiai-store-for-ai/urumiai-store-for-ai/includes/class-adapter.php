<?php
/**
 * UrumiAI Store for AI — Store adapter contract
 *
 * The seam between the MCP tool surface and whatever commerce engine is behind
 * it. Tool handlers NEVER touch WooCommerce (or any store) directly; they speak
 * only these canonical shapes, so the same tools work against the real store
 * and against the deterministic fixture used by the tests.
 *
 * MONEY. Every amount is an INTEGER in the currency's minor unit (cents for
 * USD/EUR, yen for JPY) plus an ISO-4217 code. Never a float, never a formatted
 * string. Float money loses cents, and this host has no bcmath.
 *
 * VISIBILITY. Adapters must return ONLY products that are publicly purchasable:
 * post status `publish`, purchasable, and not password-protected. Filtering at
 * the adapter means a bug in a tool handler cannot expose a draft.
 *
 * CANONICAL PRODUCT
 *   id, slug, name, description, permalink, type, currency,
 *   price_minor, regular_price_minor, sale_price_minor (int|null),
 *   in_stock (bool), stock_quantity (int|null), purchasable (bool),
 *   status, categories (string[]), image_url, attributes (map),
 *   variations (array of {id, name, price_minor, in_stock, attributes})
 *
 * CANONICAL CART
 *   token, currency, item_count, subtotal_minor, updated_at,
 *   lines: [{ key, product_id, variation_id, name, quantity,
 *             unit_price_minor, line_subtotal_minor }]
 *
 * The cart `token` is an INTERNAL handle. It is not serialized to an agent —
 * see class-sessions.php and the serializer's CART_FIELDS. What it addresses is
 * the adapter's business: the WooCommerce adapter keys its own line list by it,
 * NOT a WooCommerce session (see that file for why).
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

interface Urumiai_Store_For_Ai_Adapter {

    /**
     * Store identity: name, currency, locale, url.
     *
     * @return array
     */
    public function get_store_profile(): array;

    /**
     * Search the catalog.
     *
     * @param array $query query, category, min_price_minor, max_price_minor,
     *                     in_stock_only, limit, cursor.
     * @return array {items: array[], next_cursor: string|null, total: int}
     */
    public function search_products(array $query): array;

    /**
     * Fetch one product by id or slug.
     *
     * @param int|string $identifier Numeric id or slug.
     * @return array|null Canonical product, or null when absent/not purchasable.
     */
    public function get_product($identifier): ?array;

    /**
     * All public product categories.
     *
     * @return array
     */
    public function list_categories(): array;

    /**
     * Shipping/returns policy text the merchant chose to publish.
     *
     * @return array {shipping: string, returns: string}
     */
    public function get_policies(): array;

    /**
     * Create an empty cart, returning its opaque internal token.
     *
     * @return string
     * @throws Urumiai_Store_For_Ai_Adapter_Exception When the store cannot open a cart.
     */
    public function create_cart(): string;

    /**
     * Read a cart.
     *
     * @param string $token Cart token.
     * @return array|null Canonical cart, or null when unknown/expired.
     */
    public function get_cart(string $token): ?array;

    /**
     * Add a line to a cart.
     *
     * @param string $token        Cart token.
     * @param int    $product_id   Product id.
     * @param int    $quantity     Quantity (>= 1).
     * @param int    $variation_id Variation id, or 0.
     * @return array Updated canonical cart.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception On an unpurchasable product or insufficient stock.
     */
    public function add_cart_line(string $token, int $product_id, int $quantity, int $variation_id = 0): array;

    /**
     * Set the quantity of an existing line.
     *
     * @param string $token    Cart token.
     * @param string $line_key Line key.
     * @param int    $quantity New quantity (>= 1).
     * @return array Updated canonical cart.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception On an unknown line or insufficient stock.
     */
    public function update_cart_line(string $token, string $line_key, int $quantity): array;

    /**
     * Remove a line.
     *
     * @param string $token    Cart token.
     * @param string $line_key Line key.
     * @return array Updated canonical cart.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception On an unknown line.
     */
    public function remove_cart_line(string $token, string $line_key): array;

    /**
     * Empty a cart of every line.
     *
     * @param string $token Cart token.
     * @return array Updated canonical cart.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception On an unknown cart.
     */
    public function clear_cart(string $token): array;

    /**
     * Forget a cart entirely, releasing whatever the store holds for it.
     *
     * Called when a session ends. Must not throw for an unknown token: ending
     * a session twice is not an error.
     *
     * @param string $token Cart token.
     * @return void
     */
    public function destroy_cart(string $token): void;

    /**
     * Single-use URL that adopts an agent-built cart into a shopper's browser.
     *
     * The agent hands this to a person. Opening it binds the store session that
     * holds the cart to that visitor and lands them on checkout — see
     * class-checkout-handoff.php. It places no order and takes no payment.
     *
     * @param string $claim_token Opaque claim token minted by the session layer.
     * @return string
     */
    public function get_claim_url(string $claim_token): string;
}

/**
 * Adapter-level failure that is the caller's fault (bad product, out of stock).
 *
 * Surfaces to the agent as an MCP tool error, not a JSON-RPC protocol error:
 * the call was well-formed, the store just said no.
 *
 * @since 0.1.0
 */
class Urumiai_Store_For_Ai_Adapter_Exception extends Exception {
}
