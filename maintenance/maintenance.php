<?php
/**
 * Salve (demo store) — WordPress maintenance drop-in ("Reed").
 *
 * WordPress serves this file, and nothing else, whenever a `.maintenance` file
 * exists in the site root. Core loads it at wp-settings.php line ~79 — BEFORE
 * mu-plugins, plugins, the theme, the REST API, and before require_wp_db() at
 * line ~136 — so the whole site stops at once (storefront, wp-admin,
 * wp-login.php, REST / Store API, admin-ajax, XML-RPC, WP-Cron) and it keeps
 * working even when the database is unreachable.
 *
 * Because it runs before the database, NOTHING here can come from site
 * settings: no get_option(), no get_bloginfo(). The page is deliberately static.
 *
 * Install: copy to wp-content/maintenance.php
 *
 * ── On ───────────────────────────────────────────────────────────────────
 *   php -r 'file_put_contents(".maintenance", "<?php \$upgrading = " . (time() + 86400) . ";");'
 *
 * The FUTURE timestamp is deliberate. Core treats maintenance as finished once
 * `$upgrading` is 10 minutes old:
 *
 *     if ( ( time() - $upgrading ) >= 10 * MINUTE_IN_SECONDS ) { return false; }
 *
 * With plain time() the site would come back up on its own, mid-window, and the
 * `enable_maintenance_mode` filter cannot stop that — the expiry returns before
 * the filter runs, and the filter only ever turns maintenance OFF.
 *
 * ── Off ──────────────────────────────────────────────────────────────────
 *   rm .maintenance
 *
 * A file delete. No database, no WP-CLI, no working WordPress required.
 *
 * ── Why the headers below ────────────────────────────────────────────────
 * Core's drop-in branch is `require_once` + `die()` and never sets a status, so
 * a custom drop-in serves 200 OK unless it sets one itself. A maintenance page
 * served 200 invites search engines to index the holding page in place of the
 * shop. Do not remove these three lines.
 */

http_response_code( 503 );
header( 'Retry-After: 3600' );
header( 'Cache-Control: no-store, no-cache, must-revalidate' );
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="Salve is under scheduled maintenance.">
<title>Salve &mdash; Scheduled maintenance</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,500;1,300&family=Ysabeau+Office:wght@400;500;600&display=swap">
<style>
/* ============================================================
   Salve — scheduled-maintenance holding page ("Reed").
   Self-contained: all CSS, JS and artwork inline. No images, no
   build step, no database. The page fills the viewport and never
   scrolls.
   ============================================================ */

:root{
  --white:#FFFFFF; --paper:#F0F0F0; --ink:#221F20;
  --grey:#565656;  --grey-2:#868686; --red:#B92932;

  --pad:clamp(18px,4vmin,48px);
  --f-display:"Cormorant Garamond","Cormorant",Georgia,"Times New Roman",serif;
  --f-sans:"Ysabeau Office","Ysabeau",-apple-system,"Helvetica Neue",Arial,sans-serif;
}

*,*::before,*::after{box-sizing:border-box;}
html,body{height:100%;margin:0;overflow:hidden;}

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
          font-family="Cormorant Garamond, Georgia, serif" font-size="16" font-weight="500">S</text>

    <!-- soap bars -->
    <rect x="252" y="152" width="60" height="20" rx="8" fill="#F0F0F0" stroke="#221F20" stroke-width="2.2"/>
    <rect x="244" y="166" width="76" height="20" rx="8" fill="#FFFFFF" stroke="#221F20" stroke-width="2.2"/>
    <circle cx="282" cy="176" r="6.5" stroke="#B92932" stroke-width="1.8"/>
  </svg>

  <p class="mark">Salve</p>
  <p class="tagline">Considered formulations.</p>

  <div class="divider" role="presentation"></div>

  <h1>We&rsquo;ll be back shortly</h1>
  <p class="note">The shop is closed for scheduled maintenance. Reed between the lines &mdash; we won&rsquo;t be long.</p>

  <p class="chip"><span class="dot" aria-hidden="true"></span>Scheduled maintenance</p>

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
