<?php
/*
Plugin Name: Country Caching For WP Super Cache
Plugin URI: http://means.us.com
Description: Makes Country GeoLocation work with WP Super Cache 
Author: Andrew Wrigley
Version: 0.8.0
Author URI: http://means.us.com/
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// for update testing (for insertion into currently installed file do not uncomment here) 
/*
require (WP_CONTENT_DIR . '/plugin-update-checker/plugin-update-checker.php');
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
		'http://blog.XXXXXXXX.com/meta_ccwpsc.json',
		__FILE__,
		'country-caching-extension-for-wp-super-cache'
);
*/

define('CCWPSC_PLUGINDIR',plugin_dir_path(__FILE__));

if(require(dirname(__FILE__).'/inc/wp-php53.php')): // TRUE if running PHP v5.3+.
  if( is_admin() ):
	  define('CCWPSC_CALLING_SCRIPT', __FILE__);
    include_once(CCWPSC_PLUGINDIR . 'country_cache_wpsc.php');
  endif;
	require_once 'country_cache_wpsc.php';
else:
  wp_php53_notice('Country Caching for WPSC');
endif;
?>