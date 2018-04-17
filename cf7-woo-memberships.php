<?php
/**
 * Plugin Name:     Contact Form 7 → WooCommerce Memberships
 * Plugin URI:      https://andrewrminion.com/2018/04/cf7-woo-memberships/
 * Description:     Adds Contact Form 7 entries as users (if necessary) and grants them the specified membership.
 * Author:          AndrewRMinion Design
 * Author URI:      https://andrewrminion.com/
 * Text Domain:     cf7-woo-memberships
 * Domain Path:     /languages
 * Version:         1.0.0
 *
 * @package         CF7_Woo_Memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include_once 'includes/class-cf7-woo-memberships.php';
