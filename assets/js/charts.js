/**
 * Dependency-free canvas charts.
 *
 * Vendored rather than pulled from a CDN: this plugin charts firearm transfer
 * volumes, and that data should not be handed to a third-party script host — nor
 * should a store's admin break because a CDN is unreachable.
 *
 * Renders line and bar charts from a data-series attribute. Deliberately small:
 * no animation, no tooltips, no legend engine. Enough to read a trend.
 *
 * @package FFLCS
 */
( function () {
	'use strict';

	var PALETTE = [ '#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed' ];

	function parse( canvas ) {
		try {
			return JSON.parse( canvas.getAttribute( 'data-series' ) || 'null' );
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Size the backing store to the device pixel ratio so lines are not blurry
	 * on a retina display, then scale the context back to CSS pixels.
	 */
	function prepare( canvas ) {
		var ratio = window.devicePixelRatio || 1;
		var width = canvas.clientWidth || canvas.parentNode.clientWidth || 600;
		var height = parseInt( canvas.getAttribute( 'height' ), 10 ) || 240;

		canvas.width = width * ratio;
		canvas.height = height * ratio;
		canvas.style.width = width + 'px';
		canvas.style.height = height + 'px';

		var ctx = canvas.getContext( '2d' );
		ctx.scale( ratio, ratio );

		return { ctx: ctx, width: width, height: height };
	}

	function axes( ctx, width, height, pad ) {
		ctx.strokeStyle = '#e2e8f0';
		ctx.lineWidth = 1;
		ctx.beginPath();
		ctx.moveTo( pad, pad );
		ctx.lineTo( pad, height - pad );
		ctx.lineTo( width - pad, height - pad );
		ctx.stroke();
	}

	function drawLine( canvas, series ) {
		var setup = prepare( canvas );
		var ctx = setup.ctx;
		var pad = 34;
		var plotW = setup.width - pad * 2;
		var plotH = setup.height - pad * 2;

		var names = Object.keys( series );
		var max = 1;

		names.forEach( function ( name ) {
			Object.keys( series[ name ] ).forEach( function ( day ) {
				max = Math.max( max, Number( series[ name ][ day ] ) );
			} );
		} );

		axes( ctx, setup.width, setup.height, pad );

		names.forEach( function ( name, index ) {
			var points = Object.keys( series[ name ] );

			if ( ! points.length ) {
				return;
			}

			ctx.strokeStyle = PALETTE[ index % PALETTE.length ];
			ctx.lineWidth = 2;
			ctx.beginPath();

			points.forEach( function ( day, i ) {
				// Guard the single-point case: dividing by (length - 1) would
				// be a division by zero and paint nothing.
				var x = pad + ( points.length > 1 ? ( i / ( points.length - 1 ) ) * plotW : plotW / 2 );
				var y = setup.height - pad - ( Number( series[ name ][ day ] ) / max ) * plotH;

				if ( i === 0 ) {
					ctx.moveTo( x, y );
				} else {
					ctx.lineTo( x, y );
				}
			} );

			ctx.stroke();
		} );

		ctx.fillStyle = '#64748b';
		ctx.font = '11px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		ctx.fillText( '0', 8, setup.height - pad );
		ctx.fillText( String( max ), 8, pad + 4 );
	}

	function drawBar( canvas, series ) {
		var setup = prepare( canvas );
		var ctx = setup.ctx;
		var pad = 34;
		var keys = Object.keys( series );

		if ( ! keys.length ) {
			return;
		}

		var plotW = setup.width - pad * 2;
		var plotH = setup.height - pad * 2;
		var max = 1;

		keys.forEach( function ( key ) {
			max = Math.max( max, Number( series[ key ] ) );
		} );

		axes( ctx, setup.width, setup.height, pad );

		var slot = plotW / keys.length;
		var barW = Math.max( 6, slot * 0.6 );

		keys.forEach( function ( key, index ) {
			var value = Number( series[ key ] );
			var barH = ( value / max ) * plotH;
			var x = pad + index * slot + ( slot - barW ) / 2;

			ctx.fillStyle = PALETTE[ index % PALETTE.length ];
			ctx.fillRect( x, setup.height - pad - barH, barW, barH );

			ctx.fillStyle = '#64748b';
			ctx.font = '10px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
			ctx.save();
			// Rotate the labels: status slugs are long and would otherwise
			// overlap at any realistic width.
			ctx.translate( x + barW / 2, setup.height - pad + 6 );
			ctx.rotate( -Math.PI / 4 );
			ctx.textAlign = 'right';
			ctx.fillText( key.replace( /_/g, ' ' ), 0, 0 );
			ctx.restore();
		} );
	}

	function render() {
		document.querySelectorAll( 'canvas[data-series]' ).forEach( function ( canvas ) {
			var series = parse( canvas );

			if ( ! series ) {
				return;
			}

			if ( 'bar' === canvas.getAttribute( 'data-chart' ) ) {
				drawBar( canvas, series );
			} else {
				drawLine( canvas, series );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', render );
	} else {
		render();
	}

	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( render, 200 );
	} );
} )();
