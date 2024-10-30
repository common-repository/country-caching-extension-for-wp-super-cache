<?php
// Wordpress Dashboard WPSC Settings (under Advanced) check "Legacy page caching" and check "Late init"

function cca_enable_geoip_cachekey($key) {

if (!defined('CCA_MAXMIND_DATA_DIR')) define('CCA_MAXMIND_DATA_DIR', "ccaMaxDataDir-Replace");
if (!defined('CCA_MAX_FILENAME')) define('CCA_MAX_FILENAME', 'GeoData-Replace');

$just_these = array();  // the list of country codes to be separately cached, this string is replaced/generated by the plugin 
$ccwpsc_maxmindDirectory = "ccwpscMaxDirReplace"; // location of Maxmind script, this string is replaced/generated by the plugin 
$my_ccgroup = array();  // the list of country codes to cached as a group


// return info requested by Country Caching plugin
if ($key == 'cca_version') return 'unknown version';
if ($key == 'cca_options') return implode("," , $just_these);
//		if ($key == 'cca_data') return $ccwpsc_maxmindDataDirectory;
if ($key == 'cca_data') return CCA_MAXMIND_DATA_DIR;
if ($key == 'cca_group') return implode("," , $my_ccgroup);

// return original or modified key to WPSC

  // makes cookie notice work with WPSC
  if (isset($_COOKIE['cookie_notice_accepted']) ) :
      if ( $_COOKIE['cookie_notice_accepted'] == 'true'):
          $key = $key . 'CNt';
      else:
        $key = $key . 'CNf';
      endif;
  endif;

  // makes wpsc work with country geolocation
  if ( isset($GLOBALS['CCA_ISO_CODE']) && $GLOBALS['CCA_ISO_CODE'] == '') return $key;
  if ( ! isset($GLOBALS['CCA_ISO_CODE']) || ! ctype_alnum($GLOBALS['CCA_ISO_CODE']) || ! strlen($GLOBALS['CCA_ISO_CODE']) == 2 ) {   // : cczc_lookupISO($ccwpsc_maxmindDirectory);
    $GLOBALS['CCA_ISO_CODE'] = '';
    if (! function_exists('cca_run_geo_lookup') ) include $ccwpsc_maxmindDirectory . 'cca_lookup_ISO.inc';
    if (function_exists('cca_run_geo_lookup') ) cca_run_geo_lookup($ccwpsc_maxmindDirectory);
    if ($GLOBALS['CCA_ISO_CODE'] == '') return $key;
  }

  //  if both "just_these" and "my_ccgroup" are empty we want to separately cache every country; also separately cache if country is in just_these
  if ( (empty($just_these) && empty($my_ccgroup) ) || in_array($GLOBALS['CCA_ISO_CODE'], $just_these) ) return $key . $GLOBALS['CCA_ISO_CODE'];
  // if country is not specified for sep caching ($just_these, above) but is part of group that should be cached together
  if ( ! empty($my_ccgroup) && in_array($GLOBALS['CCA_ISO_CODE'], $my_ccgroup) ) return $key . 'mygroup';
  if ( empty($just_these)) return $key . $GLOBALS['CCA_ISO_CODE']; // this visitor country is not in the ccgroup entry so separately cache
  
  return $key;  # visitor country and is not one of "just these" or group so use default cache
}
if (function_exists('add_cacheaction')) add_cacheaction( 'wp_cache_key', 'cca_enable_geoip_cachekey' );
?>