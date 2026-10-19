=== IP Redirect Checker ===
Contributors: inverseparadox
Tags: redirect, url, http, tools, status
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disable WordPress canonical redirects and test lists of URLs from Tools > Redirect Checker.

== Description ==

IP Redirect Checker provides an administrator-only tool for testing URL response codes and final redirect destinations in batches.

* Enter one HTTP or HTTPS URL per line and run a batch of requests.
* Review each URL's final response code and final URL.
* Save results between batches, view all results or only issues, and copy the visible table for a spreadsheet.
* Optionally continue through the list automatically and recover from failed browser requests.
* Optionally disable WordPress canonical redirects while testing. This setting is enabled by default.

The checker sends GET requests and follows redirects. It reports the final response, not each redirect hop or the initial response. Requests use WordPress safe HTTP functions and may reject private-network destinations, embedded credentials, or unsafe ports. Browser credentials are not forwarded.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ip-redirect-checker` directory, or install the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate IP Redirect Checker through the Plugins menu.
3. Open Tools > Redirect Checker. Administrator access is required.
4. Paste one complete HTTP or HTTPS URL per line and save the URL list.
5. Choose whether to block canonical redirects, continue automatically, and recover automatically, then save settings.
6. Choose the number of URLs per run and a starting index, then select Get Response Codes.

In manual mode, one batch runs at a time. Automatic mode continues through the list; keep the browser tab open while it runs.

== Frequently Asked Questions ==

= What does the response code show? =

The checker follows redirects and shows the final response code and final URL. It does not display each redirect hop or the initial response code.

= Why would I block WordPress canonical redirects? =

WordPress may automatically redirect a URL to a page that returns 200. Blocking canonical redirects while testing can help identify URLs that need custom redirects instead. Blocking is enabled by default and can be changed in the plugin settings.

= What happens when a URL cannot be checked? =

Invalid URLs are marked `INVALID URL`. Failed requests, unsafe destinations, and redirect loops are marked `REQUEST ERROR` and included in the issue filter. A request can take up to 30 seconds and follow up to 50 redirects. Reduce the batch size if the hosting environment's PHP request limit is reached.

= Does automatic mode run in the background? =

No. Automatic mode makes requests from the open browser tab, pauses for two seconds between batches, and stops at the end of the list. Closing the tab stops further requests. Stop Automatic Run lets the current batch finish and save its result.

= Where are the URL list and results stored? =

The URL list, results, and settings are stored as WordPress options and shared by administrators on the current site. Changing the URL list clears saved results. Deactivating or deleting the plugin files does not erase these options; use the plugin interface to clear results.

= Are requests made with a visitor's browser credentials? =

No. Requests are made by the WordPress server, and browser credentials are not forwarded. WordPress safe HTTP handling is applied separately to each redirect hop.

== Changelog ==

= 1.0 =
* Initial release.