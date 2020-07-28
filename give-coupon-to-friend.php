<?php
/**
 * Plugin Name: Give Coupon to Friend
 * Plugin URI: https://github.com/xavierlopez/give-coupon-to-friend
 * Description: Automatically generate a coupon to give to a friend when order is completed.
 * Version: 1.0.0
 * Author: Xavier Lopez
 * Author URI: https://xavierlopez.dev
 * Text domain: give-coupon-to-friend
 * WC requires at least: 3.0.0
 * WC tested up to: 4.3
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Check if WooCommerce is active
 **/
if ( !(in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) ) {
    add_action('admin_notices', function() { ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e("'Give Coupon to Friend' plugin requires WooCommerce. Plugin not activated.'",'give-coupon-to-friend');  ?></p>
        </div>
    <?php });
    return;
}


/*
  * Load translation files
  */
  add_action('plugins_loaded', 'gctf_load_translations');
  function gctf_load_translations() {
	  load_plugin_textdomain('give-coupon-to-friend', false, 'give-coupon-to-friend/languages');
  }
  


/*
* Register submenu admin item under WooCommerce
*/
add_action('admin_menu', 'gctf_register_plugin_settings',99);
function gctf_register_plugin_settings() {
    add_submenu_page( 'woocommerce', 'Give Coupon to Friend', 'Give Coupon to Friend', 'manage_options', 'give-coupon-to-friend', 'gctf_submenu_page_callback' ); 
    
}


/*
* Settings page callback function
*/
function gctf_submenu_page_callback() {

    if (array_key_exists('give-coupon-to-friend',$_POST)) {
        update_option('gctf_text_coupon', $_POST['text_coupon']);
    ?>
       <div id ="settings-error-settings_updated" class="updated settings-error note is-dismissable">
           <strong><?php _e('Settings have been saved.','give-coupon-to-friend');?></strong>
    </div> 
    <?php 
    } 
    
    $text_coupon = get_option('gctf_text_coupon','Give this coupon to a friend:');
    ?>

    <div class="wrap">
    <h1>Give Coupon to Friend Plugin Settings</h1>
    <p> <?php _e('The coupon amount will be 100% of the order total.','give-coupon-to-friend'); ?>
    <form method="post" action="">
        <label for="text_coupon">
        <h3><?php _e('Text to show in Thank You Page and Customer Email','give-coupon-to-friend')?>:</h3>
        <textarea name="text_coupon" class="large-text"> <?php echo $text_coupon ?></textarea>    
        <input type="submit" name="give-coupon-to-friend" class="button button-primary">
    </form>
    </div>
<?php 
} 



/**
* Generate coupon on order complete
*/
add_action( 'woocommerce_checkout_update_order_meta', 'gctf_generate_coupon', 15, 1 );
function gctf_generate_coupon( $order_id ) { 

	$order = wc_get_order( $order_id );
	$amount = $order->get_total(); // Amount
	
	//if order total is less than 1, return
	if ( $amount < 1 ) {
        return;
    }

	$customer_email =  $order->get_billing_email(); // Customer billing email

	$coupon_code = $order_id * rand(100, 999); // Random Code

	// Get a new instance of the WC_Coupon object
	$coupon = new WC_Coupon();

	// Set the necessary coupon data
	$coupon->set_code( $coupon_code );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 1 );
	$coupon->set_individual_use( true );
	$coupon->set_usage_limit( 1 );
	$coupon->set_usage_limit_per_user( 1 );
	$coupon->set_limit_usage_to_x_items( 1 );
	$coupon->set_date_expires( date( "Y-m-d H:i:s", strtotime('2021-07-31') ) );
	$coupon->set_description(__( 'Automatically generated coupon with order number ', 'give-coupon-to-friend' ).$order_id);

	// Save the data
	$post_id = $coupon->save();

	// Add meta to coupon
	update_post_meta( $post_id, 'coupon_amount', $amount );
	update_post_meta( $post_id, 'email_creador', $customer_email );
	// Add meta to order
	update_post_meta( $order_id, 'cupon_generado', $coupon->get_code() );

}; 


/*
* Show coupon on thankyou page.
*/
add_action('woocommerce_thankyou', 'gctf_show_coupon',30,1);
function gctf_show_coupon($order_id) {
    if ( get_post_meta($order_id,'cupon_generado') ) {
        echo "<div id='coupon_thankyou' style='border:2px solid;padding:4em'><h3 id='cupon_amigo'>".get_option('gctf_text_coupon','Give this coupon to a friend:'."<h3>");
        echo "<h1 class='coupon_show'>".get_post_meta($order_id,'cupon_generado',true)."</h1></div>";
    }
};



/*
* Show coupon on New Order email.
*/
add_action( 'woocommerce_email_after_order_table', 'gctf_add_content_specific_email', 20, 4 );
function gctf_add_content_specific_email( $order, $sent_to_admin, $plain_text, $email ) {
    // if it is new order email AND there is a coupon generated on that order
    if ( $email->id == 'customer_completed_order' &&  get_post_meta($order->get_id(),'cupon_generado') )  {
        echo "<div style ='border:1px solid;margin-bottom:2em;padding:2em'><h2 id='cupon_email'>".get_option('gctf_text_coupon','Give this coupon to a friend:')."</h2>";
	    echo "<h1 class='coupon_show'>".get_post_meta($order->get_id(),'cupon_generado',true)."</h1></div>";  
    }
}


/*
* Make user the coupon's creator is not using it (Validation)
*/
add_action('woocommerce_after_checkout_validation', 'gctf_validate_checkout_coupon');
function gctf_validate_checkout_coupon($posted) {	

	if ( !(empty($posted)) && ($posted['billing_email']) ) {
		$billing_email = $posted['billing_email'];
	} else  {
		return;
	}

	// instance of current cart
	$carrito = WC()->cart;
	
	foreach ( $carrito->get_applied_coupons() as $coupon_code ) {
		
		// Retrieving the coupon ID
		$coupon_post_obj = get_page_by_title($coupon_code, OBJECT, 'shop_coupon');
		$coupon_id       = $coupon_post_obj->ID;
		
		// Get an instance of WC_Coupon object in an array(necessary to use WC_Coupon methods)
		$coupon = new WC_Coupon($coupon_id);
		
		// coupon creator
		$creator = get_post_meta($coupon_id, 'email_creador', true);

		//  If the creator is using the coupon, remove coupon (This coupon is a gift for a friend, not for myself!)
		if ($creator == $billing_email) {
			$coupon->add_coupon_message( WC_Coupon::E_WC_COUPON_INVALID_REMOVED );
			$carrito->remove_coupon($coupon_code);
			$carrito->calculate_totals();
		}
	}
}