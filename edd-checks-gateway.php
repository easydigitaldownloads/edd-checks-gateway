<?php
/*
Plugin Name: Easy Digital Downloads - Check Payment Gateway
Plugin URL: http://easydigitaldownloads.com/extension/checks-gateway
Description: Adds a payment gateway for accepting manual payments through hand-written Checks
Version: 1.3.2
Author: Easy Digital Downloads Team
Author URI: http://pippinsplugins.com
Contributors: mordauk
Text Domain: eddcg
Domain Path: languages
*/

if ( ! defined( 'EDDCG_PLUGIN_DIR' ) ) {
	define( 'EDDCG_PLUGIN_DIR', dirname( __FILE__ ) );
}

if ( class_exists( 'EDD_License' ) && is_admin() ) {
	$edd_checks_license = new EDD_License( __FILE__, 'Check Payment Gateway', '1.3.2', 'Easy Digital Downloads', 'eddcg_license_key' );
}

/**
 * Internationalization
 *
 * @access      public
 * @since       1.2
 * @return      void
 */
function edd_textdomain() {
	load_plugin_textdomain( 'eddcg', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'edd_textdomain' );


/**
 * Register the payment gateway
 *
 * @since  1.0
 * @return array
 */
function eddcg_register_gateway($gateways) {
	// Format: ID => Name
	$gateways['checks'] = array( 'admin_label' => 'Checks', 'checkout_label' => __( 'Check', 'eddcg' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'eddcg_register_gateway' );


/**
 * Disables the automatic marking of abandoned orders
 * Marking pending payments as abandoned could break manual check payments
 *
 * @since  1.1
 * @return void
 */
function eddcg_disable_abandoned_orders() {
	remove_action( 'edd_weekly_scheduled_events', 'edd_mark_abandoned_orders' );
}
add_action( 'plugins_loaded', 'eddcg_disable_abandoned_orders' );


/**
 * Add our payment instructions to the checkout
 *
 * @since  1.o
 * @return void
 */
function eddcg_payment_cc_form() {
	global $edd_options;
	ob_start(); ?>
	<?php do_action( 'edd_before_check_info_fields' ); ?>
	<fieldset id="edd_check_payment_info">
		<?php
		$settings_url = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways' );
		$notes = ! empty( $edd_options['eddcg_checkout_notes'] ) ? $edd_options['eddcg_checkout_notes'] : sprintf( __( 'Please enter checkout instructions in the %s settings for paying by check.', 'eddcg' ), '<a href="' . $settings_url . '">' . __( 'Payment Gateway', 'eddcg' ) . '</a>' );
		echo wpautop( stripslashes( $notes ) );
		?>
	</fieldset>
	<?php do_action( 'edd_after_check_info_fields' ); ?>
	<?php
	echo ob_get_clean();
}
add_action( 'edd_checks_cc_form', 'eddcg_payment_cc_form' );


/**
 * Process the payment
 *
 * @since  1.0
 * @return void
 */
function eddcg_process_payment($purchase_data) {

	global $edd_options;

	$purchase_summary = edd_get_purchase_summary( $purchase_data );

	// setup the payment details
	$payment = array(
		'price' 		=> $purchase_data['price'],
		'date' 			=> $purchase_data['date'],
		'user_email' 	=> $purchase_data['user_email'],
		'purchase_key' 	=> $purchase_data['purchase_key'],
		'currency' 		=> edd_get_currency(),
		'downloads' 	=> $purchase_data['downloads'],
		'cart_details' 	=> $purchase_data['cart_details'],
		'user_info' 	=> $purchase_data['user_info'],
		'status' 		=> 'pending',
	);

	// record the pending payment
	$payment = edd_insert_payment( $payment );

	if ( $payment ) {
		eddcg_send_admin_notification_email( $payment );
		add_filter( 'edd_email_show_links', '__return_false' );
		eddcg_send_payment_instructions_email( $payment );
		add_filter( 'edd_email_show_links', '__return_true' );
		edd_empty_cart();
		edd_send_to_success_page();
	} else {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}

}
add_action( 'edd_gateway_checks', 'eddcg_process_payment' );


/**
 * Sends the payment instructions email to the customer
 *
 * @since 1.3
 * @return void
 */
function eddcg_send_payment_instructions_email( $payment_id = 0 ) {

	$email_body = edd_get_option( 'eddcg_pending_email' );

	if ( empty ( $email_body ) ) {
		return;
	}

	$email_body = edd_do_email_tags( $email_body, $payment_id );

	$subject = edd_do_email_tags( edd_get_option( 'eddcg_pending_email_subject' , __( 'Your purchase is pending payment', 'eddcg' ) ), $payment_id );

	$user_info = edd_get_payment_meta_user_info( $payment_id );

	EDD()->emails->heading = edd_do_email_tags( edd_get_option( 'eddcg_pending_email_heading', false ), $payment_id );

	EDD()->emails->send( $user_info['email'], $subject, $email_body );
}


/**
 * Sends the admin notification email
 *
 * @since 1.3.3
 * @return void
 */
function eddcg_send_admin_notification_email( $payment_id = 0 ) {

	$email_body = edd_get_option( 'eddcg_admin_email' );

	if ( empty ( $email_body ) ) {
		return;
	}

	$email_body = edd_do_email_tags( $email_body, $payment_id );

	$subject = edd_do_email_tags( edd_get_option( 'eddcg_admin_email_subject' , __( 'New pending payment', 'eddcg' ) ), $payment_id );

	$user_info = edd_get_payment_meta_user_info( $payment_id );

	EDD()->emails->heading = edd_do_email_tags( edd_get_option( 'eddcg_admin_email_heading', false ), $payment_id );

	EDD()->emails->send( $user_info['email'], $subject, $email_body );
}


/**
 * Register gateway settings
 *
 * @since  1.0
 * @return array
 */
function eddcg_add_settings($settings) {

	$check_settings = array(
		array(
			'id'      => 'check_payment_settings',
			'name'    => '<strong>' . __( 'Check Payment Settings', 'eddcg' ) . '</strong>',
			'desc'    => __( 'Configure the Check Payment settings', 'eddcg' ),
			'type'    => 'header',
		),
		array(
			'id'      => 'eddcg_checkout_notes',
			'name'    => __( 'Check Payment Instructions', 'eddcg' ),
			'desc'    => sprintf( __( 'Enter the instructions you want to show to the buyer during the checkout process here. This should probably include your mailing address and who to make the check out to. Configure email settings <a href="%s">here</a>.', 'eddcg' ), esc_url( admin_url( '/edit.php?post_type=download&page=edd-settings&tab=emails' ) ) ),
			'type'    => 'rich_editor',
		),
	);

	if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
		$check_settings = array( 'checks' => $check_settings );
	}

	return array_merge( $settings, $check_settings );
}
add_filter( 'edd_settings_gateways', 'eddcg_add_settings' );


/**
 * Registers the email settings
 *
 * @since 1.3
 * @return array
 */
function eddcg_add_email_settings( $settings ) {
	$email_settings = array(
		array(
			'id'      => 'check_payment_instructions_settings',
			'name'    => '<strong>' . __( 'Check Payment Instructions', 'eddcg' ) . '</strong>',
			'desc'    => __( 'Configure the Check Payment settings', 'eddcg' ),
			'type'    => 'header',
		),
		array(
			'id'      => 'eddcg_pending_email_subject',
			'name'    => __( 'Payment Instructions Email Subject', 'eddcg' ),
			'desc'    => __( 'The subject line for the Payment Instructions Email.', 'eddcg' ),
			'type'    => 'text',
		),
		array(
			'id'      => 'eddcg_pending_email_heading',
			'name'    => __( 'Payment Instructions Email Heading', 'eddcg' ),
			'desc'    => __( 'The heading for the Payment Instructions Email body.', 'eddcg' ),
			'type'    => 'text',
		),
		array(
			'id'      => 'eddcg_pending_email',
			'name'    => __( 'Payment Instructions Email Body', 'eddcg' ),
			'desc'    => sprintf( __( 'Enter the instructions you want to email to the buyer after the checkout process. This should probably include your mailing address and who to make the check out to. Available template tags: <br> %s ', 'eddcg' ), edd_get_emails_tags_list() ),
			'type'    => 'rich_editor',
		),
		array(
			'id'      => 'check_payment_admin_email',
			'name'    => '<strong>' . __( 'Check Payment Admin Notification', 'eddcg' ) . '</strong>',
			'desc'    => __( 'Configure the Check Admin Notification settings', 'eddcg' ),
			'type'    => 'header',
		),
		array(
			'id'      => 'eddcg_admin_email_subject',
			'name'    => __( 'Admin Notification Email Subject', 'eddcg' ),
			'desc'    => __( 'The subject line for the Admin Notification Email.', 'eddcg' ),
			'type'    => 'text',
		),
		array(
			'id'      => 'eddcg_admin_email_heading',
			'name'    => __( 'Admin Notification Email Heading', 'eddcg' ),
			'desc'    => __( 'The heading for the Admin Notification Email body.', 'eddcg' ),
			'type'    => 'text',
		),
		array(
			'id'      => 'eddcg_admin_email',
			'name'    => __( 'Admin Notification Email Body', 'eddcg' ),
			'desc'    => sprintf( __( 'Enter the information you want provided to admin about the new pending purchase. Available template tags: <br> %s ', 'eddcg' ), edd_get_emails_tags_list() ),
			'type'    => 'rich_editor',
		),
	);

	if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
		$email_settings = array( 'checks' => $email_settings );
	}

	return array_merge( $settings, $email_settings );
}
add_filter( 'edd_settings_emails', 'eddcg_add_email_settings' );


/**
 * Registers the settings section for EDD 2.5+
 */
function eddcg_add_settings_section( $sections ) {
	$sections['checks'] = __( 'Checks', 'eddcg' );
	return $sections;
}
add_filter( 'edd_settings_sections_gateways', 'eddcg_add_settings_section' );
add_filter( 'edd_settings_sections_emails', 'eddcg_add_settings_section' );