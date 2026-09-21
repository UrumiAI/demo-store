<?php
/**
 * UrumiAI Store for AI — Egress serializer
 *
 * THE single chokepoint through which every byte reaching an AI agent passes.
 *
 * It is an ALLOWLIST, deliberately. A denylist ("strip email, strip phone")
 * fails the moment a store, a plugin or a future adapter introduces a field
 * nobody thought to deny — and the failure mode is silently handing customer
 * data to a third-party model. An allowlist fails the other way: a new field is
 * invisible until someone adds it here on purpose.
 *
 * Consequently nothing customer-shaped can be emitted: no email, phone, billing
 * or shipping address, no order history, no user id, no meta_data blob, no
 * private notes. There is no serializer for those shapes at all, so a tool
 * handler cannot emit one even by mistake.
 *
 * TWO INTERNAL HANDLES ARE ALSO WITHHELD, for the same reason and by the same
 * mechanism: a cart's adapter `token` (absent from CART_FIELDS) and a session's
 * `cart_token` (absent from SESSION_FIELDS). An agent addresses everything by
 * `session_id`, so the store-level handles never leave the server.
 *
 * @package Urumiai_Store_For_Ai
 * @since 0.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

final class Urumiai_Store_For_Ai_Serializer {

    /** Fields emitted for a product in a search result (compact). */
    const PRODUCT_SUMMARY_FIELDS = array(
        'id', 'slug', 'name', 'permalink', 'currency',
        'price_minor', 'regular_price_minor', 'sale_price_minor',
        'in_stock', 'purchasable', 'categories', 'image_url',
    );

    /** Fields emitted for a single product (detail). */
    const PRODUCT_DETAIL_FIELDS = array(
        'id', 'slug', 'name', 'description', 'permalink', 'type', 'currency',
        'price_minor', 'regular_price_minor', 'sale_price_minor',
        'in_stock', 'stock_quantity', 'purchasable', 'categories',
        'image_url', 'attributes', 'variations',
    );

    /** Fields emitted for a variation. */
    const VARIATION_FIELDS = array('id', 'name', 'price_minor', 'in_stock', 'attributes');

    /**
     * Fields emitted for a cart.
     *
     * Note the absence of `token`: the adapter's cart handle is internal.
     */
    const CART_FIELDS = array(
        'currency', 'item_count', 'subtotal_minor', 'updated_at', 'lines',
    );

    /** Fields emitted for a cart line. */
    const CART_LINE_FIELDS = array(
        'key', 'product_id', 'variation_id', 'name', 'quantity',
        'unit_price_minor', 'line_subtotal_minor',
    );

    /**
     * Fields emitted for a session.
     *
     * Note the absence of `cart_token`, and the absence of anything
     * customer-shaped — a session record has no such field to begin with.
     */
    const SESSION_FIELDS = array('id', 'state', 'created_at', 'updated_at', 'expires_at');

    /** Fields emitted for a checkout claim's status. */
    const HANDOFF_FIELDS = array('state', 'claimed', 'expires_at', 'claimed_at');

    /** Fields emitted for the store profile. */
    const STORE_FIELDS = array('name', 'url', 'currency', 'locale');

    /** Fields emitted for a category. */
    const CATEGORY_FIELDS = array('id', 'slug', 'name', 'parent', 'count');

    /**
     * Reduce an array to an allowlist, preserving allowlist order.
     *
     * Absent keys are skipped rather than emitted as null, so an agent can tell
     * "the store does not track this" from "the value is empty".
     *
     * @param array $source Raw canonical array.
     * @param array $fields Allowlisted keys.
     * @return array
     */
    private static function pick(array $source, array $fields): array {
        $out = array();
        foreach ($fields as $field) {
            if (array_key_exists($field, $source)) {
                $out[$field] = $source[$field];
            }
        }
        return $out;
    }

    /**
     * Serialize a product summary.
     *
     * @param array $product Canonical product.
     * @return array
     */
    public static function product_summary(array $product): array {
        return self::pick($product, self::PRODUCT_SUMMARY_FIELDS);
    }

    /**
     * Serialize a product detail, including its variations.
     *
     * @param array $product Canonical product.
     * @return array
     */
    public static function product_detail(array $product): array {
        $out = self::pick($product, self::PRODUCT_DETAIL_FIELDS);
        if (isset($out['variations']) && is_array($out['variations'])) {
            $variations = array();
            foreach ($out['variations'] as $variation) {
                if (is_array($variation)) {
                    $variations[] = self::pick($variation, self::VARIATION_FIELDS);
                }
            }
            $out['variations'] = $variations;
        }
        return $out;
    }

    /**
     * Serialize a cart, including its lines.
     *
     * @param array $cart Canonical cart.
     * @return array
     */
    public static function cart(array $cart): array {
        $out = self::pick($cart, self::CART_FIELDS);
        $lines = array();
        if (isset($cart['lines']) && is_array($cart['lines'])) {
            foreach ($cart['lines'] as $line) {
                if (is_array($line)) {
                    $lines[] = self::pick($line, self::CART_LINE_FIELDS);
                }
            }
        }
        $out['lines'] = $lines;
        return $out;
    }

    /**
     * Serialize a session.
     *
     * @param array $session Canonical session.
     * @return array
     */
    public static function session(array $session): array {
        return self::pick($session, self::SESSION_FIELDS);
    }

    /**
     * Serialize a checkout claim's status.
     *
     * @param array $status Claim status from Urumiai_Store_For_Ai_Sessions::claim_status().
     * @return array
     */
    public static function handoff(array $status): array {
        return self::pick($status, self::HANDOFF_FIELDS);
    }

    /**
     * Serialize the store profile.
     *
     * @param array $store Canonical store profile.
     * @return array
     */
    public static function store_profile(array $store): array {
        return self::pick($store, self::STORE_FIELDS);
    }

    /**
     * Serialize a category list.
     *
     * @param array $categories Canonical categories.
     * @return array
     */
    public static function categories(array $categories): array {
        $out = array();
        foreach ($categories as $category) {
            if (is_array($category)) {
                $out[] = self::pick($category, self::CATEGORY_FIELDS);
            }
        }
        return $out;
    }
}
