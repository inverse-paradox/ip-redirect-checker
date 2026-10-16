<?php
/**
 * Plugin Name: IP Redirect Checker
 * Description: Disables WordPress canonical redirects and tests lists of URLs from Tools > Redirect Checker.
 * Version: 1.0
 * Author: Inverse Paradox
 * Requires PHP: 7.4
 * Text Domain: ip-redirect-checker
 *
 * @package IP_Redirect_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Block WordPress canonical redirects when the setting is enabled (on by default).
if ( get_option( 'ip_redirect_checker_block_canonical', true ) ) {
	add_filter( 'redirect_canonical', '__return_false' );
}

require_once __DIR__ . '/class-ip-redirect-checker.php';

add_action( 'admin_menu', array( 'IP_Redirect_Checker', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'IP_Redirect_Checker', 'enqueue_scripts' ) );
add_action( 'wp_ajax_ip_redirect_checker_run', array( 'IP_Redirect_Checker', 'ajax_run' ) );
