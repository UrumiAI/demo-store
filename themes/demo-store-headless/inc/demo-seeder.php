<?php
/**
 * Demo Seeder — Glow & Co. / Salve cosmetics demo store.
 *
 * Registers `wp demo seed *` WP-CLI commands that generate analytics-ready
 * data so the Urumi analytics-AI agent can answer business prompts like
 *
 *   "Gross margins month-on-month for last 6 months"
 *   "Top 10 products by revenue last 90 days"
 *   "Best traffic source by gross profit"
 *   "Customer LTV by first-purchase month"
 *   "Refund rate by category"
 *
 * with real, plausible numbers — not noise.
 *
 * Loaded only when WP_CLI is defined (see functions.php).
 *
 * @package Demo_Store_Headless
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class Demo_Store_Seeder {

    /**
     * Cost-of-goods ratio by category slug. Cosmetics industry typicals,
     * tuned so margin queries surface meaningful differences across categories.
     *
     * Margins: fragrances best (72%), tools (60%), haircare (~50%),
     * skincare (~50%), body care (~52%), makeup worst (~38%).
     */
    const COGS_RATIOS = [
        // Skincare — premium positioning, ~50% margin
        'skincare'      => 0.50,
        'cleansers'     => 0.50,
        'moisturizers'  => 0.48,
        'serums'        => 0.45,
        'sunscreen'     => 0.42,
        // Makeup — competitive pricing, ~38% margin
        'makeup'        => 0.62,
        'foundation'    => 0.58,
        'lipstick'      => 0.40,
        'eye_makeup'    => 0.55,
        'blush'         => 0.55,
        // Haircare — ~50% margin
        'haircare'      => 0.50,
        'shampoo'       => 0.55,
        'conditioner'   => 0.55,
        'treatments'    => 0.42,
        // Body care — ~50% margin
        'body_care'     => 0.50,
        'lotions'       => 0.50,
        'scrubs'        => 0.45,
        'bath'          => 0.45,
        // Fragrances — premium, ~72% margin
        'fragrances'    => 0.28,
        // Tools — accessories, ~60% margin
        'tools'         => 0.40,
    ];

    /** Order status mix (excluding refunds, which run separately). */
    const STATUS_MIX = [
        'completed'  => 85,
        'processing' => 5,
        'on-hold'    => 3,
        'cancelled'  => 4,
        'failed'     => 3,
    ];

    /** Items-per-order weights (Pareto: most carts are 1-2 items). */
    const ITEMS_PER_ORDER_WEIGHTS = [ 1 => 30, 2 => 38, 3 => 18, 4 => 9, 5 => 5 ];

    /** Quantity per line-item weights. */
    const QTY_WEIGHTS = [ 1 => 85, 2 => 12, 3 => 3 ];

    /**
     * Order date distribution per 30-day bucket from "now" backwards.
     * Recent months busier — narrates a "growing brand" story.
     * Numbers sum to 100.
     */
    const DATE_BUCKET_WEIGHTS = [ 30, 22, 18, 14, 10, 6 ];

    /** Hour-of-day weights — bell shape, AM + evening peaks. */
    const HOUR_WEIGHTS = [
        0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 3, 7 => 4,
        8 => 6, 9 => 8, 10 => 10, 11 => 11, 12 => 10, 13 => 9, 14 => 8,
        15 => 7, 16 => 6, 17 => 6, 18 => 7, 19 => 9, 20 => 11, 21 => 10,
        22 => 6, 23 => 3,
    ];

    /** Day-of-week weights — slight weekend lean (0 = Sunday). */
    const DOW_WEIGHTS = [ 0 => 16, 1 => 12, 2 => 12, 3 => 13, 4 => 13, 5 => 15, 6 => 19 ];

    /** Billing country distribution. */
    const COUNTRY_WEIGHTS = [
        'US' => 60, 'CA' => 12, 'GB' => 10, 'AU' => 6,
        'DE' => 4,  'FR' => 3,  'IN' => 3,  'JP' => 2,
    ];

    /** First names (mixed). */
    const FIRST_NAMES = [
        'Olivia','Emma','Charlotte','Amelia','Sophia','Isabella','Ava','Mia','Evelyn','Luna',
        'Aria','Nora','Zoe','Lily','Chloe','Riya','Aisha','Priya','Mei','Yuki',
        'Liam','Noah','Oliver','Elijah','Lucas','Ethan','Mason','Logan','James','Aiden',
        'Arjun','Hiroshi','Tariq','Diego','Marco','Sebastian','Felix','Theo','Jasper','Wyatt',
    ];

    /** Last names. */
    const LAST_NAMES = [
        'Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
        'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
        'Lee','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson','Walker',
        'Patel','Khan','Singh','Chen','Wang','Kim','Park','Suzuki','Tanaka','Rossi',
    ];

    /** US states for billing addresses. */
    const US_STATES = [ 'CA','NY','TX','FL','IL','PA','OH','GA','NC','MI','NJ','VA','WA','AZ','MA','TN','IN','MO','MD','WI' ];

    /** Coupons to provision (code, type, amount, label). */
    const COUPONS = [
        [ 'WELCOME10', 'percent', 10, 'Welcome 10% off' ],
        [ 'FLASH15',   'percent', 15, 'Flash sale 15% off' ],
        [ 'SAVE5',     'fixed_cart', 5, '$5 off your order' ],
        [ 'GLOWUP20',  'percent', 20, 'Holiday 20% off' ],
    ];

    /** Marker meta key — used to identify and reset seeded data. */
    const SEED_META_KEY = '_demo_seeder';
    const SEED_META_VAL = 'glow-and-co';

    /**
     * Seed everything in correct order. Idempotent.
     *
     * ## OPTIONS
     *
     * [--customers=<n>]    : Customer count target. Default: 400.
     * [--orders=<n>]       : Order count target. Default: 1500.
     * [--days=<n>]         : Order date range (days back from now). Default: 180.
     * [--refund-rate=<f>]  : Fraction of completed orders to mark as refunded. Default: 0.04.
     *
     * ## EXAMPLES
     *
     *   wp demo seed all
     *   wp demo seed all --customers=600 --orders=2500 --days=270
     */
    public function all( $args, $assoc ) {
        $customers   = (int) ( $assoc['customers']    ?? 400 );
        $orders      = (int) ( $assoc['orders']       ?? 1500 );
        $days        = (int) ( $assoc['days']         ?? 180 );
        $refund_rate = (float) ( $assoc['refund-rate'] ?? 0.04 );

        WP_CLI::log( "▶ Seeding COGS on products..." );        $this->cogs( [], [] );
        WP_CLI::log( "▶ Seeding {$customers} customers..." );  $this->customers( [], [ 'count' => $customers ] );
        WP_CLI::log( "▶ Seeding coupons..." );                 $this->coupons( [], [] );
        WP_CLI::log( "▶ Seeding {$orders} orders over {$days} days..." );
        $this->orders( [], [ 'count' => $orders, 'days' => $days ] );
        WP_CLI::log( "▶ Refunding {$refund_rate} of completed orders..." );
        $this->refunds( [], [ 'rate' => $refund_rate ] );

        WP_CLI::log( "" );
        $this->status( [], [] );
        WP_CLI::success( "Demo data seeded." );
    }

    /** Print current counts. */
    public function status( $args, $assoc ) {
        global $wpdb;
        $product_count  = (int) wp_count_posts( 'product' )->publish;
        $with_cogs      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_wc_cog_cost'" );
        $customer_count = count( get_users( [ 'role' => 'customer', 'fields' => 'ID', 'meta_key' => self::SEED_META_KEY ] ) );
        $order_count    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id IN (
                 SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key=%s
             )",
            self::SEED_META_KEY
        ) );
        if ( ! $order_count ) {
            // Fallback for non-HPOS installs
            $order_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND ID IN (
                     SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s
                 )",
                self::SEED_META_KEY
            ) );
        }

        WP_CLI::log( "Products:        {$product_count} ({$with_cogs} with COGS)" );
        WP_CLI::log( "Seeded customers: {$customer_count}" );
        WP_CLI::log( "Seeded orders:    {$order_count}" );
    }

    /**
     * Set cost-of-goods (`_wc_cog_cost`) on every published product based on
     * its primary category. Idempotent — skips products that already have it.
     */
    public function cogs( $args, $assoc ) {
        $products = wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ] );

        $set = 0; $skipped = 0;
        foreach ( $products as $product_id ) {
            if ( get_post_meta( $product_id, '_wc_cog_cost', true ) !== '' ) {
                $skipped++;
                continue;
            }
            $product = wc_get_product( $product_id );
            $price   = (float) $product->get_regular_price();
            if ( $price <= 0 ) { continue; }

            $ratio   = $this->resolve_cogs_ratio( $product_id );
            // Add ±5% variance per product so two items in the same category
            // don't have identical margins — makes per-product margin queries interesting.
            $variance = ( mt_rand( -50, 50 ) / 1000.0 );
            $cost     = round( $price * ( $ratio + $variance ), 2 );
            $cost     = max( 0.50, $cost );

            update_post_meta( $product_id, '_wc_cog_cost', $cost );
            update_post_meta( $product_id, '_wc_cog_cost_currency', get_woocommerce_currency() );
            $set++;
        }
        WP_CLI::log( "  COGS set on {$set} products (skipped {$skipped} already-set)." );
    }

    /**
     * Generate fake customers with realistic distribution.
     *
     * ## OPTIONS
     * [--count=<n>] : Target customer count. Default: 400.
     */
    public function customers( $args, $assoc ) {
        $target = (int) ( $assoc['count'] ?? 400 );

        $existing = count( get_users( [
            'role'       => 'customer',
            'meta_key'   => self::SEED_META_KEY,
            'meta_value' => self::SEED_META_VAL,
            'fields'     => 'ID',
        ] ) );

        if ( $existing >= $target ) {
            WP_CLI::log( "  {$existing} seeded customers already exist (≥ {$target}). Skipping." );
            return;
        }

        $to_create = $target - $existing;
        $created   = 0;

        for ( $i = 0; $i < $to_create; $i++ ) {
            [ $first, $last ] = $this->random_name();
            $suffix = wp_generate_password( 4, false, false );
            $email  = strtolower( "{$first}.{$last}.{$suffix}@example.com" );
            if ( email_exists( $email ) ) { continue; }

            $user_id = wp_insert_user( [
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 16 ),
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => "{$first} {$last}",
                'role'         => 'customer',
            ] );
            if ( is_wp_error( $user_id ) ) { continue; }

            // Backdate registration over the last 365 days.
            $registered = $this->backdated_timestamp( mt_rand( 1, 365 ) );
            wp_update_user( [ 'ID' => $user_id, 'user_registered' => $registered ] );

            $addr = $this->random_billing_address( $first, $last );
            foreach ( $addr as $k => $v ) {
                update_user_meta( $user_id, 'billing_' . $k, $v );
                if ( in_array( $k, [ 'first_name','last_name','address_1','city','postcode','country','state' ], true ) ) {
                    update_user_meta( $user_id, 'shipping_' . $k, $v );
                }
            }
            update_user_meta( $user_id, self::SEED_META_KEY, self::SEED_META_VAL );

            $created++;
            if ( $created % 50 === 0 ) { WP_CLI::log( "    {$created}/{$to_create} customers..." ); }
        }
        WP_CLI::log( "  Created {$created} customers." );
    }

    /**
     * Provision a handful of coupon codes used by the order seeder. Idempotent.
     */
    public function coupons( $args, $assoc ) {
        foreach ( self::COUPONS as [ $code, $type, $amount, $label ] ) {
            if ( wc_get_coupon_id_by_code( $code ) ) { continue; }
            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount_type( $type );
            $coupon->set_amount( $amount );
            $coupon->set_description( $label );
            $coupon->set_individual_use( false );
            $coupon->save();
        }
        WP_CLI::log( "  Coupons provisioned: " . implode( ', ', array_column( self::COUPONS, 0 ) ) );
    }

    /**
     * Generate orders, backdated, with realistic distribution.
     *
     * ## OPTIONS
     * [--count=<n>] : Order count target. Default: 1500.
     * [--days=<n>]  : Date range (days back). Default: 180.
     */
    public function orders( $args, $assoc ) {
        $target = (int) ( $assoc['count'] ?? 1500 );
        $days   = (int) ( $assoc['days']  ?? 180 );

        // Existing seeded order count (avoid re-seeding when re-run).
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id IN (
                 SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key=%s
             )",
            self::SEED_META_KEY
        ) );
        if ( ! $existing ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND ID IN (
                     SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s
                 )",
                self::SEED_META_KEY
            ) );
        }
        if ( $existing >= $target ) {
            WP_CLI::log( "  {$existing} seeded orders already exist (≥ {$target}). Skipping." );
            return;
        }
        $to_create = $target - $existing;

        // Pull seeded customer IDs and published products.
        $customer_ids = get_users( [
            'role'       => 'customer',
            'meta_key'   => self::SEED_META_KEY,
            'meta_value' => self::SEED_META_VAL,
            'fields'     => 'ID',
        ] );
        if ( count( $customer_ids ) < 10 ) {
            WP_CLI::error( "Need at least 10 seeded customers — run `wp demo seed customers` first." );
        }

        $product_ids = wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ] );
        if ( count( $product_ids ) < 5 ) {
            WP_CLI::error( "Need products to seed orders against — run seed-products via `wp eval-file`." );
        }

        // Pareto product popularity: top 20% of products get ~70% of weight.
        $popularity = $this->build_product_popularity( $product_ids );

        // Pool of "repeat customers" — 30% of customers chosen up-front and weighted higher.
        $repeat_pool = $this->pick_random( $customer_ids, max( 30, (int) ( count( $customer_ids ) * 0.30 ) ) );

        $statuses = self::STATUS_MIX;
        $coupons  = array_column( self::COUPONS, 0 );

        $created = 0;
        for ( $i = 0; $i < $to_create; $i++ ) {
            // Pick customer: 60% from repeat pool (will appear multiple times across the loop), 40% from full set.
            $customer_id = mt_rand( 1, 100 ) <= 60
                ? $repeat_pool[ array_rand( $repeat_pool ) ]
                : $customer_ids[ array_rand( $customer_ids ) ];

            $order = wc_create_order( [ 'customer_id' => $customer_id ] );

            $num_items = (int) $this->weighted_pick( self::ITEMS_PER_ORDER_WEIGHTS );
            $picked    = [];
            for ( $j = 0; $j < $num_items; $j++ ) {
                $pid = $this->weighted_pick( $popularity );
                if ( isset( $picked[ $pid ] ) ) { continue; }
                $picked[ $pid ] = true;
                $product = wc_get_product( $pid );
                if ( ! $product ) { continue; }
                $qty = (int) $this->weighted_pick( self::QTY_WEIGHTS );
                $order->add_product( $product, $qty );
            }
            if ( empty( $picked ) ) { $order->delete( true ); continue; }

            // ~15% of orders carry a coupon.
            if ( mt_rand( 1, 100 ) <= 15 ) {
                $order->apply_coupon( $coupons[ array_rand( $coupons ) ] );
            }

            // Billing address — copy from user.
            $user = get_userdata( $customer_id );
            $addr = [
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->user_email,
                'address_1'  => get_user_meta( $customer_id, 'billing_address_1', true ) ?: '123 Main St',
                'city'       => get_user_meta( $customer_id, 'billing_city',      true ) ?: 'Anywhere',
                'state'      => get_user_meta( $customer_id, 'billing_state',     true ) ?: '',
                'postcode'   => get_user_meta( $customer_id, 'billing_postcode',  true ) ?: '00000',
                'country'    => get_user_meta( $customer_id, 'billing_country',   true ) ?: 'US',
                'phone'      => get_user_meta( $customer_id, 'billing_phone',     true ) ?: '',
            ];
            $order->set_address( $addr, 'billing' );
            $order->set_address( $addr, 'shipping' );
            $order->set_payment_method( 'demo_card' );
            $order->set_payment_method_title( 'Demo Card' );
            $order->set_currency( get_woocommerce_currency() );

            $order->calculate_totals();

            // Backdate the order.
            $days_ago = $this->pick_days_ago( $days );
            $ts       = $this->backdated_timestamp_for_order( $days_ago );
            $order->set_date_created( $ts );
            $order->set_date_modified( $ts );
            $order->set_date_paid( $ts );

            $status = $this->weighted_pick( $statuses );
            $order->set_status( $status, 'demo seed', false );

            $order->update_meta_data( self::SEED_META_KEY, self::SEED_META_VAL );
            $order->save();

            // HPOS-aware: also write the meta on the legacy post if it exists.
            $created++;
            if ( $created % 100 === 0 ) { WP_CLI::log( "    {$created}/{$to_create} orders..." ); }
        }
        WP_CLI::log( "  Created {$created} orders." );
    }

    /**
     * Refund a fraction of completed seeded orders so refund-rate queries are non-zero.
     *
     * ## OPTIONS
     * [--rate=<f>] : Fraction of completed orders to refund. Default: 0.04.
     */
    public function refunds( $args, $assoc ) {
        $rate = (float) ( $assoc['rate'] ?? 0.04 );
        $rate = max( 0.0, min( 1.0, $rate ) );

        global $wpdb;
        // HPOS-aware order id lookup.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
              WHERE meta_key=%s AND order_id IN (
                  SELECT id FROM {$wpdb->prefix}wc_orders WHERE status='wc-completed'
              )",
            self::SEED_META_KEY
        ) );
        if ( ! $ids ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                  JOIN {$wpdb->posts} p ON p.ID=pm.post_id
                 WHERE pm.meta_key=%s AND p.post_type='shop_order' AND p.post_status='wc-completed'",
                self::SEED_META_KEY
            ) );
        }
        if ( ! $ids ) {
            WP_CLI::warning( "No completed seeded orders found — run `wp demo seed orders` first." );
            return;
        }

        shuffle( $ids );
        $to_refund = (int) ceil( count( $ids ) * $rate );
        $to_refund = min( $to_refund, count( $ids ) );

        $reasons = [
            'Wrong shade — customer requested return',
            'Allergic reaction reported',
            'Damaged in transit',
            'Customer changed mind within 30-day window',
            'Duplicate order',
        ];

        $refunded = 0;
        for ( $i = 0; $i < $to_refund; $i++ ) {
            $order = wc_get_order( $ids[ $i ] );
            if ( ! $order ) { continue; }
            wc_create_refund( [
                'order_id'       => $order->get_id(),
                'amount'         => $order->get_total(),
                'reason'         => $reasons[ array_rand( $reasons ) ],
                'refund_payment' => false,
                'restock_items'  => false,
            ] );
            $refunded++;
        }
        WP_CLI::log( "  Refunded {$refunded} orders (target rate {$rate})." );
    }

    /**
     * Wipe all seeded data — DESTRUCTIVE. Asks for confirmation.
     *
     * ## OPTIONS
     * [--yes] : Skip the confirmation prompt.
     */
    public function reset( $args, $assoc ) {
        WP_CLI::confirm( "This will delete ALL demo-seeded orders, customers, and reset COGS. Continue?", $assoc );

        global $wpdb;
        // Orders
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key=%s",
            self::SEED_META_KEY
        ) );
        if ( ! $order_ids ) {
            $order_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s",
                self::SEED_META_KEY
            ) );
        }
        foreach ( $order_ids as $id ) {
            $order = wc_get_order( $id );
            if ( $order ) { $order->delete( true ); }
        }
        WP_CLI::log( "  Deleted " . count( $order_ids ) . " orders." );

        // Customers
        $user_ids = get_users( [
            'role'       => 'customer',
            'meta_key'   => self::SEED_META_KEY,
            'meta_value' => self::SEED_META_VAL,
            'fields'     => 'ID',
        ] );
        foreach ( $user_ids as $uid ) { wp_delete_user( $uid ); }
        WP_CLI::log( "  Deleted " . count( $user_ids ) . " customers." );

        // COGS — clear meta on products
        $cleared = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wc_cog_cost','_wc_cog_cost_currency')" );
        WP_CLI::log( "  Cleared COGS meta on {$cleared} product rows." );

        WP_CLI::success( "Reset complete." );
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** Pick the deepest matching category for a product and return its COGS ratio. */
    private function resolve_cogs_ratio( $product_id ) {
        $term_ids = wp_get_object_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $term_ids ) || ! $term_ids ) { return 0.50; }
        // Prefer deepest (child) terms by sorting by parent != 0.
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'include' => $term_ids, 'hide_empty' => false ] );
        usort( $terms, fn( $a, $b ) => ( $a->parent === 0 ? 1 : 0 ) - ( $b->parent === 0 ? 1 : 0 ) );
        foreach ( $terms as $term ) {
            if ( isset( self::COGS_RATIOS[ $term->slug ] ) ) { return self::COGS_RATIOS[ $term->slug ]; }
        }
        return 0.50;
    }

    /** Weighted pick from associative array [key => weight]. Returns key. */
    private function weighted_pick( $weights ) {
        $total = array_sum( $weights );
        $r     = mt_rand( 1, max( 1, $total ) );
        $acc   = 0;
        foreach ( $weights as $k => $w ) {
            $acc += $w;
            if ( $r <= $acc ) { return $k; }
        }
        return array_key_first( $weights );
    }

    /** Pareto-shaped popularity weights over a product list. */
    private function build_product_popularity( $product_ids ) {
        $weights   = [];
        $shuffled  = $product_ids;
        shuffle( $shuffled );
        $top_n     = max( 1, (int) ( count( $shuffled ) * 0.20 ) );
        foreach ( $shuffled as $i => $pid ) {
            $weights[ $pid ] = $i < $top_n ? mt_rand( 25, 50 ) : mt_rand( 1, 8 );
        }
        return $weights;
    }

    /** Pick N random elements from an array. */
    private function pick_random( $arr, $n ) {
        $copy = $arr;
        shuffle( $copy );
        return array_slice( $copy, 0, min( $n, count( $copy ) ) );
    }

    /** Returns "Y-m-d H:i:s" string $days_ago from now (UTC). */
    private function backdated_timestamp( $days_ago ) {
        return gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) );
    }

    /** Pick days-ago from the date bucket distribution, scaled to the range. */
    private function pick_days_ago( $days ) {
        // Map 6 buckets across $days. If range < 180 days, scale proportionally.
        $bucket_size = max( 1, (int) ( $days / count( self::DATE_BUCKET_WEIGHTS ) ) );
        $weights     = [];
        foreach ( self::DATE_BUCKET_WEIGHTS as $i => $w ) { $weights[ $i ] = $w; }
        $bucket_idx  = $this->weighted_pick( $weights );
        $low         = $bucket_idx * $bucket_size;
        $high        = min( $days, ( $bucket_idx + 1 ) * $bucket_size );
        return mt_rand( max( 0, $low ), max( 1, $high ) );
    }

    /** Backdated timestamp with realistic DOW + hour weighting. Returns WC_DateTime. */
    private function backdated_timestamp_for_order( $days_ago ) {
        $dow = (int) $this->weighted_pick( self::DOW_WEIGHTS );
        // Snap to the nearest matching day-of-week within ±3 days.
        $candidate = time() - ( $days_ago * DAY_IN_SECONDS );
        for ( $offset = 0; $offset <= 3; $offset++ ) {
            foreach ( [ -1, +1 ] as $sign ) {
                $t = $candidate + ( $sign * $offset * DAY_IN_SECONDS );
                if ( (int) gmdate( 'w', $t ) === $dow ) { $candidate = $t; break 2; }
            }
        }
        $hour   = (int) $this->weighted_pick( self::HOUR_WEIGHTS );
        $minute = mt_rand( 0, 59 );
        $second = mt_rand( 0, 59 );

        $datetime = ( new DateTime( '@' . $candidate, new DateTimeZone( 'UTC' ) ) )
            ->setTime( $hour, $minute, $second );
        return new WC_DateTime( $datetime->format( 'Y-m-d H:i:s' ), new DateTimeZone( 'UTC' ) );
    }

    /** Returns [ $first, $last ]. */
    private function random_name() {
        return [
            self::FIRST_NAMES[ array_rand( self::FIRST_NAMES ) ],
            self::LAST_NAMES[ array_rand( self::LAST_NAMES ) ],
        ];
    }

    /** Build a billing address dict tied to country distribution. */
    private function random_billing_address( $first, $last ) {
        $country = $this->weighted_pick( self::COUNTRY_WEIGHTS );
        $state   = $country === 'US' ? self::US_STATES[ array_rand( self::US_STATES ) ] : '';
        $postcode_pad = $country === 'US' ? sprintf( '%05d', mt_rand( 10000, 99999 ) ) : strtoupper( wp_generate_password( 5, false, false ) );
        return [
            'first_name' => $first,
            'last_name'  => $last,
            'address_1'  => mt_rand( 10, 9999 ) . ' ' . [ 'Main','Oak','Maple','Cedar','Pine','Elm','Birch','Hill','Lake','Park' ][ mt_rand( 0, 9 ) ] . ' St',
            'city'       => [ 'Springfield','Riverdale','Fairview','Madison','Georgetown','Bristol','Salem','Franklin','Clinton','Greenville' ][ mt_rand( 0, 9 ) ],
            'state'      => $state,
            'postcode'   => $postcode_pad,
            'country'    => $country,
            'phone'      => '',
        ];
    }
}

WP_CLI::add_command( 'demo seed', 'Demo_Store_Seeder' );
