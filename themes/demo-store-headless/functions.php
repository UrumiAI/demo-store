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
 * Demo Store Headless Theme — Functions
 *
 * @package Demo_Store_Headless
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_template_directory() . '/inc/ssr-router.php';
require_once get_template_directory() . '/inc/ssr-data.php';
require_once get_template_directory() . '/inc/ssr-schema.php';

function demo_store_setup() {
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
}
add_action('after_setup_theme', 'demo_store_setup');

function demo_store_get_assets() {
    $assets_dir = get_template_directory() . '/dist/assets/';
    $assets = array('js' => '', 'css' => '');

    if (!is_dir($assets_dir)) {
        return $assets;
    }

    foreach (scandir($assets_dir) as $file) {
        if (preg_match('/^index-([a-zA-Z0-9_-]+)\.js$/', $file)) {
            $assets['js'] = $file;
        }
        if (preg_match('/^(index|style)-([a-zA-Z0-9_-]+)\.css$/', $file)) {
            $assets['css'] = $file;
        }
    }

    return $assets;
}

function demo_store_preload_hints() {
    $assets = demo_store_get_assets();
    if (!empty($assets['js'])) {
        echo '<link rel="modulepreload" href="' . esc_url(get_template_directory_uri() . '/dist/assets/' . $assets['js']) . '">' . "\n";
    }
}
add_action('wp_head', 'demo_store_preload_hints', 1);

function demo_store_enqueue_scripts() {
    $theme_dir = get_template_directory();
    $theme_uri = get_template_directory_uri();
    $dist_dir  = $theme_dir . '/dist/assets/';

    if (is_dir($dist_dir)) {
        $assets = demo_store_get_assets();

        if (!empty($assets['css'])) {
            $css_path = $dist_dir . $assets['css'];
            wp_enqueue_style(
                'demo-store-style',
                $theme_uri . '/dist/assets/' . $assets['css'],
                array(),
                file_exists($css_path) ? filemtime($css_path) : null
            );
        }

        if (!empty($assets['js'])) {
            $js_path = $dist_dir . $assets['js'];
            wp_enqueue_script(
                'demo-store-app',
                $theme_uri . '/dist/assets/' . $assets['js'],
                array(),
                file_exists($js_path) ? filemtime($js_path) : null,
                true
            );
        }
    } else {
        wp_enqueue_script(
            'demo-store-vite-client',
            'http://localhost:5173/@vite/client',
            array(),
            null,
            false
        );
        wp_enqueue_script(
            'demo-store-app',
            'http://localhost:5173/src/main.jsx',
            array('demo-store-vite-client'),
            null,
            true
        );
    }

    wp_localize_script('demo-store-app', 'wpData', array(
        'restUrl'        => esc_url_raw(rest_url()),
        'storeApiRoot'   => esc_url_raw(rest_url('wc/store/v1/')),
        'nonce'          => wp_create_nonce('wp_rest'),
        'siteUrl'        => get_site_url(),
        'themePath'      => $theme_uri,
        'currency'       => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
        'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '$',
    ));
}
add_action('wp_enqueue_scripts', 'demo_store_enqueue_scripts');

function demo_store_cleanup_assets() {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('classic-theme-styles');
    wp_dequeue_style('global-styles');
    wp_deregister_style('global-styles');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
}
add_action('wp_enqueue_scripts', 'demo_store_cleanup_assets', 100);

function demo_store_script_type($tag, $handle, $src) {
    if (in_array($handle, array('demo-store-app', 'demo-store-vite-client'), true)) {
        $tag = '<script type="module" src="' . esc_url($src) . '"></script>';
    }
    return $tag;
}
add_filter('script_loader_tag', 'demo_store_script_type', 10, 3);

/**
 * Expose WooCommerce Store API headers (Cart-Token, Nonce) to the React
 * client. Browsers hide custom response headers unless they're listed in
 * Access-Control-Expose-Headers — without this, the cart token is set on
 * each Store API response but unreadable from JavaScript.
 */
function demo_store_expose_cart_token_header($value, $request) {
    if (is_array($value)) {
        $existing = isset($value['Access-Control-Expose-Headers']) ? $value['Access-Control-Expose-Headers'] : '';
        $headers  = array_filter(array_map('trim', explode(',', $existing)));
        foreach (array('Cart-Token', 'Nonce') as $h) {
            if (!in_array($h, $headers, true)) {
                $headers[] = $h;
            }
        }
        $value['Access-Control-Expose-Headers'] = implode(', ', $headers);
    }
    return $value;
}
add_filter('rest_allowed_cors_headers', 'demo_store_expose_cart_token_header', 10, 2);

add_filter('show_admin_bar', '__return_false');

function demo_store_disable_emoji_scripts() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
}
add_action('init', 'demo_store_disable_emoji_scripts');

function demo_store_get_seo_data($ssr_router) {
    $route_type = $ssr_router->get_route_type();
    $route_data = $ssr_router->get_route_data();
    $site_name  = get_bloginfo('name');

    $seo = array(
        'title'         => $site_name,
        'description'   => get_bloginfo('description'),
        'url'           => home_url($_SERVER['REQUEST_URI']),
        'canonical_url' => home_url($_SERVER['REQUEST_URI']),
        'og_type'       => 'website',
        'noindex'       => false,
    );

    switch ($route_type) {
        case 'home':
            $seo['canonical_url'] = home_url('/');
            $seo['url']           = home_url('/');
            break;

        case 'shop':
            $seo['title']       = 'Shop — ' . $site_name;
            $seo['description'] = 'Browse our products.';
            break;

        case 'product':
            $product = DemoStore_SSR_Data::get_product_by_slug($route_data['slug'] ?? '');
            if ($product) {
                $product_data = DemoStore_SSR_Data::format_product_data($product);
                $seo['title']       = $product_data['name'] . ' — ' . $site_name;
                $seo['description'] = wp_strip_all_tags($product_data['short_description'] ?: $product_data['description']);
                $seo['og_type']     = 'product';
                if (!empty($product_data['image'])) {
                    $seo['image'] = $product_data['image'];
                }
            }
            break;

        case 'cart':
        case 'checkout':
        case 'order-confirmation':
            $seo['noindex'] = true;
            $seo['title']   = ucwords(str_replace('-', ' ', $route_type)) . ' — ' . $site_name;
            break;
    }

    return $seo;
}
