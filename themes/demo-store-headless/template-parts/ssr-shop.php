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
 * SSR Template - Shop / Product Listing (/shop)
 *
 * Full semantic HTML for bots, crawlers, and AI answer engines (AEO).
 * Renders product catalog with names, prices, and descriptions.
 *
 * @package Demo_Store_Headless
 */

$products = DemoStore_SSR_Data::get_products(array('posts_per_page' => 12));
$currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
?>

<div class="ssr-section">
    <h1>Shop — <?php echo esc_html(get_bloginfo('name')); ?></h1>
    <p>Browse our collection of products.</p>
</div>

<?php if (!empty($products)): ?>
    <?php foreach ($products as $product):
        $data = DemoStore_SSR_Data::format_product_data($product);
        if (!$data) continue;
    ?>
    <div class="ssr-section" itemscope itemtype="https://schema.org/Product">
        <h2><a href="<?php echo esc_url($data['permalink']); ?>" itemprop="name"><?php echo esc_html($data['name']); ?></a></h2>
        <?php if (!empty($data['image'])): ?>
        <img itemprop="image" src="<?php echo esc_url($data['image']); ?>" alt="<?php echo esc_attr($data['name']); ?>" style="max-width:300px;height:auto;">
        <?php endif; ?>
        <p itemprop="offers" itemscope itemtype="https://schema.org/Offer">
            <strong>Price: <span itemprop="price" content="<?php echo esc_attr($data['price']); ?>"><?php echo esc_html($currency . $data['price']); ?></span></strong>
            <meta itemprop="priceCurrency" content="<?php echo esc_attr(get_woocommerce_currency()); ?>">
        </p>
        <?php if (!empty($data['short_description'])): ?>
        <div itemprop="description"><?php echo wp_kses_post($data['short_description']); ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <p>No products found.</p>
<?php endif; ?>
