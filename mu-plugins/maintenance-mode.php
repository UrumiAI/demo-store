<?php
/**
 * Scheduled-maintenance page ("Reed" design).
 *
 * Serves a single self-contained holding page on the front end while the shop
 * is down for maintenance, with a proper `503 Service Unavailable` +
 * `Retry-After` so search engines treat the outage as temporary and the store
 * keeps its rankings. A `200` maintenance page is how stores lose positions.
 *
 * OFF BY DEFAULT. mu-plugins load automatically on every deploy, so shipping
 * this enabled would black out the store the moment it merged. Turn it on with
 * whichever of these suits the situation:
 *
 *   wp option update demo_store_maintenance 1     # per site, no deploy
 *   define( 'DEMO_STORE_MAINTENANCE', true );     # in wp-config.php
 *   add_filter( 'demo_store_maintenance_enabled', '__return_true' );
 *
 * Off again: `wp option update demo_store_maintenance 0`.
 *
 * Only front-end page loads are intercepted (`template_redirect`). wp-admin,
 * the login screen, WP-Cron, WP-CLI and REST/Store-API requests are untouched,
 * so the team can keep working and the block editor keeps saving. Anyone who
 * can edit posts sees the real site rather than the holding page.
 *
 * Text, contact details and the retry window are all filterable — see the
 * `demo_store_maintenance_*` filters below. Nothing is hardcoded to one brand:
 * the wordmark and tagline come from the site's own title and tagline.
 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

/**
 * Is maintenance mode switched on?
 */
function demo_store_maintenance_is_enabled() {
    if ( defined( 'DEMO_STORE_MAINTENANCE' ) ) {
        $enabled = (bool) DEMO_STORE_MAINTENANCE;
    } else {
        $enabled = (bool) get_option( 'demo_store_maintenance', false );
    }

    return (bool) apply_filters( 'demo_store_maintenance_enabled', $enabled );
}

/**
 * Requests that must never see the holding page.
 *
 * REST and admin-ajax are absent on purpose: they do not run through
 * `template_redirect`, so they are already unaffected.
 */
function demo_store_maintenance_should_bypass() {
    if ( wp_doing_cron() ) {
        return true;
    }

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return true;
    }

    if ( is_admin() ) {
        return true;
    }

    // Never trap someone on the way to the login screen.
    if ( isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ), true ) ) {
        return true;
    }

    // The team keeps browsing the real storefront.
    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
        return true;
    }

    return (bool) apply_filters( 'demo_store_maintenance_bypass', false );
}

/**
 * Intercept front-end requests and serve the holding page.
 */
function demo_store_maintenance_maybe_render() {
    if ( ! demo_store_maintenance_is_enabled() || demo_store_maintenance_should_bypass() ) {
        return;
    }

    $retry_after = (int) apply_filters( 'demo_store_maintenance_retry_after', HOUR_IN_SECONDS );

    nocache_headers();
    header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

    if ( $retry_after > 0 ) {
        header( 'Retry-After: ' . $retry_after );
    }

    status_header( 503 );

    echo demo_store_maintenance_page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at build time.
    exit;
}
add_action( 'template_redirect', 'demo_store_maintenance_maybe_render', 0 );

/**
 * Build the page.
 *
 * One file, no images and no build step: the artwork is inline SVG and the
 * only external request is the Google Fonts stylesheet, which has a real
 * fallback stack behind it.
 *
 * @return string
 */
function demo_store_maintenance_page() {
    $name    = get_bloginfo( 'name' );
    $name    = '' !== trim( (string) $name ) ? $name : 'Our shop';
    $tagline = (string) get_bloginfo( 'description' );

    $heading = (string) apply_filters( 'demo_store_maintenance_heading', 'We’ll be back shortly' );
    $message = (string) apply_filters(
        'demo_store_maintenance_message',
        'The shop is closed for scheduled maintenance. Reed between the lines — we won’t be long.'
    );
    $label   = (string) apply_filters( 'demo_store_maintenance_label', 'Scheduled maintenance' );

    $email = (string) apply_filters( 'demo_store_maintenance_email', get_option( 'admin_email' ) );
    $phone = (string) apply_filters( 'demo_store_maintenance_phone', '' );

    // The wax seal carries the shop's initial.
    $initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
    $initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial ) : strtoupper( $initial );

    ob_start();
    ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $name ); ?> &mdash; <?php echo esc_html( $label ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,500;1,300&family=Ysabeau+Office:wght@400;500;600&display=swap">
<style>
:root{
  --white:#FFFFFF; --paper:#F0F0F0; --ink:#221F20;
  --grey:#565656;  --grey-2:#868686; --red:#B92932;

  --pad:clamp(18px,4vmin,48px);
  --f-display:"Cormorant Garamond","Cormorant",Georgia,"Times New Roman",serif;
  --f-sans:"Ysabeau Office","Ysabeau",-apple-system,"Helvetica Neue",Arial,sans-serif;
}

*,*::before,*::after{box-sizing:border-box;}
html,body{height:100%;margin:0;overflow:hidden;}

/* The page fills the viewport and never scrolls, on phone or desktop. */
body{
  background:var(--white);
  color:var(--ink);
  font:400 16px/1.6 var(--f-sans);
  -webkit-font-smoothing:antialiased;
  -webkit-text-size-adjust:100%;
  height:100vh;height:100dvh;
  display:grid;place-items:center;
  padding:var(--pad);
}

.stage{width:min(560px,100%);text-align:center;transform-origin:center center;}
.art{display:block;height:auto;width:clamp(212px,44vmin,340px);margin:0 auto;}

.mark{
  margin:clamp(18px,3.2vmin,32px) 0 0;
  font-family:var(--f-display);font-weight:500;
  font-size:clamp(1.15rem,3.2vmin,1.85rem);
  letter-spacing:.26em;text-transform:uppercase;
}
.tagline{
  margin:10px 0 0;font-size:clamp(.6rem,1.3vmin,.7rem);
  font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:var(--grey-2);
}
.divider{width:100%;height:1px;background:var(--paper);margin:clamp(20px,3.4vmin,34px) 0;}
h1{
  font-family:var(--f-display);font-weight:300;font-style:italic;
  font-size:clamp(2.2rem,7.8vmin,4.2rem);line-height:1.04;
  letter-spacing:-.01em;text-wrap:balance;margin:0;
}
.note{
  max-width:34ch;margin:clamp(14px,2.4vmin,22px) auto 0;
  font-size:clamp(.9rem,1.9vmin,1.02rem);line-height:1.6;color:var(--grey);
}
.chip{
  display:inline-flex;align-items:center;gap:9px;
  margin:clamp(20px,3.4vmin,34px) 0 0;padding:9px 18px;
  border:1px solid var(--paper);border-radius:999px;
  font-size:clamp(.58rem,1.3vmin,.68rem);font-weight:600;
  letter-spacing:.24em;text-transform:uppercase;color:var(--grey-2);
}
.chip .dot{width:6px;height:6px;border-radius:50%;flex:none;background:var(--red);}
.contact{
  margin:clamp(12px,2vmin,18px) 0 0;
  font-size:clamp(.74rem,1.5vmin,.84rem);line-height:1.75;color:var(--grey-2);
}
.contact a{
  color:var(--ink);text-decoration:none;
  border-bottom:1px solid var(--paper);
  transition:color .2s ease,border-color .2s ease;
}
.contact a:hover,.contact a:focus-visible{color:var(--red);border-bottom-color:var(--red);}
.contact .sep{opacity:.4;margin:0 .55em;}
</style>
</head>
<body>

<main class="stage">

  <svg class="art" viewBox="0 0 352 200" fill="none" aria-hidden="true">
    <path d="M16 187h320" stroke="#F0F0F0" stroke-width="2" stroke-linecap="round"/>

    <!-- candle -->
    <path d="M44 122h36a10 10 0 0 1 10 10v44a10 10 0 0 1-10 10H44a10 10 0 0 1-10-10v-44a10 10 0 0 1 10-10Z"
          fill="#FFFFFF" stroke="#221F20" stroke-width="2.2"/>
    <path d="M34 140h56v36a10 10 0 0 1-10 10H44a10 10 0 0 1-10-10z" fill="#F0F0F0"/>
    <path d="M34 140h56" stroke="#868686" stroke-width="1.4" opacity=".6"/>
    <path d="M62 140v-15" stroke="#221F20" stroke-width="2.2" stroke-linecap="round"/>

    <!-- reed diffuser -->
    <g stroke="#868686" stroke-width="2.6" stroke-linecap="round">
      <path d="M164 80C162 60 158 46 153 28"/>
      <path d="M168 80C167 58 165 42 164 22"/>
      <path d="M170 80C170 56 170 38 171 18"/>
      <path d="M172 80C176 58 180 44 185 26"/>
      <path d="M176 80C179 62 185 50 191 34"/>
    </g>
    <path d="M160 90h20v18h-20z" fill="#FFFFFF" stroke="#221F20" stroke-width="2.2"/>
    <rect x="155" y="76" width="30" height="13" rx="4" fill="#221F20"/>
    <path d="M150 104h40a16 16 0 0 1 16 16v50a16 16 0 0 1-16 16h-40a16 16 0 0 1-16-16v-50a16 16 0 0 1 16-16Z"
          fill="#FFFFFF" stroke="#221F20" stroke-width="2.2"/>
    <path d="M134 142h72v28a16 16 0 0 1-16 16h-40a16 16 0 0 1-16-16z" fill="#F0F0F0"/>
    <path d="M134 142h72" stroke="#868686" stroke-width="1.4" opacity=".6"/>
    <circle cx="170" cy="162" r="13" fill="#B92932"/>
    <text x="170" y="167.5" text-anchor="middle" fill="#FFFFFF"
          font-family="Cormorant Garamond, Georgia, serif" font-size="16" font-weight="500"><?php echo esc_html( $initial ); ?></text>

    <!-- soap bars -->
    <rect x="252" y="152" width="60" height="20" rx="8" fill="#F0F0F0" stroke="#221F20" stroke-width="2.2"/>
    <rect x="244" y="166" width="76" height="20" rx="8" fill="#FFFFFF" stroke="#221F20" stroke-width="2.2"/>
    <circle cx="282" cy="176" r="6.5" stroke="#B92932" stroke-width="1.8"/>
  </svg>

  <p class="mark"><?php echo esc_html( $name ); ?></p>
  <?php if ( '' !== trim( $tagline ) ) : ?>
  <p class="tagline"><?php echo esc_html( $tagline ); ?></p>
  <?php endif; ?>

  <div class="divider" role="presentation"></div>

  <h1><?php echo esc_html( $heading ); ?></h1>
  <p class="note"><?php echo wp_kses( $message, array( 'em' => array(), 'strong' => array() ) ); ?></p>

  <p class="chip"><span class="dot" aria-hidden="true"></span><?php echo esc_html( $label ); ?></p>

  <?php if ( '' !== trim( $email ) || '' !== trim( $phone ) ) : ?>
  <p class="contact">Need something? <?php
    if ( '' !== trim( $email ) ) :
      ?><a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a><?php
    endif;
    if ( '' !== trim( $email ) && '' !== trim( $phone ) ) :
      ?><span class="sep" aria-hidden="true">&middot;</span><?php
    endif;
    if ( '' !== trim( $phone ) ) :
      ?><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a><?php
    endif;
  ?></p>
  <?php endif; ?>

</main>

<script>
(function(){
  var stage = document.querySelector('.stage');
  if (!stage) { return; }

  /* Shrink the block if a short viewport (a landscape phone) would clip it,
     so the page never scrolls and nothing is cut off. */
  function fit(){
    stage.style.transform = 'none';
    var cs = getComputedStyle(document.body);
    var h = document.body.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
    var w = document.body.clientWidth  - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    if (!stage.offsetHeight || !stage.offsetWidth) { return; }
    var k = Math.min(1, h / stage.offsetHeight, w / stage.offsetWidth);
    if (k < 0.995) { stage.style.transform = 'scale(' + k.toFixed(4) + ')'; }
  }

  var pending;
  function scheduleFit(){
    cancelAnimationFrame(pending);
    pending = requestAnimationFrame(fit);
  }

  window.addEventListener('resize', scheduleFit);
  window.addEventListener('orientationchange', scheduleFit);
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(scheduleFit); }
  scheduleFit();
})();
</script>
</body>
</html>
    <?php
    return ob_get_clean();
}
