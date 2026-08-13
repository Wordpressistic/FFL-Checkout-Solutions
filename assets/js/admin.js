/**
 * Admin behaviours.
 *
 * Small and unopinionated: confirmations on destructive actions, and live ATF
 * import progress so a long-running import does not look frozen.
 *
 * @package FFLCS
 */
( function () {
	'use strict';

	var cfg = window.fflcsAdmin || {};
	var i18n = cfg.i18n || {};

	function confirmDestructive() {
		document.querySelectorAll( '[data-fflcs-confirm]' ).forEach( function ( element ) {
			element.addEventListener( 'click', function ( event ) {
				var message = element.getAttribute( 'data-fflcs-confirm' ) || i18n.confirm;

				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	/**
	 * Poll the sync status while an import is running.
	 *
	 * Stops as soon as it is no longer running, so a finished import does not
	 * keep an admin tab making requests indefinitely.
	 */
	function trackImportProgress() {
		var bar = document.querySelector( '.fflcs-progress span' );

		if ( ! bar || ! cfg.restUrl ) {
			return;
		}

		var timer = setInterval( function () {
			fetch( cfg.restUrl + 'admin/activity/summary', {
				headers: { 'X-WP-Nonce': cfg.nonce },
				credentials: 'same-origin'
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( data ) {
					if ( ! data ) {
						clearInterval( timer );
						return;
					}
					// The dashboard reloads to pick up the new counts rather
					// than trying to patch every figure in place.
					window.location.reload();
				} )
				.catch( function () {
					clearInterval( timer );
				} );
		}, 30000 );
	}

	function init() {
		confirmDestructive();
		trackImportProgress();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
