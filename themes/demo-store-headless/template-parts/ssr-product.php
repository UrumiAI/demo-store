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
 * SSR Template - Product Detail Page (/product/{slug})
 *
 * Full semantic HTML for bots, crawlers, and AI answer engines (AEO).
 * Renders product name, description, price, and images as readable content.
 *
 * @package Demo_Store_Headless
 */

$slug = $this->get_route_data('slug');
$product = DemoStore_SSR_Data::get_product_by_slug($slug);

if (!$product) {
    echo '<div class="ssr-section"><h1>Product Not Found</h1><p>The product you are looking for could not be found.</p>';
    echo '<p><a href="' . esc_url(home_url('/shop')) . '">Browse all products</a></p></div>';
    return;
}

$product_data = DemoStore_SSR_Data::format_product_data($product);
$currency = get_woocommerce_currency_symbol();

// Structured data
echo DemoStore_SSR_Schema::product_schema($product_data);

$breadcrumbs = array(
    array('name' => 'Home', 'url' => home_url()),
    array('name' => 'Shop', 'url' => home_url('/shop')),
    array('name' => $product_data['name'], 'url' => $product_data['permalink'])
);
echo DemoStore_SSR_Schema::breadcrumb_schema($breadcrumbs);
?>

<div class="ssr-section" itemscope itemtype="https://schema.org/Product">
    <h1 itemprop="name"><?php echo esc_html($product_data['name']); ?></h1>

    <?php if (!empty($product_data['image'])): ?>
    <img itemprop="image" src="<?php echo esc_url($product_data['image']); ?>" alt="<?php echo esc_attr($product_data['name']); ?>" style="max-width:100%;height:auto;">
    <?php endif; ?>

    <p itemprop="offers" itemscope itemtype="https://schema.org/Offer">
        <strong>Price: <span itemprop="price" content="<?php echo esc_attr($product_data['price']); ?>"><?php echo esc_html($currency . $product_data['price']); ?></span></strong>
        <meta itemprop="priceCurrency" content="<?php echo esc_attr(get_woocommerce_currency()); ?>">
        <?php if ($product_data['in_stock']): ?>
        <span itemprop="availability" content="https://schema.org/InStock"> — In Stock</span>
        <?php else: ?>
        <span itemprop="availability" content="https://schema.org/OutOfStock"> — Out of Stock</span>
        <?php endif; ?>
    </p>

    <?php if (!empty($product_data['short_description'])): ?>
    <div itemprop="description">
        <?php echo wp_kses_post($product_data['short_description']); ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($product_data['description'])): ?>
    <div>
        <h2>Description</h2>
        <?php echo wp_kses_post($product_data['description']); ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($product_data['sku'])): ?>
    <p><small>SKU: <span itemprop="sku"><?php echo esc_html($product_data['sku']); ?></span></small></p>
    <?php endif; ?>

    <?php if (!empty($product_data['categories'])): ?>
    <p><small>Categories: <?php
        $cat_names = array_map(function($cat) { return esc_html($cat['name']); }, $product_data['categories']);
        echo implode(', ', $cat_names);
    ?></small></p>
    <?php endif; ?>
</div>
