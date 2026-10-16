# IP Redirect Checker

**Version:** 1.0  
**Author:** Inverse Paradox  
**Requires:** PHP 7.4 or newer and a supported WordPress installation.

## Installation

1. In Plugins > Add New > Upload Plugin, upload `ip-redirect-checker.zip`.
2. Activate IP Redirect Checker.
3. Open Tools > Redirect Checker (administrator access required).
4. Paste one complete HTTP/HTTPS URL per line and click Save URL List.
5. Under Settings, choose canonical redirect blocking, automatic continuation,
   and automatic recovery, then click Save Settings.
6. Choose URLs per run (50 initially) and Starting Index (0 initially).
7. Click Get Response Codes. Manual mode runs one batch; automatic mode
   continues through the remaining list. Keep the tab open in automatic mode.

## Behavior

- Canonical redirect blocking can be switched on or off in Settings. It is
  on by default. This helps identify URLs that WordPress would automatically
  redirect to a page returning 200 but that should have custom redirects.
- Sends GET requests and follows redirects, showing the final response code
  and final URL, not each redirect hop or the initial response code.
- Shows four result columns: Index, Original URL, Response Code, Final URL.
- Saves results between batches. Retesting an index overwrites its result.
- View all saved results or only results whose final response is not 200.
- Invalid URLs are marked `INVALID URL`. Failed requests, unsafe destinations,
  and redirect loops are marked `REQUEST ERROR` and included in the issue filter.
- Request limits: 30 seconds total per URL, up to 50 redirects.
  Reduce the batch size if your hosting's overall PHP request limit is reached.
  Completed rows are saved individually, even if a later URL times out.
- Automatic continuation and recovery are off by default. Automatic mode uses
  your configured batch size, pauses for two seconds between batches, and
  stops at the end of the list.
- Slow automatic batches yield between URLs after about 20 seconds, then
  continue with the next request. Individual URL timeouts are 30 seconds.
  Results and the recovery cursor are saved after every URL, even when a
  hosting timeout interrupts a batch.
- Optional automatic recovery detects stalled requests after 60 seconds and
  retries failed requests up to three times, waiting five seconds between
  attempts. It also waits for an active server request to release its lock.
  A fatal timeout leaves a lock that expires after 90 seconds. Recovery stops
  after repeated failures or approximately three minutes without success.
- Automatic requests use a saved cursor, so a lost response can resume at
  the next unprocessed URL. Rows whose browser response was lost still appear
  in Saved results under All results. Invalid URLs and non-200 results are
  recorded and testing continues; transport recovery is for failed AJAX requests.
- Stop Automatic Run lets the current batch finish and saves its result.
  Closing the tab stops further requests. This is not a background queue.
- Start a new run if the URL list or canonical blocking setting changes.
  Do not test in multiple tabs or change settings/list during a run.
- Lists and results are shared by administrators on the current site.
- Changing the saved URL list clears saved results to prevent mismatched indexes.
- Deleting results requires confirmation and leaves the saved URL list intact.
- Copy visible results copies the current filtered table, including column
  headings, for pasting into a spreadsheet as four columns.

## Data

The plugin uses the `ip_redirect_checker_urls` and `ip_redirect_checker_results`
WordPress options, with autoload disabled. Deactivation and deletion of the
plugin files do not erase these options or saved settings. Use the UI to clear
results if needed.

## Security And Maintenance

- Only users with `manage_options` can access the screen or batch endpoint.
- Requests use `wp_safe_remote_get()` with a separate safe request for every
  redirect hop. This reports the final destination while applying WordPress's
  URL safety rules. It may reject unrelated private-network destinations,
  embedded credentials, or unsafe ports. No browser credentials are forwarded.
