<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <?php
    $ssr_router = new DemoStore_SSR_Router();
    $seo_data   = demo_store_get_seo_data($ssr_router);

    if (!empty($seo_data['title'])) {
        add_filter('pre_get_document_title', function() use ($seo_data) {
            return $seo_data['title'];
        }, 999);
    }

    remove_action('wp_head', 'wp_robots', 1);
    remove_action('wp_head', 'rel_canonical');
    ?>

    <meta name="description" content="<?php echo esc_attr($seo_data['description']); ?>">
    <?php if (!empty($seo_data['noindex'])): ?>
    <meta name="robots" content="noindex, follow">
    <?php else: ?>
    <meta name="robots" content="index, follow, max-image-preview:large">
    <?php endif; ?>

    <meta property="og:locale" content="<?php echo esc_attr(get_locale()); ?>">
    <meta property="og:type" content="<?php echo esc_attr($seo_data['og_type']); ?>">
    <meta property="og:title" content="<?php echo esc_attr($seo_data['title']); ?>">
    <meta property="og:description" content="<?php echo esc_attr($seo_data['description']); ?>">
    <meta property="og:url" content="<?php echo esc_url($seo_data['url']); ?>">
    <meta property="og:site_name" content="<?php echo esc_attr(get_bloginfo('name')); ?>">
    <?php if (!empty($seo_data['image'])): ?>
    <meta property="og:image" content="<?php echo esc_url($seo_data['image']); ?>">
    <?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr($seo_data['title']); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr($seo_data['description']); ?>">
    <?php if (!empty($seo_data['image'])): ?>
    <meta name="twitter:image" content="<?php echo esc_url($seo_data['image']); ?>">
    <?php endif; ?>

    <link rel="canonical" href="<?php echo esc_url($seo_data['canonical_url']); ?>">

    <style id="ssr-styles">
        #ssr-content {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1a1a2e;
            line-height: 1.6;
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
        }
        #ssr-content h1 { font-size: 2rem; font-weight: 700; margin-bottom: 1rem; }
        #ssr-content h2 { font-size: 1.4rem; font-weight: 600; margin-top: 2rem; margin-bottom: 0.75rem; }
        #ssr-content p  { margin-bottom: 1rem; }
        #ssr-content a  { color: #2F2A8C; text-decoration: underline; }
        #ssr-content .ssr-section { margin-bottom: 2rem; }
        #ssr-content img { max-width: 100%; height: auto; }
    </style>

    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <?php $ssr_router->render(); ?>
    <?php wp_footer(); ?>
</body>
</html>
