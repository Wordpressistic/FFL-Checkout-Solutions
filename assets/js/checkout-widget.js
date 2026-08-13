/**
 * FFL dealer selector — checkout widget.
 *
 * Vanilla JS, no dependencies. Runs on both the classic and block checkouts:
 * selection state lives in hidden inputs inside the checkout form, which both
 * modes submit, so no block-specific data store is needed.
 *
 * @package FFLCS
 */
( function () {
	'use strict';

	var cfg = window.fflcsCheckout || {};
	var i18n = cfg.i18n || {};

	var root;
	var resultsEl;
	var countEl;
	var resultsBar;
	var selectedEl;
	var selectedInfoEl;
	var savedEl;

	var inputs = {};
	var activeTab = 'zip';
	var lastResults = [];
	var inFlight = null;

	function $( id ) {
		return document.getElementById( id );
	}

	function text( el, value ) {
		el.textContent = value == null ? '' : String( value );
	}

	/**
	 * Build an element with attributes and text. Used instead of innerHTML
	 * throughout: dealer names come from the ATF feed and are rendered into the
	 * page, so nothing user- or feed-supplied is ever parsed as markup.
	 */
	function el( tag, className, textContent ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( textContent != null ) {
			node.textContent = String( textContent );
		}
		return node;
	}

	function money( amount ) {
		var symbol = cfg.currency || '$';
		return symbol + Number( amount || 0 ).toFixed( 2 );
	}

	// ── Tabs ─────────────────────────────────────────────────────────────────

	function activateTab( name ) {
		activeTab = name;

		root.querySelectorAll( '.fflcs-tab' ).forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-tab' ) === name;
			tab.classList.toggle( 'is-active', on );
			tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );

		root.querySelectorAll( '.fflcs-panel' ).forEach( function ( panel ) {
			var on = panel.getAttribute( 'data-panel' ) === name;
			panel.classList.toggle( 'is-active', on );
			panel.hidden = ! on;
		} );
	}

	// ── Search ───────────────────────────────────────────────────────────────

	function currentQuery() {
		var params = {};

		if ( activeTab === 'zip' ) {
			var zip = ( inputs.zip.value || '' ).replace( /\D/g, '' );
			if ( zip.length !== 5 ) {
				return { error: i18n.invalidZip };
			}
			params.zip = zip;
			params.radius = inputs.radius.value;
		} else if ( activeTab === 'name' ) {
			if ( ! inputs.name.value.trim() ) {
				return { error: i18n.needFilter };
			}
			params.name = inputs.name.value.trim();
			if ( inputs.state.value ) {
				params.state = inputs.state.value;
			}
		} else if ( activeTab === 'license' ) {
			if ( ! inputs.license.value.trim() ) {
				return { error: i18n.needFilter };
			}
			params.license = inputs.license.value.trim();
		} else if ( activeTab === 'phone' ) {
			var phone = ( inputs.phone.value || '' ).replace( /\D/g, '' );
			if ( phone.length < 4 ) {
				return { error: i18n.needFilter };
			}
			params.phone = phone;
		}

		return { params: params };
	}

	function search() {
		var query = currentQuery();

		if ( query.error ) {
			renderMessage( query.error, 'error' );
			return;
		}

		// Supersede any request still in flight, so a fast second search never
		// gets overwritten by a slow first one resolving after it.
		if ( inFlight && inFlight.abort ) {
			inFlight.abort();
		}

		renderMessage( i18n.searching, 'loading' );

		var url = cfg.searchUrl + '?' + new URLSearchParams( query.params ).toString();
		var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
		inFlight = controller;

		fetch( url, {
			headers: { 'X-WP-Nonce': cfg.nonce },
			credentials: 'same-origin',
			signal: controller ? controller.signal : undefined
		} )
			.then( function ( response ) {
				if ( response.status === 429 ) {
					throw new Error( 'rate_limited' );
				}
				if ( ! response.ok ) {
					throw new Error( 'request_failed' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				inFlight = null;
				lastResults = ( data && data.dealers ) || [];
				renderResults( lastResults );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}
				inFlight = null;
				renderMessage(
					error && error.message === 'rate_limited' ? i18n.rateLimited : i18n.error,
					'error'
				);
			} );
	}

	// ── Rendering ────────────────────────────────────────────────────────────

	function renderMessage( message, kind ) {
		resultsEl.innerHTML = '';
		resultsEl.appendChild( el( 'p', 'fflcs-message fflcs-message--' + ( kind || 'info' ), message ) );
		resultsBar.hidden = true;
	}

	function renderResults( dealers ) {
		resultsEl.innerHTML = '';

		if ( ! dealers.length ) {
			renderMessage( i18n.noResults, 'empty' );
			return;
		}

		resultsBar.hidden = false;
		text( countEl, dealers.length + ' ' + ( dealers.length === 1 ? i18n.countOne : i18n.countMany ) );

		var list = el( 'ul', 'fflcs-list' );

		dealers.forEach( function ( dealer ) {
			list.appendChild( renderCard( dealer ) );
		} );

		resultsEl.appendChild( list );
	}

	function renderCard( dealer ) {
		var item = el( 'li', 'fflcs-card' );

		if ( Number( dealer.is_preferred ) === 1 ) {
			item.classList.add( 'is-preferred' );
		}

		var header = el( 'div', 'fflcs-card__header' );
		header.appendChild( el( 'span', 'fflcs-card__name', dealer.business_name ) );

		if ( Number( dealer.is_preferred ) === 1 ) {
			header.appendChild( el( 'span', 'fflcs-badge fflcs-badge--preferred', i18n.preferred ) );
		}

		item.appendChild( header );

		var address = [ dealer.premise_street, dealer.premise_city ]
			.filter( Boolean )
			.join( ', ' );
		var region = [ dealer.premise_state, dealer.premise_zip ].filter( Boolean ).join( ' ' );

		item.appendChild( el( 'p', 'fflcs-card__address', address + ( region ? ', ' + region : '' ) ) );

		var meta = el( 'div', 'fflcs-card__meta' );

		if ( dealer.distance_miles != null ) {
			meta.appendChild(
				el( 'span', 'fflcs-chip', dealer.distance_miles + ' ' + i18n.milesAway )
			);
		} else {
			meta.appendChild( el( 'span', 'fflcs-chip fflcs-chip--muted', i18n.noDistance ) );
		}

		var fee = Number( dealer.transfer_fee || 0 );
		meta.appendChild(
			el(
				'span',
				'fflcs-chip',
				fee > 0 ? i18n.feeLabel + ': ' + money( fee ) : i18n.feeUnknown
			)
		);

		if ( dealer.phone ) {
			var phoneLink = el( 'a', 'fflcs-chip fflcs-chip--link', dealer.phone );
			phoneLink.href = 'tel:' + String( dealer.phone ).replace( /[^0-9+]/g, '' );
			meta.appendChild( phoneLink );
		}

		item.appendChild( meta );

		var button = el( 'button', 'fflcs-btn fflcs-btn--select', i18n.select );
		button.type = 'button';
		button.addEventListener( 'click', function () {
			selectDealer( dealer );
		} );

		item.appendChild( button );

		return item;
	}

	// ── Selection ────────────────────────────────────────────────────────────

	function selectDealer( dealer ) {
		inputs.dealerId.value = dealer.id;
		inputs.dealerName.value = dealer.business_name;
		inputs.dealerZip.value = dealer.premise_zip || '';

		selectedInfoEl.innerHTML = '';
		selectedInfoEl.appendChild( el( 'strong', '', dealer.business_name ) );
		selectedInfoEl.appendChild(
			el(
				'span',
				'fflcs-selected__address',
				[ dealer.premise_street, dealer.premise_city, dealer.premise_state, dealer.premise_zip ]
					.filter( Boolean )
					.join( ', ' )
			)
		);

		var fee = Number( dealer.transfer_fee || 0 );
		if ( fee > 0 ) {
			selectedInfoEl.appendChild(
				el( 'span', 'fflcs-selected__fee', i18n.feeLabel + ': ' + money( fee ) )
			);
		}

		selectedEl.hidden = false;
		resultsEl.innerHTML = '';
		resultsBar.hidden = true;

		root.dispatchEvent(
			new CustomEvent( 'fflcs:dealer-selected', { bubbles: true, detail: dealer } )
		);

		// Ask WooCommerce to refresh the review block so any dealer-dependent
		// fee recalculates. Guarded — the block checkout has no jQuery events.
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}

		selectedEl.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function clearSelection() {
		inputs.dealerId.value = '';
		inputs.dealerName.value = '';
		inputs.dealerZip.value = '';
		selectedEl.hidden = true;

		if ( lastResults.length ) {
			renderResults( lastResults );
		}
	}

	// ── Saved dealers (populated by the saved-dealers module) ────────────────

	function renderSaved( dealers ) {
		if ( ! savedEl || ! dealers || ! dealers.length ) {
			return;
		}

		savedEl.innerHTML = '';
		savedEl.appendChild( el( 'p', 'fflcs-saved__heading', i18n.savedHeading ) );

		var strip = el( 'div', 'fflcs-saved__strip' );

		dealers.forEach( function ( dealer ) {
			var chip = el( 'button', 'fflcs-saved__chip', dealer.business_name );
			chip.type = 'button';
			chip.addEventListener( 'click', function () {
				selectDealer( dealer );
			} );
			strip.appendChild( chip );
		} );

		savedEl.appendChild( strip );
		savedEl.hidden = false;
	}

	// Exposed so the saved-dealers module can hand us its list without needing
	// its own copy of the rendering and selection logic.
	window.fflcsRenderSavedDealers = renderSaved;

	// ── Boot ─────────────────────────────────────────────────────────────────

	function init() {
		root = $( 'fflcs-widget' );
		if ( ! root ) {
			return;
		}

		resultsEl = $( 'fflcs-results' );
		countEl = $( 'fflcs-count' );
		resultsBar = root.querySelector( '.fflcs-resultsbar' );
		selectedEl = $( 'fflcs-selected' );
		selectedInfoEl = $( 'fflcs-selected-info' );
		savedEl = $( 'fflcs-saved' );

		inputs = {
			zip: $( 'fflcs-zip' ),
			radius: $( 'fflcs-radius' ),
			name: $( 'fflcs-name' ),
			state: $( 'fflcs-state' ),
			license: $( 'fflcs-license' ),
			phone: $( 'fflcs-phone' ),
			dealerId: $( 'fflcs-dealer-id' ),
			dealerName: $( 'fflcs-dealer-name' ),
			dealerZip: $( 'fflcs-dealer-zip' )
		};

		root.querySelectorAll( '.fflcs-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activateTab( tab.getAttribute( 'data-tab' ) );
			} );
		} );

		$( 'fflcs-search' ).addEventListener( 'click', search );
		$( 'fflcs-change' ).addEventListener( 'click', clearSelection );

		// Enter submits the search rather than the whole checkout form — a
		// shopper pressing Enter in the ZIP field should not place the order.
		[ 'zip', 'name', 'license', 'phone' ].forEach( function ( key ) {
			if ( ! inputs[ key ] ) {
				return;
			}
			inputs[ key ].addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					search();
				}
			} );
		} );

		// Auto-search when the billing ZIP is already known, so the common case
		// needs no interaction at all.
		if ( inputs.zip && /^\d{5}$/.test( inputs.zip.value ) ) {
			search();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// The block checkout can replace the DOM after our first init; re-run when
	// the widget reappears without its listeners.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', function () {
			if ( $( 'fflcs-widget' ) && ! $( 'fflcs-widget' ).dataset.fflcsBound ) {
				init();
				$( 'fflcs-widget' ).dataset.fflcsBound = '1';
			}
		} );
	}
} )();
