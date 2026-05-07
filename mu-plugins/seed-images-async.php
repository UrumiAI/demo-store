<?php
/**
 * Plugin Name: Glow & Co. Product Image Seeder (Async)
 * Description: Schedules per-product image imports via Action Scheduler using Unsplash.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GCSEEDER_IMAGE_MAP', [
    'cleansers' => [
        'https://images.unsplash.com/photo-1556228578-0d85b1a4d571?w=800',
        'https://images.unsplash.com/photo-1631729371254-42c2892f0e6e?w=800',
        'https://images.unsplash.com/photo-1570194065650-d99fb4a38691?w=800',
    ],
    'moisturizers' => [
        'https://images.unsplash.com/photo-1611930022073-b7a4ba5fcccd?w=800',
        'https://images.unsplash.com/photo-1570194065650-d99fb4a38691?w=800',
        'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=800',
    ],
    'serums' => [
        'https://images.unsplash.com/photo-1620916566398-39f1143ab7be?w=800',
        'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?w=800',
        'https://images.unsplash.com/photo-1615397349754-cfa2066a298e?w=800',
    ],
    'sunscreen' => [
        'https://images.unsplash.com/photo-1532947974358-a218d18d8e04?w=800',
        'https://images.unsplash.com/photo-1521223344201-d169cd1d4672?w=800',
    ],
    'foundation' => [
        'https://images.unsplash.com/photo-1586495777744-4413f21062fa?w=800',
        'https://images.unsplash.com/photo-1631214524020-7e18db9a8f92?w=800',
        'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=800',
    ],
    'lipstick' => [
        'https://images.unsplash.com/photo-1586495777744-4413f21062fa?w=800',
        'https://images.unsplash.com/photo-1583241800698-e8ab01830a07?w=800',
        'https://images.unsplash.com/photo-1631214524020-7e18db9a8f92?w=800',
    ],
    'eye_makeup' => [
        'https://images.unsplash.com/photo-1512496015851-a90fb38ba796?w=800',
        'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=800',
        'https://images.unsplash.com/photo-1631214524020-7e18db9a8f92?w=800',
    ],
    'blush' => [
        'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=800',
        'https://images.unsplash.com/photo-1586495777744-4413f21062fa?w=800',
    ],
    'shampoo' => [
        'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=800',
        'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?w=800',
    ],
    'conditioner' => [
        'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=800',
        'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?w=800',
    ],
    'treatments' => [
        'https://images.unsplash.com/photo-1526947425960-945c6e72858f?w=800',
        'https://images.unsplash.com/photo-1535585209827-a15fcdbc4c2d?w=800',
    ],
    'lotions' => [
        'https://images.unsplash.com/photo-1611930022073-b7a4ba5fcccd?w=800',
        'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=800',
    ],
    'scrubs' => [
        'https://images.unsplash.com/photo-1570194065650-d99fb4a38691?w=800',
        'https://images.unsplash.com/photo-1598440947619-2c35fc9aa908?w=800',
    ],
    'bath' => [
        'https://images.unsplash.com/photo-1570194065650-d99fb4a38691?w=800',
        'https://images.unsplash.com/photo-1607006344380-b6775a0824a7?w=800',
    ],
    'fragrances' => [
        'https://images.unsplash.com/photo-1541643600914-78b084683601?w=800',
        'https://images.unsplash.com/photo-1523293182086-7651a899d37f?w=800',
        'https://images.unsplash.com/photo-1587017539504-67cfbddac569?w=800',
    ],
    'tools' => [
        'https://images.unsplash.com/photo-1522335789203-aabd1fc54bc9?w=800',
        'https://images.unsplash.com/photo-1527799820374-dcf8d9d4a388?w=800',
        'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=800',
    ],
]);

define( 'GCSEEDER_CAT_ID_TO_SLUG', [
    26 => 'cleansers', 27 => 'moisturizers', 28 => 'serums', 29 => 'sunscreen',
    31 => 'foundation', 32 => 'lipstick', 33 => 'eye_makeup', 34 => 'blush',
    36 => 'shampoo', 37 => 'conditioner', 38 => 'treatments',
    40 => 'lotions', 41 => 'scrubs', 42 => 'bath',
    43 => 'fragrances', 44 => 'tools',
    25 => 'serums', 30 => 'foundation', 35 => 'shampoo', 39 => 'lotions',
]);

/**
 * Schedule image imports on first load.
 */
add_action( 'init', function () {
    if ( ! class_exists( 'ActionScheduler' ) ) {
        return;
    }

    $scheduled_key = 'gcseeder_images_scheduled_v2';
    if ( get_option( $scheduled_key ) ) {
        return;
    }

    $products = wc_get_products( [
        'limit'  => -1,
        'status' => 'publish',
        'return' => 'ids',
    ] );

    $count = 0;
    foreach ( $products as $product_id ) {
        if ( get_post_thumbnail_id( $product_id ) ) {
            continue;
        }

        as_schedule_single_action(
            time() + ( $count * 5 ),
            'gcseeder_import_product_image_v2',
            [ 'product_id' => $product_id ],
            'gcseeder-images'
        );
        $count++;
    }

    update_option( $scheduled_key, [
        'total'      => $count,
        'started_at' => current_time( 'mysql' ),
    ] );

    if ( function_exists( 'error_log' ) ) {
        error_log( "[GCSeeder-Images] Scheduled $count product image imports (v2)." );
    }
}, 20 );

/**
 * Import an image for a single product using download_url + media_handle_sideload.
 */
add_action( 'gcseeder_import_product_image_v2', function ( $product_id ) {
    if ( get_post_thumbnail_id( $product_id ) ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return;
    }

    // Find the right image URL based on category.
    $cat_ids   = $product->get_category_ids();
    $image_url = null;

    foreach ( $cat_ids as $cat_id ) {
        if ( isset( GCSEEDER_CAT_ID_TO_SLUG[ $cat_id ] ) ) {
            $slug   = GCSEEDER_CAT_ID_TO_SLUG[ $cat_id ];
            $images = GCSEEDER_IMAGE_MAP[ $slug ] ?? null;
            if ( $images ) {
                $image_url = $images[ array_rand( $images ) ];
                break;
            }
        }
    }

    if ( ! $image_url ) {
        $image_url = 'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=800';
    }

    // Download the file.
    $tmp_file = download_url( $image_url );
    if ( is_wp_error( $tmp_file ) ) {
        error_log( "[GCSeeder-Images] Download failed for product $product_id: " . $tmp_file->get_error_message() );
        return;
    }

    // Ensure .jpg extension so WP recognizes the file type.
    $new_tmp = $tmp_file . '.jpg';
    rename( $tmp_file, $new_tmp );

    // Create a safe filename from the product name.
    $safe_name = sanitize_file_name( $product->get_name() ) . '.jpg';

    $file_array = [
        'name'     => $safe_name,
        'tmp_name' => $new_tmp,
    ];

    $attachment_id = media_handle_sideload( $file_array, $product_id, $product->get_name() );

    if ( is_wp_error( $attachment_id ) ) {
        error_log( "[GCSeeder-Images] Sideload failed for product $product_id: " . $attachment_id->get_error_message() );
        @unlink( $new_tmp );
        return;
    }

    $product->set_image_id( $attachment_id );
    $product->save();

    error_log( "[GCSeeder-Images] Set image $attachment_id for product $product_id ({$product->get_name()})." );
}, 10, 1 );
