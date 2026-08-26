<?php
/**
 * Plugin Name: Salve Marketing Campaigns
 * Description: Create editable marketing email campaigns, preview them, send a test, and launch consent-based batches with unsubscribe support.
 * Version: 1.0.0
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
	const META_OFFSET     = '_salve_campaign_offset';
	const META_SENT       = '_salve_campaign_sent';
	const META_FAILED     = '_salve_campaign_failed';
	const USER_OPT_IN     = 'salve_marketing_opted_in';
	const USER_UNSUB      = 'salve_marketing_unsubscribed';
	const BATCH_SIZE      = 10;

	public function __construct() {
		add_action( 'init', array( $this, 'register_campaign_post_type' ) );
		add_filter( 'default_content', array( $this, 'default_campaign_content' ), 10, 2 );
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
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
			)
		);
	}

	public function default_campaign_content( $content, $post ) {
		if ( self::POST_TYPE !== $post->post_type || '' !== $content ) {
			return $content;
		}

		return '<p>Hello {{first_name}},</p><p>We have something considered especially for you.</p><p>Warmly,<br>Salve</p>';
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
		$subject = get_post_meta( $post->ID, self::META_SUBJECT, true );
		$status  = get_post_meta( $post->ID, self::META_STATUS, true ) ?: 'draft';
		wp_nonce_field( 'salve_campaign_details', 'salve_campaign_details_nonce' );
		?>
		<p>
			<label for="salve_campaign_subject"><strong><?php esc_html_e( 'Email subject', 'salve-marketing-campaigns' ); ?></strong></label><br>
			<input class="widefat" type="text" id="salve_campaign_subject" name="salve_campaign_subject" value="<?php echo esc_attr( $subject ); ?>" placeholder="<?php esc_attr_e( 'A considered note from Salve', 'salve-marketing-campaigns' ); ?>">
		</p>
		<p><?php esc_html_e( 'Edit the email body in the main editor above. Available personalisation: {{first_name}}, {{site_name}}.', 'salve-marketing-campaigns' ); ?></p>
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
		if ( isset( $_POST['salve_campaign_subject'] ) ) {
			update_post_meta( $post_id, self::META_SUBJECT, sanitize_text_field( wp_unslash( $_POST['salve_campaign_subject'] ) ) );
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
		$subject  = get_post_meta( $campaign_id, self::META_SUBJECT, true );
		if ( ! $campaign || '' === trim( $subject ) || '' === trim( wp_strip_all_tags( $campaign->post_content ) ) ) {
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
		$subject = $this->replace_tokens( get_post_meta( $campaign_id, self::META_SUBJECT, true ), $recipient );
		return wp_mail( $recipient->user_email, $subject, $this->render_email( $campaign, $recipient ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	private function render_email( $campaign, $recipient ) {
		$body     = $this->replace_tokens( wpautop( wp_kses_post( $campaign->post_content ) ), $recipient );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$unsubscribe = $recipient->ID ? $this->unsubscribe_url( $recipient ) : '#';
		return '<!doctype html><html><body style="margin:0;background:#f7f3ed;color:#1a1612;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="padding:32px 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff"><tr><td style="padding:44px 44px 28px;font-family:Georgia,serif;font-size:34px;letter-spacing:-1px">' . esc_html( $site_name ) . '</td></tr><tr><td style="padding:0 44px 36px;font-size:16px;line-height:1.65">' . $body . '</td></tr><tr><td style="padding:24px 44px;background:#f0ebe3;color:#6b635a;font-size:12px;line-height:1.5">You are receiving this because you opted in to marketing from ' . esc_html( $site_name ) . '. <a style="color:#6b635a" href="' . esc_url( $unsubscribe ) . '">Unsubscribe</a></td></tr></table></td></tr></table></body></html>';
	}

	private function replace_tokens( $value, $recipient ) {
		$first_name = get_user_meta( $recipient->ID, 'first_name', true );
		if ( ! $first_name ) {
			$first_name = $recipient->display_name;
		}
		return strtr(
			$value,
			array(
				'{{first_name}}' => esc_html( $first_name ?: __( 'there', 'salve-marketing-campaigns' ) ),
				'{{site_name}}'  => esc_html( get_bloginfo( 'name' ) ),
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
			'campaign_incomplete'   => array( 'error', __( 'Add an email subject and body before launching.', 'salve-marketing-campaigns' ) ),
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
