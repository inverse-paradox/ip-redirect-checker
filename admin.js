/**
 * Copy the currently rendered saved results as tab-separated spreadsheet cells.
 * Initialize separately so copying also works when automatic runs are disabled.
 */

/* global ipRedirectChecker, navigator */
( function () {
	'use strict';

	const button = document.getElementById( 'iprc-copy-results' );
	const status = document.getElementById( 'iprc-copy-status' );

	if ( ! button || ! status ) {
		return;
	}

	button.addEventListener( 'click', async function () {
		const table = document.querySelector( '#iprc-saved-results table' );

		if (
			! table ||
			! table.tBodies.length ||
			! table.tBodies[ 0 ].rows.length
		) {
			status.textContent = wp.i18n.__(
				'No visible results to copy.',
				'ip-redirect-checker'
			);
			return;
		}

		// Read this view only; preserve encoded URLs and keep each cell on one line.
		const text = Array.from( table.rows )
			.map( function ( row ) {
				return Array.from( row.cells )
					.map( function ( cell ) {
						return cell.textContent
							.replace( /[\t\r\n]+/g, ' ' )
							.trim();
					} )
					.join( '\t' );
			} )
			.join( '\r\n' );

		button.disabled = true;
		status.textContent = '';

		try {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				await navigator.clipboard.writeText( text );
			} else {
				// Older browsers and HTTP admin screens lack the Clipboard API.
				const field = document.createElement( 'textarea' );
				field.value = text;
				field.readOnly = true;
				field.className = 'screen-reader-text';
				button.parentNode.appendChild( field );

				try {
					field.select();
					if ( ! document.execCommand( 'copy' ) ) {
						throw new Error( 'Clipboard unavailable.' );
					}
				} finally {
					field.remove();
					button.disabled = false;
					button.focus();
				}
			}

			status.textContent = wp.i18n.__(
				'Visible results copied, including column headings. Paste into your spreadsheet.',
				'ip-redirect-checker'
			);
		} catch {
			status.textContent = wp.i18n.__(
				'Clipboard access failed. Select the table and copy it manually, or allow clipboard access in your browser.',
				'ip-redirect-checker'
			);
		} finally {
			button.disabled = false;
		}
	} );
} )();

/**
 * Continue administrator-started batches without reloading the Tools screen.
 * Results and the retry cursor are saved by PHP after every completed URL.
 */
( function () {
	'use strict';

	const form = document.getElementById( 'ip-redirect-checker-run' );

	if ( ! form || form.dataset.auto !== '1' ) {
		return;
	}

	const sprintf = wp.i18n.sprintf;
	const progress = document.getElementById( 'ip-auto-progress' );
	const stop = document.getElementById( 'ip-auto-stop' );
	const output = document.getElementById( 'ip-auto-results' );
	const recover = form.dataset.recover === '1';
	let running = false;
	let disabled = [];

	/**
	 * Allow the server a short pause between batches and recovery attempts.
	 *
	 * @param {number} milliseconds Delay before resolving.
	 * @return {Promise} Completion of the delay.
	 */
	function delay( milliseconds ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, milliseconds );
		} );
	}

	stop.addEventListener( 'click', function () {
		running = false;
		stop.disabled = true;
		progress.textContent = wp.i18n.__(
			'Stopping after the current batch finishes. Completed results are saved.',
			'ip-redirect-checker'
		);
	} );

	/**
	 * Request a batch, optionally recovering from a lost or stalled response.
	 *
	 * The server lock prevents overlapping requests, while the saved cursor
	 * prevents a successful but undelivered batch from being run again.
	 *
	 * @param {Object} parameters Run token or initial batch parameters.
	 * @return {Promise<Object|null>} Saved progress, or null when stopped.
	 */
	async function request( parameters ) {
		let retries = 0;
		// Keep one recovery deadline across every attempt in this request.
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return
		const began = Date.now();

		while ( running ) {
			const controller = new AbortController();
			const timer = setTimeout( function () {
				controller.abort();
			}, 60000 );

			try {
				const body = new URLSearchParams(
					Object.assign(
						{
							action: 'ip_redirect_checker_run',
							nonce: ipRedirectChecker.nonce,
						},
						parameters
					)
				);
				const response = await fetch( ipRedirectChecker.url, {
					method: 'POST',
					credentials: 'same-origin',
					body,
					signal: controller.signal,
				} );

				if ( response.status === 401 || response.status === 403 ) {
					const error = new Error(
						wp.i18n.__(
							'Your session expired or access was denied. Reload this page and sign in again if needed.',
							'ip-redirect-checker'
						)
					);
					error.permanent = true;
					throw error;
				}

				const result = await response.json();

				if ( ! result || ! result.success || ! result.data ) {
					let message = wp.i18n.__(
						'The server did not return a valid batch response.',
						'ip-redirect-checker'
					);
					if ( result && result.data && result.data.message ) {
						message = result.data.message;
					}
					const error = new Error( message );
					error.busy = Boolean(
						result && result.data && result.data.busy
					);
					error.permanent = response.status === 400;
					throw error;
				}

				return result.data;
			} catch ( error ) {
				if ( ! running ) {
					return null;
				}
				if (
					error.permanent ||
					! recover ||
					retries >= 3 ||
					Date.now() - began > 180000
				) {
					throw error;
				}
				if ( ! error.busy ) {
					retries = retries + 1;
				}
				progress.textContent = wp.i18n.__(
					'Request stalled or failed. Waiting before resuming saved progress…',
					'ip-redirect-checker'
				);
				await delay( 5000 );
			} finally {
				clearTimeout( timer );
			}
		}

		return null;
	}

	form.addEventListener( 'submit', async function ( event ) {
		event.preventDefault();

		if ( running ) {
			return;
		}

		const batchSize = Number( form.elements.mpr.value );
		const start = Number( form.elements.si.value );

		if (
			! Number.isSafeInteger( batchSize ) ||
			batchSize < 1 ||
			! Number.isSafeInteger( start ) ||
			start < 0
		) {
			progress.textContent = wp.i18n.__(
				'Enter a positive batch size and a starting index of 0 or higher.',
				'ip-redirect-checker'
			);
			return;
		}

		running = true;
		stop.hidden = false;
		stop.disabled = false;

		// Keep the saved list and settings stable during this run. Preserve any
		// controls that were already disabled, such as an empty-list submit button.
		disabled = Array.from(
			document.querySelectorAll(
				'.iprc-wrap form input, .iprc-wrap form textarea, .iprc-wrap form button'
			)
		).filter( function ( element ) {
			return ! element.disabled;
		} );
		disabled.forEach( function ( element ) {
			element.disabled = true;
		} );

		output.replaceChildren();
		const table = document.createElement( 'table' );
		table.className = 'widefat striped';
		const header = table.createTHead().insertRow();
		const headings = [
			wp.i18n.__( 'Index', 'ip-redirect-checker' ),
			wp.i18n.__( 'Original URL', 'ip-redirect-checker' ),
			wp.i18n.__( 'Response Code', 'ip-redirect-checker' ),
			wp.i18n.__( 'Final URL', 'ip-redirect-checker' ),
		];

		headings.forEach( function ( label ) {
			const cell = document.createElement( 'th' );
			cell.scope = 'col';
			cell.textContent = label;
			header.appendChild( cell );
		} );

		const rows = table.createTBody();
		output.appendChild( table );
		progress.textContent = wp.i18n.__(
			'Starting automatic run…',
			'ip-redirect-checker'
		);

		try {
			let state = await request( {
				si: String( start ),
				mpr: String( batchSize ),
			} );

			while ( running && state && ! state.done ) {
				progress.textContent = sprintf(
					/* translators: 1: Batch size, 2: Starting index, 3: Total URLs. */
					wp.i18n.__(
						'Checking up to %1$d URLs, starting at index %2$d of %3$d…',
						'ip-redirect-checker'
					),
					batchSize,
					state.next,
					state.total
				);
				state = await request( { token: state.token } );

				if ( ! state ) {
					break;
				}

				form.elements.si.value = state.next;

				for ( const result of state.rows || [] ) {
					const row = rows.insertRow();
					[ 'i', 'ogurl', 'response', 'newurl' ].forEach(
						function ( key ) {
							const cell = row.insertCell();
							// Use text rather than HTML: tested URLs may contain markup.
							cell.textContent = result[ key ];
							if ( key === 'response' ) {
								if ( String( result[ key ] ) === '200' ) {
									cell.className = 'iprc-status-success';
								} else {
									cell.className = 'iprc-status-issue';
								}
							}
						}
					);
				}

				if ( ! state.done && running ) {
					progress.textContent = wp.i18n.__(
						'Batch finished. Continuing in 2 seconds…',
						'ip-redirect-checker'
					);
					await delay( 2000 );
				}
			}

			if ( state ) {
				form.elements.si.value = state.next;
			}

			progress.textContent = wp.i18n.__(
				'Automatic run stopped. Completed results are saved.',
				'ip-redirect-checker'
			);
			if ( state && state.done ) {
				progress.textContent = wp.i18n.__(
					'All remaining URLs checked. Reload Saved results for the complete list, including recovered requests.',
					'ip-redirect-checker'
				);
			}
		} catch ( error ) {
			progress.textContent = sprintf(
				/* translators: %s: Request failure message. */
				wp.i18n.__(
					'Automatic run paused: %s Reload this page to resume from saved progress.',
					'ip-redirect-checker'
				),
				error.message
			);
		} finally {
			running = false;
			stop.hidden = true;
			disabled.forEach( function ( element ) {
				element.disabled = false;
			} );
		}
	} );
} )();
