/** One cancellable, encoded request per dependent field and parent value. */
( function ( $, mw ) {
	'use strict';

	const dependencies = mw.pageFormsDependencies = {};
	const has = ( object, key ) => Object.prototype.hasOwnProperty.call( object, key );

	function active( $field ) {
		return $field.length && document.documentElement.contains( $field[ 0 ] ) &&
			!$field.closest( '.multipleTemplateStarter, .pf-removed-instance' ).length;
	}

	function requestData( dependent, parent, value ) {
		const cargo = mw.config.get( 'wgPageFormsCargoFields' ) || {};
		const properties = mw.config.get( 'wgPageFormsFieldProperties' ) || {};
		const data = {
			action: 'pfautocomplete', format: 'json', limit: 500,
			basevalue: Array.isArray( value ) ? value.join( ',' ) : String( value == null ? '' : value )
		};
		if ( has( cargo, dependent ) && has( cargo, parent ) ) {
			const field = cargo[ dependent ].split( '|' );
			const base = cargo[ parent ].split( '|' );
			Object.assign( data, {
				cargo_table: field[ 0 ], cargo_field: field[ 1 ],
				base_cargo_table: base[ 0 ], base_cargo_field: base[ 1 ]
			} );
		} else {
			data.property = properties[ dependent ];
			data.baseprop = properties[ parent ];
		}
		return data;
	}

	function status( $field, state, error ) {
		if ( state.$status ) {
			state.$status.remove();
		}
		state.$status = $( '<span>' ).addClass( 'pf-dependent-status' )
			.attr( 'role', error ? 'alert' : 'status' )
			.text( mw.msg( error ? 'pf-dependent-error' : 'pf-dependent-loading' ) )
			.insertAfter( $field );
		if ( error ) {
			$( '<button>' ).attr( 'type', 'button' ).text( mw.msg( 'pf-retry' ) )
				.appendTo( state.$status ).on( 'click', () => {
					dependencies.update( $field, state.dependent, state.parent, state.value, true );
				} );
		}
	}

	function refresh( $field ) {
		// A non-visible field has no widget yet: its eventual initializer reads
		// the stored choices. Do not defeat lazy loading by rebuilding it now.
		if ( !$field.data( 'select2' ) ) {
			return;
		}
		if ( $field.hasClass( 'pfTokens' ) ) {
			( $field.data( 'pfTokensInstance' ) || new pageforms.select2.tokens() ).refresh( $field );
		} else if ( $field.hasClass( 'pfComboBox' ) ) {
			( $field.data( 'pfComboBoxInstance' ) || new pageforms.ComboBoxInput( {} ) ).refresh( $field );
		}
	}

	dependencies.update = function ( $field, dependent, parent, value, force = false ) {
		if ( !active( $field ) ) {
			return $.Deferred().resolve().promise();
		}
		const data = requestData( dependent, parent, value );
		const key = JSON.stringify( data );
		const previous = $field.data( 'pfDependentState' );
		if ( !force && previous && previous.key === key && !previous.error && ( previous.pending || Date.now() - previous.completedAt < 30000 ) ) {
			return previous.promise;
		}
		const deferred = $.Deferred();
		const state = { key, dependent, parent, value, pending: true, promise: deferred.promise() };
		// Invalidate before abort(), which can call fail/always synchronously.
		$field.data( 'pfDependentState', state );
		if ( previous ) {
			if ( previous.$status ) {
				previous.$status.remove();
			}
			if ( previous.xhr && previous.pending ) {
				previous.xhr.abort();
			}
		}
		$field.attr( 'aria-busy', 'true' );
		status( $field, state, false );
		const current = () => active( $field ) && $field.data( 'pfDependentState' ) === state;
		const fail = () => {
			state.pending = false;
			if ( current() ) {
				state.error = true;
				$field.removeAttr( 'aria-busy' );
				status( $field, state, true );
			}
			// Resolve false rather than leave an unhandled rejected jQuery promise.
			deferred.resolve( false );
		};
		if ( !( data.baseprop && data.property ) && !( data.base_cargo_table && data.cargo_table ) ) {
			fail();
			return state.promise;
		}
		state.xhr = $.ajax( { url: mw.util.wikiScript( 'api' ), data, dataType: 'json', timeout: 15000 } );
		state.xhr.done( ( response ) => {
			state.pending = false;
			if ( !current() ) {
				deferred.resolve( false );
				return;
			}
			if ( !response || response.error || !Array.isArray( response.pfautocomplete ) ||
				!response.pfautocomplete.every( ( item ) => item && ( typeof item.title === 'string' || typeof item.title === 'number' ) ) ) {
				fail();
				return;
			}
			$field.data( 'autocompletevalues', response.pfautocomplete.map( ( item ) => String( item.title ) ) )
				.data( 'autocompleteitems', response.pfautocomplete )
				.data( 'autocompleteMore', response.pfautocomplete_more === true ).removeAttr( 'aria-busy' );
			state.completedAt = Date.now();
			state.$status.remove();
			try {
				refresh( $field );
				deferred.resolve( true );
			} catch ( error ) {
				fail();
			}
		} ).fail( fail );
		return state.promise;
	};

	dependencies.cancel = function ( $root ) {
		$root.find( 'input, select, textarea' ).addBack( 'input, select, textarea' ).each( function () {
			const $field = $( this );
			const state = $field.data( 'pfDependentState' );
			$field.removeData( 'pfDependentState' ).removeAttr( 'aria-busy' );
			if ( state ) {
				if ( state.$status ) {
					state.$status.remove();
				}
				if ( state.xhr && state.pending ) {
					state.xhr.abort();
				}
			}
		} );
	};

	dependencies.settle = function ( $form ) {
		const pending = [];
		let failed = false;
		$form.find( 'input, select, textarea' ).each( function () {
			const $field = $( this );
			if ( !active( $field ) || $field.closest( '.hiddenByPF' ).length || this.disabled ) {
				return;
			}
			const state = $field.data( 'pfDependentState' );
			if ( state && state.pending ) {
				pending.push( Promise.resolve( state.promise ) );
			}
			failed = failed || !!( state && state.error );
		} );
		if ( failed ) {
			return Promise.reject( new Error( 'Dependent suggestions unavailable' ) );
		}
		// A dependent-field update can start a subsequent link in a chain.
		return pending.length ? Promise.all( pending ).then( () => dependencies.settle( $form ) ) : Promise.resolve();
	};

	mw.hook( 'pf.formSaved' ).add( ( $form ) => {
		$form.find( 'input, select, textarea' ).each( function () {
			const state = $( this ).data( 'pfDependentState' );
			if ( state ) { state.completedAt = 0; }
		} );
	} );

	$.fn.setDependentAutocompletion = function ( dependent, parent, value ) {
		return dependencies.update( this.first(), dependent, parent, value );
	};

	$.fn.setAutocompleteForDependentField = function ( partOfMultiple ) {
		return this.each( function () {
			const $source = $( this );
			if ( !active( $source ) ) {
				return;
			}
			const name = partOfMultiple ? $source.attr( 'origname' ) : $source.attr( 'name' );
			const pairs = mw.config.get( 'wgPageFormsDependentFields' ) || [];
			const names = new Set( pairs.filter( ( pair ) => pair[ 0 ] === name ).map( ( pair ) => pair[ 1 ] ) );
			const $scope = partOfMultiple ? $source.closest( '.multipleTemplateInstance' ) : $source.closest( 'form' );
			$scope.find( 'input, select, textarea' ).each( function () {
				const $field = $( this );
				const dependent = $field.attr( partOfMultiple ? 'origname' : 'name' );
				if ( names.has( dependent ) && ( !partOfMultiple || $field.closest( '.multipleTemplateInstance' )[ 0 ] === $scope[ 0 ] ) ) {
					dependencies.update( $field, dependent, name, $source.val() );
				}
			} );
		} );
	};
}( jQuery, mediaWiki ) );
