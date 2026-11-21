<?php
/**
 * Removes the plugin's settings when the merchant deletes it.
 *
 * Every `_urumi_agent_session` row (the service's conversation reference) is
 * deleted from both order meta tables, in batches. The other order tags
 * (`_urumi_agent_channel`, `_mode`, `_signal`, `_status_seq`) are kept: they are
 * part of each order's history, like Woo's own `_wc_order_attribution_*` fields.
 *
 * WordPress runs this file only when the plugin is deleted from wp-admin, and
 * then for the current site only. Removing the plugin directory (a deploy, git)
 * does not run it.
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-check-in.php';
require_once __DIR__ . '/includes/class-handoff-uses.php';
require_once __DIR__ . '/includes/class-order-events.php';
require_once __DIR__ . '/includes/class-order-tagger.php';
require_once __DIR__ . '/includes/class-privacy.php';

// The conversation reference on every order (H6), 1000 rows per DELETE. It
// goes first because it is personal data: the steps below call Action
// Scheduler, which can throw, and nothing here catches that. It contains its
// own Throwables, so the steps below still run.
Urumi_Agentic_Privacy::delete_all_sessions();

// Cancel the queued check-ins (a no-op without Action Scheduler).
Urumi_Agentic_Check_In::unschedule();

foreach ( Urumi_Agentic_Settings::OPTIONS as $urumi_agentic_option ) {
	delete_option( $urumi_agentic_option );
}

// The hand-off single-use table, its options and its cleanup job.
Urumi_Agentic_Handoff_Uses::uninstall();
// Cancel queued order events (a no-op without Action Scheduler).
Urumi_Agentic_Order_Events::unschedule();
// The merchant screen's cached feed status (PL-4).
delete_transient( 'urumi_agentic_feed_status' );
