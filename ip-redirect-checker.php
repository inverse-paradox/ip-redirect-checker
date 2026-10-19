<?php
/**
 * Plugin Name: IP Redirect Checker
 * Description: Disables WordPress canonical redirects and tests lists of URLs from Tools > Redirect Checker.
 * Version: 1.0.0
 * Author: Inverse Paradox
 * Requires PHP: 7.4
 * Text Domain: ip-redirect-checker
 *
 * @package IP_Redirect_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IP_REDIRECT_CHECKER_FILE', __FILE__ );
define( 'IP_REDIRECT_CHECKER_VERSION', '1.0.0' );

require_once __DIR__ . '/class-ip-redirect-checker.php';

$ip_redirect_checker = new IP_Redirect_Checker();
