<?php
/**
 * UrumiAI Store for AI — WooCommerce adapter
 *
 * Binds the adapter contract to a live WooCommerce store.
 *
 * NOT EXERCISED BY THE LOCAL TEST SUITE. This host has no WordPress and no
 * WooCommerce, so the suite runs the fixture adapter instead. What the suite
 * does verify is that this class satisfies the same interface and declares no
 * method the contract does not define — the shape is pinned even where the
 * behavior cannot be. Treat the behavior here as unverified until it runs
 * against a real store.
 *
 * THE AGENT'S CART IS NOT A WOOCOMMERCE SESSION. It is a line list
 * (product, variation, quantity) this plugin stores against the cart token,
 * priced and stock-checked against the live catalog on every read.
 *
 * That is deliberate, and was learned the hard way. The first version bound
 * WC()->session to the cart token via set_customer_id() and used the real
 * WooCommerce cart. WC_Session_Handler has no such public method: the bind
 * silently did nothing, every request operated on the anonymous session, and
 * carts did not survive a single round trip. Even done correctly, rebinding an
 * already-initialized session fights whatever session handler and object cache
 * the host has substituted.
 *
 * Owning the line list means the agent's basket needs no session, no cookie and
 * no lock. The shopper's cart is still a real WooCommerce cart — the handoff
 * replays these lines into it (class-checkout-handoff.php), which is where
 * taxes, shipping and checkout have to be real, and are.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Woocommerce_Adapter implements Urumiai_Store_For_Ai_Adapter {

    /**
     * Query var carrying a checkout claim on the storefront.
     *
     * Shared with class-checkout-handoff.php, which consumes it.
     */
    const CLAIM_QUERY_VAR = 'urumiai_claim';

    /**
     * Is WooCommerce active and loaded?
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    /**
     * Convert a WooCommerce decimal price string to integer minor units.
     *
     * wc_get_price_decimals() tells us how many minor digits this currency has,
     * so zero-decimal currencies (JPY) are handled rather than assumed to be
     * hundredths.
     *
     * @param mixed $price Decimal price string or number.
     * @return int
     */
    private function to_minor($price): int {
        if ('' === $price || null === $price) {
            return 0;
        }
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        return (int) round(((float) $price) * pow(10, $decimals));
    }

    /**
     * Map a WC_Product to the canonical product shape.
     *
     * @param WC_Product $product WooCommerce product.
     * @return array
     */
    private function map_product($product): array {
        $categories = array();
        foreach (wc_get_product_terms($product->get_id(), 'product_cat', array('fields' => 'slugs')) as $slug) {
            $categories[] = $slug;
        }

        $variations = array();
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if (!$child) {
                    continue;
                }
                $variations[] = array(
                    'id'          => $child->get_id(),
                    'name'        => $child->get_name(),
                    'price_minor' => $this->to_minor($child->get_price()),
                    'in_stock'    => $child->is_in_stock(),
                    'attributes'  => $child->get_attributes(),
                );
            }
        }

        $image_url = '';
        $image_id  = $product->get_image_id();
        if ($image_id) {
            $src = wp_get_attachment_image_url($image_id, 'medium');
            if ($src) {
                $image_url = $src;
            }
        }

        return array(
            'id'                  => $product->get_id(),
            'slug'                => $product->get_slug(),
            'name'                => $product->get_name(),
            'description'         => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
            'permalink'           => $product->get_permalink(),
            'type'                => $product->get_type(),
            'currency'            => get_woocommerce_currency(),
            'price_minor'         => $this->to_minor($product->get_price()),
            'regular_price_minor' => $this->to_minor($product->get_regular_price()),
            'sale_price_minor'    => '' === $product->get_sale_price() ? null : $this->to_minor($product->get_sale_price()),
            'in_stock'            => $product->is_in_stock(),
            'stock_quantity'      => $product->get_stock_quantity(),
            'purchasable'         => $product->is_purchasable(),
            'status'              => $product->get_status(),
            'categories'          => $categories,
            'image_url'           => $image_url,
            'attributes'          => $product->get_attributes(),
            'variations'          => $variations,
        );
    }

    /**
     * @inheritDoc
     */
    public function get_store_profile(): array {
        return array(
            'name'     => get_bloginfo('name'),
            'url'      => home_url(),
            'currency' => get_woocommerce_currency(),
            'locale'   => get_locale(),
        );
    }

    /**
     * @inheritDoc
     */
    public function search_products(array $query): array {
        $limit  = isset($query['limit']) ? max(1, (int) $query['limit']) : 20;
        $offset = isset($query['cursor']) && '' !== $query['cursor'] ? max(0, (int) $query['cursor']) : 0;

        $args = array(
            'status'   => 'publish',
            'limit'    => $limit,
            'offset'   => $offset,
            'paginate' => true,
        );

        if (!empty($query['query'])) {
            $args['s'] = (string) $query['query'];
        }
        if (!empty($query['category'])) {
            $args['category'] = array((string) $query['category']);
        }
        if (!empty($query['in_stock_only'])) {
            $args['stock_status'] = 'instock';
        }

        $results = wc_get_products($args);
        $items   = array();

        foreach ($results->products as $product) {
            if (!$product->is_purchasable()) {
                continue;
            }
            $mapped = $this->map_product($product);

            // Price filters are applied here rather than in the query because
            // WooCommerce stores prices as strings in postmeta; comparing in
            // minor units is the only correct comparison.
            if (isset($query['min_price_minor']) && $mapped['price_minor'] < (int) $query['min_price_minor']) {
                continue;
            }
            if (isset($query['max_price_minor']) && $mapped['price_minor'] > (int) $query['max_price_minor']) {
                continue;
            }
            $items[] = $mapped;
        }

        $total = (int) $results->total;
        $next  = ($offset + $limit) < $total ? (string) ($offset + $limit) : null;

        return array('items' => $items, 'next_cursor' => $next, 'total' => $total);
    }

    /**
     * @inheritDoc
     */
    public function get_product($identifier): ?array {
        $product = is_numeric($identifier)
            ? wc_get_product((int) $identifier)
            : wc_get_product(wc_get_product_id_by_sku((string) $identifier));

        if (!$product) {
            $page = get_page_by_path((string) $identifier, OBJECT, 'product');
            if ($page) {
                $product = wc_get_product($page->ID);
            }
        }

        if (!$product || 'publish' !== $product->get_status() || !$product->is_purchasable()) {
            return null;
        }
        return $this->map_product($product);
    }

    /**
     * @inheritDoc
     */
    public function list_categories(): array {
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
        if (is_wp_error($terms)) {
            return array();
        }

        $out = array();
        foreach ($terms as $term) {
            $out[] = array(
                'id'     => $term->term_id,
                'slug'   => $term->slug,
                'name'   => $term->name,
                'parent' => $term->parent,
                'count'  => $term->count,
            );
        }
        return $out;
    }

    /**
     * @inheritDoc
     */
    public function get_policies(): array {
        return array(
            'shipping' => (string) get_option('urumiai_store_for_ai_shipping_policy', ''),
            'returns'  => (string) get_option('urumiai_store_for_ai_returns_policy', ''),
        );
    }

    /**
     * Cart storage key for a token.
     *
     * @param string $token Cart token.
     * @return string
     */
    private function cart_key(string $token): string {
        return 'urumiai_sfa_cart_' . md5($token);
    }

    /**
     * How long an untouched agent cart survives, in seconds.
     *
     * Longer than the default session TTL so the session layer, not the
     * storage, is what decides a cart is over.
     */
    const CART_TTL = 172800;

    /**
     * Read the stored line list for a cart.
     *
     * @param string $token Cart token.
     * @return array|null Raw lines ({product_id, variation_id, quantity}), or null when unknown.
     */
    private function read_lines(string $token): ?array {
        $stored = get_transient($this->cart_key($token));
        return is_array($stored) ? $stored : null;
    }

    /**
     * Persist the line list for a cart.
     *
     * @param string $token Cart token.
     * @param array  $lines Raw lines.
     * @return void
     */
    private function write_lines(string $token, array $lines): void {
        set_transient($this->cart_key($token), $lines, self::CART_TTL);
    }

    /**
     * Resolve the purchasable WC_Product for a line, or refuse.
     *
     * @param int $product_id   Product id.
     * @param int $variation_id Variation id, or 0.
     * @return WC_Product
     * @throws Urumiai_Store_For_Ai_Adapter_Exception When it is not purchasable.
     */
    private function purchasable_product(int $product_id, int $variation_id) {
        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);

        if (!$product || !$product->is_purchasable()) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('product %d is not available for purchase', $product_id)
            );
        }

        // A variation inherits its parent's status, so check the parent too.
        $parent_status = get_post_status($product_id);
        if ('publish' !== $parent_status) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('product %d is not available for purchase', $product_id)
            );
        }

        if (!$product->is_in_stock()) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('product %d is out of stock', $product_id)
            );
        }

        return $product;
    }

    /**
     * Deterministic key for a product/variation pair.
     *
     * Stable across requests, unlike WooCommerce's own per-insertion cart item
     * keys — an agent that reads a cart, thinks, and then acts on a line key
     * must not miss because the key was regenerated in between.
     *
     * @param int $product_id   Product id.
     * @param int $variation_id Variation id, or 0.
     * @return string
     */
    private function line_key(int $product_id, int $variation_id): string {
        return sprintf('line_%d_%d', $product_id, $variation_id);
    }

    /**
     * @inheritDoc
     */
    public function create_cart(): string {
        $token = 'agent_' . wp_generate_password(24, false, false);
        $this->write_lines($token, array());
        return $token;
    }

    /**
     * @inheritDoc
     */
    public function get_cart(string $token): ?array {
        $lines = $this->read_lines($token);
        return null === $lines ? null : $this->map_cart($token, $lines);
    }

    /**
     * Price a stored line list against the live catalog.
     *
     * Prices and names are read from WooCommerce on every call rather than
     * cached with the line, so a price change between the agent adding an item
     * and the shopper opening checkout shows up as the current price.
     *
     * @param string $token Cart token.
     * @param array  $lines Raw lines.
     * @return array Canonical cart.
     */
    private function map_cart(string $token, array $lines): array {
        $out      = array();
        $subtotal = 0;
        $count    = 0;

        foreach ($lines as $line) {
            $product_id   = (int) $line['product_id'];
            $variation_id = (int) $line['variation_id'];
            $quantity     = (int) $line['quantity'];

            $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
            if (!$product) {
                // Deleted since it was added; drop it rather than emit a ghost.
                continue;
            }

            $unit          = $this->to_minor($product->get_price());
            $line_subtotal = $unit * $quantity;

            $out[] = array(
                'key'                 => $this->line_key($product_id, $variation_id),
                'product_id'          => $product_id,
                'variation_id'        => $variation_id,
                'name'                => $product->get_name(),
                'quantity'            => $quantity,
                'unit_price_minor'    => $unit,
                'line_subtotal_minor' => $line_subtotal,
            );

            $subtotal += $line_subtotal;
            $count    += $quantity;
        }

        return array(
            'token'          => $token,
            'currency'       => get_woocommerce_currency(),
            'lines'          => $out,
            'item_count'     => $count,
            'subtotal_minor' => $subtotal,
            'updated_at'     => time(),
        );
    }

    /**
     * Refuse a quantity the store cannot actually supply.
     *
     * @param WC_Product $product  Resolved product.
     * @param int        $quantity Total quantity the line would hold.
     * @param int        $product_id Product id, for the message.
     * @return void
     * @throws Urumiai_Store_For_Ai_Adapter_Exception When stock is insufficient.
     */
    private function assert_stock($product, int $quantity, int $product_id) {
        if (!$product->managing_stock()) {
            return;
        }
        $available = (int) $product->get_stock_quantity();
        if (!$product->backorders_allowed() && $quantity > $available) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('only %d of product %d remain in stock', $available, $product_id)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function add_cart_line(string $token, int $product_id, int $quantity, int $variation_id = 0): array {
        $lines = $this->read_lines($token);
        if (null === $lines) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $product = $this->purchasable_product($product_id, $variation_id);

        if ($product->is_type('variable') && 0 === $variation_id) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('product %d is variable; a variation_id is required', $product_id)
            );
        }

        $key   = $this->line_key($product_id, $variation_id);
        $found = false;
        foreach ($lines as $index => $line) {
            if ($this->line_key((int) $line['product_id'], (int) $line['variation_id']) === $key) {
                $lines[$index]['quantity'] = (int) $line['quantity'] + $quantity;
                $this->assert_stock($product, $lines[$index]['quantity'], $product_id);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->assert_stock($product, $quantity, $product_id);
            $lines[] = array(
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'quantity'     => $quantity,
            );
        }

        $this->write_lines($token, $lines);
        return $this->map_cart($token, $lines);
    }

    /**
     * @inheritDoc
     */
    public function update_cart_line(string $token, string $line_key, int $quantity): array {
        $lines = $this->read_lines($token);
        if (null === $lines) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $found = false;
        foreach ($lines as $index => $line) {
            if ($this->line_key((int) $line['product_id'], (int) $line['variation_id']) === $line_key) {
                $product = $this->purchasable_product((int) $line['product_id'], (int) $line['variation_id']);
                $this->assert_stock($product, $quantity, (int) $line['product_id']);
                $lines[$index]['quantity'] = $quantity;
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(sprintf('no cart line %s', $line_key));
        }

        $this->write_lines($token, $lines);
        return $this->map_cart($token, $lines);
    }

    /**
     * @inheritDoc
     */
    public function remove_cart_line(string $token, string $line_key): array {
        $lines = $this->read_lines($token);
        if (null === $lines) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $before = count($lines);
        $lines  = array_values(array_filter($lines, function ($line) use ($line_key) {
            return $this->line_key((int) $line['product_id'], (int) $line['variation_id']) !== $line_key;
        }));

        if (count($lines) === $before) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(sprintf('no cart line %s', $line_key));
        }

        $this->write_lines($token, $lines);
        return $this->map_cart($token, $lines);
    }

    /**
     * @inheritDoc
     */
    public function clear_cart(string $token): array {
        if (null === $this->read_lines($token)) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $this->write_lines($token, array());
        return $this->map_cart($token, array());
    }

    /**
     * @inheritDoc
     */
    public function destroy_cart(string $token): void {
        delete_transient($this->cart_key($token));
    }

    /**
     * @inheritDoc
     *
     * A storefront URL carrying the claim token. It creates no order and
     * contacts no gateway — class-checkout-handoff.php answers it by copying
     * the basket into the visitor's own cart and redirecting to checkout.
     */
    public function get_claim_url(string $claim_token): string {
        return add_query_arg(self::CLAIM_QUERY_VAR, rawurlencode($claim_token), home_url('/'));
    }
}
