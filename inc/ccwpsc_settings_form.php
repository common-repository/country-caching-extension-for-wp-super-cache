<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// determine whether a normal or multisite settings form link is required
$cc_networkadmin = is_network_admin() ? 'network_admin_' : '';
add_filter( $cc_networkadmin . 'plugin_action_links_' . plugin_basename( CCWPSC_CALLING_SCRIPT ), 'ccwpsc_add_sitesettings_link' );
function ccwpsc_add_sitesettings_link( $links ) {
	if (is_multisite()):
	  $admin_suffix = 'network/admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	else:
	  $admin_suffix = 'admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	endif;
	return array_merge(array('settings' => '<a href="' . admin_url($admin_suffix) . '">Country Caching Settings</a>'), $links	);
}

// ensure CSS for dashboard forms is sent to browser
add_action('admin_enqueue_scripts', 'ccwpsc_load_admincssjs');
function ccwpsc_load_admincssjs() {
  if( (! wp_script_is( 'cca-textwidget-style', 'enqueued' )) && $GLOBALS['pagenow'] == 'admin.php' ): wp_enqueue_style( 'cca-textwidget-style', plugins_url( 'css/cca-textwidget.css' , __FILE__ ) ); endif;
}

// automatically display admin notice messages when using the add_menu_page (like WP does for add_options_page 's)
function ccwpsc_admin_notices_action() {  
    settings_errors( 'ccwpsc_group' );
}
if (is_multisite()): add_action( 'network_admin_notices', 'ccwpsc_admin_notices_action' );
else: add_action( 'admin_notices', 'ccwpsc_admin_notices_action' );
endif;

// return permissions of a directory or file as a 4 character "octal" string
function ccwpsc_return_permissions($item) {
 clearstatcache(true, $item);
 $item_perms = @fileperms($item);
return empty($item_perms) ? '' : substr(sprintf('%o', $item_perms), -4);	
}

// ensure WPSC advanced settings for caching mode and cache http headers are correctly set for custom cache keys
$wp_cache_config_file = WP_CONTENT_DIR . '/wp-cache-config.php';
$ccwpsc_option_set = get_option( 'ccwpsc_caching_options' );
if ( $ccwpsc_option_set ) :
  if ($ccwpsc_option_set['caching_mode'] == 'WPSC'):
	  if (isset( $wp_cache_mod_rewrite) && $wp_cache_mod_rewrite == 1): 
		  wp_cache_replace_line('^ *\$wp_cache_mod_rewrite', "\$wp_cache_mod_rewrite = 0;", $wp_cache_config_file);  // simple caching
		endif;
		if (isset( $wpsc_save_headers) && $wpsc_save_headers == 0):
				  wp_cache_replace_line('^ *\$wpsc_save_headers', "\$wpsc_save_headers = 1;", $wp_cache_config_file);  // simple caching
		endif;
	endif;
endif;

// add_action, when needed, provided in constructor below
function ccwpsc_missing_file_notice(){
  if (is_multisite()): $admin_suffix = 'network/admin.php?page=' . CCWPSC_SETTINGS_SLUG;
	 else: $admin_suffix = 'admin.php?page=' . CCWPSC_SETTINGS_SLUG;
  endif;
	echo '<div class="notice notice-error"><p>' . 'It looks like you have recently updated Super Cache and its Country Caching add-on has been deleted.<br>';
	echo ' <a href="' . admin_url($admin_suffix) . '">Either re-save your current Country Caching settings to regenerate the needed add-on; or change its setting to disabled.</a><br>';
	echo 'You can prevent unintended deletion by UN-checking the "<i>Use WPSC default plugin folder</i>" option.</p></div>';
}


// INSTANTIATE OBJECT
$ccwpsc_settings_page = new CCWPSCcountryCache();

/*===============================================
CLASS FOR SETTINGS FORM AND GENERATION OF ADD-ON SCRIPT
================================================*/
class CCWPSCcountryCache {   // everything belyond this point this class
//======================
  private $initial_option_values = array(
	  'activation_status' => 'new',
		'caching_mode' => 'none',
		'wp_cache_plugins_dir' => '',
		'cache_iso_cc' => '',
		'diagnostics' => FALSE,
		'initial_message'=> ''
	);

//	public static $crontime_array = array('hourly'=>'Hourly', 'twicedaily'=>'Twice Daily', 'daily'=>'Daily', 'cca_weekly'=>'Weekly', 'never'=>'NEVER');
	public $options = array();
  public $user_type;
  public $submit_action;
	private $caching_status;
  private $valid_wpsc_custdir;
  private $wpsc_cust_error;

  public function __construct() {
	  register_activation_hook(CCWPSC_CALLING_SCRIPT, array( $this, 'CCWPSC_activate' ) );
		register_deactivation_hook(CCWPSC_CALLING_SCRIPT, array( $this, 'CCWPSC_deactivate'));
		$this->is_plugin_update = get_option( 'CCWPSC_VERSION_UPDATE' );
    $this->maxmind_status = get_option('cc_maxmind_status', array()); // Maxmind is used by other plugins so we store its status in an option
		
		// use stored values or intialize as a first/fresh install
  	if ( get_option ( 'ccwpsc_caching_options' ) ) :
  	  $this->options = get_option ( 'ccwpsc_caching_options' );
		else:
		  $this->options = $this->initial_option_values;
//			$this->options['first_run'] = TRUE;
  	endif;
		
		// pointless setting in $this->initial_option_values as would not be set for existing options created by earlier plugin versions. So do here 
		if (empty($this->options['use_group'])): $this->options['use_group'] = FALSE;	endif;
		if (empty($this->options['my_ccgroup'])) : $this->options['my_ccgroup'] = CCWPSC_EU_GROUP; endif;

		// if WPSC is activated get the current plugin dir location from WPSC's config file
		global $wp_cache_plugins_dir;
		if (isset($wp_cache_plugins_dir)) : $this->options['wp_cache_plugins_dir'] = $wp_cache_plugins_dir; endif;

		update_option( 'ccwpsc_caching_options', $this->options );
		$this->wpsc_cust_error = FALSE;
		$this->valid_wpsc_custdir = FALSE;
		$this->validate_wpsc_custpath();


    //  version 0.7.0 included additonal options and requires manual settings save to rebuild addon script.
		//  subsequent version updates might require automatic add-on rebuild
     if ($this->options['caching_mode'] == 'WPSC' && $this->is_plugin_update) :
		   $this->ensure_maxfile_present();
//         $this->CCWPSC_activate();  // dependent on version
//				 update_option('CCWPSC_VERSION_UPDATE', false);
     endif;

    // check for deletion of add-on by WPSC version update
		if ($this->options['caching_mode'] == 'WPSC' && ! empty($this->options['use_WPSC_dir']) && CCWPSC_WPSC_ENABLED && isset($wp_cache_plugins_dir)) :
    //  clearstatcache(true,$wp_cache_plugins_dir . '/' . CCWPSC_ADDON_SCRIPT);
      if ( ! $this->is_plugin_update && ! file_exists($wp_cache_plugins_dir . '/' . CCWPSC_ADDON_SCRIPT)):
        if (is_multisite()): add_action( 'network_admin_notices', 'ccwpsc_missing_file_notice' );
         else: add_action( 'admin_notices', 'ccwpsc_missing_file_notice' );
    		endif;
    	endif;
    endif;

    // set-up settings menu for single or multisite
    if (is_multisite() ) :
     	    $this->user_type = 'manage_network_options';
          add_action( 'network_admin_menu', array( $this, 'add_plugin_page' ) ); 
    			$this->submit_action = "../options.php";
    else:
    		 $this->user_type = 'manage_options';
         add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
    		 $this->submit_action = "options.php";
    endif;

    add_action( 'admin_init', array( $this, 'page_init' ) );

  } // end construct


	// REMOVE EXTENSION SCRIPT ON DEACTIVATION
  public function CCWPSC_deactivate()   {
    wp_clear_scheduled_hook( 'country_caching_check_wpsc' );  // just to make sure (should have been removed on version update)
		// brute force delete add-on script from all possible known locations
		$this->delete_wpsc_addon();
		$this->options['activation_status'] = 'deactivated';
    update_option( 'ccwpsc_caching_options', $this->options );
  }


	//  ACTIVATION/RE-ACTIVATION
  public function CCWPSC_activate() {
		$this->options['initial_message'] = '';
		if ( $this->options['caching_mode'] == 'WPSC' && ! $this->ensure_maxfile_present() ) :
		  if(empty($_SERVER["HTTP_CF_IPCOUNTRY"])): $this->options['initial_message'] .= __('There was a problem installing new Maxmind2 mmdb file. Click the "CC Status" tab for more info.<br>'); endif;
		endif;
		// if country caching is enabled then rebuild add-on script which is removed on deactivation
    if ( $this->options['caching_mode'] == 'WPSC') :
		   $this->options['activation_status'] = 'activating';
  		 $script_update_result = $this->ccwpsc_build_script( $this->options['cache_iso_cc'], $this->options['use_group'], $this->options['my_ccgroup'] );
  		 if ( empty($this->options['last_output_err']) ) :
  		    $script_update_result = $this->ccwpsc_write_script($script_update_result);
  		 endif;
    	 if ( $script_update_result != 'Done' ) :
   		    $this->options['initial_message'] .=  __('You have reactivated this plugin - however there was a problem rebuilding the Super Cache add-on script: (') . $script_update_result . ')';
			 else: 
			    $this->options['initial_message'] .=  ('Country Caching was re-activated, and the add-on script for WPSC appears to have been rebuilt successfully');
       endif;
  	endif;
		$this->options['activation_status'] = 'activated';
		update_option( 'ccwpsc_caching_options', $this->options );
  }  //  END CCWPSC_activate()


  // Add Country Caching options page to Dashboard->Settings
  public function add_plugin_page() {
    add_menu_page(
          'Country Caching Settings', /* html title tag */
          'WPSC Country Caching', // title (shown in dash->Settings).
          $this->user_type, // 'manage_options', // min user authority
          CCWPSC_SETTINGS_SLUG, // page url slug
          array( $this, 'create_ccwpsc_site_admin_page' ),  //  function/method to display settings menu
  				'dashicons-admin-plugins'
    );
  }

  // Register and add settings
  public function page_init() {        
    register_setting(
      'ccwpsc_group', // group the field is part of 
    	'ccwpsc_caching_options',  // option prefix to name of field
			array( $this, 'sanitize' )
    );
  }


  // THE SETTINGS FORM FRAMEWORK ( callback func specified in add_options_page func)
  public function create_ccwpsc_site_admin_page() {

	  // if site is not using Cloudflare GeoIP warn if Maxmind data is not installled
		if ( empty($_SERVER["HTTP_CF_IPCOUNTRY"]) && ! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) ) :
		  $this->options['initial_message'] .= __('Maxmind country look-up data "GeoLite2-Country.mmdb" needs to be installed. It will be installed automatically if the "Enable WPSC" check box is checked and you save your settings. This may take a few seconds.<br>');
			if (file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat') ) :
			  $this->options['initial_message'] .= __('Out of date Maxmind Legacy files were were installed by an earlier CC version and will continue to be used until you re-save your your settings<br>');
			endif;
    endif;

   // render settings form
?>  <div class="wrap cca-cachesettings">  
      <div id="icon-themes" class="icon32"></div> 
      <h2>WPSC Country Caching</h2>  
<?php 
    if (!empty($this->options['initial_message'])) echo '<div class="cca-msg">' . $this->options['initial_message'] . '</div>';
    $this->options['initial_message'] = '';
		$active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'WPSC';
?>
      <h2 class="nav-tab-wrapper">  
         <a href="?page=<?php echo CCWPSC_SETTINGS_SLUG ?>&tab=WPSC" class="nav-tab <?php echo $active_tab == 'WPSC' ? 'nav-tab-active' : ''; ?>">WPSC Country Cache Settings</a>
				 <a href="?page=<?php echo CCWPSC_SETTINGS_SLUG ?>&tab=CNusers" class="nav-tab <?php echo $active_tab == 'CNusers' ? 'nav-tab-active' : ''; ?>">Cookie Notice users</a>
         <a href="?page=<?php echo CCWPSC_SETTINGS_SLUG ?>&tab=Configuration" class="nav-tab <?php echo $active_tab == 'Configuration' ? 'nav-tab-active' : ''; ?>">CC Status</a>
      </h2>
			<form method="post" action="<?php echo $this->submit_action; ?>">  
<?php 
      settings_fields( 'ccwpsc_group' );
  		if( $active_tab == 'Configuration' ) :
  		    $this->render_config_panel();
   		elseif ($active_tab == 'CNusers'):
   		    $this->render_cookienotice_panel();
  		 else : $this->render_wpsc_panel();
  		endif;
?>
      </form> 
    </div> 
<?php
		 update_option( 'ccwpsc_caching_options', $this->options);
  }  //  END create_ccwpsc_site_admin_page()


  // render the Settings Form Tab for building the Super Cache add-on script
  public function render_wpsc_panel() {
	
	  if (!empty($this->is_plugin_update)) : update_option('CCWPSC_VERSION_UPDATE', false); endif; // we've retrieved its value and displayed msg; re-set option to false
		$this->is_plugin_update = FALSE;

 	  $this->validate_wpsc_custpath();
	?>
	  <div class="cca-brown"><p><?php echo $this->ccwpsc_wpsc_status();?></p></div>
    <hr><h3>Enable Country Caching for WPSC</h3>

		<p><input type="checkbox" id="ccwpsc_use_ccwpsc_wpsc" name="ccwpsc_caching_options[caching_mode]" <?php checked($this->options['caching_mode']=='WPSC'); ?>><label for="ccwpsc_use_ccwpsc_wpsc">
		 <?php _e('Enable Country Caching'); ?></label></p>
		 
		<p  class="cca-indent20"><?php _e("Use WPSC default plugin folder."); ?>&nbsp; <input type="checkbox" id="ccwpsc_no_addon_dir" name="ccwpsc_caching_options[use_WPSC_dir]" <?php
      if (! empty($this->options['use_WPSC_dir']) ) echo 'checked="checked"'; _e("> (<b>NOT</b> recommended; and advised against in WPSC's own guide)"); ?><br>
    <i>See <a href="<?php echo CCWPSC_DOCUMENTATION . '#whycust';?>" target="_blank">the CC Guide</a> for advice on when to use this option and its disadvantages.</i></p>



    <hr><h3><?php _e('Optional Settings to reduce caching overheads'); ?></h3>
		<?php _e('<p><b>Use same cache for most countries:</b></p><p class="cca-indent20">Create unique cache files for these <a href="https://www.iso.org/obp/ui/#search/code/" target="_blank">country codes</a> ONLY:'); ?>
		<input name="ccwpsc_caching_options[cache_iso_cc]" type="text" value="<?php echo $this->options['cache_iso_cc']; ?>" />
		<i>(<?php _e('e.g.');?> "CA,DE,AU")</i><p>
		<p class="cca-indent20"><?php _e("If left empty and group cache (below) is not enabled, then a cached page will be generated for every country from which you've had one or more visitors.");?><br>
		<i><?php _e('Example usage: if you set the field to "CA,AU", separate cache will only be created for Canada and for Australia; "standard" page cache will be used for all other visitors.');?>.</i></p>
		<br><p><b><?php _e('and/or create a single cache for this group of countries'); ?></b></p>
		<p class="cca-indent20"><input type="checkbox" id="ccwpsc_use_group" name="ccwpsc_caching_options[use_group]" <?php checked(!empty($this->options['use_group']));?>><label for="ccwpsc_use_group">
		<?php _e('Enable shared caching for this group:'); ?></label></p>
		<?php if (empty($this->options['my_ccgroup'])):
		  $this->options['my_ccgroup'] = CCWPSC_EU_GROUP;
		endif;
?>
	  <div class="cca-indent20">
  	  <input id="ccwpsc_my_ccgroup" name="ccwpsc_caching_options[my_ccgroup]" type="text" style="width:600px !important" value="<?php echo $this->options['my_ccgroup']; ?>" />
  		  <br><?php _e("Replace with your own list. (Initially contains European Union countries, but no guarantee it is accurate.)");  ?>
		</div>
		<p><i><?php _e('Example 2: You want everyone to see your standard "US content" except visitors from France & Canada. For legal compliance you also display a cookie notice when visitors are from the EU'); ?>
<?php _e('<br><b>How:</b> set the plugin to separately cache "FR,CA", ensure the group box contains all EU codes; and enable shared caching.'); ?></i></p>
<p><i><?php _e('Example 3: You only want 2 separate caches one for Group and one for NOT Group e.g. EU and non-EU: ');
 _e('insert "AX" in the "create unique cache" box, ensure group box contains all EU codes, and enable shared caching.');
 _e( '<br>Result: one cache for EU visitors, a cache for AX (if you ever get a visitor from Aland Islands), ');
 _e( 'and one standard cache seen by your non-EU visitors.'); ?></i></p>

	<input type="hidden" id="ccwpsc_geoip_action" name="ccwpsc_caching_options[action]" value="WPSC" />
<?php
   submit_button('Save these settings','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 

   if($this->options['caching_mode']=='WPSC'):
			  _e('<br><p><i>This plugin includes GeoLite data created by MaxMind, available from <a href="http://www.maxmind.com">http://www.maxmind.com</a>.</i></p>');
	 endif;
  }  // END function render_wpsc_panel


  // render info panel for cokkie notice users 
  public function render_cookienotice_panel() {
  ?>
  <p><b>As well as Country/EU geolocation, this plugin also makes Cookie Notice work correctly with WPSC.<br>
  (read about the issue in these Cookie Notice support forum posts: <a href=https://dfactory.eu/support/topic/caching-and-and-wrong-cookie-state-for-visitor/" target="_blank">Dfactory</a> and 
  <a href="https://wordpress.org/support/topic/compatible-with-w3-total-cache-9/#post-10366731" target="_blank">wordpress.org</a>)</b></p>
  <hr><h3>If you are NOT using country or EU geolocation:</h3>
  <p>On the settings tab:</p>
  <p class="cca-indent20">Tick the <i>Enable Country Caching</i> check box.</p>
  <p class="cca-indent20">Enter "XX" in the box labeled "<i>Create unique cache for these country codes ONLY</i>". <span class="cca-brown">(XX prevents caching by country)</span></p>
  <p class="cca-indent20">Save these settings.</p>
  <p>Clear your cache. From now on, WPSC will create 2 cached versions (cookies allowed & cookies refused) of a page: and in future your visitors will be served the correct version for their settings.</p><br>
  <hr><h3>If you are also using the CCA plugin to prevent Cookie Notice running for non EU countries:</h3>  
  <p>On the settings tab:</p>
  <p class="cca-indent20">Tick the <i>Enable Country Caching</i> check box.</p>
  <p class="cca-indent20">Enter "XX" in the box labeled "<i>Create unique cache for these country codes ONLY</i>". <span class="cca-brown">(unless you are also using country geolocation for other purposes)</span></p>
  <p class="cca-indent20">Tick the <i>Enable shared caching for this group</i> check box.</p>
  <p class="cca-indent20">Ensure the box below it contains the complete list of EU/EEA country codes</p>
  <p class="cca-indent20">Save these settings.</p>
  <p>Clear your cache. From now on, WPSC will create cached pages for non-EU, EU cookies allowed & EU cookies refused; and your visitors will be served the correct version for their settings.</p>
  <?php
  }  //  END function render_cookienotice_panel

	
  // render tab panel for monitoring and diagnostic information
  public function render_config_panel() {
    echo '<input type="hidden" id="ccwpsc_geoip_action" name="ccwpsc_caching_options[action]" value="Configuration" />'; ?>
    <p class="cca-brown"><?php echo __('View the ') . ' <a href="' . CCWPSC_DOCUMENTATION . '" target="_blank">' . __('Country Caching Guide');?></a>.</p>


		<hr><h3>Problem Fixing</h3>
    <p><input id="ccwpsc_force_reset" name="ccwpsc_caching_options[force_reset]" type="checkbox"/>
    <label for="ccwpsc_force_reset"><?php _e("Reset CCA Country Caching to initial values (also removes the country caching add-on script(s) generated for WPSC).");?></label></p>
    <?php submit_button('Reset Now','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); ?>

		<hr><h3>Information about the add-on script being used by Super Cache:</h3>
		<p><input type="checkbox" id="ccwpsc_addon_info" name="ccwpsc_caching_options[addon_data]" ><label for="ccwpsc_addon_info">
 		  <?php _e('Display script data'); ?></label></p>
<?php
    if (!empty($this->options['addon_data'])) :
			$this->options['addon_data'] = '';
			clearstatcache( true, $this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT );
			if ( ! file_exists( $this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT) ) :
			  if (isset($this->options['wpsc_path']) && file_exists($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT) ):
				  echo '<br><span class="cca-brown">' . __('A country caching add-on generated by an earlier version of this plugin is being used. RE-SAVE YOUR CC SETTINGS to generate the new add-on.') . '</span><br>';
				else:
				  echo '<br><span class="cca-brown">' . __('Country Caching is not enabled. The add-on script does not exist.') . '</span><br>';
				endif;
			else:
			  include_once($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT);
  			if ( function_exists('cca_enable_geoip_cachekey') ):
				  $add_on_ver = cca_enable_geoip_cachekey('cca_version');
					if ($add_on_ver != CCWPSC_ADDON_VERSION): $add_on_ver .= ' (<b>This was created with a previous version of Country Caching - YOU SHOULD RE-SAVE YOUR CC SETTINGS to generate new add-on</b>)'; endif; 
						echo '<span class="cca-brown">' . __('Add-on script version: ') . '</span>' . $add_on_ver . '<br>';
  				  $new_codes = cca_enable_geoip_cachekey('cca_options');
						$valid_codes = $this->is_valid_ISO_list($new_codes);
            if ($valid_codes):
          		echo '<span class="cca-brown">' . __('The script is set to separately cache') .  ' "<u>';
          			if (empty($new_codes) ):
          			  echo  __('all countries') . '</u></span>".<br>';
          			else:
          			  echo $new_codes . '</u>"; ' .  __('the standard cache will be used for all other countries.') . '</span><br>';
          			endif;
					    else:
					    echo  __('Add-on script "') . CCWPSC_ADDON_SCRIPT . __(' is present in "') . esc_html($this->options['wp_cache_plugins_dir']) . __('" but has an INVALID Country Code List (values: "') . esc_html($new_codes) . __('") and should be deleted.') . '<br>';
  				   endif;
						 $new_codes = cca_enable_geoip_cachekey('cca_group');
						 if (! empty($new_codes) ):
          	   if (substr($new_codes, 0,9 ) == 'cca_group') :  // addons created by previous plugin versions do not recognise 'options' and will simply return a string starting with 'options'
          			 echo  __('The add-on script was created by a previous version of the Country Caching plugin.<br>It will work, but the latest version allows you to cache countries as a group') . '<br>';
          			 echo __('You can update to the latest add-on script by saving settings again on the "Comet Cache" tab.<br>');
          	   elseif ($this->is_valid_ISO_list($new_codes)):
          	     echo '<span class="cca-brown">' . __('The script is set to create a single cache for this group of countries:') .  '</span> ' . $new_codes . '<br>';
               endif;
             endif;
						 $max_dir = cca_enable_geoip_cachekey('cca_data');
						 if ($max_dir != 'cca_data'): // old script will return argument name
					     echo '<span class="cca-brown">' .__('The script looks for Maxmind data in </span>"') . esc_html($max_dir) . '".<br>';
						 endif;
					else:
					  echo  __('The add-on script "') . CCWPSC_ADDON_SCRIPT . __(' is present in "') . esc_html($this->options['wp_cache_plugins_dir']) . __('" but I am unable to identify its settings.') . '<br>';
					endif;
					if ( CCWPSC_WPSC_PRESENT) :
					  global $wp_cache_plugins_dir; // from WPSC config
					  echo '<b>The following script paths should be the same:</b><br>';
					  echo '&nbsp;&nbsp; WPSC expects this add-on script in: "' . esc_html($wp_cache_plugins_dir) . '"<br>';
					endif;
					echo '&nbsp;&nbsp; CC creates this add-on script here : "' . esc_html($this->options['wp_cache_plugins_dir']) . '"<br>';
			 endif;
		endif;

?>
		<hr><h3>GeoIP Information and Status:</h3>
		<p><input type="checkbox" id="ccwpsc_geoip_info" name="ccwpsc_caching_options[geoip_data]" ><label for="ccwpsc_geoip_info">
 		  <?php _e('Display GeoIP data'); ?></label></p>
<?php
		if ($this->options['geoip_data']) :
			$this->options['geoip_data'] = '';
			if (! function_exists('cca_run_geo_lookup') ) include CCWPSC_MAXMIND_DIR . 'cca_lookup_ISO.inc';
			if ( ! isset($GLOBALS['CCA_ISO_CODE']) || empty($GLOBALS['cca-lookup-msg'])) : // then lookup has not already been done by another plugin
			  cca_run_geo_lookup(CCWPSC_MAXMIND_DIR); // sets global CCA_ISO_CODE and status msg
			endif;
			// as GLOBALS can be set by any process we need to sanitize/format before use
			if (! ctype_alnum($GLOBALS["CCA_ISO_CODE"]) || ! strlen($GLOBALS["CCA_ISO_CODE"]) == 2) $_SERVER["CCA_ISO_CODE"] = "";
			$lookupMsg = str_replace('<CCA_CUST_IPVAR_LINK>', CCA_CUST_IPVAR_LINK, $GLOBALS['cca-lookup-msg']);
			$lookupMsg = str_replace('<CCA_CUST_GEO_LINK>', CCA_CUST_GEO_LINK, $lookupMsg);
?>
      <p class="cca-brown">You appear to be located in <i>(or CCA preview mode is)</i> <b>"<?php echo $GLOBALS["CCA_ISO_CODE"];?>"</b>
      <br><?php echo $lookupMsg;?></p>

			<br><hr><p><b><u>Your Server's IP Address Variables (for info only):</u></b></p>
			<?php echo "<hr><p><b>Cloudflare Country Variable:<b> "; 
      if ( ! empty($_SERVER["HTTP_CF_IPCOUNTRY"])):
        echo '"' . $_SERVER["HTTP_CF_IPCOUNTRY"] . '"</p>';
      else:
        echo 'Not found</p>';
      endif;
      foreach (array('HTTP_X_REAL_IP', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED','HTTP_CF_CONNECTING_IP') as $key):
        echo "<hr><p><b>$key<b>: ";  
      	if (empty($_SERVER[$key])):
      	  echo 'is empty or not set</p>';
      		continue;
      	endif;
        $possIP = $_SERVER[$key];
        echo htmlspecialchars($possIP);
      	$ip = explode(',', $possIP);
        if (count($ip) > 1):  // its a comma separated list of enroute IPs
      	  echo '<br>&nbsp;&nbsp;a check of the first item indicates'; 
        endif;
        if ($ip[0] != '127.0.0.1' && filter_var(trim($ip[0]), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) !== false) :
      	  echo ' <i>it appears to be a valid IP address</i>';
        else :
      	  echo ' <i>it look like an invalid or reserved IP address</i>';
      	endif;
      endforeach;
			echo '<br><hr><hr>';
		endif;
?>
		<hr><h3>Information useful for support requests:</h3>
		<p><input type="checkbox" id="ccwpsc_diagnostics" name="ccwpsc_caching_options[diagnostics]"><label for="ccwpsc_diagnostics"><?php _e(' List plugin values/Maxmind Health/File Permissions'); ?></label></p>
<?php
		if ($this->options['diagnostics']) :
		  $this->options['diagnostics'] = '';
		  $this->validate_wpsc_custpath();

		  echo '<h4><u>WP Supercache Status:</u></h4>';
			echo '<div class="cca-brown">' . $this->ccwpsc_wpsc_status() . '</div>';
			echo 'WPSC looks for CC add-on script in: ' . esc_html($this->options['wp_cache_plugins_dir']) . '<br>';

			echo '<h4><u>Software Versions:</u></h4>';
			echo 'PHP: ' . phpversion();
			echo '<br>CC for WPSC: ' . get_option('CCWPSC_VERSION') . '<br>';

      echo '<h4><u>Constants:</u></h4>';
      echo '<span class="cca-brown">WPCACHEHOME (defined by WPSC) = </span>'; echo defined('WPCACHEHOME') ? WPCACHEHOME : 'not defined';
      echo '<br><span class="cca-brown">CCWPSC_WPSC_PRESENT (WPSC plugin exists &amp; activated) = </span>'; echo (defined('CCWPSC_WPSC_PRESENT') && CCWPSC_WPSC_PRESENT) ? 'TRUE' : 'FALSE';
      echo '<br><span class="cca-brown">CCWPSC_USUAL_ADDONDIR (WPSC default add-ons folder) = </span>' . CCWPSC_USUAL_ADDONDIR;
      echo '<br><span class="cca-brown">CCWPSC_ADDON_DIR (CC WPSC add-on folder) = </span>'; echo CCWPSC_ADDON_DIR;
      echo '<br><span class="cca-brown">CCWPSC_ADDON_SCRIPT = </span>'; echo CCWPSC_ADDON_SCRIPT;

      echo '<h4><u>Variables:</u></h4>';
		  $esc_options = esc_html(print_r($this->options, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current setting values") . ':</span>' . str_replace ( '[' , '<br> [' , print_r($esc_options, TRUE )) . '</p>';

			echo '<hr><h4><u>Maxmind Data status:</u></h4>';
			if (file_exists(CCA_MAXMIND_DATA_DIR)):
				echo 'Maxmind Directory: "' . CCA_MAXMIND_DATA_DIR . '"<br>';
				if (file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME)): 
				    echo __('File "') . CCA_MAX_FILENAME . __('" last successfully updated : ') . date("F d Y H:i:s.",filemtime(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME)) . '<br>';
				else: 
					  echo '<span class="cca-brown">' . __('Maxmind look-up file "') . CCA_MAX_FILENAME . __('" could not be found. ');
						if (file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIP.dat') || file_exists(CCA_MAXMIND_DATA_DIR . 'GeoIPv6.dat')): 
						  echo __('Out of date Maxmind Legacy files have been found and will be used for geolocation.') . '<br>';
						else:
						  echo __('Maxmind geolocation will not be functioning.') . '<br>';
						endif;
						echo __('Ensure "Enable CC Country Caching add-on" is checked ("Comet Cache" tab) and then save settings'). '</span><br>';
				 endif;
			else:
					echo '<span class="cca-brown">' . __('The Maxmind Directory ("') . CCA_MAXMIND_DATA_DIR . '") ' . __('does not exist. Maxmind Country GeoLocation will not be working.')  . '</span><br>';
			endif;
			if (! empty($this->maxmind_status['health'] ) && $this->maxmind_status['health'] != 'ok'):
			 		echo '<p>' . __('The last update process reported a problem: ') . ' <span class="cca-brown">' . $this->maxmind_status['result_msg'] . '</span>"</p>';
			endif; 
			echo '<hr>';
		  $esc_options = esc_html(print_r($this->maxmind_status, TRUE ));  // option values from memory there is a slim chance stored values will differ
		  echo '<span class="cca-brown">' . __("Current values") . ':</span>' . str_replace ( '[' , '<br> [' , print_r($esc_options, TRUE )) . '</p>';

      echo '<h4><u>' . __('File and Directory Permissions') . ':</u></h4>';
      echo '<span class="cca-brown">' . __('Last file/directory error":') . '</span> ';
			if ( empty($this->options['last_output_err']) ):
			  echo 'none<br>'; 
			else: echo $this->options['last_output_err'] . '<br>'; endif;
			clearstatcache();
      $wpcontent_stat = @stat(WP_CONTENT_DIR);
      if (function_exists('posix_getuid') && function_exists('posix_getpwuid') && function_exists('posix_geteuid')
           && function_exists('posix_getgid') && function_exists('posix_getegid') && $wpcontent_stat) :
        $real_process_uid  = posix_getuid(); 
        $real_process_data =  posix_getpwuid($real_process_uid);
        $real_process_user =  $real_process_data['name'];
      	$real_process_group = posix_getgid();
        $real_process_gdata =  posix_getpwuid($real_process_group);
        $real_process_guser =  $real_process_gdata['name'];	
        $e_process_uid  = posix_geteuid(); 
        $e_process_data =  posix_getpwuid($e_process_uid);
        $e_process_user =  $e_process_data['name'];
      	$e_process_group = posix_getegid();
        $e_process_gdata =  posix_getpwuid($e_process_group);
        $e_process_guser =  $e_process_gdata['name'];	
      	$wpcontent_data =  posix_getpwuid($wpcontent_stat['uid']);
      	$wpcontent_owner = $wpcontent_data['name'];
      	$wpcontent_gdata =  posix_getpwuid($wpcontent_stat['gid']);
      	$wpcontent_group = $wpcontent_gdata['name'];
        echo '<span class="cca-brown">' . __('This plugin is being run by "real user":') . '</span> ' . $real_process_user . ' (UID:' . $real_process_uid . ') Group: ' . $real_process_guser .' (GID:' . $real_process_group . ') N.B. this user may also be a member of other groups.<br>'; 
      	echo '<span class="cca-brown">' . __('The effective user is: ') . '</span>' . $e_process_user . ' (UID:' . $e_process_uid . ' GID:' . posix_getegid() . ')<br>'; 
        echo '<span class="cca-brown">' . __('"wp-content" directory') . '</span>: ' . __('Owner = ') . $wpcontent_data['name'] . ' (UID:' . $wpcontent_stat['uid'] . ') | Group = ' .  $wpcontent_group . ' (GID:' . $wpcontent_stat['gid'] . ')<br>';
      else:
        __('Unable to obtain information on the plugin process owner(user).  Your server might not have the PHP posix extension (installed on the majority of Linux servers) which this plugin uses to get this info.') . '<br>';
      endif; 
      echo '<span class="cca-brown">' . __('"wp-content" folder permissions: </span>') . ccwpsc_return_permissions(WP_CONTENT_DIR) . '<br>';
      echo '<span class="cca-brown">' . __('"WPSC add-on\'s folder" ') . CCWPSC_USUAL_ADDONDIR . __(' permissions') .'</span>: ' . ccwpsc_return_permissions(CCWPSC_USUAL_ADDONDIR) . '<br>';
      if ($this->valid_wpsc_custdir) :
        echo '<span class="cca-brown">' . __('"Custom WPSC add-on folder" ') . CCWPSC_ADDON_DIR . __(' permissions') .'</span>: ' . ccwpsc_return_permissions(CCWPSC_ADDON_DIR) . '<br>';
      else:
        echo __('You have not defined a custom WPSC add-on folder (or the path you defined was invalid)') . '<br>';
      endif;
      echo '<span class="cca-brown">Permissions for add-on script "' . CCWPSC_ADDON_SCRIPT . '": </span>' . ccwpsc_return_permissions($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT);
		endif;

    submit_button('Display Information','primary', 'submit', TRUE, array( 'style'=>'cursor: pointer; cursor: hand;color:white;background-color:#2ea2cc') ); 
  }   // END function render_config_panel



  // validate and save settings fields changes
  public function sanitize( $input ) {
    if ($this->options['activation_status'] != 'activated') return $this->options; // activation hook carries out its own "sanitizing"
		$input['action'] = empty($input['action']) ? '' : strip_tags($input['action']);
  	// initialize messages
		$settings_msg = '';
		$msg_type = 'updated';
    $delete_result = '';

// PROCESS config tab input 
    if ($input['action'] == 'Configuration') :

			if (! empty($input['force_reset']) ) :
        if ( $this->options['wp_cache_plugins_dir'] == CCWPSC_ADDON_DIR ): // we don't want to replace in config if previously set to use other plugins dir
          $temp = CCWPSC_CONFIG_ADDONDIR;
        	// configure WPSC to look for plugin dir in standard location
					$this->reset_WPSC_plugin_config();
        else:
          $temp = $this->options['wp_cache_plugins_dir'];
        endif;
				$this->options = $this->initial_option_values;
				$this->options['wp_cache_plugins_dir'] = $temp;
				$this->options['activation_status'] = 'activated';

		    $delete_result = $this->delete_wpsc_addon();	
  		  if ($delete_result != ''):
  				$msg_type = 'error';
  			  $msg_part = $delete_result;
  			else:
  			  $msg_part = __('Country caching has been reset to none.<br>');
					$this->options['use_WPSC_dir'] = FALSE;
  				$this->options['cache_iso_cc'] = '';
					$this->options['use_group'] = FALSE;
					$this->options['my_ccgroup'] = CCWPSC_EU_GROUP;
  			endif;
  			$settings_msg = $msg_part . $settings_msg;
			  update_option('ccwpsc_caching_options',$this->initial_option_values);
			endif;
  		if ($settings_msg != '') :
        add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
      endif;

		  $this->options['diagnostics'] = empty($input['diagnostics']) ? FALSE : TRUE;
		  $this->options['addon_data'] = empty($input['addon_data']) ? FALSE : TRUE;
			$this->options['geoip_data'] = empty($input['geoip_data']) ? FALSE : TRUE;
  		return $this->options;
    endif;

		//  RETURN IF INPUT IS NOT FROM "WPSC" TAB (The WPSC tab should be the only one not sanitized at this point).
    if ($input['action'] != 'WPSC'): return $this->options; endif;

// WPSC SETTINGS TAB INPUT

		// take opportunity to housekeep
		$this->remove_obsolete_settings();

		// prepare input for processing
		$new_mode = empty($input['caching_mode']) ? 'none' : 'WPSC';
		$use_WPSC_dir = empty($input['use_WPSC_dir'] ) ? FALSE : TRUE;
		$cache_iso_cc = empty($input['cache_iso_cc']) ? '' : strtoupper(trim($input['cache_iso_cc']));
		$my_ccgroup = empty($input['my_ccgroup']) ? '' : strtoupper(trim($input['my_ccgroup']));
		$use_group = empty($input['use_group'] ) ? FALSE : TRUE;
		$cache_iso_cc = empty($input['cache_iso_cc']) ? '' : strtoupper(trim($input['cache_iso_cc']));

		$error_iso_detected = FALSE;
		if ($this->is_valid_ISO_list($cache_iso_cc)):
		  $error_iso_cc = FALSE;
		else:
		  $error_iso_cc = TRUE;
		  $error_iso_detected = TRUE;
		endif;
		if ( empty($my_ccgroup) ):
		  $my_ccgroup = CCWPSC_EU_GROUP;
			$use_group = FALSE;
		elseif ($this->is_valid_ISO_list($my_ccgroup)):
		  $error_iso_group = FALSE;
		else:
		  $use_group = FALSE;
		  $error_iso_group = TRUE;
			$error_iso_detected = TRUE;
			if ($error_iso_cc): $error_iso_both = TRUE; endif;
		endif;


		// user is trying to enable country caching without Supercache plugin being present!
    if ( $new_mode != 'none'  && ! CCWPSC_WPSC_PRESENT ) :
      add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ),
        __("ERROR: WP Super Cache has to be installed <u>and activated</u> before configuring Country Caching.<br>") .
				__("N.B. you can still configure Country Caching if you have temporarilly disabled caching via WPSC's own settings"),
        'error'
      );
      return $this->options;
    endif;

		// user is not enabling country caching and it wasn't previously enabled
		if ( $new_mode == 'none' && $this->options['caching_mode'] == 'none') :
			if (! $error_iso_detected):
			  $this->options['use_WPSC_dir'] = $use_WPSC_dir;
			  $this->options['cache_iso_cc'] = $cache_iso_cc;
				$this->options['my_ccgroup'] = $my_ccgroup;
				$this->options['use_group'] = $use_group;
        $settings_msg = __("Any changes to settings have been updated; HOWEVER you have NOT ENABLED country caching.") .  '.<br>';
			else :
        $settings_msg .= __("Not updated (invalid country entry found); also you did not enable country caching.<br>");
			endif;
			$settings_msg .= __("I'll take this opportunity to housekeep and remove any orphan country caching scripts. ");
		  $settings_msg .= $this->delete_wpsc_addon();
			add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), $settings_msg, 'error' );
      return $this->options;	  
		endif;

		$msg_part = '';

		// user is changing to OPTION "NONE" we are disabling country caching and need to remove the WPSC add-on script
		if ($new_mode == 'none') :
		   $delete_result = $this->delete_wpsc_addon();	
		   if ($delete_result != ''):
				  $msg_type = 'error';
			    $msg_part = $delete_result;
			 else:
		       $msg_part = __('Country caching has been disabled.<br>');
           if (  ! $error_iso_cc ) :
        	     $this->options['cache_iso_cc'] = $cache_iso_cc;
           endif;
           if ( ! $error_iso_group ) :
        	     $this->options['my_ccgroup'] = $my_ccgroup;
							 $this->options['use_group'] = $use_group;
           endif;
					 $this->options['use_WPSC_dir'] = $use_WPSC_dir;
				   $this->options['caching_mode'] = 'none';
			 endif;
			 $this->reset_WPSC_plugin_config();
			 $settings_msg = $msg_part . $settings_msg;


		// check if user has submitted option to enable country caching, but the input comma separated Country Code list is invalid
    elseif ( $new_mode == 'WPSC' && $error_iso_detected ):
				$settings_msg .= __('WARNING: Settings have NOT been changed. Country Code text box error (it must be empty or contain 2 character ISO country codes separated by commas).<br>');
				add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), $settings_msg, 'error'	);
				return $this->options;


		// user has opted for country caching "enabled" and has provided a valid list of country codes
		elseif ( $new_mode == 'WPSC') :

  		 // Country Caching has been enabled; ensure Maxmind files are installed if needed
  		 if ( ! $this->ensure_maxfile_present() ) :
  			  if ( empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
  				  $settings_msg = $settings_msg . '<br>No changes made. Maxmind mmdb file is missing and could not be installed.<br>' . $this->maxmind_status['result_msg'];
  					add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), __($settings_msg), 'error');
  				  return $this->options;
  				endif;
  		 endif;

			 $temp = $this->options['wp_cache_plugins_dir'];
			 if ( $use_WPSC_dir ) : $this->options['wp_cache_plugins_dir'] = CCWPSC_CONFIG_ADDONDIR;
			  else: $this->options['wp_cache_plugins_dir'] = CCWPSC_ADDON_DIR;
			 endif;

  		 $script_string = $this->ccwpsc_build_script( $cache_iso_cc, $use_group, $my_ccgroup);
  		 if ( empty($this->options['last_output_err']) ) :
  		    $script_update_result = $this->ccwpsc_write_script($script_string);
  		 endif;
 			 if ($script_update_result == 'Done') :
			   $this->options['use_WPSC_dir'] = $use_WPSC_dir;
				 $this->options['cache_iso_cc'] = $cache_iso_cc;
				 $this->options['my_ccgroup'] = $my_ccgroup;
				 $this->options['use_group'] = $use_group;
				 $this->options['caching_mode'] = 'WPSC';
				 $msg_part = __("Settings have been updated and country caching is enabled for WPSC.<br>"); 
				 $settings_msg = $msg_part . $settings_msg;
				 
				 $wp_cache_config_file = WP_CONTENT_DIR . '/wp-cache-config.php';
				 if (! $use_WPSC_dir && $temp != $this->options['wp_cache_plugins_dir'] ) :				 
				   wp_cache_replace_line('^ *\$wp_cache_plugins_dir', "\$wp_cache_plugins_dir = '" . CCWPSC_ADDON_DIR . "';", $wp_cache_config_file);
				 elseif ( $use_WPSC_dir && $temp != $this->options['wp_cache_plugins_dir'] ):
				   wp_cache_replace_line('^ *\$wp_cache_plugins_dir', '$wp_cache_plugins_dir = WPCACHEHOME . ' . "'plugins';", $wp_cache_config_file);
				 endif;
				 wp_cache_replace_line('^ *\$wp_cache_mod_rewrite', "\$wp_cache_mod_rewrite = 0;", $wp_cache_config_file);  // simple caching
				 wp_cache_replace_line('^ *\$wpsc_save_headers', "\$wpsc_save_headers = 1;", $wp_cache_config_file);
			else:
			  $settings_msg .= $script_update_result . '<br>';
				$new_mode = $this->options['caching_mode'];  // as build of add-on script has failed, we want to keep the existing setting when updating options (below)
			endif;
    endif;

		if ($settings_msg != '') :
      add_settings_error('ccwpsc_group',esc_attr( 'settings_updated' ), __($settings_msg),	$msg_type	);
    endif;
		return $this->options;
  }  	// END OF SANITIZE FUNCTION


  // delete the WPSC add-on script from all possible locations where, at some point, it could have been placed
  function delete_wpsc_addon() {
  	$result_msg = '';
    if ( file_exists(CCWPSC_ADDON_DIR . '/' .  CCWPSC_ADDON_SCRIPT ) && ! unlink(CCWPSC_ADDON_DIR . '/' .  CCWPSC_ADDON_SCRIPT ) ) :
     	  $result_msg = CCWPSC_ADDON_DIR . '/' . CCWPSC_ADDON_SCRIPT . ' ';
    endif;
    if (file_exists(CCWPSC_CONFIG_ADDONDIR . '/' .  CCWPSC_ADDON_SCRIPT ) && ! unlink(CCWPSC_CONFIG_ADDONDIR . '/' .  CCWPSC_ADDON_SCRIPT ) ) :
     	  $result_msg .= esc_html(CCWPSC_CONFIG_ADDONDIR) . '/' . CCWPSC_ADDON_SCRIPT . ' ';
    endif;
    if ( CCWPSC_USUAL_ADDONDIR != CCWPSC_CONFIG_ADDONDIR &&  file_exists(CCWPSC_USUAL_ADDONDIR . '/' .  CCWPSC_ADDON_SCRIPT ) && ! unlink(CCWPSC_USUAL_ADDONDIR . '/' .  CCWPSC_ADDON_SCRIPT ) ) :
    		$result_msg .= CCWPSC_USUAL_ADDONDIR . '/' . CCWPSC_ADDON_SCRIPT . ' ';
    endif;
        // cater for orphan script when WPSC plugin and (and associated constants) have already been deleted
    if ( ! empty($this->options['wp_cache_plugins_dir']) && $this->options['wp_cache_plugins_dir'] != CCWPSC_USUAL_ADDONDIR
     && $this->options['wp_cache_plugins_dir'] != CCWPSC_CONFIG_ADDONDIR && $this->options['wp_cache_plugins_dir'] != CCWPSC_ADDON_DIR) :
    	 if (file_exists($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT ) && ! unlink($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT ) ):
    			$result_msg .= esc_html($this->options['wp_cache_plugins_dir']) . '/' . CCWPSC_ADDON_SCRIPT . ' ';
       endif;
    endif;
  	if ($result_msg != ''):
  	  return __('Warning: I was unable to remove the old country caching addon script(s): "') .  $result_msg . __('". You can try altering folder permissions on one of the parent directories; or delete this file yourself.<br>');
  	endif;
    return '';
  }


  function ensure_maxfile_present($doEmail = FALSE) {
		if (! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) || @filesize(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) < 8000 ) :
		  $do_max = new CCAmaxmindUpdate();
			$bool_success = $do_max->save_maxmind($doEmail); // if method argument is true then email will be sent on failure
			$this->maxmind_status = $do_max->get_max_status();
			unset($do_max);
			return $bool_success;
		endif;
		return TRUE;
  }


	// if CCWPSC_ADDON_DIR (defined in WPSC's config file) is a valid directory path, create the directory if it doesn't already exist, and return TRUE; otherwise return FALSE
  function validate_wpsc_custpath() {
  	$this->valid_wpsc_custdir = FALSE;
  	$this->wpsc_cust_error = '';
    if (validate_file($this->options['wp_cache_plugins_dir']) > 0 && validate_file($this->options['wp_cache_plugins_dir']) != 2 ) :
     		$this->wpsc_cust_error = __('The defined WPSC add-on path(') . esc_html($this->options['wp_cache_plugins_dir']) . __(') is invalid.');
 	  elseif ( ! file_exists($this->options['wp_cache_plugins_dir']) ):
       	// dedicated servers may require 775 permissions - check and see what permissions have been set for other plugin directories
        $item_perms = ccwpsc_return_permissions(CCWPSC_PLUGINDIR);  // determine permissions to set when creating directory
        if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) == '7') :
              $ccwpsc_perms = 0775;
        else:
              $ccwpsc_perms = 0755;
        endif;
  			if (mkdir($this->options['wp_cache_plugins_dir'], $ccwpsc_perms, true) ):
  				    $this->options['initial_message'] .= __('The add-on directory did not exist. It HAS BEEN CREATED.');
  					  $this->valid_wpsc_custdir = TRUE;
      	else:
  				    $this->wpsc_cust_error = __('The add-on directory (') . esc_html($this->options['wp_cache_plugins_dir']) . __(') does not exist and I was unable to create it (this may be due to the file permissions of one of the folders in the directory path).');
  			endif;
		else: 
  			  $this->valid_wpsc_custdir = TRUE;
  	endif;
    return $this->valid_wpsc_custdir;
  }


  function ccwpsc_wpsc_status() {
    if (! CCWPSC_WPSC_PRESENT) :
  	   return __('It DOES NOT looks like your site is using WP Super Cache (WPSC) or it is not activated so Country Caching will not be applied.');
  	else:
  		$wpsc_running = __("It looks like WP Super Cache (WPSC) is installed and activated on your site ");
 
  	  if ( ! CCWPSC_WPSC_ENABLED ) :
			  $wpsc_running .= __("but <u>caching is currently disabled</u> - see your WPSC settings. N.B. <u>you can still configure Country Caching</u> before re-enabling WPSC");
  		endif;
			$wpsc_running .= '<br>';
      if (! empty($_SERVER["HTTP_CF_IPCOUNTRY"]) ) :
  	     $geoip_used = __('Cloudflare data is being used for GeoIP	');
  		elseif ( ! file_exists(CCA_MAXMIND_DATA_DIR . CCA_MAX_FILENAME) || $this->maxmind_status['health'] == 'fail') :
  		   $geoip_used = __('There is a problem with GeoIP - see the "CC Status" tab. Re-saving settings (assuming "enable country caching" is checked) may solve this problem.');
  		else:
  		   $geoip_used = '';
  		endif;
  		if (empty($this->options['cache_iso_cc'])) :
  		  $opto = __("<br>To fully optimize performance you should limit the countries that are individually cached.");
  		 else: $opto = '';
  		endif;
  		if ($this->options['caching_mode'] == 'WPSC'):
  			  if (! CCWPSC_WPSC_PRESENT ) :
  				  $wpsc_status = $wpsc_running;  // notify user WPSC is deactivated
  			  elseif (file_exists($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT)) :
					  $add_on_ver = '';
					  if ( ! function_exists('cca_enable_geoip_cachekey') ) : include_once($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT); endif;
						if ( function_exists('cca_enable_geoip_cachekey') ): $add_on_ver = cca_enable_geoip_cachekey('cca_version'); endif;
						if  ($add_on_ver == CCWPSC_ADDON_VERSION) :
						  $wpsc_status = $wpsc_running . __("Country caching set up looks okay.") . '<br>';
							$wpsc_status .= $opto . $geoip_used;
						else:
						  $wpsc_status = $wpsc_running . __("An old version of the Country Caching add-on script is being used. Re-save your settings to build a new version.") . '<br>';
						endif;
					// if default plugin folder was used before update to 0.7.0+ then ['wp_cache_plugins_dir'] might contain wrong location until the add-on is rebuilt
					// so check if add-on is present using old ['wpsc_path'].  (obsolete ['wpsc_path'] will be unset the first time settings are saved under the updated plugin)
					elseif (isset($this->options['wpsc_path']) && file_exists($this->options['wpsc_path'] . CCWPSC_ADDON_SCRIPT) ):
					  $wpsc_status = $wpsc_running . __("An old version of the Country Caching add-on script is being used. Re-save your settings to build latest version.") . '<br>';
  				else:
  					$wpsc_status =  $geoip_used . '<br>' .__("Country Caching is NOT working. It looks like WPSC is running, and you have enabled country caching. HOWEVER; the add-on script is not present in WPSC's plugins folder.<br>" );
  					$wpsc_status .= __("Click the Submit button below to regenerate and apply the add-on script.");
  				endif;
  		else:  // user has not checked to enable WPSC country caching
  		  $wpsc_status =  $wpsc_running . __("N.B. You have not enabled WPSC country caching.<br>");
  		  if ( file_exists($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT) || file_exists(CCWPSC_ADDON_DIR  . '/' . CCWPSC_ADDON_SCRIPT) 
				 || file_exists(CCWPSC_CONFIG_ADDONDIR  . '/' . CCWPSC_ADDON_SCRIPT) || file_exists(CCWPSC_USUAL_ADDONDIR  . '/' . CCWPSC_ADDON_SCRIPT)) :
  			  $wpsc_status .=  __('ALTHOUGH, "Enable WPSC" is not checked, the country caching script STILL EXISTS as an add-on to WPSC and country caching may still be active.<br>');
  				$wpsc_status .= __("Clicking the Submit button below should result in the add-on being deleted and resolve this problem.");
  			endif;
  		endif;

  	endif;
  	return $wpsc_status;
  }    // END OF ccwpsc_wpsc_status FUNCTION


  function ccwpsc_build_script( $cache_iso_cc, $use_group, $my_ccgroup) {
	  if ($this->options['activation_status'] != 'activating' && ! CCWPSC_WPSC_PRESENT) :
		   $this->options['last_output_err']  =  '* ' . __("ERROR: WPSC caching doesn't appear to be running on your site (maybe you have de-activated it, or it isn't installed).");
			 return FALSE;
		endif;
		if ( ! defined('CCWPSC_MAXMIND_DIR') ) :
		   $this->options['last_output_err']  =  '* ' . __('Error: on building the add-on script for WPSC (value for constant CCWPSC_MAXMIND_DIR not found)');
			 return FALSE;
		endif;
		$template_script = CCWPSC_PLUGINDIR . 'caching_plugins/' . CCWPSC_ADDON_SCRIPT;
    $file_string = @file_get_contents($template_script); 
		if (empty($file_string)) : 
			if ( file_exists( $template_script ) ):
			  $this->options['last_output_err']  =  '*' . __('Error: unable to read the template script ("') . $template_script . __('") used to build or alter the plugin for Super Cache');
				return FALSE;
		  else:
			  $this->options['last_output_err']  = '*' . __('Error: it looks like the template script ("') . $template_script . __('") needed to build or alter the add-on to Super Cache has been deleted.');
				return FALSE;
      endif;
		endif;

		unset($this->options['last_output_err']) ;
		if ( ! empty($cache_iso_cc) ) : $file_string = str_replace('$just_these = array();', '$just_these = explode(",","' . $cache_iso_cc .'");',  $file_string); endif;
		if ( ! empty($use_group) && ! empty($my_ccgroup)): $file_string = str_replace('$my_ccgroup = array();', '$my_ccgroup = explode(",","' . $my_ccgroup .'");', $file_string); endif;

		$file_string = str_replace('GeoData-Replace', CCA_MAX_FILENAME, $file_string);
		$file_string = str_replace('unknown version', CCWPSC_ADDON_VERSION, $file_string);
		$file_string = str_replace('ccaMaxDataDir-Replace', CCA_MAXMIND_DATA_DIR, $file_string);
    $file_string = str_replace('ccwpscMaxDirReplace', CCWPSC_MAXMIND_DIR, $file_string);
    return $file_string;
  }    // END OF ccwpsc_build_script FUNCTION


  function ccwpsc_write_script( $file_string) {
    $this->validate_wpsc_custpath();
    if (! $this->valid_wpsc_custdir ):
    	  $this->options['last_output_err'] =  '* ' . __("Sorry, either the add-on directory (defined in WPSC's config file) does not exist, or there's a problem with its path: ") . $this->wpsc_cust_error;
				return $this->options['last_output_err'];
    endif;
		if ( (validate_file( $this->options['wp_cache_plugins_dir'] )!=0 && validate_file( $this->options['wp_cache_plugins_dir'] )!=2) || ! file_exists($this->options['wp_cache_plugins_dir']) ):
		  return 'Error: directory ' . $this->options['wp_cache_plugins_dir'] . ' does not exist';
		endif;
		
		$this->delete_wpsc_addon();
		if( ! file_put_contents($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT, $file_string) ) :
  	  $this->options['last_output_err'] =  date('d M Y h:i a; ') .  __(' unable to create/update add-on script ') . '<i>' . $this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT . '</i>.';
		  return "Error: writing to WPSC's plugin directory";
		endif;
    // dedicated servers may require 664 permissions - check and see what permissions have been set for othe plugin scripts
		$item_perms = ccwpsc_return_permissions(CCWPSC_CALLING_SCRIPT);
    if (strlen($item_perms) == 4 && substr($item_perms, 2, 1) > "5" ) :
      $ccwpsc_perms = 0664;
    else:
      $ccwpsc_perms = 0644;
    endif;
  	chmod($this->options['wp_cache_plugins_dir'] . '/' . CCWPSC_ADDON_SCRIPT, $ccwpsc_perms);
  	return 'Done';
  }   // END OF ccwpsc_write_script FUNCTION


  function is_valid_ISO_list($list) {
    if ( $list != '') :
  	  $codes = explode(',' , $list);
  		foreach ($codes as $code) :
  		   if ( ! ctype_alpha($code) || strlen($code) != 2) :
     		   return FALSE;
  			 endif;
  		endforeach;	
  	endif;
		return TRUE;
	}

	function reset_WPSC_plugin_config() {
	  if ( $this->options['wp_cache_plugins_dir'] == CCWPSC_ADDON_DIR ): // we don't want to replace in config if previously set to use other plugins dir
		  $wp_cache_config_file = WP_CONTENT_DIR . '/wp-cache-config.php';
		  wp_cache_replace_line('^ *\$wp_cache_plugins_dir', '$wp_cache_plugins_dir = WPCACHEHOME . ' . "'plugins';", $wp_cache_config_file);
			$this->options['wp_cache_plugins_dir'] = CCWPSC_CONFIG_ADDONDIR;
		endif;
		return;
	}

  function remove_obsolete_settings() {
    wp_clear_scheduled_hook( 'country_caching_check_wpsc' );   // no longer used in vers post Apr 2018
    unset($this->options['override_tab']);  // no longer used in vers post Apr 2018
    unset($this->options['cron_frequency']);  // no longer used in vers post Apr 2018
    unset($this->options['list_jobs']);  // no longer used in vers post Apr 2018
    unset($this->options['wpsc_path']);  // no longer used in vers post Apr 2018
    unset($this->options['cca_maxmind_data_dir']);
  }

} // END CLASS
