<?php
/**
 * Plugin Name: Glow & Co. Order Attribution Seeder (Async)
 * Description: Backfills realistic attribution data on orders via Action Scheduler.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Realistic attribution profiles for a cosmetics DTC brand.
define( 'GCSEEDER_ATTRIBUTION_PROFILES', [
    // 30% — Google organic search
    [ 'weight' => 30, 'source_type' => 'organic', 'origin' => 'Organic: google.com', 'utm_source' => 'google', 'utm_medium' => 'organic', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '', 'referrer' => 'https://www.google.com' ],
    // 5% — Bing organic
    [ 'weight' => 5, 'source_type' => 'organic', 'origin' => 'Organic: bing.com', 'utm_source' => 'bing', 'utm_medium' => 'organic', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '', 'referrer' => 'https://www.bing.com' ],
    // 15% — Instagram paid ads
    [ 'weight' => 15, 'source_type' => 'utm', 'origin' => 'Social: instagram.com', 'utm_source' => 'instagram', 'utm_medium' => 'paid_social', 'utm_campaign' => 'summer_glow_2026', 'utm_term' => 'skincare_routine', 'utm_content' => 'carousel_ad_serum', 'referrer' => 'https://l.instagram.com' ],
    // 8% — Instagram organic
    [ 'weight' => 8, 'source_type' => 'organic', 'origin' => 'Social: instagram.com', 'utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => 'bio_link', 'referrer' => 'https://l.instagram.com' ],
    // 10% — Google Ads
    [ 'weight' => 10, 'source_type' => 'utm', 'origin' => 'Paid: google.com', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'brand_skincare', 'utm_term' => 'glow+and+co+skincare', 'utm_content' => 'search_ad_v2', 'referrer' => 'https://www.google.com' ],
    // 5% — Google Shopping
    [ 'weight' => 5, 'source_type' => 'utm', 'origin' => 'Paid: google.com', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'shopping_serums', 'utm_term' => 'vitamin_c_serum', 'utm_content' => 'pla_feed', 'referrer' => 'https://www.google.com' ],
    // 7% — TikTok ads
    [ 'weight' => 7, 'source_type' => 'utm', 'origin' => 'Social: tiktok.com', 'utm_source' => 'tiktok', 'utm_medium' => 'paid_social', 'utm_campaign' => 'creator_collab_may', 'utm_term' => 'beauty_routine', 'utm_content' => 'ugc_video_01', 'referrer' => 'https://www.tiktok.com' ],
    // 4% — TikTok organic
    [ 'weight' => 4, 'source_type' => 'organic', 'origin' => 'Social: tiktok.com', 'utm_source' => 'tiktok', 'utm_medium' => 'social', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '', 'referrer' => 'https://www.tiktok.com' ],
    // 3% — Facebook ads
    [ 'weight' => 3, 'source_type' => 'utm', 'origin' => 'Social: facebook.com', 'utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'retargeting_cart_abandon', 'utm_term' => '', 'utm_content' => 'dpa_moisturizer', 'referrer' => 'https://l.facebook.com' ],
    // 3% — Email marketing
    [ 'weight' => 3, 'source_type' => 'utm', 'origin' => 'Email', 'utm_source' => 'klaviyo', 'utm_medium' => 'email', 'utm_campaign' => 'welcome_series_3', 'utm_term' => '', 'utm_content' => 'hero_cta', 'referrer' => '' ],
    // 2% — Email newsletter
    [ 'weight' => 2, 'source_type' => 'utm', 'origin' => 'Email', 'utm_source' => 'klaviyo', 'utm_medium' => 'email', 'utm_campaign' => 'weekly_newsletter_may', 'utm_term' => '', 'utm_content' => 'new_arrivals_block', 'referrer' => '' ],
    // 2% — Influencer / affiliate
    [ 'weight' => 2, 'source_type' => 'referral', 'origin' => 'Referral: skinfluencer.com', 'utm_source' => 'skinfluencer', 'utm_medium' => 'affiliate', 'utm_campaign' => 'spring_picks', 'utm_term' => '', 'utm_content' => 'top10_serums_article', 'referrer' => 'https://www.skinfluencer.com/top-10-serums-2026' ],
    // 2% — Beauty blog referral
    [ 'weight' => 2, 'source_type' => 'referral', 'origin' => 'Referral: allure.com', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '', 'referrer' => 'https://www.allure.com/story/best-vitamin-c-serums' ],
    // 4% — Direct traffic
    [ 'weight' => 4, 'source_type' => 'typein', 'origin' => 'Direct', 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '', 'referrer' => '' ],
]);

// Realistic user agents with device types.
define( 'GCSEEDER_USER_AGENTS', [
    // Mobile — 55%
    [ 'weight' => 20, 'device' => 'Mobile', 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' ],
    [ 'weight' => 12, 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', 'device' => 'Mobile' ],
    [ 'weight' => 10, 'ua' => 'Mozilla/5.0 (Linux; Android 15; Pixel 9 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.88 Mobile Safari/537.36', 'device' => 'Mobile' ],
    [ 'weight' => 8, 'ua' => 'Mozilla/5.0 (Linux; Android 14; SM-S926B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.6533.103 Mobile Safari/537.36', 'device' => 'Mobile' ],
    [ 'weight' => 5, 'ua' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/130.0 Mobile/15E148 Safari/605.1.15', 'device' => 'Mobile' ],
    // Desktop — 35%
    [ 'weight' => 12, 'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'device' => 'Desktop' ],
    [ 'weight' => 8, 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'device' => 'Desktop' ],
    [ 'weight' => 7, 'ua' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', 'device' => 'Desktop' ],
    [ 'weight' => 5, 'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'device' => 'Desktop' ],
    [ 'weight' => 3, 'ua' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'device' => 'Desktop' ],
    // Tablet — 10%
    [ 'weight' => 6, 'ua' => 'Mozilla/5.0 (iPad; CPU OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'device' => 'Tablet' ],
    [ 'weight' => 4, 'ua' => 'Mozilla/5.0 (Linux; Android 14; SM-X810) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.6533.103 Safari/537.36', 'device' => 'Tablet' ],
]);

/**
 * Weighted random pick from an array with 'weight' keys.
 */
function gcseeder_weighted_random( $items ) {
    $total = array_sum( array_column( $items, 'weight' ) );
    $rand  = mt_rand( 1, $total );
    $sum   = 0;
    foreach ( $items as $item ) {
        $sum += $item['weight'];
        if ( $rand <= $sum ) {
            return $item;
        }
    }
    return end( $items );
}

/**
 * Schedule attribution backfill on first load.
 */
add_action( 'init', function () {
    if ( ! class_exists( 'ActionScheduler' ) ) {
        return;
    }

    $scheduled_key = 'gcseeder_attribution_scheduled';
    if ( get_option( $scheduled_key ) ) {
        return;
    }

    // Get all order IDs.
    $orders = wc_get_orders( [
        'limit'  => -1,
        'return' => 'ids',
    ] );

    // Batch them — 25 orders per action.
    $batches = array_chunk( $orders, 25 );
    $count   = 0;
    foreach ( $batches as $batch ) {
        as_schedule_single_action(
            time() + ( $count * 5 ),
            'gcseeder_backfill_attribution_batch',
            [ 'order_ids' => $batch ],
            'gcseeder-attribution'
        );
        $count++;
    }

    update_option( $scheduled_key, [
        'total_orders' => count( $orders ),
        'batches'      => $count,
        'started_at'   => current_time( 'mysql' ),
    ] );

    error_log( "[GCSeeder-Attribution] Scheduled $count batches for " . count( $orders ) . " orders." );
}, 20 );

/**
 * Process a batch of orders — set attribution meta.
 */
add_action( 'gcseeder_backfill_attribution_batch', function ( $order_ids ) {
    $updated = 0;
    foreach ( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            continue;
        }

        // Pick a random attribution profile and user agent.
        $profile = gcseeder_weighted_random( GCSEEDER_ATTRIBUTION_PROFILES );
        $ua_info = gcseeder_weighted_random( GCSEEDER_USER_AGENTS );

        // Session data.
        $order_date       = $order->get_date_created();
        $session_start    = $order_date ? $order_date->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
        $session_count    = mt_rand( 1, 12 );
        $session_pages    = mt_rand( 2, 15 );

        // Entry pages.
        $entry_pages = [
            '/', '/shop/', '/product-category/skincare/', '/product-category/makeup/',
            '/product/vitamin-c-brightening-serum/', '/product/hydra-glow-daily-moisturizer/',
            '/product/velvet-matte-lipstick-ruby-red/', '/collections/best-sellers/',
            '/pages/about/', '/product-category/fragrances/',
        ];
        $session_entry = $entry_pages[ array_rand( $entry_pages ) ];

        // Set all attribution meta.
        $order->update_meta_data( '_wc_order_attribution_source_type', $profile['source_type'] );
        $order->update_meta_data( '_wc_order_attribution_origin', $profile['origin'] );
        $order->update_meta_data( '_wc_order_attribution_utm_source', $profile['utm_source'] );
        $order->update_meta_data( '_wc_order_attribution_utm_medium', $profile['utm_medium'] );
        $order->update_meta_data( '_wc_order_attribution_utm_campaign', $profile['utm_campaign'] );
        $order->update_meta_data( '_wc_order_attribution_utm_term', $profile['utm_term'] );
        $order->update_meta_data( '_wc_order_attribution_utm_content', $profile['utm_content'] );
        $order->update_meta_data( '_wc_order_attribution_referrer', $profile['referrer'] );
        $order->update_meta_data( '_wc_order_attribution_device_type', $ua_info['device'] );
        $order->update_meta_data( '_wc_order_attribution_user_agent', $ua_info['ua'] );
        $order->update_meta_data( '_wc_order_attribution_session_count', $session_count );
        $order->update_meta_data( '_wc_order_attribution_session_pages', $session_pages );
        $order->update_meta_data( '_wc_order_attribution_session_start_time', $session_start );
        $order->update_meta_data( '_wc_order_attribution_session_entry', $session_entry );

        $order->save();
        $updated++;
    }

    error_log( "[GCSeeder-Attribution] Batch complete: updated $updated orders." );
}, 10, 1 );

/**
 * Auto-apply attribution to newly created orders (catches orders from the order seeder).
 * Runs on woocommerce_new_order hook — only if the order has no attribution yet.
 */
add_action( 'woocommerce_new_order', function ( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Skip if already has attribution.
    if ( $order->get_meta( '_wc_order_attribution_source_type' ) ) {
        return;
    }

    $profile = gcseeder_weighted_random( GCSEEDER_ATTRIBUTION_PROFILES );
    $ua_info = gcseeder_weighted_random( GCSEEDER_USER_AGENTS );

    $order_date    = $order->get_date_created();
    $session_start = $order_date ? $order_date->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

    $entry_pages = [
        '/', '/shop/', '/product-category/skincare/', '/product-category/makeup/',
        '/product/vitamin-c-brightening-serum/', '/product/hydra-glow-daily-moisturizer/',
        '/product/velvet-matte-lipstick-ruby-red/', '/collections/best-sellers/',
        '/pages/about/', '/product-category/fragrances/',
    ];

    $order->update_meta_data( '_wc_order_attribution_source_type', $profile['source_type'] );
    $order->update_meta_data( '_wc_order_attribution_origin', $profile['origin'] );
    $order->update_meta_data( '_wc_order_attribution_utm_source', $profile['utm_source'] );
    $order->update_meta_data( '_wc_order_attribution_utm_medium', $profile['utm_medium'] );
    $order->update_meta_data( '_wc_order_attribution_utm_campaign', $profile['utm_campaign'] );
    $order->update_meta_data( '_wc_order_attribution_utm_term', $profile['utm_term'] );
    $order->update_meta_data( '_wc_order_attribution_utm_content', $profile['utm_content'] );
    $order->update_meta_data( '_wc_order_attribution_referrer', $profile['referrer'] );
    $order->update_meta_data( '_wc_order_attribution_device_type', $ua_info['device'] );
    $order->update_meta_data( '_wc_order_attribution_user_agent', $ua_info['ua'] );
    $order->update_meta_data( '_wc_order_attribution_session_count', mt_rand( 1, 12 ) );
    $order->update_meta_data( '_wc_order_attribution_session_pages', mt_rand( 2, 15 ) );
    $order->update_meta_data( '_wc_order_attribution_session_start_time', $session_start );
    $order->update_meta_data( '_wc_order_attribution_session_entry', $entry_pages[ array_rand( $entry_pages ) ] );

    $order->save();
}, 10, 1 );
