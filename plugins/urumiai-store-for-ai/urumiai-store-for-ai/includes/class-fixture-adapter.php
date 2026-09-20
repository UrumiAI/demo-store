<?php
/**
 * UrumiAI Store for AI — Fixture adapter
 *
 * A deterministic in-memory store implementing the full adapter contract. Two
 * jobs:
 *
 *   1. It is what the test suite runs against, so the REAL server, tools,
 *      session rules, serializer and safety policy are all exercised end to end
 *      on a machine with no WordPress and no WooCommerce.
 *   2. It documents the contract by example — a new adapter can be checked
 *      against the same suite.
 *
 * The catalog deliberately includes a draft product and an unpurchasable one,
 * so "the adapter hides what must stay hidden" is a testable claim rather than
 * an assurance. Two products also carry customer-shaped fields the serializer
 * must drop.
 *
 * Carts live in process memory only. This is not a production store.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Fixture_Adapter implements Urumiai_Store_For_Ai_Adapter {

    /**
     * All products, including ones that must never surface.
     *
     * @var array
     */
    private $products;

    /**
     * Carts by token.
     *
     * @var array
     */
    private $carts = array();

    /**
     * Counter backing deterministic token generation.
     *
     * @var int
     */
    private $sequence = 0;

    /**
     * Fixed clock, so serialized timestamps are stable across runs.
     *
     * @var int
     */
    private $now;

    /**
     * Constructor.
     *
     * @param array|null $products Override catalog; defaults to sample_catalog().
     * @param int        $now      Fixed timestamp for updated_at.
     */
    public function __construct(?array $products = null, int $now = 1758240000) {
        $this->products = null === $products ? self::sample_catalog() : $products;
        $this->now      = $now;
    }

    /**
     * The sample catalog.
     *
     * Includes two products that must never reach an agent:
     *   - id 4 ("Unreleased Roaster") is status `draft`
     *   - id 5 ("Discontinued Grinder") is purchasable = false
     *
     * @return array
     */
    public static function sample_catalog(): array {
        return array(
            array(
                'id' => 1, 'slug' => 'ceramic-pour-over', 'name' => 'Ceramic Pour-Over Kit',
                'description' => 'A two-cup ceramic dripper with a reusable steel filter.',
                'permalink' => 'https://shop.example/product/ceramic-pour-over',
                'type' => 'simple', 'currency' => 'USD',
                'price_minor' => 3200, 'regular_price_minor' => 4000, 'sale_price_minor' => 3200,
                'in_stock' => true, 'stock_quantity' => 25, 'purchasable' => true,
                'status' => 'publish', 'categories' => array('brewing'),
                'image_url' => 'https://shop.example/img/pour-over.jpg',
                'attributes' => array('material' => 'ceramic'), 'variations' => array(),
                // Deliberate landmine: a customer-shaped field the adapter
                // carries internally. The serializer must drop it.
                'customer_email' => 'buyer@example.com',
            ),
            array(
                'id' => 2, 'slug' => 'burr-grinder', 'name' => 'Hand Burr Grinder',
                'description' => 'Stainless conical burrs with stepped adjustment.',
                'permalink' => 'https://shop.example/product/burr-grinder',
                'type' => 'variable', 'currency' => 'USD',
                'price_minor' => 6500, 'regular_price_minor' => 6500, 'sale_price_minor' => null,
                'in_stock' => true, 'stock_quantity' => 8, 'purchasable' => true,
                'status' => 'publish', 'categories' => array('brewing', 'tools'),
                'image_url' => 'https://shop.example/img/grinder.jpg',
                'attributes' => array('burr' => 'conical'),
                'variations' => array(
                    array('id' => 201, 'name' => 'Black', 'price_minor' => 6500, 'in_stock' => true,
                          'attributes' => array('color' => 'black')),
                    array('id' => 202, 'name' => 'Steel', 'price_minor' => 7000, 'in_stock' => false,
                          'attributes' => array('color' => 'steel')),
                ),
            ),
            array(
                'id' => 3, 'slug' => 'espresso-blend-1kg', 'name' => 'Espresso Blend 1kg',
                'description' => 'Seasonal espresso blend, roasted weekly.',
                'permalink' => 'https://shop.example/product/espresso-blend-1kg',
                'type' => 'simple', 'currency' => 'USD',
                'price_minor' => 2400, 'regular_price_minor' => 2400, 'sale_price_minor' => null,
                'in_stock' => false, 'stock_quantity' => 0, 'purchasable' => true,
                'status' => 'publish', 'categories' => array('coffee'),
                'image_url' => 'https://shop.example/img/espresso.jpg',
                'attributes' => array(), 'variations' => array(),
            ),
            array(
                'id' => 4, 'slug' => 'unreleased-roaster', 'name' => 'Unreleased Roaster',
                'description' => 'Not announced yet.',
                'permalink' => 'https://shop.example/product/unreleased-roaster',
                'type' => 'simple', 'currency' => 'USD',
                'price_minor' => 99000, 'regular_price_minor' => 99000, 'sale_price_minor' => null,
                'in_stock' => true, 'stock_quantity' => 3, 'purchasable' => true,
                'status' => 'draft', 'categories' => array('equipment'),
                'image_url' => '', 'attributes' => array(), 'variations' => array(),
            ),
            array(
                'id' => 5, 'slug' => 'discontinued-grinder', 'name' => 'Discontinued Grinder',
                'description' => 'No longer sold.',
                'permalink' => 'https://shop.example/product/discontinued-grinder',
                'type' => 'simple', 'currency' => 'USD',
                'price_minor' => 4500, 'regular_price_minor' => 4500, 'sale_price_minor' => null,
                'in_stock' => true, 'stock_quantity' => 2, 'purchasable' => false,
                'status' => 'publish', 'categories' => array('tools'),
                'image_url' => '', 'attributes' => array(), 'variations' => array(),
            ),
        );
    }

    /**
     * Products an agent is allowed to see.
     *
     * @return array
     */
    private function visible_products(): array {
        return array_values(array_filter($this->products, function ($product) {
            return 'publish' === $product['status'] && !empty($product['purchasable']);
        }));
    }

    /**
     * @inheritDoc
     */
    public function get_store_profile(): array {
        return array(
            'name'     => 'Example Coffee Co.',
            'url'      => 'https://shop.example',
            'currency' => 'USD',
            'locale'   => 'en_US',
            // Another landmine for the serializer.
            'admin_email' => 'owner@example.com',
        );
    }

    /**
     * @inheritDoc
     */
    public function search_products(array $query): array {
        $items = $this->visible_products();

        if (!empty($query['query'])) {
            $needle = strtolower((string) $query['query']);
            $items  = array_values(array_filter($items, function ($product) use ($needle) {
                return false !== strpos(strtolower($product['name']), $needle)
                    || false !== strpos(strtolower($product['description']), $needle);
            }));
        }

        if (!empty($query['category'])) {
            $category = (string) $query['category'];
            $items    = array_values(array_filter($items, function ($product) use ($category) {
                return in_array($category, $product['categories'], true);
            }));
        }

        if (isset($query['min_price_minor'])) {
            $min   = (int) $query['min_price_minor'];
            $items = array_values(array_filter($items, function ($product) use ($min) {
                return (int) $product['price_minor'] >= $min;
            }));
        }

        if (isset($query['max_price_minor'])) {
            $max   = (int) $query['max_price_minor'];
            $items = array_values(array_filter($items, function ($product) use ($max) {
                return (int) $product['price_minor'] <= $max;
            }));
        }

        if (!empty($query['in_stock_only'])) {
            $items = array_values(array_filter($items, function ($product) {
                return !empty($product['in_stock']);
            }));
        }

        $total  = count($items);
        $offset = isset($query['cursor']) && '' !== $query['cursor'] ? max(0, (int) $query['cursor']) : 0;
        $limit  = isset($query['limit']) ? max(1, (int) $query['limit']) : 20;
        $page   = array_slice($items, $offset, $limit);

        $next = ($offset + $limit) < $total ? (string) ($offset + $limit) : null;

        return array('items' => $page, 'next_cursor' => $next, 'total' => $total);
    }

    /**
     * @inheritDoc
     */
    public function get_product($identifier): ?array {
        foreach ($this->visible_products() as $product) {
            if ((string) $product['id'] === (string) $identifier || $product['slug'] === (string) $identifier) {
                return $product;
            }
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function list_categories(): array {
        $counts = array();
        foreach ($this->visible_products() as $product) {
            foreach ($product['categories'] as $slug) {
                $counts[$slug] = isset($counts[$slug]) ? $counts[$slug] + 1 : 1;
            }
        }
        ksort($counts);

        $out = array();
        $id  = 1;
        foreach ($counts as $slug => $count) {
            $out[] = array(
                'id'     => $id++,
                'slug'   => $slug,
                'name'   => ucfirst(str_replace('-', ' ', $slug)),
                'parent' => 0,
                'count'  => $count,
            );
        }
        return $out;
    }

    /**
     * @inheritDoc
     */
    public function get_policies(): array {
        return array(
            'shipping' => 'Ships within 2 business days. Free over $50 in the contiguous US.',
            'returns'  => 'Unopened goods may be returned within 30 days for a full refund.',
        );
    }

    /**
     * @inheritDoc
     */
    public function create_cart(): string {
        $this->sequence++;
        $token = sprintf('cart_%06d', $this->sequence);

        $this->carts[$token] = array(
            'token'          => $token,
            'currency'       => 'USD',
            'lines'          => array(),
            'item_count'     => 0,
            'subtotal_minor' => 0,
            'updated_at'     => $this->now,
        );
        return $token;
    }

    /**
     * @inheritDoc
     */
    public function get_cart(string $token): ?array {
        return isset($this->carts[$token]) ? $this->carts[$token] : null;
    }

    /**
     * Recompute derived cart totals from its lines.
     *
     * @param string $token Cart token.
     * @return array Updated cart.
     */
    private function recalculate(string $token): array {
        $cart     = $this->carts[$token];
        $subtotal = 0;
        $count    = 0;

        foreach ($cart['lines'] as $index => $line) {
            $line_subtotal = (int) $line['unit_price_minor'] * (int) $line['quantity'];
            $cart['lines'][$index]['line_subtotal_minor'] = $line_subtotal;
            $subtotal += $line_subtotal;
            $count    += (int) $line['quantity'];
        }

        $cart['subtotal_minor'] = $subtotal;
        $cart['item_count']     = $count;
        $cart['updated_at']     = $this->now;

        $this->carts[$token] = $cart;
        return $cart;
    }

    /**
     * Resolve the purchasable unit price for a product/variation pair.
     *
     * @param array $product      Canonical product.
     * @param int   $variation_id Variation id, or 0.
     * @return int Unit price in minor units.
     * @throws Urumiai_Store_For_Ai_Adapter_Exception When the variation is unknown or out of stock.
     */
    private function resolve_price(array $product, int $variation_id): int {
        if (0 === $variation_id) {
            if (!empty($product['variations'])) {
                throw new Urumiai_Store_For_Ai_Adapter_Exception(
                    sprintf('product %d is variable; a variation_id is required', $product['id'])
                );
            }
            return (int) $product['price_minor'];
        }

        foreach ($product['variations'] as $variation) {
            if ((int) $variation['id'] === $variation_id) {
                if (empty($variation['in_stock'])) {
                    throw new Urumiai_Store_For_Ai_Adapter_Exception(
                        sprintf('variation %d is out of stock', $variation_id)
                    );
                }
                return (int) $variation['price_minor'];
            }
        }

        throw new Urumiai_Store_For_Ai_Adapter_Exception(
            sprintf('variation %d does not belong to product %d', $variation_id, $product['id'])
        );
    }

    /**
     * @inheritDoc
     */
    public function add_cart_line(string $token, int $product_id, int $quantity, int $variation_id = 0): array {
        if (!isset($this->carts[$token])) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $product = $this->get_product($product_id);
        if (null === $product) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('product %d is not available for purchase', $product_id)
            );
        }
        if (empty($product['in_stock'])) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(
                sprintf('product %d is out of stock', $product_id)
            );
        }

        $unit_price = $this->resolve_price($product, $variation_id);
        $line_key   = sprintf('line_%d_%d', $product_id, $variation_id);

        $cart  = $this->carts[$token];
        $found = false;
        foreach ($cart['lines'] as $index => $line) {
            if ($line['key'] === $line_key) {
                $cart['lines'][$index]['quantity'] += $quantity;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $cart['lines'][] = array(
                'key'                 => $line_key,
                'product_id'          => $product_id,
                'variation_id'        => $variation_id,
                'name'                => $product['name'],
                'quantity'            => $quantity,
                'unit_price_minor'    => $unit_price,
                'line_subtotal_minor' => $unit_price * $quantity,
            );
        }

        $available = isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : null;
        if (null !== $available) {
            foreach ($cart['lines'] as $line) {
                if ($line['key'] === $line_key && (int) $line['quantity'] > $available) {
                    throw new Urumiai_Store_For_Ai_Adapter_Exception(
                        sprintf('only %d of product %d remain in stock', $available, $product_id)
                    );
                }
            }
        }

        $this->carts[$token] = $cart;
        return $this->recalculate($token);
    }

    /**
     * @inheritDoc
     */
    public function update_cart_line(string $token, string $line_key, int $quantity): array {
        if (!isset($this->carts[$token])) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $cart  = $this->carts[$token];
        $found = false;
        foreach ($cart['lines'] as $index => $line) {
            if ($line['key'] === $line_key) {
                $product   = $this->get_product((int) $line['product_id']);
                $available = (null !== $product && isset($product['stock_quantity']))
                    ? (int) $product['stock_quantity'] : null;
                if (null !== $available && $quantity > $available) {
                    throw new Urumiai_Store_For_Ai_Adapter_Exception(
                        sprintf('only %d of product %d remain in stock', $available, (int) $line['product_id'])
                    );
                }
                $cart['lines'][$index]['quantity'] = $quantity;
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(sprintf('no cart line %s', $line_key));
        }

        $this->carts[$token] = $cart;
        return $this->recalculate($token);
    }

    /**
     * @inheritDoc
     */
    public function remove_cart_line(string $token, string $line_key): array {
        if (!isset($this->carts[$token])) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $cart   = $this->carts[$token];
        $before = count($cart['lines']);

        $cart['lines'] = array_values(array_filter($cart['lines'], function ($line) use ($line_key) {
            return $line['key'] !== $line_key;
        }));

        if (count($cart['lines']) === $before) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception(sprintf('no cart line %s', $line_key));
        }

        $this->carts[$token] = $cart;
        return $this->recalculate($token);
    }

    /**
     * @inheritDoc
     */
    public function clear_cart(string $token): array {
        if (!isset($this->carts[$token])) {
            throw new Urumiai_Store_For_Ai_Adapter_Exception('unknown cart');
        }

        $this->carts[$token]['lines'] = array();
        return $this->recalculate($token);
    }

    /**
     * @inheritDoc
     */
    public function destroy_cart(string $token): void {
        unset($this->carts[$token]);
    }

    /**
     * @inheritDoc
     */
    public function get_claim_url(string $claim_token): string {
        return 'https://shop.example/?urumiai_claim=' . rawurlencode($claim_token);
    }
}
