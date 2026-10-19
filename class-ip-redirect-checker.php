<?php
/**
 * Redirect Checker administrator tools.
 *
 * @package IP_Redirect_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin URL tester and resumable batch requests.
 *
 * Stores four-column results using zero-based URL list indexes.
 */
final class IP_Redirect_Checker {
	const PAGE    = 'ip-redirect-checker';
	const URLS    = 'ip_redirect_checker_urls';
	const RESULTS = 'ip_redirect_checker_results';
	const AUTO    = 'ip_redirect_checker_auto';
	const RECOVER = 'ip_redirect_checker_recover';
	const BLOCK   = 'ip_redirect_checker_block_canonical';
	const LOCK    = 'ip_redirect_checker_request_lock';

	private $updater;

	/**
	 * Initiate plugin and hooks
	 */
	public function __construct() {

		// Block WordPress canonical redirects when the setting is enabled (on by default).
		if ( get_option( 'ip_redirect_checker_block_canonical', true ) ) {
			add_filter( 'redirect_canonical', '__return_false' );
		}

		add_action( 'admin_menu', array( 'IP_Redirect_Checker', 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'IP_Redirect_Checker', 'enqueue_scripts' ) );
		add_action( 'wp_ajax_ip_redirect_checker_run', array( 'IP_Redirect_Checker', 'ajax_run' ) );

		if ( class_exists( '\InverseParadox\Framework\Updater' ) ) {
			$this->updater = new \InverseParadox\Framework\Updater(
				\IP_REDIRECT_CHECKER_FILE,
				\IP_REDIRECT_CHECKER_VERSION,
				'inverse-paradox',
				'ip-redirect-checker'
			);
		}
	}

	/**
	 * Load assets only on this plugin's Tools screen.
	 *
	 * @param string $hook Current admin screen hook.
	 * @return void
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ip-redirect-checker', plugins_url( 'admin.css', __FILE__ ), array(), (string) filemtime( __DIR__ . '/admin.css' ) );
		wp_enqueue_script( 'ip-redirect-checker', plugins_url( 'admin.js', __FILE__ ), array( 'wp-i18n' ), (string) filemtime( __DIR__ . '/admin.js' ), true );
		wp_set_script_translations( 'ip-redirect-checker', 'ip-redirect-checker' );
		wp_localize_script(
			'ip-redirect-checker',
			'ipRedirectChecker',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ip_redirect_checker_auto' ),
			)
		);
	}

	/**
	 * Acquire the site-wide write lock; expired locks can be reclaimed.
	 *
	 * @return array|false Lock identity, or false while another worker is active.
	 */
	private static function acquire_lock() {
		global $wpdb;
		$old = get_option( self::LOCK );
		if ( is_array( $old ) && isset( $old['expires'] ) && $old['expires'] < time() ) {
			// Compare and delete so an expired worker cannot remove a newer lock.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete is required; the option cache is cleared below.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, maybe_serialize( $old ) ) );
			wp_cache_delete( self::LOCK, 'options' );
		}
		$lock = array(
			'id'      => wp_generate_uuid4(),
			'expires' => time() + 90,
		);
		if ( ! add_option( self::LOCK, $lock, '', false ) ) {
			return false;
		}
		return $lock;
	}

	/**
	 * Release only this worker's lock, including after an expired lock was replaced.
	 *
	 * @param array $lock Lock identity returned by acquire_lock().
	 * @return void
	 */
	private static function release_lock( $lock ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete protects a replacement lock; the cache is cleared below.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK, maybe_serialize( $lock ) ) );
		wp_cache_delete( self::LOCK, 'options' );
	}

	/**
	 * Extend a long manual batch only while it still owns the lock.
	 *
	 * @param array $lock Lock identity, updated after a successful renewal.
	 * @return bool Whether this worker still owns the lock.
	 */
	private static function renew_lock( &$lock ) {
		global $wpdb;

		$renewed            = $lock;
		$renewed['expires'] = time() + 90;
		if ( $renewed === $lock ) {
			return true;
		}

		// The old value is part of the WHERE clause; option helpers cannot perform this comparison atomically.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomically renew this owner only; the option cache is cleared below.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $renewed ),
				self::LOCK,
				maybe_serialize( $lock )
			)
		);
		wp_cache_delete( self::LOCK, 'options' );
		if ( 1 !== $updated ) {
			return false;
		}
		$lock = $renewed;
		return true;
	}

	/**
	 * Run an authenticated batch and persist a cursor after every completed URL.
	 *
	 * @return void
	 */
	public static function ajax_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Administrator access required.', 'ip-redirect-checker' ) ), 403 );
		}
		check_ajax_referer( 'ip_redirect_checker_auto', 'nonce' );
		$token = self::post_value( 'token' );
		if ( '' !== $token && ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid run token. Start a new run.', 'ip-redirect-checker' ) ), 400 );
		}
		$lock = self::acquire_lock();
		if ( false === $lock ) {
			wp_send_json_error(
				array(
					'message' => __( 'Waiting for the previous request to finish.', 'ip-redirect-checker' ),
					'busy'    => true,
				),
				409
			);
		}
		$data  = null;
		$error = '';
		try {
			$urls        = get_option( self::URLS, array() );
			$fingerprint = hash( 'sha256', maybe_serialize( array( $urls, (bool) get_option( self::BLOCK, true ) ) ) );
			if ( '' === $token ) {
				$start = filter_var( self::post_value( 'si' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
				$batch = filter_var( self::post_value( 'mpr' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
				if ( false === $start || false === $batch || empty( $urls ) ) {
					$error = __( 'Save a URL list and enter a valid batch size and starting index.', 'ip-redirect-checker' );
				} else {
					$token = wp_generate_uuid4();
					$state = array(
						'next'        => min( $start, count( $urls ) ),
						'fingerprint' => $fingerprint,
						'batch'       => $batch,
					);
				}
			} else {
				$state = get_transient( 'ip_rc_' . get_current_user_id() . '_' . $token );
				if ( ! is_array( $state ) || ! isset( $state['batch'] ) || $state['fingerprint'] !== $fingerprint ) {
					$error = __( 'This run expired, or the URL list / canonical setting changed. Start a new run.', 'ip-redirect-checker' );
				}
			}
			if ( '' === $error ) {
				$current_rows = array();
				// Starting creates a resumable cursor; subsequent requests run a batch.
				if ( '' !== self::post_value( 'token' ) && $state['next'] < count( $urls ) ) {
					$started = microtime( true );
					$end     = $state['next'] + min( $state['batch'], count( $urls ) - $state['next'] );
					$rows    = get_option( self::RESULTS, array() );
					while ( $state['next'] < $end ) {
						$index          = $state['next'];
						$row            = self::check_url( $urls[ $index ], $index );
						$rows[ $index ] = $row;
						$current_rows[] = $row;
						update_option( self::RESULTS, $rows, false );
						$state['next'] = $state['next'] + 1;
						// Save the cursor after every row, not only after the batch.
						set_transient( 'ip_rc_' . get_current_user_id() . '_' . $token, $state, DAY_IN_SECONDS );
						// Yield between URLs on slow sites, without shortening a URL's timeout.
						if ( microtime( true ) - $started >= 20 ) {
							break;
						}
					}
				}
				set_transient( 'ip_rc_' . get_current_user_id() . '_' . $token, $state, DAY_IN_SECONDS );
				$data = array(
					'token' => $token,
					'next'  => $state['next'],
					'total' => count( $urls ),
					'done'  => $state['next'] >= count( $urls ),
					'rows'  => $current_rows,
				);
			}
		} finally {
			self::release_lock( $lock );
		}
		if ( $error ) {
			wp_send_json_error( array( 'message' => $error ), 400 );
		}
		wp_send_json_success( $data );
	}

	/**
	 * Register the administrator-only Tools screen.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_management_page( __( 'Redirect Checker', 'ip-redirect-checker' ), __( 'Redirect Checker', 'ip-redirect-checker' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Build an admin link without accepting a user-provided destination.
	 *
	 * @param array $args Optional query arguments.
	 * @return string
	 */
	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Read a scalar POST value; callers validate it for its particular use.
	 *
	 * @param string $key     Form field name.
	 * @param string $fallback Default when the field is absent or is an array.
	 * @return string
	 */
	private static function post_value( $key, $fallback = '' ) {
		// Callers verify the nonce; the action is read first only to select its nonce field.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return $fallback;
		}
		// Preserve URL percent encoding: sanitize_textarea_field() would strip encoded octets.
		// Callers validate numeric fields, whitelist actions, compare settings, and validate URLs before requests.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Context-specific validation is performed by the protected handlers.
		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Render escaped, copyable results in four columns.
	 *
	 * @param array $rows Result rows indexed by URL position.
	 * @return void
	 */
	private static function render_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No saved results match this view. Save a URL list and run a batch to get started.', 'ip-redirect-checker' ) . '</p>';
			return;
		}
		?>
		<div class="iprc-table-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'URL response results', 'ip-redirect-checker' ); ?>">
			<table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Index', 'ip-redirect-checker' ); ?></th><th scope="col"><?php esc_html_e( 'Original URL', 'ip-redirect-checker' ); ?></th><th scope="col"><?php esc_html_e( 'Response Code', 'ip-redirect-checker' ); ?></th><th scope="col"><?php esc_html_e( 'Final URL', 'ip-redirect-checker' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$status_class = 'iprc-status iprc-status-issue';
						if ( '200' === (string) $row['response'] ) {
							$status_class = 'iprc-status iprc-status-success';
						}
						?>
						<tr><td><?php echo esc_html( $row['i'] ); ?></td><td><?php echo esc_html( $row['ogurl'] ); ?></td><td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $row['response'] ); ?></span></td><td><?php echo esc_html( $row['newurl'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Return the final status and URL using a safe GET for every redirect hop.
	 *
	 * @param string $url   Original URL to test.
	 * @param int    $index Zero-based position in the saved list.
	 * @return array
	 */
	private static function check_url( $url, $index ) {
		$row    = array(
			'i'        => $index,
			'ogurl'    => $url,
			'response' => 'INVALID URL',
			'newurl'   => 'N/A',
		);
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $row;
		}

		$current_url = $url;
		$started     = microtime( true );

		// Follow each hop explicitly to validate every destination and report the final URL.
		for ( $redirects = 0; $redirects <= 50; $redirects++ ) {
			$remaining = 30 - ( microtime( true ) - $started );
			if ( $remaining <= 0 ) {
				$row['response'] = 'REQUEST ERROR';
				return $row;
			}

			// WordPress validates the destination, including private addresses and unsafe ports.
			// Never forward the administrator's browser cookies or credentials to tested URLs.
			$response      = wp_safe_remote_get(
				$current_url,
				array(
					'timeout'             => $remaining,
					'redirection'         => 0,
					'limit_response_size' => 1,
				)
			);
			$row['newurl'] = $current_url;
			if ( is_wp_error( $response ) ) {
				$row['response'] = 'REQUEST ERROR';
				return $row;
			}

			$row['response'] = (int) wp_remote_retrieve_response_code( $response );
			$location        = wp_remote_retrieve_header( $response, 'location' );
			if ( $row['response'] < 300 || $row['response'] >= 400 || ! is_string( $location ) || '' === $location ) {
				return $row;
			}
			if ( 50 === $redirects ) {
				$row['response'] = 'REQUEST ERROR';
				return $row;
			}
			$current_url = WP_Http::make_absolute_url( $location, $current_url );
		}

		return $row;
	}

	/**
	 * Handle protected admin forms and render the URL tester.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ip-redirect-checker' ) );
		}
		$urls         = get_option( self::URLS, array() );
		$rows         = get_option( self::RESULTS, array() );
		$current_rows = array();
		$max_per_run  = 50;
		$next_index   = 0;
		$notice       = '';
		$error        = '';
		$action       = '';

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$nonce_fields  = array(
				'save_urls'      => '_iprc_list_nonce',
				'save_settings'  => '_iprc_settings_nonce',
				'delete_results' => '_iprc_delete_nonce',
				'run'            => '_iprc_run_nonce',
			);
			$posted_action = self::post_value( 'ip_action' );
			if ( ! isset( $nonce_fields[ $posted_action ] ) ) {
				wp_die( esc_html__( 'Unknown form action.', 'ip-redirect-checker' ) );
			}
			check_admin_referer( 'ip_redirect_checker', $nonce_fields[ $posted_action ] );
			$lock = self::acquire_lock();
			if ( false === $lock ) {
				$error = __( 'Another batch is still running. Wait for it to finish before changing settings, lists, or results.', 'ip-redirect-checker' );
			} else {
				try {
					$urls   = get_option( self::URLS, array() );
					$rows   = get_option( self::RESULTS, array() );
					$action = self::post_value( 'ip_action' );
					if ( 'save_settings' === $action ) {
						update_option( self::BLOCK, '1' === self::post_value( 'block_canonical' ), false );
						update_option( self::AUTO, '1' === self::post_value( 'auto_continue' ), false );
						update_option( self::RECOVER, '1' === self::post_value( 'recover' ), false );
						$notice = __( 'Settings saved.', 'ip-redirect-checker' );
					} elseif ( 'save_urls' === $action ) {
						$lines    = preg_split( '/\r\n|\r|\n/', self::post_value( 'urls' ) );
						$new_urls = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
						if ( $new_urls !== $urls ) {
							$urls = $new_urls;
							update_option( self::URLS, $urls, false );
							delete_option( self::RESULTS );
							$rows   = array();
							$notice = __( 'URL list saved. Previous results were cleared because the list changed.', 'ip-redirect-checker' );
						} else {
							$notice = __( 'URL list saved.', 'ip-redirect-checker' );
						}
					} elseif ( 'delete_results' === $action ) {
						delete_option( self::RESULTS );
						$rows   = array();
						$notice = __( 'Stored URL data deleted. Your URL list has been kept.', 'ip-redirect-checker' );
					} elseif ( 'run' === $action ) {
						$max_per_run = filter_var( self::post_value( 'mpr' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
						$start_index = filter_var( self::post_value( 'si' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
						if ( false === $max_per_run || false === $start_index ) {
							$error       = __( 'Enter whole numbers: URLs per run must be at least 1 and Starting Index must be at least 0.', 'ip-redirect-checker' );
							$max_per_run = 50;

						} elseif ( empty( $urls ) ) {
							$error = __( 'Save a URL list before running a test.', 'ip-redirect-checker' );
						} elseif ( $start_index >= count( $urls ) ) {
							$next_index = count( $urls );
							$notice     = __( 'No URLs remain at this starting index.', 'ip-redirect-checker' );
						} else {
							$end_index = $start_index + min( $max_per_run, count( $urls ) - $start_index );
							for ( $i = $start_index; $i < $end_index; $i++ ) {
								if ( ! self::renew_lock( $lock ) ) {
									$error     = __( 'This batch lost its write lock. Reload the page before continuing.', 'ip-redirect-checker' );
									$end_index = $i;
									break;
								}
								$row            = self::check_url( $urls[ $i ], $i );
								$rows[ $i ]     = $row;
								$current_rows[] = $row;
								// Persist each completed URL so a later timeout does not lose it.
								update_option( self::RESULTS, $rows, false );
							}
							$next_index = $end_index;
							$notice     = __( 'Batch finished. Starting Index is ready for the next batch.', 'ip-redirect-checker' );
						}
					}
				} finally {
					self::release_lock( $lock );
				}
			}
		}

		if ( ! empty( $rows ) && 'run' !== $action ) {
			$next_index = max( array_keys( $rows ) ) + 1;
		}
		$view = 'all';
		if ( isset( $_GET['urldata'] ) && is_string( $_GET['urldata'] ) && 'potential-issues' === $_GET['urldata'] ) {
			$view = 'potential-issues';
		}
		ksort( $rows, SORT_NUMERIC );
		$issue_rows = array();
		foreach ( $rows as $index => $row ) {
			if ( '200' !== (string) $row['response'] ) {
				$issue_rows[ $index ] = $row;
			}
		}
		$display_rows = $rows;
		if ( 'potential-issues' === $view ) {
			$display_rows = $issue_rows;
		}
		$mode_label = __( 'Manual batches', 'ip-redirect-checker' );
		if ( get_option( self::AUTO, false ) ) {
			$mode_label = __( 'Automatic batches', 'ip-redirect-checker' );
		}
		$confirm_delete = isset( $_GET['delete'] ) && 'confirm' === $_GET['delete'];
		$run_attributes = array();
		if ( empty( $urls ) ) {
			$run_attributes['disabled'] = 'disabled';
		}

		require __DIR__ . '/admin-page.php';
	}
}
