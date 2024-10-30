=== Country Caching For WP Super Cache ===
Contributors: wrigs1
Donate link: http://means.us.com/
Tags: caching, WP Super Cache, Super Cache, country, GeoIP, Maxmind, geolocation, cache
Requires at least: 3.3
Tested up to: 4.9.6
Requires PHP: 5.4 or later
Stable tag: trunk
GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Extends WP Super Cache to cache by page/visitor country instead of just page. Solves "wrong country content" Geo-Location issues.

== Description ==

DUE TO PERSONAL CIRCUMSTANCES I AM NO LONGER ABLE TO DEVELOP OR SUPPORT THIS PLUGIN. IF YOU ARE INTERESTED IN ADOPTING THIS PLUGIN SEE https://developer.wordpress.org/plugins/wordpress-org/take-over-an-existing-plugin/

**Bonus** also makes Cookie Notice work correctly with WPSC (whether using country/EU geolocation or not). 

Allows WP Super Cache to display the correct page/widget content for a visitor's country when you are using geo-location; solves problems like these reported on  [Wordpress.Org](https://wordpress.org/support/topic/plugin-wp-super-cache-super-cache-with-geo-targeting ) and  [StackOverflow](http://stackoverflow.com/questions/21308405/geolocation-in-wordpress ).

 A similar extension is available [for Comet Cache](https://wordpress.org/plugins/country-caching-extension/).

This plugin builds an extension script that enables Super Cache to create separate snapshots (cache) for each page based on country location.

Separate snapshots can be restricted to specific countries.  E.g. if you are based in the US but customize some content for Canadian or Mexican visitors, you can restrict separate caching to CA & MX visitors; and all other visitors will see the same cached ("US") content.

You can also specify a single snapshot for a group of countries e.g. all European Union countries.

It works on both normal Wordpress and Multisite (see FAQ) installations.

More info in [the user guide]( http://wptest.means.us.com/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/ )

**Identification of visitor country for caching**

Via Cloudflare or Maxmind (when the plugin is first enabled it uploads GeoLite2 data created by MaxMind, available from http://www.maxmind.com ). Cloudflare works with any PHP version, but Maxmind Geolite2 requires PHP 5.4 or later. *It is also possible to connect a different GeoLocation sytem of your choice (see documentation).*

If you use Cloudflare and have "switched on" their GeoLocation option ( see [Cloudflare's  instructions](https://support.cloudflare.com/hc/en-us/articles/200168236-What-does-CloudFlare-IP-Geolocation-do- ) ) then it will be used to identify visitor country.  If not, then the Maxmind Country Database will be used.

**Updating** (If not using Cloudflare for country) The installed Maxmind Country/IP data file will lose accuracy over time.  To automate a monthly update of this file, install and enable the [Category Country Aware (CCA) plugin](https://wordpress.org/plugins/category-country-aware/ ) (Country Caching and the Cataegory Country Aware (CCA) plugins use the same Maxmind data file in the same folder and the CCA plugin includes code for its update). The CCA plugin has many other features and functionality you may find useful. Alternatively you can manually update (FAQ below).


== ADVICE==

I don't recommend you use ANY Caching plugin UNLESS you know how to use an FTP program (e.g. Filezilla). Caching plugins can cause "white screen" problems for some users. WP Super Cache is no different; when I checked the first page of its support forum it included 4 [posts like  this](https://wordpress.org/support/topic/site-broken-after-activate-wp-super-cache). Sometimes the only solution is to manually delete files using FTP or OS command line. When deactivated/deleted via Dashboard->Plugins; the Country Caching plugin deletes its files, but in "white screen" situations you may have to resort to "manual" deletion - see FAQ for instructions.



== Installation ==

(Obvously you must have WP Supercache installed and activated. If you wish, you can switch off caching in it settings until you have finished your Country Cachching set-up.)

Install Country Caching plugin in normal way. Then go to "Dashboard->WPSC Country Caching". Check the "*Enable WPSC Country Caching add-on*" box, and save settings.


== Frequently Asked Questions ==

= Where can I find support/additional documentation =

Support questions should be posted on Wordpress.Org<br />
Additional documentation [is provided here]( http://wptest.means.us.com/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/ )


= How do I know its working =

See [these checks](http://wptest.means.us.com/geolocation-and-wp-super-cache-caching-by-page-visitor-country-instead-of-just-page/#works).

= How do I keep the Maxmind country/IP range data up to date =

Automatically: install the [Category Country Aware plugin](https://wordpress.org/plugins/category-country-aware/ ) from Wordpress.Org and enable its settings; it will update your Maxmind data every "month".

Manually: monthly/whatever; download "GeoLite2-Country.tar.gz" from [Maxmind](https://dev.maxmind.com/geoip/geoip2/geolite2/ ) and extract the file "GeoLite2-Country.mmdb" and upload it to your servers "/wp-content/cca_maxmind_data/" directory.

= Will it work on Multisites =

Yes, it will be the same for all blogs (you can't have it on for Blog A, and off for Blog B).

On MultiSites, the WPSC Country Caching settings menu will be visible on the Network Admin Dashboard (only).


= How do I stop/remove Country Caching =

Temporarilly: uncheck "Enable Country Caching" in the plugin's settings.

Permanently: deactivate then delete plugin via Dashboard in usual way. Then go to WP Super Cache settings and clear cache.

If you deleted the plugin's directory instead of uninstalling via Dashboard::

1.  Use your Server's Control Panel, or log into your site via FTP; e.g. with CoreFTP or FileZilla and (if necessary).
2.  Delete this directory and its content: /wp-content/ccwpsc_plugins/ (or any alternative add-on directory you defined yourself by editing wp-cache-config.php) 
3.  Delete this file: "cca_wpsc_geoip_plugin.php" from "/wp-content/wp-super-cache/plugins/" 
4.  Edit /wp-content/wp-cache-config.php and change *"$wp_cache_plugins_dir = '/somepath/wp-content/ccwpsc_plugins';"* to *"$wp_cache_plugins_dir = WPCACHEHOME . 'plugins';"*


== Screenshots ==

1. Simple set up. Dashboard->WPSC Country Caching


== Changelog ==

= 0.8.0 =
* Fix for Cookie Notice to make it work correctly with WPSC (whether or not you are using geolocation or CCA to restrict CN to EU visitors)

= 0.7.0 =
* Major improvements - to benefit from these changes you should re-save your CC settings. See [information on major changes/improvements](http://wptest.means.us.com/ccwpsc-changes/ ).

= 0.6.0 =
* Altered code to handle Wordpress function validate_file treating file paths on IIS servers like "D:\site\path/" as invalid. Not tested on IIS (volunteers?).
* Increased the number of server variables checked to identify visitor location
* Additional diagnostics added to Support tab

== Upgrade Notice ==

= 0.8.0 =
* Fix for Cookie Notice to make it work correctly with WPSC (whether or not you are using CCA to restrict CN to EU visitors)

== License ==

This program is free software licensed under the terms of the [GNU General Public License version 2](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html) as published by the Free Software Foundation.

In particular please note the following:

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.