<?php
/*
Plugin Name: Easy Digital Downloads - Check Payment Gateway
Plugin URL: http://easydigitaldownloads.com/extension/checks-gateway
Description: Adds a payment gateway for accepting manual payments through hand-written Checks
Version: 1.1.1
Author: Pippin Williamson
Author URI: http://pippinsplugins.com
Contributors: mordauk
*/

if(!defined('EDDCG_PLUGIN_DIR')) {
	define('EDDCG_PLUGIN_DIR', dirname(__FILE__));
}
define( 'EDD_CHECK_GATEWAY_STORE_API_URL', 'http://easydigitaldownloads.com' );
define( 'EDD_CHECK_GATEWAY_PRODUCT_NAME', 'Check Payment Gateway' );
define( 'EDD_CHECK_GATEWAY_VERSION', '1.1.1' );


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
add_filter('edd_payment_gateways', 'eddcg_register_gateway');


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
	<?php do_action('edd_before_check_info_fields'); ?>
	<fieldset id="edd_check_payment_info">
		<?php
		$settings_url = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways' );
		$notes = ! empty( $edd_options['eddcg_checkout_notes'] ) ? $edd_options['eddcg_checkout_notes'] : sprintf( __('Please enter checkout instructions in the %s settings for paying by check.', 'eddcg'), '<a href="' . $settings_url . '">' . __('Payment Gateway', 'eddcg') . '</a>' );
		echo wpautop( stripslashes( $notes ) );
		?>
	</fieldset>
	<?php do_action('edd_after_check_info_fields'); ?>
	<?php
	echo ob_get_clean();
}
add_action('edd_checks_cc_form', 'eddcg_payment_cc_form');


/**
 * Process the payment
 *
 * @since  1.0
 * @return void
 */
function eddcg_process_payment($purchase_data) {

	global $edd_options;

	$purchase_summary = edd_get_purchase_summary($purchase_data);

	// setup the payment details
	$payment = array(
		'price' 		=> $purchase_data['price'],
		'date' 			=> $purchase_data['date'],
		'user_email' 	=> $purchase_data['user_email'],
		'purchase_key' 	=> $purchase_data['purchase_key'],
		'currency' 		=> $edd_options['currency'],
		'downloads' 	=> $purchase_data['downloads'],
		'cart_details' 	=> $purchase_data['cart_details'],
		'user_info' 	=> $purchase_data['user_info'],
		'status' 		=> 'pending'
	);

	// record the pending payment
	$payment = edd_insert_payment($payment);

	if( $payment ) {
		edd_cg_send_admin_notice( $payment );
		edd_empty_cart();
		edd_send_to_success_page();
	} else {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	}

}
add_action( 'edd_gateway_checks', 'eddcg_process_payment' );


/**
 * Sends a notice to site admins about the pending sale
 *
 * @since  1.1
 * @return void
 */
function edd_cg_send_admin_notice( $payment_id = 0 ) {

	/* Send an email notification to the admin */
	$admin_email = edd_get_admin_notice_emails();
	$user_info   = edd_get_payment_meta_user_info( $payment_id );

	if ( isset( $user_info['id'] ) && $user_info['id'] > 0 ) {
		$user_data = get_userdata($user_info['id']);
		$name      = $user_data->display_name;
	} elseif ( isset( $user_info['first_name'] ) && isset($user_info['last_name'] ) ) {
		$name = $user_info['first_name'] . ' ' . $user_info['last_name'];
	} else {
		$name = $user_info['email'];
	}

	$amount        = edd_currency_filter( edd_format_amount( edd_get_payment_amount( $payment_id ) ) );

	$admin_subject = apply_filters( 'eddcg_admin_purchase_notification_subject', __( 'New pending purchase', 'eddcg' ), $payment_id );

	$admin_message = __( 'Hello', 'eddcg' ) . "\n\n" . sprintf( __( 'A %s purchase has been made', 'eddcg' ), edd_get_label_plural() ) . ".\n\n";
	$admin_message.= sprintf( __( '%s sold:', 'eddcg' ), edd_get_label_plural() ) .  "\n\n";

	$download_list = '';
	$downloads     = edd_get_payment_meta_downloads( $payment_id );

	if ( is_array( $downloads ) ) {
		foreach ( $downloads as $download ) {
			$title = get_the_title( $download['id'] );
			if ( isset( $download['options'] ) ) {
				if ( isset( $download['options']['price_id'] ) ) {
					$title .= ' - ' . edd_get_price_option_name( $download['id'], $download['options']['price_id'], $payment_id );
				}
			}
			$download_list .= html_entity_decode( $title, ENT_COMPAT, 'UTF-8' ) . "\n";
		}
	}

	$order_url      = admin_url( 'edit.php?post_type=download&page=edd-payment-history&edd-action=view-order-details&id=' . $payment_id );
	$admin_message .= $download_list . "\n";
	$admin_message .= __( 'Purchased by: ', 'eddcg' )   . " " . html_entity_decode( $name, ENT_COMPAT, 'UTF-8' ) . "\n";
	$admin_message .= __( 'Amount: ', 'eddcg' )         . " " . html_entity_decode( $amount, ENT_COMPAT, 'UTF-8' ) . "\n\n";
	$admin_message .= __( 'This is a pending purchase awaiting payment.', 'eddcg' ) . "\n\n";
	$admin_message .= sprintf( __( 'View Order Details: %s.', 'eddcg' ), $order_url ) . "\n\n";
	$admin_message  = apply_filters( 'eddcg_admin_purchase_notification', $admin_message, $payment_id );
	$admin_headers  = apply_filters( 'eddcg_admin_purchase_notification_headers', array(), $payment_id );
	$attachments    = apply_filters( 'eddcg_admin_purchase_notification_attachments', array(), $payment_id );

	wp_mail( $admin_email, $admin_subject, $admin_message, $admin_headers, $attachments );
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
			'name'    => '<strong>' . __('Check Payment Settings', 'eddcg') . '</strong>',
			'desc'    => __('Configure the Check Payment settings', 'eddcg'),
			'type'    => 'header'
		),
		array(
			'id'      => 'eddcg_license_key',
			'name'    => __( 'License Key', 'eddcg' ),
			'desc'    => __( 'Enter your license for the Check Payment Gateway to receive automatic upgrades', 'eddcg' ),
			'type'    => 'license_key',
			'size'    => 'regular',
			'options' => array( 'is_valid_license_option' => 'eddcg_license_key_active' )
		),
		array(
			'id'      => 'eddcg_checkout_notes',
			'name'    => __('Check Payment Instructions', 'eddcg'),
			'desc'    => __('Enter the instructions you want to show to the buyer during the checkout process here. This should probably include your mailing address and who to make the check out to.', 'eddcg'),
			'type'    => 'rich_editor'
		)
	);

	return array_merge( $settings, $check_settings );
}
add_filter( 'edd_settings_gateways', 'eddcg_add_settings' );


/**
 * Activate a license key
 *
 * @since       1.0
 * @return      void
 */
function eddcg_activate_license() {
	global $edd_options;

	if ( ! isset( $_POST['edd_settings_gateways'] ) )
		return;
	if ( ! isset( $_POST['edd_settings_gateways']['eddcg_license_key'] ) )
		return;

	if ( get_option( 'eddcg_license_key_active' ) == 'valid' )
		return;

	$license = sanitize_text_field( $_POST['edd_settings_gateways']['eddcg_license_key'] );

	// data to send in our API request
	$api_params = array(
		'edd_action'=> 'activate_license',
		'license'   => $license,
		'item_name' => urlencode( EDD_CHECK_GATEWAY_PRODUCT_NAME ) // the name of our product in EDD
	);

	// Call the custom API.
	$response = wp_remote_get( add_query_arg( $api_params, EDD_CHECK_GATEWAY_STORE_API_URL ), array( 'timeout' => 15, 'sslverify' => false ) );

	// make sure the response came back okay
	if ( is_wp_error( $response ) )
		return false;

	// decode the license data
	$license_data = json_decode( wp_remote_retrieve_body( $response ) );

	update_option( 'eddcg_license_key_active', $license_data->license );

}
add_action( 'admin_init', 'eddcg_activate_license' );


/**
 * Deactivate a license key
 *
 * @since  1.1
 * @return void
 */
function eddcg_deactivate_license() {
	global $edd_options;

	if ( ! isset( $_POST['edd_settings_gateways'] ) )
		return;

	if ( ! isset( $_POST['edd_settings_gateways']['eddcg_license_key'] ) )
		return;

	// listen for our activate button to be clicked
	if( isset( $_POST['eddcg_license_key_deactivate'] ) ) {
	    // run a quick security check
	    if( ! check_admin_referer( 'eddcg_license_key_nonce', 'eddcg_license_key_nonce' ) )
	      return; // get out if we didn't click the Activate button

	    // retrieve the license from the database
	    $license = trim( $edd_options['eddcg_license_key'] );

	    // data to send in our API request
	    $api_params = array(
	      'edd_action'=> 'deactivate_license',
	      'license'   => $license,
	      'item_name' => urlencode( EDD_CHECK_GATEWAY_PRODUCT_NAME ) // the name of our product in EDD
	    );

	    // Call the custom API.
	    $response = wp_remote_get( add_query_arg( $api_params, EDD_CHECK_GATEWAY_STORE_API_URL ), array( 'timeout' => 15, 'sslverify' => false ) );

	    // make sure the response came back okay
	    if ( is_wp_error( $response ) )
	    	return false;

	    // decode the license data
	    $license_data = json_decode( wp_remote_retrieve_body( $response ) );

	    // $license_data->license will be either "deactivated" or "failed"
	    if( $license_data->license == 'deactivated' )
	    	delete_option( 'eddcg_license_key_active' );

	}
}
add_action( 'admin_init', 'eddcg_deactivate_license' );


/**
 * Registers the new license field type
 *
 * @access      private
 * @since       10
 * @return      void
*/

if( ! function_exists( 'edd_license_key_callback' ) ) {
	function edd_license_key_callback( $args ) {
		global $edd_options;

		if( isset( $edd_options[ $args['id'] ] ) ) { $value = $edd_options[ $args['id'] ]; } else { $value = isset( $args['std'] ) ? $args['std'] : ''; }
		$size = isset( $args['size'] ) && !is_null($args['size']) ? $args['size'] : 'regular';
		$html = '<input type="text" class="' . $args['size'] . '-text" id="edd_settings_' . $args['section'] . '[' . $args['id'] . ']" name="edd_settings_' . $args['section'] . '[' . $args['id'] . ']" value="' . esc_attr( $value ) . '"/>';

		if( 'valid' == get_option( $args['options']['is_valid_license_option'] ) ) {
			$html .= wp_nonce_field( $args['id'] . '_nonce', $args['id'] . '_nonce', false );
			$html .= '<input type="submit" class="button-secondary" name="' . $args['id'] . '_deactivate" value="' . __( 'Deactivate License',  'edd-recurring' ) . '"/>';
		}
		$html .= '<label for="edd_settings_' . $args['section'] . '[' . $args['id'] . ']"> '  . $args['desc'] . '</label>';

		echo $html;
	}
}

/**
 * Plugin updater
 *
 * @access      public
 * @since       1.1
 * @return      void
 */

function eddcg_updater() {

	if ( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
		// load our custom updater
		include dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php';
	}

	global $edd_options;

	// retrieve our license key from the DB
	$eddcg_license_key = isset( $edd_options['eddcg_license_key'] ) ? trim( $edd_options['eddcg_license_key'] ) : '';

	// setup the updater
	$eddcg_updater = new EDD_SL_Plugin_Updater( EDD_CHECK_GATEWAY_STORE_API_URL, __FILE__, array(
			'version'   => EDD_CHECK_GATEWAY_VERSION,   // current version number
			'license'   => $eddcg_license_key, // license key (used get_option above to retrieve from DB)
			'item_name' => EDD_CHECK_GATEWAY_PRODUCT_NAME, // name of this plugin
			'author'   => 'Pippin Williamson'  // author of this plugin
		)
	);
}
add_action( 'admin_init', 'eddcg_updater' );
