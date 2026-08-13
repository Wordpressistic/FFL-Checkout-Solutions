/**
 * Dealer confirmation portal.
 *
 * The three action buttons all submit the same form; which button was pressed
 * decides the action. Submitting rather than firing three separate handlers
 * keeps native validation (required fields, the OTP input) working.
 *
 * @package FFLCS
 */
( function () {
	'use strict';

	var cfg = window.fflcsPortal || {};
	var i18n = cfg.i18n || {};

	var form = document.getElementById( 'fflcs-portal-form' );
	var result = document.getElementById( 'fflcs-portal-result' );
	var actionField = document.getElementById( 'fflcs-portal-action' );

	if ( ! form ) {
		return;
	}

	function show( message, tone ) {
		result.hidden = false;
		result.className = 'fflcs-portal__result is-' + ( tone || 'info' );
		result.textContent = message;
	}

	function setBusy( busy ) {
		form.querySelectorAll( 'button' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
	}

	// Record which button was pressed before submit fires, so the handler knows
	// the intent. The click order is guaranteed: click precedes submit.
	form.querySelectorAll( 'button[data-action]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			actionField.value = button.getAttribute( 'data-action' );
		} );
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		if ( ! form.checkValidity() ) {
			form.reportValidity();
			return;
		}

		setBusy( true );
		show( i18n.submitting, 'info' );

		var payload = {};
		new FormData( form ).forEach( function ( value, key ) {
			payload[ key ] = value;
		} );

		fetch( cfg.confirmUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( outcome ) {
				if ( ! outcome.ok || ! outcome.data || ! outcome.data.success ) {
					var message =
						( outcome.data && outcome.data.message ) || i18n.error;
					show( message, 'error' );
					setBusy( false );
					return;
				}

				// Success is terminal: the token is spent, so the form is
				// removed rather than left in a state that would fail if
				// pressed again.
				show( outcome.data.message, 'success' );
				form.remove();
			} )
			.catch( function () {
				show( i18n.error, 'error' );
				setBusy( false );
			} );
	} );

	// ── Email OTP ────────────────────────────────────────────────────────────

	var otpButton = document.getElementById( 'fflcs-send-otp' );

	if ( otpButton ) {
		otpButton.addEventListener( 'click', function () {
			otpButton.disabled = true;

			var body = new URLSearchParams();
			body.append( 'action', 'fflcs_portal_otp' );
			body.append( 'token', form.querySelector( 'input[name="token"]' ).value );

			fetch( cfg.otpUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( data && data.success ) {
						show( i18n.otpSent, 'success' );
					} else {
						show(
							( data && data.data && data.data.message ) || i18n.otpFailed,
							'error'
						);
					}
				} )
				.catch( function () {
					show( i18n.otpFailed, 'error' );
				} )
				.finally( function () {
					// Re-enable after a beat so an impatient double-click does
					// not send two codes and invalidate the first.
					setTimeout( function () {
						otpButton.disabled = false;
					}, 5000 );
				} );
		} );
	}
} )();
