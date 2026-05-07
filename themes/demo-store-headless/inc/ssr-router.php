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
 * SSR Router — Detects current route and renders semantic HTML for crawlers.
 *
 * @package Demo_Store_Headless
 */

if (!defined('ABSPATH')) {
    exit;
}

class DemoStore_SSR_Router {

    private $route_type = 'home';
    private $route_data = array();

    public function __construct() {
        $this->detect_route();
    }

    private function detect_route() {
        $request_uri = $_SERVER['REQUEST_URI'];
        $parsed_url  = parse_url($request_uri);
        $path        = isset($parsed_url['path']) ? trim($parsed_url['path'], '/') : '';

        $site_path = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');
        if (!empty($site_path) && strpos($path, $site_path) === 0) {
            $path = trim(substr($path, strlen($site_path)), '/');
        }

        if (empty($path) || $path === 'home') {
            $this->route_type = 'home';
        } elseif ($path === 'shop' || preg_match('#^product-category/([^/]+)/?$#', $path, $matches)) {
            $this->route_type = 'shop';
            if (isset($matches[1])) {
                $this->route_data['category'] = $matches[1];
            }
        } elseif (preg_match('#^product/([^/]+)/?$#', $path, $matches)) {
            $this->route_type = 'product';
            $this->route_data['slug'] = $matches[1];
        } elseif ($path === 'cart') {
            $this->route_type = 'cart';
        } elseif ($path === 'checkout') {
            $this->route_type = 'checkout';
        } elseif (preg_match('#^order-confirmation/(\d+)/?$#', $path, $matches)) {
            $this->route_type = 'order-confirmation';
            $this->route_data['order_id'] = $matches[1];
        } else {
            $this->route_type = 'home';
        }
    }

    public function get_route_type() {
        return $this->route_type;
    }

    public function get_route_data($key = null) {
        if ($key) {
            return isset($this->route_data[$key]) ? $this->route_data[$key] : null;
        }
        return $this->route_data;
    }

    public function render() {
        $template_file = get_template_directory() . '/template-parts/ssr-' . $this->route_type . '.php';

        echo '<article id="ssr-content" role="main">';
        if (file_exists($template_file)) {
            include $template_file;
        }
        echo '</article>';
        echo '<div id="root"></div>';
    }
}
