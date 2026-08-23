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
        <div class="hero-orbit hero-orbit-one"></div><div class="hero-orbit hero-orbit-two"></div>
        <div class="hero-copy">
            <p class="signal-label"><span></span> ENERGY, REWIRED</p>
            <h1>Find your<br><em>frequency.</em></h1>
            <p class="hero-intro">Clean energy for the hours that need more from you. Big flavor. Zero hesitation.</p>
            <p><a class="btn btn-primary" href="<?php echo esc_url(home_url('/shop')); ?>">Shop the drop <b>↗</b></a></p>
            <div class="hero-stat-row"><div><strong>180</strong><span>mg caffeine</span></div><div><strong>0</strong><span>g sugar</span></div><div><strong>+ B</strong><span>vitamins</span></div></div>
        </div>
        <div class="hero-art"><img src="<?php echo esc_url($theme_uri . '/public/energy/photos/afterglow-cans.jpg'); ?>" alt="PULSE energy drinks"><span class="hero-badge">NEW<br>DROP<br><i>01</i></span></div>
    </section>
</div>
