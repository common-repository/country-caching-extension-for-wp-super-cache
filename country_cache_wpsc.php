<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define('CCWPSC_DOCUMENTATION', 'http://wptest.means.us.com/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/');
define('CCWPSC_UPGRADE_MSG', "Country Caching has been updated with a fix for a Cookie Notice (CN) caching problem reported on their forums."
 . " If using CN you should re-save your CC settings and clear cache.");
define('CCWPSC_ADDON_VERSION','0.8.0' );
define('CCWPSC_SETTINGS_SLUG', 'ccwpsc-cache-settings');
define('CCWPSC_USUAL_ADDONDIR', WP_CONTENT_DIR . '/plugins/wp-super-cache/plugins');
define('CCWPSC_ADDON_DIR', WP_CONTENT_DIR . '/ccwpsc_plugins');
define('CCWPSC_ADDON_SCRIPT','cca_wpsc_geoip_plugin.php' );
define('CCWPSC_MAXMIND_DIR', CCWPSC_PLUGINDIR . 'maxmind/');
define('CCWPSC_EU_GROUP','BE,BG,CZ,DK,DE,EE,IE,GR,ES,FR,HR,IT,CY,LV,LT,LU,HU,MT,NL,AT,PL,PT,RO,SI,SK,FI,SE,EU,GB' );

// if Super Cache is activated then WPCACHEHOME will be defined
  if (defined('WPCACHEHOME') && realpath(WPCACHEHOME)) { // (if realpath false then dir does not exist, or path is invalid)
define('CCWPSC_WPSC_PRESENT',TRUE);
define('CCWPSC_CONFIG_ADDONDIR', WPCACHEHOME . 'plugins');
  } else {
define('CCWPSC_WPSC_PRESENT',FALSE);
define('CCWPSC_CONFIG_ADDONDIR', CCWPSC_USUAL_ADDONDIR);
  }
  global $cache_enabled; // from WPSC config
  if ( CCWPSC_WPSC_PRESENT && ! empty($cache_enabled)) {
define('CCWPSC_WPSC_ENABLED',TRUE);
  } else {
define('CCWPSC_WPSC_ENABLED',FALSE);
  }


//  **** CONSTANTS SHARED WITH OTHER PLUGINS ****
if (!defined('CCA_MAXMIND_DATA_DIR')) define('CCA_MAXMIND_DATA_DIR', WP_CONTENT_DIR . '/cca_maxmind_data/');
if (!defined('CCA_MAX_FILENAME')) define('CCA_MAX_FILENAME', 'GeoLite2-Country.mmdb');
if (!defined('CCA_CUST_IPVAR_LINK')) define('CCA_CUST_IPVAR_LINK', '<a href="//wptest.means.us.com/cca-customize-server-var-lookup/" target="_blank">');
if (!defined('CCA_CUST_GEO_LINK')) define('CCA_CUST_GEO_LINK', '<a href="//wptest.means.us.com/cca-customizing-country-lookup/" target="_blank">');


// plugin version checking
add_action( 'admin_init', 'ccwpsc_version_mangement' );
function ccwpsc_version_mangement(){  // credit to "thenbrent" www.wpaustralia.org/wordpress-forums/topic/update-plugin-hook/
  $plugin_info = get_plugin_data( CCWPSC_CALLING_SCRIPT , false, false );  // switch to this line if this function is used from an include
  $last_script_ver = get_option('CCWPSC_VERSION');
  if (empty($last_script_ver)):
    // its a new install
    update_option('CCWPSC_VERSION', $plugin_info['Version']);
  else:
    $version_status = version_compare( $plugin_info['Version'] , $last_script_ver);
    // can test if script is later {1}, or earlier {-1} than the previous installed e.g. if ($version_status > 0 &&  version_compare( "0.6.3" , $last_script_ver )  > 0) :
    if ($version_status != 0):
      wp_clear_scheduled_hook( 'country_caching_check_wpsc' );  // Apr 2018 not used in versions post 0.6.0 
      update_option('CCWPSC_VERSION_UPDATE', true);
      update_option('CCWPSC_VERSION', $plugin_info['Version']);
    endif;
  endif;
	if (get_option('CCWPSC_VERSION_UPDATE')) :  // set just now, or previously set and not yet unset by plugin
    if (is_multisite()):
      add_action( 'network_admin_notices', 'ccwpsc_upgrade_notice' );
    else: 
      add_action( 'admin_notices', 'ccwpsc_upgrade_notice' );
    endif;
  endif;
}


// add_actiom applied by  version check
function ccwpsc_upgrade_notice(){
	if (is_multisite()):
	   $admin_suffix = 'network/admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	else:
	   $admin_suffix = 'admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	endif;
  echo '<div class="notice notice-success"><p>' . CCWPSC_UPGRADE_MSG . ' <a href="' . admin_url($admin_suffix) . '">Dismiss message and go to settings.</a></p></div>';
}


if( is_admin() ):
  if ( ! class_exists('CCAmaxmindUpdate') ) include(CCWPSC_PLUGINDIR . 'inc/update_maxmind.php');
  include_once(CCWPSC_PLUGINDIR . 'inc/ccwpsc_settings_form.php');
endif;