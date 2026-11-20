<?php
/**
 * Personal-data export and erasure for the agent order tags (H6).
 *
 * @package Urumi_Agentic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the agent order tags into WooCommerce's own personal-data tools.
 *
 * It uses Woo's `woocommerce-customer-orders` exporter and eraser rather than
 * registering its own. Those already find the person's orders (billing email
 * plus user id, 10 per page), and our data lives only on those orders. So the
 * two can never disagree about which orders belong to the person, and Woo's
 * settings decide what is erased:
 *   - export: the tags are added to each order's rows;
 *   - erase: `_urumi_agent_session` is removed whenever Woo removes an order's
 *     personal data (an erasure request with order data removal on, or Woo's
 *     retention job for old orders). Channel, mode and signal stay, as Woo
 *     keeps its own `_wc_order_attribution_*` fields;
 *   - the privacy-policy guide gets suggested text about the plugin.
 *
 * Nothing here runs on a shopper request, and every callback contains its
 * own Throwables.
 */
class Urumi_Agentic_Privacy {

	/**
	 * The name shown in the privacy-policy guide.
	 */
	const POLICY_NAME = 'Urumi Agentic checkout';

	/**
	 * Default rows per uninstall DELETE, so a large store never holds a long lock.
	 */
	const UNINSTALL_BATCH = 1000;

	/**
	 * Adds the hooks. Woo fires the export and erase hooks only from its own
	 * privacy code, so they can be added before WooCommerce loads.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'woocommerce_privacy_export_order_personal_data', array( self::class, 'export_order' ), 10, 2 );
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', array( self::class, 'erase_order' ), 10, 1 );
		add_action( 'admin_init', array( self::class, 'add_policy_content' ), 10, 0 );
	}

	/**
	 * Adds the agent tags to one order's rows in the personal-data export.
	 *
	 * Only a `WC_Order` with a channel tag gets rows. Anything else, and any
	 * failure, returns the rows as given.
	 *
	 * @param mixed $personal_data Name/value rows Woo built for the order.
	 * @param mixed $order         The order.
	 * @return mixed
	 */
	public static function export_order( $personal_data, $order = null ) {
		if ( ! is_array( $personal_data ) || ! $order instanceof WC_Order ) {
			return $personal_data;
		}
		try {
			$channel = $order->get_meta( Urumi_Agentic_Order_Tagger::META_CHANNEL );
			if ( ! is_scalar( $channel ) || '' === (string) $channel ) {
				return $personal_data;
			}
			$mode    = $order->get_meta( Urumi_Agentic_Order_Tagger::META_MODE );
			$signal  = $order->get_meta( Urumi_Agentic_Order_Tagger::META_SIGNAL );
			$session = $order->get_meta( Urumi_Agentic_Order_Tagger::META_SESSION );

			// Fixed order. A value that is missing or not a plain value gets no row.
			$rows = array( array( __( 'AI assistant', 'urumi-agentic' ), self::channel_label( (string) $channel ) ) );
			if ( is_scalar( $mode ) && '' !== (string) $mode ) {
				$rows[] = array( __( 'AI assistant order type', 'urumi-agentic' ), self::mode_label( (string) $mode ) );
			}
			if ( is_scalar( $signal ) && '' !== (string) $signal ) {
				$rows[] = array( __( 'AI assistant signal', 'urumi-agentic' ), self::signal_label( (string) $signal ) );
			}
			if ( is_scalar( $session ) && '' !== (string) $session ) {
				$rows[] = array( __( 'AI assistant conversation reference', 'urumi-agentic' ), (string) $session );
			}
		} catch ( Throwable $e ) {
			self::log_failure( 'Privacy export', $e );
			return $personal_data;
		}
		foreach ( $rows as $row ) {
			$personal_data[] = array(
				'name'  => $row[0],
				'value' => $row[1],
			);
		}
		return $personal_data;
	}

	/**
	 * Removes the agent session id from an order whose personal data Woo is removing.
	 *
	 * Woo fires this inside WC_Privacy_Erasers::remove_order_personal_data(),
	 * for erasure requests and for its retention jobs alike, and saves the
	 * order a few lines later. So only the order object is changed here: the
	 * delete reaches the database in Woo's own save, and this never saves.
	 * Refunds and anything that is not a `WC_Order` are left alone.
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	public static function erase_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			// A value that is not a plain value is deleted too, never cast.
			$session = $order->get_meta( Urumi_Agentic_Order_Tagger::META_SESSION );
			if ( is_scalar( $session ) && '' === (string) $session ) {
				return;
			}
			$order->delete_meta_data( Urumi_Agentic_Order_Tagger::META_SESSION );
		} catch ( Throwable $e ) {
			self::log_failure( 'Privacy erase', $e );
		}
	}

	/**
	 * Suggests privacy-policy text in Settings > Privacy (admin screens only).
	 *
	 * @param string $add_function The WordPress function that adds it. Tests pass another name.
	 * @return void
	 */
	public static function add_policy_content( $add_function = 'wp_add_privacy_policy_content' ) {
		try {
			if ( ! function_exists( $add_function ) ) {
				return;
			}
			call_user_func( $add_function, self::POLICY_NAME, self::policy_text() );
		} catch ( Throwable $e ) {
			self::log_failure( 'Privacy policy text', $e );
		}
	}

	/**
	 * The suggested privacy-policy text. Merchants can edit it. It is a draft: legal has not signed off
	 * its claims (Urumi as processor, Urumi's own retention), so its first paragraph says so inside the
	 * text a merchant copies. Legal sign-off gates enabling the plugin on the first production store.
	 *
	 * @return string HTML.
	 */
	public static function policy_text() {
		$paragraphs = array(
			__( 'DRAFT, review before use: this suggested text has not yet been reviewed by legal counsel. Check it, in particular what it says about Urumi\'s role and how long Urumi keeps data, before adding it to your privacy policy, and remove this paragraph once you have.', 'urumi-agentic' ),
			__( 'When a shopper reaches this store from an AI assistant (such as ChatGPT), we note it on their order: which assistant, whether the order was opened from the assistant\'s checkout link or the assistant referred the shopper, and the signal that showed it.', 'urumi-agentic' ),
			__( 'For an order opened from an assistant\'s checkout link, we also store the assistant\'s conversation reference. We keep it until the order\'s personal data is removed, either on request or by the store\'s personal data retention settings for completed orders (and refunded orders, on WooCommerce versions that support it).', 'urumi-agentic' ),
			__( 'The products in a checkout link and the conversation reference are kept in the store\'s normal shopper session. They are used for at most 4 hours and deleted when the shopper\'s session expires and the store clears expired sessions (with WooCommerce\'s default settings, about 2 days after the shopper\'s last visit, or up to a week for logged-in customers on recent versions).', 'urumi-agentic' ),
			__( 'Urumi runs the AI assistant checkout service for this store. Urumi receives the ID of each of these orders and its status changes, as a recipient of this data and as our processor. Urumi also holds the details the assistant passed in the checkout link (such as contact and delivery details), under its own retention.', 'urumi-agentic' ),
			__( 'Urumi sends each status change of these orders, as a signed message linked to the shopper\'s conversation, to the platform of the AI assistant the shopper used (for example OpenAI, for ChatGPT).', 'urumi-agentic' ),
		);
		$html       = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		return '<div class="wp-suggested-text">' . $html . '</div>';
	}

	/**
	 * Deletes every `_urumi_agent_session` row from both order meta tables, a
	 * batch at a time, on uninstall. The other tags stay with each order's
	 * history. `urumi_agentic_uninstall_batch_size` changes the batch size.
	 *
	 * Each DELETE is ordered by the table's primary key, so with LIMIT it is
	 * deterministic and safe for statement-based replication. A DELETE that
	 * fails stops that table and is logged with a fixed code. Any Throwable is
	 * contained, so the rest of uninstall still runs.
	 *
	 * @param int|null $batch Rows per DELETE; null for the filtered default.
	 * @return void
	 */
	public static function delete_all_sessions( $batch = null ) {
		global $wpdb;
		try {
			if ( null === $batch ) {
				$batch = apply_filters( 'urumi_agentic_uninstall_batch_size', self::UNINSTALL_BATCH );
			}
			$batch  = max( 1, (int) $batch );
			$tables = array();
			$orders = $wpdb->prefix . 'wc_orders_meta';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, a one-off check.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders ) ) ) === $orders ) {
				$tables['orders_meta'] = array( $orders, 'id' );
			}
			$tables['postmeta'] = array( $wpdb->prefix . 'postmeta', 'meta_id' );
			foreach ( $tables as $code => $table ) {
				list( $name, $key ) = $table;
				do {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall deletes our own meta key; the table and key names are the WordPress prefix plus fixed names.
					$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$name}` WHERE meta_key = %s ORDER BY `{$key}` LIMIT %d", Urumi_Agentic_Order_Tagger::META_SESSION, $batch ) );
				} while ( is_int( $deleted ) && $deleted > 0 );
				if ( false === $deleted ) {
					self::log_warning( 'Uninstall session delete failed: ' . $code );
				}
			}
		} catch ( Throwable $e ) {
			self::log_failure( 'Uninstall session delete', $e );
		}
	}

	/**
	 * A readable channel, or the stored value when it is not a known one.
	 *
	 * @param string $channel Stored channel.
	 * @return string
	 */
	private static function channel_label( $channel ) {
		return Urumi_Agentic_Order_Filter::channel_label( $channel );
	}

	/**
	 * A readable order type, or the stored value when it is not a known one.
	 *
	 * @param string $mode Stored mode.
	 * @return string
	 */
	private static function mode_label( $mode ) {
		$labels = array(
			'handoff'  => __( 'Opened from an assistant checkout link', 'urumi-agentic' ),
			'referred' => __( 'Referred by an assistant', 'urumi-agentic' ),
		);
		return $labels[ $mode ] ?? $mode;
	}

	/**
	 * A readable signal, or the stored value when it is not a known one.
	 *
	 * @param string $signal Stored signal.
	 * @return string
	 */
	private static function signal_label( $signal ) {
		$labels = array(
			'handoff_link' => __( 'Assistant checkout link', 'urumi-agentic' ),
			'agent_header' => __( 'Assistant request header', 'urumi-agentic' ),
			'utm'          => __( 'Campaign link parameters', 'urumi-agentic' ),
			'referral'     => __( 'Link from the assistant website', 'urumi-agentic' ),
		);
		return $labels[ $signal ] ?? $signal;
	}

	/**
	 * Logs a skipped step with the exception class only: messages can carry
	 * order or shopper data.
	 *
	 * @param string    $what What was skipped.
	 * @param Throwable $e    What was thrown.
	 * @return void
	 */
	private static function log_failure( $what, Throwable $e ) {
		self::log_warning( $what . ' skipped after ' . get_class( $e ) );
	}

	/**
	 * Logs a fixed message, never order or shopper data.
	 *
	 * @param string $message What happened.
	 * @return void
	 */
	private static function log_warning( $message ) {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( $message, array( 'source' => 'urumi-agentic' ) );
			}
		} catch ( Throwable $ignored ) {
			// Logging must not break the privacy tools either.
			unset( $ignored );
		}
	}
}
