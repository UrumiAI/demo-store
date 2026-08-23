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

$theme_uri = get_template_directory_uri();
?>
<div class="energy-home">
    <section class="pulse-hero">
        <video class="hero-video" autoplay muted loop playsinline preload="metadata" poster="<?php echo esc_url($theme_uri . '/public/energy/photos/afterglow-cans.jpg'); ?>" aria-hidden="true"><source src="<?php echo esc_url($theme_uri . '/public/energy/video/neon-light-layer.mp4'); ?>" type="video/mp4"></video>
        <div class="hero-copy">
            <p class="signal-label"><span></span> 180 MG / ZERO SUGAR</p>
            <h1>Stay<br><em>charged.</em></h1>
            <p class="hero-intro">Bright flavor and clean energy for whatever happens next.</p>
            <p><a class="btn btn-primary" href="<?php echo esc_url(home_url('/shop')); ?>">Shop energy <b>↗</b></a></p>
        </div>
        <div class="hero-art"><img src="<?php echo esc_url($theme_uri . '/public/energy/photos/voltage-can.jpg'); ?>" alt="PULSE energy drink"></div>
        <a class="hero-quick-card" href="<?php echo esc_url(home_url('/product/voltage-citrus-energy')); ?>"><span>01 / CITRUS</span><strong>Voltage</strong><small>180 mg caffeine · 0 g sugar</small></a>
    </section>
</div>
