<?php
/**
 * Plugin Name: Salve Marketing Campaigns
 * Description: Create editable marketing email campaigns, preview them, send a test, and launch consent-based batches with unsubscribe support.
 * Version: 1.3.0
 * Author: Salve
 * License: GPL-2.0-or-later
 * Text Domain: salve-marketing-campaigns
 */

defined( 'ABSPATH' ) || exit;

final class Salve_Marketing_Campaigns {
	const POST_TYPE       = 'salve_campaign';
	const CRON_HOOK       = 'salve_marketing_send_batch';
	const META_STATUS     = '_salve_campaign_status';
	const META_SUBJECT    = '_salve_campaign_subject';
	const META_EYEBROW    = '_salve_campaign_eyebrow';
	const META_HEADING    = '_salve_campaign_heading';
	const META_MESSAGE    = '_salve_campaign_message';
	const META_CTA_LABEL  = '_salve_campaign_cta_label';
	const META_CTA_URL    = '_salve_campaign_cta_url';
	const META_PRODUCT_ONE = '_salve_campaign_product_one';
	const META_PRODUCT_TWO = '_salve_campaign_product_two';
	const META_OFFSET     = '_salve_campaign_offset';
	const META_SENT       = '_salve_campaign_sent';
	const META_FAILED     = '_salve_campaign_failed';
	const USER_OPT_IN     = 'salve_marketing_opted_in';
	const USER_UNSUB      = 'salve_marketing_unsubscribed';
	const BATCH_SIZE      = 10;

	public function __construct() {
		add_action( 'init', array( $this, 'register_campaign_post_type' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_campaign_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_campaign_fields' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_campaign_row_action' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_launch_page' ) );
		add_action( 'admin_post_salve_marketing_send_test', array( $this, 'send_test' ) );
		add_action( 'admin_post_salve_marketing_launch', array( $this, 'launch_campaign' ) );
		add_action( self::CRON_HOOK, array( $this, 'send_batch' ) );
		add_action( 'show_user_profile', array( $this, 'render_consent_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_consent_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_consent_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_consent_field' ) );
		add_action( 'template_redirect', array( $this, 'handle_unsubscribe' ) );
	}

	public function register_campaign_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Email Campaigns', 'salve-marketing-campaigns' ),
					'singular_name' => __( 'Email Campaign', 'salve-marketing-campaigns' ),
					'add_new_item'  => __( 'Create Campaign', 'salve-marketing-campaigns' ),
					'edit_item'     => __( 'Edit Campaign', 'salve-marketing-campaigns' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-email-alt',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
			)
		);
	}

	public function add_campaign_meta_box() {
		add_meta_box(
			'salve-campaign-details',
			__( 'Campaign details', 'salve-marketing-campaigns' ),
			array( $this, 'render_campaign_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_campaign_meta_box( $post ) {
		$fields   = $this->campaign_fields( $post->ID );
		$status   = get_post_meta( $post->ID, self::META_STATUS, true ) ?: 'draft';
		$products = $this->available_products();
		wp_nonce_field( 'salve_campaign_details', 'salve_campaign_details_nonce' );
		?>
		<p><?php esc_html_e( 'This campaign uses the Salve native HTML email design. Edit the content fields below; the launch screen shows the exact email preview.', 'salve-marketing-campaigns' ); ?></p>
		<table class="form-table" role="presentation">
			<tr><th><label for="salve_campaign_subject"><?php esc_html_e( 'Email subject', 'salve-marketing-campaigns' ); ?></label></th><td><input class="regular-text" type="text" id="salve_campaign_subject" name="salve_campaign_subject" value="<?php echo esc_attr( $fields['subject'] ); ?>"></td></tr>
			<tr><th><label for="salve_campaign_eyebrow"><?php esc_html_e( 'Eyebrow', 'salve-marketing-campaigns' ); ?></label></th><td><input class="regular-text" type="text" id="salve_campaign_eyebrow" name="salve_campaign_eyebrow" value="<?php echo esc_attr( $fields['eyebrow'] ); ?>"></td></tr>
			<tr><th><label for="salve_campaign_heading"><?php esc_html_e( 'Heading', 'salve-marketing-campaigns' ); ?></label></th><td><input class="regular-text" type="text" id="salve_campaign_heading" name="salve_campaign_heading" value="<?php echo esc_attr( $fields['heading'] ); ?>"></td></tr>
			<tr><th><label for="salve_campaign_message"><?php esc_html_e( 'Message', 'salve-marketing-campaigns' ); ?></label></th><td><textarea class="large-text" rows="7" id="salve_campaign_message" name="salve_campaign_message"><?php echo esc_textarea( $fields['message'] ); ?></textarea></td></tr>
			<tr><th><label for="salve_campaign_cta_label"><?php esc_html_e( 'Button label', 'salve-marketing-campaigns' ); ?></label></th><td><input class="regular-text" type="text" id="salve_campaign_cta_label" name="salve_campaign_cta_label" value="<?php echo esc_attr( $fields['cta_label'] ); ?>"></td></tr>
			<tr><th><label for="salve_campaign_cta_url"><?php esc_html_e( 'Button URL', 'salve-marketing-campaigns' ); ?></label></th><td><input class="large-text code" type="url" id="salve_campaign_cta_url" name="salve_campaign_cta_url" value="<?php echo esc_attr( $fields['cta_url'] ); ?>"></td></tr>
			<tr>
				<th><?php esc_html_e( 'Featured products', 'salve-marketing-campaigns' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'These appear as image, price, and shop-link cards in the email. Leave either field on “Use newest product” to fill it automatically from your catalog.', 'salve-marketing-campaigns' ); ?></p>
					<p><label for="salve_campaign_product_one"><?php esc_html_e( 'Product one', 'salve-marketing-campaigns' ); ?></label><br>
					<select id="salve_campaign_product_one" name="salve_campaign_product_one">
						<option value="0" <?php selected( 0, absint( $fields['product_one'] ) ); ?>><?php esc_html_e( 'Use newest product', 'salve-marketing-campaigns' ); ?></option>
						<?php foreach ( $products as $product ) : ?>
							<option value="<?php echo esc_attr( $product->ID ); ?>" <?php selected( $product->ID, absint( $fields['product_one'] ) ); ?>><?php echo esc_html( get_the_title( $product ) ); ?></option>
						<?php endforeach; ?>
					</select></p>
					<p><label for="salve_campaign_product_two"><?php esc_html_e( 'Product two', 'salve-marketing-campaigns' ); ?></label><br>
					<select id="salve_campaign_product_two" name="salve_campaign_product_two">
						<option value="0" <?php selected( 0, absint( $fields['product_two'] ) ); ?>><?php esc_html_e( 'Use newest product', 'salve-marketing-campaigns' ); ?></option>
						<?php foreach ( $products as $product ) : ?>
							<option value="<?php echo esc_attr( $product->ID ); ?>" <?php selected( $product->ID, absint( $fields['product_two'] ) ); ?>><?php echo esc_html( get_the_title( $product ) ); ?></option>
						<?php endforeach; ?>
					</select></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Personalisation fields: {{first_name}} and {{site_name}}.', 'salve-marketing-campaigns' ); ?></p>
		<p><strong><?php esc_html_e( 'Status:', 'salve-marketing-campaigns' ); ?></strong> <?php echo esc_html( ucfirst( $status ) ); ?></p>
		<?php if ( $post->ID ) : ?>
			<p><a class="button button-secondary" href="<?php echo esc_url( $this->launch_url( $post->ID ) ); ?>"><?php esc_html_e( 'Preview, test & launch', 'salve-marketing-campaigns' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	public function save_campaign_fields( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || empty( $_POST['salve_campaign_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['salve_campaign_details_nonce'] ) ), 'salve_campaign_details' ) ) {
			return;
		}
		$fields = array(
			'subject'   => array( self::META_SUBJECT, 'sanitize_text_field' ),
			'eyebrow'   => array( self::META_EYEBROW, 'sanitize_text_field' ),
			'heading'   => array( self::META_HEADING, 'sanitize_text_field' ),
			'message'   => array( self::META_MESSAGE, 'sanitize_textarea_field' ),
			'cta_label' => array( self::META_CTA_LABEL, 'sanitize_text_field' ),
			'cta_url'   => array( self::META_CTA_URL, 'esc_url_raw' ),
			'product_one' => array( self::META_PRODUCT_ONE, 'absint' ),
			'product_two' => array( self::META_PRODUCT_TWO, 'absint' ),
		);
		foreach ( $fields as $field => $settings ) {
			$key = 'salve_campaign_' . $field;
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $settings[0], call_user_func( $settings[1], wp_unslash( $_POST[ $key ] ) ) );
			}
		}
		if ( ! get_post_meta( $post_id, self::META_STATUS, true ) ) {
			update_post_meta( $post_id, self::META_STATUS, 'draft' );
		}
	}

	public function add_campaign_row_action( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['salve-review-launch'] = '<a href="' . esc_url( $this->launch_url( $post->ID ) ) . '">' . esc_html__( 'Preview & launch', 'salve-marketing-campaigns' ) . '</a>';
		}
		return $actions;
	}

	public function register_launch_page() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Campaign launch', 'salve-marketing-campaigns' ),
			__( 'Campaign launch', 'salve-marketing-campaigns' ),
			'edit_posts',
			'salve-campaign-launch',
			array( $this, 'render_launch_page' )
		);
	}

	public function render_launch_page() {
		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$campaign    = get_post( $campaign_id );
		if ( ! $campaign || self::POST_TYPE !== $campaign->post_type || ! current_user_can( 'edit_post', $campaign_id ) ) {
			wp_die( esc_html__( 'Campaign not found.', 'salve-marketing-campaigns' ) );
		}

		$eligible = $this->eligible_recipients();
		$status   = get_post_meta( $campaign_id, self::META_STATUS, true ) ?: 'draft';
		$sent     = absint( get_post_meta( $campaign_id, self::META_SENT, true ) );
		$failed   = absint( get_post_meta( $campaign_id, self::META_FAILED, true ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_the_title( $campaign ) ); ?></h1>
			<p><a href="<?php echo esc_url( get_edit_post_link( $campaign_id ) ); ?>">&larr; <?php esc_html_e( 'Edit campaign', 'salve-marketing-campaigns' ); ?></a></p>
			<?php $this->render_notice(); ?>
			<div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:24px;max-width:1200px">
				<div>
					<h2><?php esc_html_e( 'Email preview', 'salve-marketing-campaigns' ); ?></h2>
					<div style="background:#f0f0f1;padding:24px">
						<?php echo $this->render_email( $campaign, (object) array( 'ID' => 0, 'user_email' => get_option( 'admin_email' ), 'display_name' => __( 'Preview reader', 'salve-marketing-campaigns' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
				<div>
					<h2><?php esc_html_e( 'Launch controls', 'salve-marketing-campaigns' ); ?></h2>
					<p><strong><?php esc_html_e( 'Eligible recipients:', 'salve-marketing-campaigns' ); ?></strong> <?php echo esc_html( count( $eligible ) ); ?></p>
					<p><strong><?php esc_html_e( 'Campaign status:', 'salve-marketing-campaigns' ); ?></strong> <?php echo esc_html( ucfirst( $status ) ); ?></p>
					<?php if ( $sent || $failed ) : ?><p><?php printf( esc_html__( 'Sent: %1$d · Failed: %2$d', 'salve-marketing-campaigns' ), $sent, $failed ); ?></p><?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:20px 0">
						<input type="hidden" name="action" value="salve_marketing_send_test">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
						<?php wp_nonce_field( 'salve_marketing_test_' . $campaign_id ); ?>
						<button class="button button-secondary" type="submit"><?php esc_html_e( 'Send a test to me', 'salve-marketing-campaigns' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="salve_marketing_launch">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>">
						<?php wp_nonce_field( 'salve_marketing_launch_' . $campaign_id ); ?>
						<p><label><input type="checkbox" name="confirm_launch" value="1" required> <?php esc_html_e( 'I confirm this is a marketing email for recipients who have opted in.', 'salve-marketing-campaigns' ); ?></label></p>
						<button class="button button-primary" type="submit" <?php disabled( 'sending', $status ); ?>><?php esc_html_e( 'Launch campaign', 'salve-marketing-campaigns' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Campaigns are sent in batches of 10 via WordPress mail and include an unsubscribe link. Configure a reliable SMTP provider before sending to a large audience.', 'salve-marketing-campaigns' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public function send_test() {
		$campaign_id = $this->verified_campaign_request( 'salve_marketing_test_' );
		$user        = wp_get_current_user();
		$sent        = $this->send_campaign_email( $campaign_id, $user );
		$this->redirect_with_notice( $campaign_id, $sent ? 'test_sent' : 'test_failed' );
	}

	public function launch_campaign() {
		$campaign_id = $this->verified_campaign_request( 'salve_marketing_launch_' );
		if ( empty( $_POST['confirm_launch'] ) ) {
			$this->redirect_with_notice( $campaign_id, 'confirmation_required' );
		}
		$campaign = get_post( $campaign_id );
		$fields   = $this->campaign_fields( $campaign_id );
		if ( ! $campaign || '' === trim( $fields['subject'] ) || '' === trim( $fields['heading'] ) || '' === trim( $fields['message'] ) ) {
			$this->redirect_with_notice( $campaign_id, 'campaign_incomplete' );
		}
		if ( ! $this->eligible_recipients() ) {
			$this->redirect_with_notice( $campaign_id, 'no_recipients' );
		}

		update_post_meta( $campaign_id, self::META_STATUS, 'sending' );
		update_post_meta( $campaign_id, self::META_OFFSET, 0 );
		update_post_meta( $campaign_id, self::META_SENT, 0 );
		update_post_meta( $campaign_id, self::META_FAILED, 0 );
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $campaign_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $campaign_id ) );
		}
		$this->redirect_with_notice( $campaign_id, 'queued' );
	}

	public function send_batch( $campaign_id ) {
		if ( 'sending' !== get_post_meta( $campaign_id, self::META_STATUS, true ) ) {
			return;
		}
		$recipients = $this->eligible_recipients();
		$offset     = absint( get_post_meta( $campaign_id, self::META_OFFSET, true ) );
		$batch      = array_slice( $recipients, $offset, self::BATCH_SIZE );
		$sent       = absint( get_post_meta( $campaign_id, self::META_SENT, true ) );
		$failed     = absint( get_post_meta( $campaign_id, self::META_FAILED, true ) );

		foreach ( $batch as $recipient ) {
			if ( $this->send_campaign_email( $campaign_id, $recipient ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		$offset += count( $batch );
		update_post_meta( $campaign_id, self::META_OFFSET, $offset );
		update_post_meta( $campaign_id, self::META_SENT, $sent );
		update_post_meta( $campaign_id, self::META_FAILED, $failed );

		if ( $offset >= count( $recipients ) ) {
			update_post_meta( $campaign_id, self::META_STATUS, 'sent' );
			return;
		}
		wp_schedule_single_event( time() + 60, self::CRON_HOOK, array( $campaign_id ) );
	}

	private function eligible_recipients() {
		$users = get_users(
			array(
				'fields'     => array( 'ID', 'user_email', 'display_name' ),
				'meta_key'   => self::USER_OPT_IN,
				'meta_value' => 'yes',
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
		return array_values(
			array_filter(
				$users,
				function ( $user ) {
					return is_email( $user->user_email ) && 'yes' !== get_user_meta( $user->ID, self::USER_UNSUB, true );
				}
			)
		);
	}

	private function send_campaign_email( $campaign_id, $recipient ) {
		$campaign = get_post( $campaign_id );
		if ( ! $campaign || self::POST_TYPE !== $campaign->post_type || ! is_email( $recipient->user_email ) ) {
			return false;
		}
		$subject = sanitize_text_field( $this->replace_tokens( $this->campaign_fields( $campaign_id )['subject'], $recipient ) );
		return wp_mail( $recipient->user_email, $subject, $this->render_email( $campaign, $recipient ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	private function render_email( $campaign, $recipient ) {
		$fields      = $this->campaign_fields( $campaign->ID );
		$eyebrow     = esc_html( $this->replace_tokens( $fields['eyebrow'], $recipient ) );
		$heading     = esc_html( $this->replace_tokens( $fields['heading'], $recipient ) );
		$message     = nl2br( esc_html( $this->replace_tokens( $fields['message'], $recipient ) ) );
		$cta_label   = esc_html( $this->replace_tokens( $fields['cta_label'], $recipient ) );
		$cta_url     = esc_url( $this->replace_tokens( $fields['cta_url'], $recipient ) );
		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$unsubscribe = $recipient->ID ? $this->unsubscribe_url( $recipient ) : '#';
		$product_cards = $this->render_product_cards( $campaign->ID );
		return '<!doctype html><html><body style="margin:0;background:#f7f3ed;color:#1a1612;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff"><tr><td style="padding:42px 44px 30px;background:#1a1612;color:#fbf8f3"><div style="font-family:Georgia,serif;font-size:34px;letter-spacing:-1px">' . esc_html( $site_name ) . '</div><div style="margin-top:18px;color:#d9d2c8;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase">' . $eyebrow . '</div><h1 style="margin:10px 0 0;color:#fbf8f3;font-family:Georgia,serif;font-size:42px;font-weight:normal;line-height:1.08">' . $heading . '</h1></td></tr><tr><td style="padding:42px 44px 20px;font-size:16px;line-height:1.7">' . $message . '</td></tr>' . $product_cards . '<tr><td style="padding:12px 44px 44px"><a href="' . $cta_url . '" style="display:inline-block;background:#1a1612;color:#fbf8f3;padding:14px 24px;font-size:12px;font-weight:bold;letter-spacing:1.5px;text-decoration:none;text-transform:uppercase">' . $cta_label . '</a></td></tr><tr><td style="padding:24px 44px;background:#f0ebe3;color:#6b635a;font-size:12px;line-height:1.5">You are receiving this because you opted in to marketing from ' . esc_html( $site_name ) . '. <a style="color:#6b635a" href="' . esc_url( $unsubscribe ) . '">Unsubscribe</a></td></tr></table></td></tr></table></body></html>';
	}

	private function campaign_fields( $campaign_id ) {
		$defaults = array(
			'subject'   => 'A considered note from {{site_name}}',
			'eyebrow'   => 'Considered cosmetics',
			'heading'   => 'A small ritual, for you.',
			'message'   => "Hello {{first_name}},\n\nWe have something considered especially for you.",
			'cta_label' => 'Shop the collection',
			'cta_url'   => home_url( '/shop' ),
			'product_one' => 0,
			'product_two' => 0,
		);
		$meta_keys = array(
			'subject'   => self::META_SUBJECT,
			'eyebrow'   => self::META_EYEBROW,
			'heading'   => self::META_HEADING,
			'message'   => self::META_MESSAGE,
			'cta_label' => self::META_CTA_LABEL,
			'cta_url'   => self::META_CTA_URL,
			'product_one' => self::META_PRODUCT_ONE,
			'product_two' => self::META_PRODUCT_TWO,
		);
		foreach ( $meta_keys as $field => $meta_key ) {
			$value = get_post_meta( $campaign_id, $meta_key, true );
			if ( '' !== $value ) {
				$defaults[ $field ] = $value;
			}
		}
		return $defaults;
	}

	private function available_products() {
		return get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	private function featured_products( $campaign_id ) {
		$fields    = $this->campaign_fields( $campaign_id );
		$product_ids = array();

		foreach ( array( $fields['product_one'], $fields['product_two'] ) as $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id && 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id ) ) {
				$product_ids[] = $product_id;
			}
		}

		$product_ids = array_values( array_unique( $product_ids ) );
		$recent_ids  = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 2,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'post__not_in'   => $product_ids,
			)
		);

		return array_slice( array_merge( $product_ids, $recent_ids ), 0, 2 );
	}

	private function render_product_cards( $campaign_id ) {
		$cards = array();

		foreach ( $this->featured_products( $campaign_id ) as $product_id ) {
			$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$title      = get_the_title( $product_id );
			$url        = get_permalink( $product_id );
			$image_id   = $product ? $product->get_image_id() : get_post_thumbnail_id( $product_id );
			$gallery_ids = $product ? $product->get_gallery_image_ids() : array();
			if ( ! $image_id && $gallery_ids ) {
				$image_id = $gallery_ids[0];
			}
			$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';
			$price     = $product ? wp_strip_all_tags( $product->get_price_html() ) : '';
			$image     = $image_url ? '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $title ) . '" width="252" style="display:block;width:100%;height:auto;border:0">' : '';

			$cards[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5ddd3"><tr><td>' . $image . '</td></tr><tr><td style="padding:18px 16px 8px;font-family:Georgia,serif;font-size:20px;line-height:1.2">' . esc_html( $title ) . '</td></tr><tr><td style="padding:0 16px 18px;color:#6b635a;font-size:14px">' . esc_html( $price ) . '</td></tr><tr><td style="padding:0 16px 20px"><a href="' . esc_url( $url ) . '" style="color:#1a1612;font-size:11px;font-weight:bold;letter-spacing:1.25px;text-decoration:underline;text-transform:uppercase">Shop now</a></td></tr></table>';
		}

		if ( ! $cards ) {
			return '';
		}

		$first_card  = $cards[0];
		$second_card = isset( $cards[1] ) ? $cards[1] : '';
		return '<tr><td style="padding:10px 44px 32px"><div style="margin:0 0 14px;color:#6b635a;font-size:11px;font-weight:bold;letter-spacing:1.8px;text-transform:uppercase">Featured for you</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td width="50%" valign="top" style="padding:0 8px 0 0">' . $first_card . '</td><td width="50%" valign="top" style="padding:0 0 0 8px">' . $second_card . '</td></tr></table></td></tr>';
	}

	private function replace_tokens( $value, $recipient ) {
		$first_name = $recipient->ID ? get_user_meta( $recipient->ID, 'first_name', true ) : '';
		if ( ! $first_name ) {
			$first_name = $recipient->display_name;
		}
		return strtr(
			$value,
			array(
				'{{first_name}}' => wp_strip_all_tags( $first_name ?: __( 'there', 'salve-marketing-campaigns' ) ),
				'{{site_name}}'  => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			)
		);
	}

	private function unsubscribe_url( $recipient ) {
		$token = hash_hmac( 'sha256', $recipient->ID . '|' . $recipient->user_email, wp_salt( 'nonce' ) );
		return add_query_arg(
			array(
				'salve_unsubscribe' => $recipient->ID,
				'token'             => $token,
			),
			home_url( '/' )
		);
	}

	public function handle_unsubscribe() {
		if ( empty( $_GET['salve_unsubscribe'] ) || empty( $_GET['token'] ) ) {
			return;
		}
		$user_id = absint( $_GET['salve_unsubscribe'] );
		$user    = get_user_by( 'id', $user_id );
		$token   = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		if ( ! $user ) {
			wp_die( esc_html__( 'This unsubscribe link is invalid.', 'salve-marketing-campaigns' ), esc_html__( 'Unsubscribe', 'salve-marketing-campaigns' ), array( 'response' => 404 ) );
		}
		$expected = hash_hmac( 'sha256', $user->ID . '|' . $user->user_email, wp_salt( 'nonce' ) );
		if ( ! hash_equals( $expected, $token ) ) {
			wp_die( esc_html__( 'This unsubscribe link is invalid.', 'salve-marketing-campaigns' ), esc_html__( 'Unsubscribe', 'salve-marketing-campaigns' ), array( 'response' => 403 ) );
		}
		update_user_meta( $user->ID, self::USER_OPT_IN, 'no' );
		update_user_meta( $user->ID, self::USER_UNSUB, 'yes' );
		wp_die( esc_html__( 'You have been unsubscribed from Salve marketing emails.', 'salve-marketing-campaigns' ), esc_html__( 'Unsubscribed', 'salve-marketing-campaigns' ), array( 'response' => 200 ) );
	}

	public function render_consent_field( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$opted_in = 'yes' === get_user_meta( $user->ID, self::USER_OPT_IN, true );
		?>
		<h2><?php esc_html_e( 'Marketing email consent', 'salve-marketing-campaigns' ); ?></h2>
		<table class="form-table"><tr><th><label for="salve_marketing_opted_in"><?php esc_html_e( 'Email updates', 'salve-marketing-campaigns' ); ?></label></th><td><label><input type="checkbox" id="salve_marketing_opted_in" name="salve_marketing_opted_in" value="yes" <?php checked( $opted_in ); ?>> <?php esc_html_e( 'This user has opted in to receive marketing emails.', 'salve-marketing-campaigns' ); ?></label></td></tr></table>
		<?php
	}

	public function save_consent_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$opted_in = ! empty( $_POST['salve_marketing_opted_in'] ) ? 'yes' : 'no';
		update_user_meta( $user_id, self::USER_OPT_IN, $opted_in );
		if ( 'yes' === $opted_in ) {
			delete_user_meta( $user_id, self::USER_UNSUB );
		}
	}

	private function verified_campaign_request( $nonce_action_prefix ) {
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		if ( ! $campaign_id || ! current_user_can( 'edit_post', $campaign_id ) || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action_prefix . $campaign_id ) ) {
			wp_die( esc_html__( 'You are not allowed to run this campaign action.', 'salve-marketing-campaigns' ), esc_html__( 'Campaign action', 'salve-marketing-campaigns' ), array( 'response' => 403 ) );
		}
		return $campaign_id;
	}

	private function launch_url( $campaign_id, $notice = '' ) {
		$url = add_query_arg( array( 'post_type' => self::POST_TYPE, 'page' => 'salve-campaign-launch', 'campaign_id' => $campaign_id ), admin_url( 'edit.php' ) );
		return $notice ? add_query_arg( 'salve_notice', $notice, $url ) : $url;
	}

	private function redirect_with_notice( $campaign_id, $notice ) {
		wp_safe_redirect( $this->launch_url( $campaign_id, $notice ) );
		exit;
	}

	private function render_notice() {
		if ( empty( $_GET['salve_notice'] ) ) {
			return;
		}
		$notices = array(
			'test_sent'             => array( 'success', __( 'Test email sent to your account email address.', 'salve-marketing-campaigns' ) ),
			'test_failed'           => array( 'error', __( 'WordPress could not send the test email. Check your mail/SMTP configuration.', 'salve-marketing-campaigns' ) ),
			'queued'                => array( 'success', __( 'Campaign queued. Delivery will run in small background batches.', 'salve-marketing-campaigns' ) ),
			'confirmation_required' => array( 'error', __( 'Please confirm the marketing consent statement before launching.', 'salve-marketing-campaigns' ) ),
			'campaign_incomplete'   => array( 'error', __( 'Add an email subject, heading, and message before launching.', 'salve-marketing-campaigns' ) ),
			'no_recipients'         => array( 'error', __( 'There are no opted-in recipients yet.', 'salve-marketing-campaigns' ) ),
		);
		$key = sanitize_key( wp_unslash( $_GET['salve_notice'] ) );
		if ( ! isset( $notices[ $key ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $key ][0] ), esc_html( $notices[ $key ][1] ) );
	}
}

new Salve_Marketing_Campaigns();

/**
 * A deliberately non-payment deposit record for the one product requested by
 * the merchant. Cash on Delivery cannot collect funds at checkout, so this
 * records the split transparently and never represents it as an online charge.
 */
final class Salve_Deposit_Simulation {
	const PRODUCT_SKU        = 'ORB-OXIDE-01';
	const CART_FULL_PRICE    = 'salve_deposit_full_unit_price';
	const ITEM_FLAG          = '_salve_deposit_simulation';
	const ITEM_BALANCE       = '_salve_deposit_balance_due';
	const ORDER_FLAG         = '_salve_deposit_simulation';
	const ORDER_DEPOSIT      = '_salve_deposit_due_now';
	const ORDER_BALANCE      = '_salve_deposit_balance_due';
	const ORDER_BALANCE_DUE  = '_salve_deposit_balance_status';

	public function __construct() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_deposit_price' ), 100 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'limit_deposit_orders_to_cod' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_deposit_line_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'record_deposit_order_meta' ) );
		add_action( 'init', array( $this, 'register_shipped_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_shipped_status' ) );
		add_action( 'woocommerce_order_status_shipped', array( $this, 'mark_balance_due_on_shipment' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_deposit_details' ) );
	}

	public function apply_deposit_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $this->is_deposit_product( $cart_item['data'] ) ) {
				continue;
			}
			$full_price = isset( $cart_item[ self::CART_FULL_PRICE ] ) ? (float) $cart_item[ self::CART_FULL_PRICE ] : (float) $cart_item['data']->get_price( 'edit' );
			if ( $full_price <= 0 ) {
				continue;
			}
			$cart->cart_contents[ $cart_item_key ][ self::CART_FULL_PRICE ] = $full_price;
			$cart->cart_contents[ $cart_item_key ]['data']->set_price( wc_format_decimal( $full_price / 2 ) );
		}
	}

	public function limit_deposit_orders_to_cod( $gateways ) {
		if ( ! $this->cart_has_deposit_product() ) {
			return $gateways;
		}
		return isset( $gateways['cod'] ) ? array( 'cod' => $gateways['cod'] ) : array();
	}

	public function add_deposit_line_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['data'] ) || ! $this->is_deposit_product( $values['data'] ) ) {
			return;
		}
		$amount = (float) $item->get_total() + (float) $item->get_total_tax();
		$item->add_meta_data( self::ITEM_FLAG, 'yes', true );
		$item->add_meta_data( self::ITEM_BALANCE, wc_format_decimal( $amount ), true );
		$item->add_meta_data( __( 'Deposit simulation', 'salve-marketing-campaigns' ), __( '50% by Cash on Delivery; remaining 50% recorded for manual collection after shipment.', 'salve-marketing-campaigns' ), true );
	}

	public function record_deposit_order_meta( $order ) {
		$deposit_due = 0.0;
		$balance_due = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( 'yes' !== $item->get_meta( self::ITEM_FLAG, true ) ) {
				continue;
			}
			$deposit_due += (float) $item->get_total() + (float) $item->get_total_tax();
			$balance_due += (float) $item->get_meta( self::ITEM_BALANCE, true );
		}
		if ( $deposit_due <= 0 ) {
			return;
		}
		$order->update_meta_data( self::ORDER_FLAG, 'yes' );
		$order->update_meta_data( self::ORDER_DEPOSIT, wc_format_decimal( $deposit_due ) );
		$order->update_meta_data( self::ORDER_BALANCE, wc_format_decimal( $balance_due ) );
		$order->update_meta_data( self::ORDER_BALANCE_DUE, 'pending_shipment' );
		$order->add_order_note( sprintf( __( 'Deposit simulation: %1$s is due by Cash on Delivery. A separate %2$s balance is recorded for manual collection after shipment. No online payment was collected.', 'salve-marketing-campaigns' ), wp_strip_all_tags( wc_price( $deposit_due, array( 'currency' => $order->get_currency() ) ) ), wp_strip_all_tags( wc_price( $balance_due, array( 'currency' => $order->get_currency() ) ) ) ) );
		$order->save();
	}

	public function register_shipped_status() {
		register_post_status( 'wc-shipped', array( 'label' => _x( 'Shipped', 'Order status', 'salve-marketing-campaigns' ), 'public' => true, 'exclude_from_search' => false, 'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true, 'label_count' => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'salve-marketing-campaigns' ) ) );
	}

	public function add_shipped_status( $statuses ) {
		$updated = array();
		foreach ( $statuses as $status => $label ) {
			$updated[ $status ] = $label;
			if ( 'wc-processing' === $status ) {
				$updated['wc-shipped'] = _x( 'Shipped', 'Order status', 'salve-marketing-campaigns' );
			}
		}
		return $updated;
	}

	public function mark_balance_due_on_shipment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( self::ORDER_FLAG, true ) || 'due' === $order->get_meta( self::ORDER_BALANCE_DUE, true ) ) {
			return;
		}
		$balance_due = (float) $order->get_meta( self::ORDER_BALANCE, true );
		$order->update_meta_data( self::ORDER_BALANCE_DUE, 'due' );
		$order->add_order_note( sprintf( __( 'Your shipment is on its way. The remaining %s balance is due for manual collection. This is a deposit simulation; no automatic payment has been taken.', 'salve-marketing-campaigns' ), wp_strip_all_tags( wc_price( $balance_due, array( 'currency' => $order->get_currency() ) ) ) ), true );
		$order->save();
	}

	public function render_order_deposit_details( $order ) {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( self::ORDER_FLAG, true ) ) {
			return;
		}
		$deposit = (float) $order->get_meta( self::ORDER_DEPOSIT, true );
		$balance = (float) $order->get_meta( self::ORDER_BALANCE, true );
		$status  = $order->get_meta( self::ORDER_BALANCE_DUE, true );
		?>
		<div class="order_data_column" style="clear:both;float:none;width:auto;margin-top:18px;padding:14px;background:#f6f7f7;border-left:4px solid #2271b1">
			<h3 style="margin-top:0"><?php esc_html_e( '50% deposit simulation', 'salve-marketing-campaigns' ); ?></h3>
			<p><?php printf( esc_html__( 'Cash on Delivery amount: %s', 'salve-marketing-campaigns' ), wp_kses_post( wc_price( $deposit, array( 'currency' => $order->get_currency() ) ) ) ); ?><br>
			<?php printf( esc_html__( 'Manual balance: %s', 'salve-marketing-campaigns' ), wp_kses_post( wc_price( $balance, array( 'currency' => $order->get_currency() ) ) ) ); ?><br>
			<?php echo 'due' === $status ? esc_html__( 'Balance status: due after shipment', 'salve-marketing-campaigns' ) : esc_html__( 'Balance status: pending shipment', 'salve-marketing-campaigns' ); ?></p>
		</div>
		<?php
	}

	private function cart_has_deposit_product() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['data'] ) && $this->is_deposit_product( $cart_item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_deposit_product( $product ) {
		return $product instanceof WC_Product && self::PRODUCT_SKU === $product->get_sku();
	}
}

new Salve_Deposit_Simulation();

add_action( 'plugins_loaded', 'salve_register_stripe_test_gateway', 20 );
add_action( 'template_redirect', 'salve_handle_stripe_test_return' );
add_filter( 'woocommerce_payment_gateways', 'salve_add_stripe_test_gateway' );
add_filter( 'woocommerce_available_payment_gateways', 'salve_add_stripe_test_to_available_gateways', 5 );

/**
 * Registers a hosted Stripe Checkout gateway. It deliberately accepts only
 * Stripe test keys and keeps card entry entirely on Stripe's hosted page.
 */
function salve_register_stripe_test_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Salve_Stripe_Test_Gateway' ) ) {
		return;
	}

	class Salve_Stripe_Test_Gateway extends WC_Payment_Gateway {
		const ID         = 'salve_stripe_test';
		const SECRET_KEY = 'salve_stripe_test_secret_key';

		public function __construct() {
			$this->id                 = self::ID;
			$this->method_title       = __( 'Stripe test payments', 'salve-marketing-campaigns' );
			$this->method_description = __( 'Hosted Stripe Checkout using test credentials only.', 'salve-marketing-campaigns' );
			$this->title              = __( 'Card — Stripe test mode', 'salve-marketing-campaigns' );
			$this->description        = __( 'Use a Stripe test card. No live payment is collected.', 'salve-marketing-campaigns' );
			$this->enabled            = 'yes';
			$this->has_fields         = false;
			$this->supports           = array( 'products' );
		}

		public function is_available() {
			return parent::is_available();
		}

		public function process_payment( $order_id ) {
			$order      = wc_get_order( $order_id );
			$secret_key = $this->secret_key();
			if ( ! $order || ! $secret_key ) {
				wc_add_notice( __( 'Stripe test payments are not configured.', 'salve-marketing-campaigns' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$amount = wc_add_number_precision( $order->get_total() );
			if ( $amount <= 0 ) {
				wc_add_notice( __( 'Stripe test payments require a positive order total.', 'salve-marketing-campaigns' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$return_url = add_query_arg(
				array(
					'salve_stripe_return' => '1',
					'order_id'            => $order->get_id(),
					'key'                 => $order->get_order_key(),
				),
				home_url( '/' )
			) . '&session_id={CHECKOUT_SESSION_ID}';
			$response = wp_remote_post(
				'https://api.stripe.com/v1/checkout/sessions',
				array(
					'timeout' => 45,
					'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
					'body'    => array(
						'mode'                                           => 'payment',
						'payment_method_types[0]'                        => 'card',
						'success_url'                                    => $return_url,
						'cancel_url'                                     => add_query_arg( 'stripe_cancelled', '1', home_url( '/checkout/' ) ),
						'client_reference_id'                            => (string) $order->get_id(),
						'customer_email'                                 => $order->get_billing_email(),
						'metadata[order_id]'                             => (string) $order->get_id(),
						'metadata[order_key]'                            => $order->get_order_key(),
						'payment_intent_data[metadata][order_id]'         => (string) $order->get_id(),
						'line_items[0][price_data][currency]'            => strtolower( $order->get_currency() ),
						'line_items[0][price_data][unit_amount]'         => $amount,
						'line_items[0][price_data][product_data][name]'  => sprintf( __( '%s order #%s', 'salve-marketing-campaigns' ), get_bloginfo( 'name' ), $order->get_order_number() ),
						'line_items[0][quantity]'                        => 1,
					),
				)
			);
			$session = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : array();
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 || empty( $session['id'] ) || empty( $session['url'] ) ) {
				$order->add_order_note( __( 'Stripe test Checkout session could not be created.', 'salve-marketing-campaigns' ) );
				wc_add_notice( __( 'Stripe could not start the test checkout. Please try again.', 'salve-marketing-campaigns' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( '_salve_stripe_test_session_id', sanitize_text_field( $session['id'] ) );
			$order->add_order_note( __( 'Stripe test Checkout session created. Awaiting payment confirmation.', 'salve-marketing-campaigns' ) );
			$order->save();
			return array( 'result' => 'success', 'redirect' => esc_url_raw( $session['url'] ) );
		}

		private function secret_key() {
			return (string) get_option( self::SECRET_KEY, '' );
		}
	}

}

function salve_add_stripe_test_gateway( $gateways ) {
	salve_register_stripe_test_gateway();
	if ( class_exists( 'Salve_Stripe_Test_Gateway' ) ) {
		$gateways[] = 'Salve_Stripe_Test_Gateway';
	}
	return $gateways;
}

function salve_add_stripe_test_to_available_gateways( $gateways ) {
	salve_register_stripe_test_gateway();
	if ( class_exists( 'Salve_Stripe_Test_Gateway' ) ) {
		$gateway = new Salve_Stripe_Test_Gateway();
		if ( $gateway->is_available() ) {
			$gateways[ $gateway->id ] = $gateway;
		}
	}
	return $gateways;
}

function salve_handle_stripe_test_return() {
	if ( '1' !== ( $_GET['salve_stripe_return'] ?? '' ) ) {
		return;
	}
	$order_id   = absint( $_GET['order_id'] ?? 0 );
	$order_key  = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
	$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );
	$order      = $order_id ? wc_get_order( $order_id ) : false;
	if ( ! $order || ! $session_id || ! hash_equals( $order->get_order_key(), $order_key ) || ! hash_equals( (string) $order->get_meta( '_salve_stripe_test_session_id', true ), $session_id ) ) {
		wp_die( esc_html__( 'The Stripe test payment return could not be verified.', 'salve-marketing-campaigns' ), esc_html__( 'Payment verification', 'salve-marketing-campaigns' ), array( 'response' => 403 ) );
	}

	$secret_key = (string) get_option( 'salve_stripe_test_secret_key', '' );
	$response   = wp_remote_get( 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $session_id ), array( 'timeout' => 45, 'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ) ) );
	$session    = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : array();
	$amount     = wc_add_number_precision( $order->get_total() );
	if ( is_wp_error( $response ) || 'paid' !== ( $session['payment_status'] ?? '' ) || (string) $order->get_id() !== (string) ( $session['metadata']['order_id'] ?? '' ) || $amount !== (int) ( $session['amount_total'] ?? -1 ) ) {
		wp_die( esc_html__( 'Stripe has not confirmed this test payment.', 'salve-marketing-campaigns' ), esc_html__( 'Payment pending', 'salve-marketing-campaigns' ), array( 'response' => 402 ) );
	}

	if ( ! $order->is_paid() ) {
		$order->payment_complete( sanitize_text_field( $session['payment_intent'] ?? $session_id ) );
		$order->add_order_note( __( 'Stripe test payment confirmed after hosted Checkout.', 'salve-marketing-campaigns' ) );
	}
	wp_safe_redirect( add_query_arg( 'key', $order->get_order_key(), home_url( '/order-confirmation/' . $order->get_id() ) ) );
	exit;
}
