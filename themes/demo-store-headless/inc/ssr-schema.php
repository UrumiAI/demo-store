<?php
/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Naming mapping when porting:
 *   demo_store_*     ↔ base_headless_*       (function prefix)
 *   DemoStore_SSR_*  ↔ BaseHeadless_SSR_*    (PHP class prefix)
 *   Demo_Store_      ↔ Base_                 (@package tag)
 */

/**
 * SSR Schema — JSON-LD structured data helpers.
 *
 * @package Demo_Store_Headless
 */

if (!defined('ABSPATH')) {
    exit;
}

class DemoStore_SSR_Schema {

    public static function product_schema($product_data) {
        if (!$product_data) {
            return '';
        }

        $schema = array(
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            'name'        => $product_data['name'],
            'description' => wp_strip_all_tags($product_data['short_description'] ?: $product_data['description']),
            'sku'         => $product_data['sku'],
            'image'       => array(),
        );

        if (!empty($product_data['image'])) {
            $schema['image'][] = $product_data['image'];
        }
        foreach ($product_data['images'] as $image) {
            $schema['image'][] = $image['src'];
        }

        $schema['offers'] = array(
            '@type'         => 'Offer',
            'url'           => $product_data['permalink'],
            'priceCurrency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'price'         => $product_data['price'],
            'availability'  => $product_data['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
        );

        $schema['brand'] = array(
            '@type' => 'Brand',
            'name'  => get_bloginfo('name'),
        );

        return self::output_schema($schema);
    }

    public static function breadcrumb_schema($items) {
        $position   = 1;
        $list_items = array();

        foreach ($items as $item) {
            $list_items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => $item['name'],
                'item'     => $item['url'],
            );
        }

        return self::output_schema(array(
            '@context'        => 'https://schema.org/',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list_items,
        ));
    }

    private static function output_schema($schema) {
        return '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . '</script>';
    }
}
