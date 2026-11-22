/** Initialize each widget on visibility or intent, never its entire form. */
( function ( $, mw ) {
	'use strict';

	const lazy = mw.pageFormsLazyWidgets = {};
	let observer;
	const states = new WeakMap();
	const excluded = '.multipleTemplateStarter, .pf-removed-instance';

	function available( element ) {
		return document.documentElement.contains( element ) && !$( element ).closest( excluded ).length;
	}

	function nearViewport( element ) {
		if ( !element.getClientRects().length ) {
			return false;
		}
		const rect = element.getBoundingClientRect();
		const height = window.innerHeight || document.documentElement.clientHeight;
		return rect.top < height + 240 && rect.bottom > -240;
	}

	lazy.run = function ( element, interact = false ) {
		const state = states.get( element );
		if ( !state || !available( element ) ) {
			return Promise.resolve();
		}
		if ( interact ) {
			state.interact = true;
		}
		if ( state.pending || state.ready ) {
			return state.pending || Promise.resolve();
		}
		if ( observer ) {
			observer.unobserve( element );
		}
		const $element = $( element );
		$element.attr( 'aria-busy', 'true' );
		state.pending = Promise.resolve().then( () => {
			if ( available( element ) ) {
				return state.initialize( $element );
			}
		} ).then( () => {
			if ( !available( element ) ) {
				return;
			}
			state.ready = true;
			$element.removeAttr( 'aria-busy' );
			if ( state.error ) {
				state.error.remove();
			}
			// Do not steal focus if the user has moved to a different control.
			if ( state.interact && document.activeElement === element ) {
				const widget = $element.data( 'select2' );
				if ( widget ) {
					$element.select2( 'open' );
					const $search = widget.selection.$search || widget.dropdown.$search;
					if ( $search && $search.length ) {
						$search.trigger( 'focus' );
					}
				}
			}
		} ).catch( () => {
			$element.removeAttr( 'aria-busy' );
			if ( !available( element ) ) {
				return;
			}
			if ( !state.error ) {
				state.error = $( '<button>' ).attr( 'type', 'button' ).addClass( 'pf-widget-retry' )
					.text( mw.msg( 'pf-widget-retry' ) ).insertAfter( $element )
					.on( 'click', () => { element.focus(); lazy.run( element, true ); } );
			}
		} ).then( () => {
			state.pending = null;
			state.interact = false;
		} );
		return state.pending;
	};

	lazy.register = function ( $elements, initialize ) {
		if ( observer === undefined ) {
			observer = typeof window.IntersectionObserver === 'function' ? new window.IntersectionObserver( ( entries ) => {
				entries.forEach( ( entry ) => {
					if ( entry.isIntersecting ) {
						lazy.run( entry.target );
					}
				} );
			}, { rootMargin: '240px 0px' } ) : null;
		}
		$elements.each( function () {
			if ( !available( this ) || states.has( this ) ) {
				return;
			}
			states.set( this, { initialize } );
			$( this ).on( 'focusin.pfLazy pointerdown.pfLazy', ( event ) => {
				if ( event.type === 'pointerdown' && !states.get( this ).ready ) {
					// Keep the initial click useful even if ResourceLoader is still loading.
					event.preventDefault();
					this.focus();
				}
				lazy.run( this, true );
			} );
			if ( observer === null || nearViewport( this ) ) {
				lazy.run( this );
			} else {
				observer.observe( this );
			}
		} );
	};

	lazy.pause = function ( $root ) {
		$root.find( '*' ).addBack().each( function () {
			if ( observer && states.has( this ) ) {
				observer.unobserve( this );
			}
		} );
	};

	lazy.resume = function ( $root ) {
		$root.find( '*' ).addBack().each( function () {
			const state = states.get( this );
			if ( state && !state.ready && available( this ) ) {
				if ( observer ) {
					observer.observe( this );
				} else {
					lazy.run( this );
				}
			}
		} );
	};
}( jQuery, mediaWiki ) );
