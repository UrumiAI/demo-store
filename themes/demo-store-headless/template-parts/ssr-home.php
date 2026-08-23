<?php
/**
 * SSR Template — Homepage
 *
 * @package Demo_Store_Headless
 */

$theme_uri = get_template_directory_uri();
?>
<div class="orbital-home">
    <section class="shoe-showcase" aria-label="Featured footwear">
        <div class="shoe-orbit shoe-orbit-one" aria-hidden="true"></div>
        <div class="shoe-orbit shoe-orbit-two" aria-hidden="true"></div>
        <div class="shoe-showcase-copy">
            <p class="shoe-kicker">ORBITAL / FOOTWEAR SYSTEMS</p>
            <h1>Move<br>like the<br><em>future.</em></h1>
            <p>A low-gravity runner for city miles, late nights, and every fast exit.</p>
            <p><a class="shoe-shop-link" href="<?php echo esc_url(home_url('/shop')); ?>">Explore collection <span>↗</span></a></p>
        </div>
        <div class="shoe-stage">
            <div class="shoe-stage-glow" aria-hidden="true"></div>
            <img class="shoe-render" src="<?php echo esc_url($theme_uri . '/public/shoes/aero-void.jpg'); ?>" alt="Aero Void sneaker">
            <div class="shoe-stage-meta"><span>01</span><span>/ 03</span></div>
        </div>
        <aside class="shoe-buybox">
            <p>Runner / 001</p>
            <h2>Aero Void</h2>
            <div class="shoe-price"><strong>$180</strong><del>$210</del></div>
            <div class="shoe-colour"><span>Color</span><b>Ivory mesh / bone</b></div>
            <div class="shoe-sizes"><span>EU</span><i>38</i><i>40</i><i>42</i><i>44</i></div>
            <a class="shoe-detail-link" href="<?php echo esc_url(home_url('/product/aero-void-runner')); ?>">View shoe <span>↗</span></a>
        </aside>
        <p class="shoe-manifesto">Confidence, engineered for motion.</p>
    </section>
</div>
