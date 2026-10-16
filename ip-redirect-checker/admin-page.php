<?php
/**
 * Redirect Checker admin screen.
 *
 * Variables are supplied by IP_Redirect_Checker::render_page().
 *
 * @package IP_Redirect_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap iprc-wrap">
	<h1><?php esc_html_e( 'Redirect Checker', 'ip-redirect-checker' ); ?></h1>
	<p class="iprc-intro"><?php esc_html_e( 'Check URL destinations, find response errors, and control WordPress canonical redirects.', 'ip-redirect-checker' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<div class="iprc-summary" aria-label="<?php esc_attr_e( 'Saved test summary', 'ip-redirect-checker' ); ?>">
		<div><span><?php esc_html_e( 'URLs in list', 'ip-redirect-checker' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $urls ) ) ); ?></strong></div>
		<div><span><?php esc_html_e( 'Saved results', 'ip-redirect-checker' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></strong></div>
		<div><span><?php esc_html_e( 'Potential issues', 'ip-redirect-checker' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $issue_rows ) ) ); ?></strong></div>
	</div>

	<div class="iprc-layout">
		<div class="iprc-main">
			<section class="postbox iprc-panel" aria-labelledby="iprc-list-heading">
				<div class="iprc-panel-heading"><h2 id="iprc-list-heading"><?php esc_html_e( 'URL list', 'ip-redirect-checker' ); ?></h2></div>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
						<?php wp_nonce_field( 'ip_redirect_checker', '_iprc_list_nonce' ); ?>
						<input type="hidden" name="ip_action" value="save_urls" />
						<p><label for="ip-urls"><?php esc_html_e( 'One complete HTTP or HTTPS URL per line.', 'ip-redirect-checker' ); ?></label></p>
						<textarea id="ip-urls" name="urls" rows="9" class="large-text code" spellcheck="false" aria-describedby="iprc-list-help"><?php echo esc_textarea( implode( "\n", $urls ) ); ?></textarea>
						<p class="description" id="iprc-list-help"><?php esc_html_e( 'Blank lines are ignored. Saving a changed list clears the previous results.', 'ip-redirect-checker' ); ?></p>
						<?php submit_button( __( 'Save URL List', 'ip-redirect-checker' ), 'secondary', 'iprc_save_urls' ); ?>
					</form>
				</div>
			</section>

			<section class="postbox iprc-panel" aria-labelledby="iprc-run-heading">
				<div class="iprc-panel-heading"><h2 id="iprc-run-heading"><?php esc_html_e( 'Run URL checks', 'ip-redirect-checker' ); ?></h2><span class="iprc-badge"><?php echo esc_html( $mode_label ); ?></span></div>
				<div class="inside">
					<form id="ip-redirect-checker-run" data-auto="<?php echo esc_attr( (int) get_option( self::AUTO, false ) ); ?>" data-recover="<?php echo esc_attr( (int) get_option( self::RECOVER, false ) ); ?>" method="post" action="<?php echo esc_url( self::page_url() ); ?>">
						<?php wp_nonce_field( 'ip_redirect_checker', '_iprc_run_nonce' ); ?>
						<input type="hidden" name="ip_action" value="run" />
						<table class="form-table" role="presentation">
							<tr><th scope="row"><label for="mpr"><?php esc_html_e( 'URLs per run', 'ip-redirect-checker' ); ?></label></th><td><input type="number" id="mpr" name="mpr" min="1" step="1" required class="small-text" value="<?php echo esc_attr( $max_per_run ); ?>" aria-describedby="iprc-batch-help" /><p class="description" id="iprc-batch-help"><?php esc_html_e( 'Start with 50. Use a smaller batch if your hosting times out.', 'ip-redirect-checker' ); ?></p></td></tr>
							<tr><th scope="row"><label for="si"><?php esc_html_e( 'Starting index', 'ip-redirect-checker' ); ?></label></th><td><input type="number" id="si" name="si" min="0" step="1" required class="small-text" value="<?php echo esc_attr( $next_index ); ?>" aria-describedby="iprc-index-help" /><p class="description" id="iprc-index-help"><?php esc_html_e( 'The first URL is index 0. This advances after each batch; change it to retest earlier URLs.', 'ip-redirect-checker' ); ?></p></td></tr>
						</table>
						<?php submit_button( __( 'Get Response Codes', 'ip-redirect-checker' ), 'primary', 'iprc_run', true, $run_attributes ); ?>
					</form>
					<div class="iprc-run-status"><p id="ip-auto-progress" role="status" aria-live="polite" aria-atomic="true"></p><button type="button" id="ip-auto-stop" class="button" hidden><?php esc_html_e( 'Stop Automatic Run', 'ip-redirect-checker' ); ?></button></div>
					<div id="ip-auto-results" class="iprc-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Automatic run results', 'ip-redirect-checker' ); ?>"></div>
					<?php if ( $current_rows ) : ?>
						<h3><?php esc_html_e( 'Current batch results', 'ip-redirect-checker' ); ?></h3>
						<?php self::render_table( $current_rows ); ?>
					<?php endif; ?>
				</div>
			</section>

			<section class="postbox iprc-panel" id="iprc-saved-results" aria-labelledby="iprc-results-heading">
				<div class="iprc-panel-heading"><h2 id="iprc-results-heading"><?php esc_html_e( 'Saved results', 'ip-redirect-checker' ); ?></h2></div>
				<div class="inside">
					<p class="description"><?php esc_html_e( 'Final responses after redirects. Copy visible results to paste all four columns into a spreadsheet. Reload this view after an automatic run to see updated saved results.', 'ip-redirect-checker' ); ?></p>
					<div class="iprc-results-toolbar">
						<ul class="subsubsub" aria-label="<?php esc_attr_e( 'Filter saved results', 'ip-redirect-checker' ); ?>">
							<li><a href="<?php echo esc_url( self::page_url( array( 'urldata' => 'all' ) ) . '#iprc-saved-results' ); ?>" 
							<?php
							if ( 'all' === $view ) {
								echo 'class="current" aria-current="page"'; }
							?>
							><?php esc_html_e( 'All results', 'ip-redirect-checker' ); ?> <span class="count">(<?php echo esc_html( count( $rows ) ); ?>)</span></a> |</li>
							<li><a href="<?php echo esc_url( self::page_url( array( 'urldata' => 'potential-issues' ) ) . '#iprc-saved-results' ); ?>" 
							<?php
							if ( 'potential-issues' === $view ) {
								echo 'class="current" aria-current="page"'; }
							?>
							><?php esc_html_e( 'Potential issues', 'ip-redirect-checker' ); ?> <span class="count">(<?php echo esc_html( count( $issue_rows ) ); ?>)</span></a></li>
						</ul>
						<button type="button" id="iprc-copy-results" class="button" <?php disabled( empty( $display_rows ) ); ?>><?php esc_html_e( 'Copy visible results', 'ip-redirect-checker' ); ?></button>
						<a class="submitdelete" href="<?php echo esc_url( self::page_url( array( 'delete' => 'confirm' ) ) . '#iprc-delete-confirm' ); ?>"><?php esc_html_e( 'Delete saved results', 'ip-redirect-checker' ); ?></a>
					</div>
					<p id="iprc-copy-status" role="status" aria-live="polite" aria-atomic="true"></p>
					<?php if ( $confirm_delete ) : ?>
						<div id="iprc-delete-confirm" class="iprc-delete-confirm">
							<h3><?php esc_html_e( 'Delete all saved results?', 'ip-redirect-checker' ); ?></h3>
							<p><?php esc_html_e( 'This cannot be undone. Your URL list and settings will be kept.', 'ip-redirect-checker' ); ?></p>
							<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
								<?php wp_nonce_field( 'ip_redirect_checker', '_iprc_delete_nonce' ); ?>
								<input type="hidden" name="ip_action" value="delete_results" />
								<?php submit_button( __( 'Confirm Deletion', 'ip-redirect-checker' ), 'secondary', 'iprc_delete', false ); ?>
								<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Cancel', 'ip-redirect-checker' ); ?></a>
							</form>
						</div>
					<?php endif; ?>
					<?php self::render_table( $display_rows ); ?>
				</div>
			</section>
		</div>

		<div class="iprc-sidebar">
			<section class="postbox iprc-panel" aria-labelledby="iprc-settings-heading">
				<div class="iprc-panel-heading"><h2 id="iprc-settings-heading"><?php esc_html_e( 'Settings', 'ip-redirect-checker' ); ?></h2></div>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
						<?php wp_nonce_field( 'ip_redirect_checker', '_iprc_settings_nonce' ); ?>
						<input type="hidden" name="ip_action" value="save_settings" />
						<fieldset class="iprc-setting"><legend class="screen-reader-text"><?php esc_html_e( 'Canonical redirects', 'ip-redirect-checker' ); ?></legend><label for="iprc-block"><input id="iprc-block" type="checkbox" name="block_canonical" value="1" <?php checked( get_option( self::BLOCK, true ) ); ?> aria-describedby="iprc-block-help" /> <?php esc_html_e( 'Block canonical redirects', 'ip-redirect-checker' ); ?></label><p class="description" id="iprc-block-help"><?php esc_html_e( 'Applies site-wide, including close-match and trailing-slash corrections. Uncheck to restore normal WordPress behavior. Cached redirects may need clearing.', 'ip-redirect-checker' ); ?></p></fieldset>
						<fieldset class="iprc-setting"><legend class="screen-reader-text"><?php esc_html_e( 'Automatic testing', 'ip-redirect-checker' ); ?></legend><label for="iprc-auto"><input id="iprc-auto" type="checkbox" name="auto_continue" value="1" <?php checked( get_option( self::AUTO, false ) ); ?> aria-describedby="iprc-auto-help" /> <?php esc_html_e( 'Continue automatically', 'ip-redirect-checker' ); ?></label><p class="description" id="iprc-auto-help"><?php esc_html_e( 'Uses your batch size and continues until the list is finished. Keep this tab open.', 'ip-redirect-checker' ); ?></p></fieldset>
						<fieldset class="iprc-setting"><legend class="screen-reader-text"><?php esc_html_e( 'Timeout recovery', 'ip-redirect-checker' ); ?></legend><label for="iprc-recover"><input id="iprc-recover" type="checkbox" name="recover" value="1" <?php checked( get_option( self::RECOVER, false ) ); ?> aria-describedby="iprc-recover-help" /> <?php esc_html_e( 'Retry stalled requests', 'ip-redirect-checker' ); ?></label><p class="description" id="iprc-recover-help"><?php esc_html_e( 'For automatic mode. Detects stalls after 60 seconds and retries up to three times from saved progress.', 'ip-redirect-checker' ); ?></p></fieldset>
						<?php submit_button( __( 'Save Settings', 'ip-redirect-checker' ), 'secondary', 'iprc_save_settings' ); ?>
					</form>
				</div>
			</section>
			<section class="postbox iprc-panel" aria-labelledby="iprc-help-heading">
				<div class="iprc-panel-heading"><h2 id="iprc-help-heading"><?php esc_html_e( 'How results work', 'ip-redirect-checker' ); ?></h2></div>
				<div class="inside">
					<p><?php esc_html_e( 'A URL that redirects successfully to a 200 response stays out of Potential issues. The table shows its final destination.', 'ip-redirect-checker' ); ?></p>
					<p><?php esc_html_e( 'Potential issues includes every final result other than 200: errors, redirect loops, and failed or blocked requests.', 'ip-redirect-checker' ); ?></p>
					<p><?php esc_html_e( 'Slow automatic batches can finish early and continue in the next request. Each completed URL is saved.', 'ip-redirect-checker' ); ?></p>
				</div>
			</section>
		</div>
	</div>
</div>
