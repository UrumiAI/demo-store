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
 * SSR Template - Checkout Page
 *
 * No SEO content needed (secure transaction page).
 *
 * @package Demo_Store_Headless
 */
?>
<div class="ssr-section">
    <h1>Checkout</h1>
    <p>Please enable JavaScript to complete your purchase securely.</p>
    <p><a href="<?php echo esc_url(home_url('/shop')); ?>">Continue Shopping</a></p>
</div>
