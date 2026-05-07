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
 * SSR Data — WooCommerce product fetchers used by SSR templates.
 *
 * @package Demo_Store_Headless
 */

if (!defined('ABSPATH')) {
    exit;
}

class DemoStore_SSR_Data {

    public static function get_product_by_slug($slug) {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $query = new WP_Query(array(
            'post_type'      => 'product',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
        ));

        if ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            wp_reset_postdata();
            return $product;
        }

        return null;
    }

    public static function get_products($args = array()) {
        if (!function_exists('wc_get_product')) {
            return array();
        }

        $defaults = array(
            'post_type'      => 'product',
            'posts_per_page' => 12,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $args     = wp_parse_args($args, $defaults);
        $query    = new WP_Query($args);
        $products = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                if ($product) {
                    $products[] = $product;
                }
            }
            wp_reset_postdata();
        }

        return $products;
    }

    public static function format_product_data($product) {
        if (!$product || !function_exists('wc_get_product')) {
            return null;
        }

        $image          = wp_get_attachment_image_src($product->get_image_id(), 'full');
        $gallery_ids    = $product->get_gallery_image_ids();
        $gallery_images = array();

        foreach ($gallery_ids as $image_id) {
            $gallery_image = wp_get_attachment_image_src($image_id, 'full');
            if ($gallery_image) {
                $gallery_images[] = array(
                    'src' => $gallery_image[0],
                    'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                );
            }
        }

        return array(
            'id'                => $product->get_id(),
            'name'              => $product->get_name(),
            'slug'              => $product->get_slug(),
            'price'             => $product->get_price(),
            'regular_price'     => $product->get_regular_price(),
            'sale_price'        => $product->get_sale_price(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku'               => $product->get_sku(),
            'stock_status'      => $product->get_stock_status(),
            'in_stock'          => $product->is_in_stock(),
            'image'             => $image ? $image[0] : '',
            'images'            => $gallery_images,
            'categories'        => self::get_product_categories($product),
            'permalink'         => get_permalink($product->get_id()),
        );
    }

    private static function get_product_categories($product) {
        $categories = array();
        $terms      = get_the_terms($product->get_id(), 'product_cat');

        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }

        return $categories;
    }
}
