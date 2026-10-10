/* DevThrives Order Summary: "copy order number" and "print" buttons. */
( function () {
	'use strict';

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		// Fallback for older browsers and non-https pages.
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( area );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! target || ! target.closest ) { return; }

		var copyBtn = target.closest( '[data-dts-copy]' );
		if ( copyBtn ) {
			e.preventDefault();
			copyText( copyBtn.getAttribute( 'data-dts-copy' ) || '' ).then( function () {
				copyBtn.classList.add( 'is-copied' );
				clearTimeout( copyBtn._dtsTimer );
				copyBtn._dtsTimer = setTimeout( function () {
					copyBtn.classList.remove( 'is-copied' );
				}, 1600 );
			} ).catch( function () {} );
			return;
		}

		if ( target.closest( '[data-dts-print]' ) ) {
			e.preventDefault();
			window.print();
		}
	} );
}() );
