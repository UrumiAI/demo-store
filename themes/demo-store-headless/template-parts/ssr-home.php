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
 * SSR Template — Homepage
 *
 * @package Demo_Store_Headless
 */

$site_name = get_bloginfo('name');
$tagline   = get_bloginfo('description');
?>
<div class="ssr-section">
    <h1><?php echo esc_html($site_name); ?></h1>
    <?php if (!empty($tagline)): ?>
    <p><?php echo esc_html($tagline); ?></p>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(home_url('/shop')); ?>">Browse the shop →</a></p>
</div>
