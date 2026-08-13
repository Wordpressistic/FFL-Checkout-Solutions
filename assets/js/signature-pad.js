/**
 * Signature capture pad.
 *
 * Pointer events rather than separate mouse and touch handlers, so a stylus on
 * a tablet — the realistic device at a gun counter — works the same as a mouse.
 *
 * @package FFLCS
 */
( function () {
	'use strict';

	function setup( canvas ) {
		var ctx = canvas.getContext( '2d' );
		var ratio = window.devicePixelRatio || 1;
		var drawing = false;
		var dirty = false;

		canvas.width = canvas.clientWidth * ratio;
		canvas.height = canvas.clientHeight * ratio;
		ctx.scale( ratio, ratio );

		ctx.lineWidth = 2;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		ctx.strokeStyle = '#0f172a';

		function position( event ) {
			var rect = canvas.getBoundingClientRect();
			return { x: event.clientX - rect.left, y: event.clientY - rect.top };
		}

		canvas.addEventListener( 'pointerdown', function ( event ) {
			drawing = true;
			dirty = true;
			// Capture the pointer so a stroke that leaves the canvas still
			// tracks, instead of ending mid-signature.
			canvas.setPointerCapture( event.pointerId );

			var point = position( event );
			ctx.beginPath();
			ctx.moveTo( point.x, point.y );
		} );

		canvas.addEventListener( 'pointermove', function ( event ) {
			if ( ! drawing ) {
				return;
			}
			event.preventDefault();

			var point = position( event );
			ctx.lineTo( point.x, point.y );
			ctx.stroke();
		} );

		[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( name ) {
			canvas.addEventListener( name, function () {
				drawing = false;
			} );
		} );

		var form = canvas.closest( 'form' );
		var field = form ? form.querySelector( 'input[name="signature_data"]' ) : null;
		var clear = form ? form.querySelector( '[data-fflcs-clear-signature]' ) : null;

		if ( clear ) {
			clear.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				ctx.clearRect( 0, 0, canvas.width, canvas.height );
				dirty = false;
			} );
		}

		if ( form && field ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! dirty ) {
					event.preventDefault();
					window.alert( canvas.getAttribute( 'data-empty-message' ) || 'Please sign before submitting.' );
					return;
				}

				field.value = canvas.toDataURL( 'image/png' );
			} );
		}
	}

	function init() {
		document.querySelectorAll( 'canvas[data-fflcs-signature]' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
