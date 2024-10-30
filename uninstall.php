<?php
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();
delete_option('CCWPSC_VERSION');
delete_option('CCWPSC_VERSION_UPDATE');
delete_option('ccwpsc_caching_options');
