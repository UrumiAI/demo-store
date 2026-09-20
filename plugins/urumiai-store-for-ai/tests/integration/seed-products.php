<?php
/**
 * TEST FIXTURE — not part of the plugin under test.
 *
 * Seeds a handful of WooCommerce products so the integration test has a real
 * catalog to shop. Runs in-request rather than via `wp wc product create`,
 * which blows the wasm heap in WordPress Playground.
 *
 * Mirrors the fixture adapter's catalog so the same assertions apply:
 *   - a sale-priced simple product, in stock
 *   - a second in-stock product with limited stock (8)
 *   - an out-of-stock product (must be refused on add)
 *   - a DRAFT product (must never be visible to an agent)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    if (get_option('urumiai_test_seeded')) {
        return;
    }
    if (!function_exists('wc_get_product') || !class_exists('WC_Product_Simple')) {
        return;
    }

    $catalog = array(
        array('Ceramic Pour-Over Kit', 'POUR-1', '40', '32', 25, 'instock', 'publish'),
        array('Hand Burr Grinder',     'GRIND-1', '65', '',  8,  'instock', 'publish'),
        array('Espresso Blend 1kg',    'ESP-1',  '24', '',   0,  'outofstock', 'publish'),
        array('Unreleased Roaster',    'SECRET-1', '990', '', 3, 'instock', 'draft'),
    );

    foreach ($catalog as $row) {
        list($name, $sku, $regular, $sale, $stock, $stock_status, $status) = $row;

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($sku);
        $product->set_regular_price($regular);
        if ('' !== $sale) {
            $product->set_sale_price($sale);
        }
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        $product->set_stock_status($stock_status);
        $product->set_status($status);
        $product->set_catalog_visibility('visible');
        $product->set_short_description($name . ' — seeded test product.');
        $product->save();
    }

    update_option('urumiai_test_seeded', 1);
}, 20);
