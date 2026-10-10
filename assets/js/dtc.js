/* DevThrives Checkout Widget */
( function () {
	'use strict';

	var D = window.dtcData || {};

	function $( root, sel ) { return root.querySelector( sel ); }
	function $$( root, sel ) { return Array.prototype.slice.call( root.querySelectorAll( sel ) ); }

	function toFormData( obj ) {
		var fd = new FormData();
		Object.keys( obj ).forEach( function ( k ) { fd.append( k, obj[ k ] ); } );
		return fd;
	}

	function post( data ) {
		var body = data instanceof FormData ? data : toFormData( data );
		return fetch( D.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	function init( root ) {
		if ( root.getAttribute( 'data-dtc-ready' ) ) { return; }
		root.setAttribute( 'data-dtc-ready', '1' );

		var form = $( root, '.dtc-form' );
		if ( ! form ) { return; }

		var cfgRaw = root.getAttribute( 'data-config' ) || '{}';
		var cfg = {};
		try { cfg = JSON.parse( cfgRaw ); } catch ( e ) {}

		var mode = cfg.source || 'manual';
		var coupon = '';
		var timer = null;
		var seq = 0;
		var busy = false;

		var areas = {
			items: $( root, '.dtc-js-items' ),
			shipping: $( root, '.dtc-js-shipping' ),
			totals: $( root, '.dtc-js-totals' ),
			payment: $( root, '.dtc-js-payment' ),
			button: $( root, '.dtc-js-button' )
		};
		var btn = $( root, '.dtc-btn' );
		var notices = $( root, '.dtc-notices' );

		var productBox = $( root, '.dtc-products' );
		var startMode = productBox ? productBox.getAttribute( 'data-default' ) : null; // first | all | none
		var isMulti = !! productBox && '1' === productBox.getAttribute( 'data-multi' );
		var touched = false;   // becomes true once the visitor changes a product themselves
		var inflight = null;   // the refresh request that is running right now
		var inEditor = !! ( window.elementorFrontend && window.elementorFrontend.isEditMode && window.elementorFrontend.isEditMode() );

		/* ---------- helpers ---------- */

		function checked( name ) {
			var el = form.querySelector( 'input[name="' + name + '"]:checked' );
			return el ? el.value : '';
		}

		function getItems() {
			if ( 'cart' === mode ) { return []; }
			var items = [];
			$$( root, '.dtc-product-card' ).forEach( function ( card ) {
				var input = $( card, '.dtc-product-input' );
				if ( ! input || ! input.checked ) { return; }
				var sel = $( card, '.dtc-variation' );
				var qty = $( card, '.dtc-qty' );
				items.push( {
					id: parseInt( card.getAttribute( 'data-id' ), 10 ),
					variation_id: sel ? parseInt( sel.value, 10 ) : 0,
					qty: qty ? Math.max( 1, parseInt( qty.value, 10 ) || 1 ) : 1
				} );
			} );
			return items;
		}

		/** Make every card's highlight follow its real checked state. */
		function syncCards() {
			$$( root, '.dtc-product-card' ).forEach( function ( card ) {
				var input = $( card, '.dtc-product-input' );
				card.classList.toggle( 'is-selected', !! ( input && input.checked ) );
			} );
		}

		/**
		 * The default product must be really selected, not just highlighted.
		 * If nothing is checked (cached page, restored form, script order...), restore what
		 * the cards show, or fall back to the "Selected at Start" setting.
		 */
		function ensureDefault() {
			if ( 'cart' === mode ) { return; }
			var inputs = $$( root, '.dtc-product-input' );
			if ( ! inputs.length || inputs.some( function ( i ) { return i.checked; } ) ) { return; }

			var shown = inputs.filter( function ( i ) {
				var card = i.closest( '.dtc-product-card' );
				return card && card.classList.contains( 'is-selected' );
			} );

			if ( shown.length ) {
				shown.forEach( function ( i ) { i.checked = true; } );
			} else if ( startMode && 'none' !== startMode ) {
				if ( isMulti && 'all' === startMode ) {
					inputs.forEach( function ( i ) { i.checked = true; } );
				} else {
					inputs[ 0 ].checked = true;
				}
			}
			syncCards();
		}

		function withLocation( data ) {
			[ 'billing_country', 'billing_state', 'billing_postcode', 'billing_city' ].forEach( function ( name ) {
				var el = form.elements[ name ];
				if ( el && el.value !== undefined ) { data[ name ] = el.value; }
			} );
			return data;
		}

		function showNotice( msg, isHtml, quiet ) {
			if ( ! notices || ! msg ) { return; }
			if ( isHtml ) { notices.innerHTML = msg; } else { notices.textContent = msg; }
			notices.hidden = false;
			if ( ! quiet && notices.scrollIntoView ) { notices.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
		}

		function clearNotice() {
			if ( notices ) { notices.hidden = true; notices.innerHTML = ''; }
		}

		/* ---------- live refresh ---------- */

		function refresh() {
			clearTimeout( timer );
			timer = setTimeout( run, 120 );
		}

		function run( quiet ) {
			var my = ++seq;
			quiet = true === quiet; // a quiet run is the automatic one on page load
			if ( ! quiet ) { root.classList.add( 'is-loading' ); }
			var data = withLocation( {
				action: 'dtc_refresh',
				cfg: cfgRaw,
				items: JSON.stringify( getItems() ),
				shipping: checked( 'shipping_method[0]' ),
				payment: checked( 'payment_method' ),
				coupon: coupon
			} );
			var req = post( data ).then( function ( res ) {
				if ( my !== seq ) { return; }
				root.classList.remove( 'is-loading' );
				if ( res && res.success ) { apply( res.data, quiet ); }
			} ).catch( function () {
				if ( my === seq ) { root.classList.remove( 'is-loading' ); }
			} );
			inflight = req; // never rejects, so it is safe to wait for
			req.then( function () { if ( inflight === req ) { inflight = null; } } );
			return req;
		}

		function apply( d, quiet ) {
			areas.items.innerHTML = d.items;
			areas.shipping.innerHTML = d.shipping;
			areas.totals.innerHTML = d.totals;
			if ( areas.payment && areas.payment.getAttribute( 'data-sig' ) !== d.payment_sig ) {
				areas.payment.innerHTML = d.payment;
				areas.payment.setAttribute( 'data-sig', d.payment_sig );
			}
			areas.button.innerHTML = d.button;
			btn.disabled = !! d.empty;

			var msg = $( root, '.dtc-coupon-msg' );
			if ( msg ) { msg.textContent = d.coupon_msg || ''; }
			if ( d.coupon_msg ) { coupon = ''; }

			if ( d.error ) { showNotice( d.error, false, quiet ); } else { clearNotice(); }
		}

		/* ---------- events ---------- */

		root.addEventListener( 'change', function ( e ) {
			var t = e.target;

			if ( t.classList.contains( 'dtc-product-input' ) ) {
				touched = true;
				syncCards();
				refresh();
			} else if ( t.classList.contains( 'dtc-variation' ) ) {
				var opt = t.options[ t.selectedIndex ];
				var card = t.closest( '.dtc-product-card' );
				var price = card && $( card, '.dtc-product-price' );
				if ( price && opt && opt.getAttribute( 'data-price' ) ) { price.innerHTML = opt.getAttribute( 'data-price' ); }
				refresh();
			} else if ( t.classList.contains( 'dtc-qty' ) ) {
				var min = parseInt( t.min, 10 ) || 1;
				var max = parseInt( t.max, 10 ) || 0;
				var val = parseInt( t.value, 10 ) || min;
				if ( val < min ) { val = min; }
				if ( max && val > max ) { val = max; }
				t.value = val;
				refresh();
			} else if ( t.name === 'shipping_method[0]' ) {
				$$( root, '.dtc-ship-item' ).forEach( function ( el ) {
					var input = $( el, 'input' );
					el.classList.toggle( 'is-active', !! ( input && input.checked ) );
				} );
				refresh();
			} else if ( t.name === 'payment_method' ) {
				$$( root, '.dtc-pay-item' ).forEach( function ( el ) {
					var input = $( el, '.dtc-pay-input' );
					el.classList.toggle( 'is-active', !! ( input && input.checked ) );
				} );
			} else if ( t.classList.contains( 'dtc-loc' ) ) {
				refresh();
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var t = e.target;

			var step = t.closest( '.dtc-qty-btn' );
			if ( step ) {
				e.preventDefault();
				var input = $( step.parentNode, '.dtc-qty' );
				var min = parseInt( input.min, 10 ) || 1;
				var max = parseInt( input.max, 10 ) || 0;
				var next = ( parseInt( input.value, 10 ) || min ) + parseInt( step.getAttribute( 'data-dir' ), 10 );
				if ( next < min ) { next = min; }
				if ( max && next > max ) { next = max; }
				input.value = next;
				refresh();
				return;
			}

			if ( t.closest( '.dtc-coupon-toggle' ) ) {
				$( root, '.dtc-coupon' ).classList.toggle( 'is-open' );
				return;
			}

			if ( t.closest( '.dtc-coupon-apply' ) ) {
				applyCoupon();
				return;
			}

			if ( t.closest( '.dtc-coupon-remove' ) ) {
				e.preventDefault();
				coupon = '';
				refresh();
			}
		} );

		function applyCoupon() {
			var input = $( root, '.dtc-coupon-input' );
			coupon = input ? input.value.trim() : '';
			if ( coupon ) { run(); }
		}

		var couponInput = $( root, '.dtc-coupon-input' );
		if ( couponInput ) {
			couponInput.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) { e.preventDefault(); applyCoupon(); }
			} );
		}

		/* ---------- state list follows the country ---------- */

		var stateWrap = $( root, '.dtc-state-wrap' );
		var countrySel = form.elements.billing_country;
		if ( stateWrap && countrySel && 'SELECT' === countrySel.tagName ) {
			var allStates = {};
			try { allStates = JSON.parse( stateWrap.getAttribute( 'data-states' ) || '{}' ); } catch ( e ) {}

			countrySel.addEventListener( 'change', function () {
				var current = stateWrap.querySelector( '[name="billing_state"]' );
				if ( ! current ) { return; }
				var list = allStates[ countrySel.value ];
				var next;
				if ( list && 'object' === typeof list && Object.keys( list ).length ) {
					next = document.createElement( 'select' );
					var first = document.createElement( 'option' );
					first.value = '';
					first.textContent = D.i18n ? D.i18n.select : 'Select...';
					next.appendChild( first );
					Object.keys( list ).forEach( function ( code ) {
						var o = document.createElement( 'option' );
						o.value = code;
						o.textContent = list[ code ];
						next.appendChild( o );
					} );
				} else {
					next = document.createElement( 'input' );
					next.type = 'text';
					next.placeholder = current.placeholder || '';
				}
				next.className = current.className;
				next.name = 'billing_state';
				next.id = current.id;
				next.required = current.required;
				stateWrap.replaceChild( next, current );
			} );
		}

		/* ---------- place order ---------- */

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( busy ) { return; }

			// The visitor never touched the products: the default one must count as selected.
			if ( ! touched ) { ensureDefault(); }

			if ( 'cart' !== mode && ! getItems().length ) {
				showNotice( cfg.msg_empty || 'Please select a product.' );
				return;
			}
			if ( form.reportValidity && ! form.reportValidity() ) { return; }

			busy = true;
			clearTimeout( timer ); // a pending refresh is pointless once the order is sent
			btn.disabled = true;
			var oldLabel = areas.button.innerHTML;
			areas.button.textContent = cfg.button_loading || 'Processing...';
			clearNotice();

			function fail( html ) {
				busy = false;
				btn.disabled = false;
				areas.button.innerHTML = oldLabel;
				if ( html ) {
					showNotice( html, true );
				} else {
					showNotice( D.i18n ? D.i18n.error : 'Something went wrong. Please try again.' );
				}
			}

			// Let a refresh that is still running finish first, then get a fresh nonce
			// so cached pages never fail.
			Promise.resolve( inflight )
				.then( function () { return fetch( D.ajaxUrl + '?action=dtc_nonce', { credentials: 'same-origin' } ); } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( n ) {
					var fd = new FormData( form );
					fd.append( 'action', 'dtc_place_order' );
					fd.append( 'nonce', ( n && n.data && n.data.nonce ) || D.nonce );
					fd.append( 'cfg', cfgRaw );
					fd.append( 'items', JSON.stringify( getItems() ) );
					fd.append( 'coupon', coupon );
					return post( fd );
				} )
				.then( function ( res ) {
					if ( res && 'success' === res.result && res.redirect ) {
						window.location.href = res.redirect;
						return;
					}
					fail( res && res.messages );
				} )
				.catch( function () { fail(); } );
		} );

		/* ---------- start-up: no click needed ---------- */

		// 1. What the cards show must be what is really selected.
		ensureDefault();
		syncCards();

		// 2. Tell the server once, exactly like a click would, so the cart and session
		//    already match the default product before the visitor presses the button.
		//    (Skipped inside the Elementor editor, where nothing can be ordered.)
		if ( ! inEditor ) { run( true ); }
	}

	function boot() {
		$$( document, '.dtc-wrap' ).forEach( init );
	}

	if ( 'loading' !== document.readyState ) {
		boot();
	} else {
		document.addEventListener( 'DOMContentLoaded', boot );
	}

	// Elementor editor: start the widget again each time it is redrawn.
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', function () {
			if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
				window.elementorFrontend.hooks.addAction( 'frontend/element_ready/dt_checkout.default', function ( $scope ) {
					var el = $scope[ 0 ] && $scope[ 0 ].querySelector( '.dtc-wrap' );
					if ( el ) { init( el ); }
				} );
			}
		} );
	}
}() );
