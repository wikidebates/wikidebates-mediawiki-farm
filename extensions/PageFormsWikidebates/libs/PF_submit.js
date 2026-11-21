/**
 * Save a form without leaving it. Share validation and the submission lock with
 * normal saves and previews; only acknowledge a well-formed successful response.
 */
( function ( $, mw ) {
	'use strict';

	const submission = mw.pageFormsSubmission;
	let $form;
	let $sacButtons;
	let editVersion = 0;

	function enableButtons( enabled ) {
		$sacButtons.not( '.disabled' )
			.toggleClass( 'oo-ui-widget-enabled', enabled )
			.toggleClass( 'oo-ui-widget-disabled', !enabled )
			.find( 'button, input' ).prop( 'disabled', !enabled );
	}

	function setChanged() {
		editVersion++;
		$sacButtons.addClass( 'pf-save_and_continue-changed' )
			.removeClass( 'pf-save_and_continue-ok pf-save_and_continue-error' );
		if ( !$form.data( 'pfSubmissionPending' ) && !$form.data( 'submitted' ) ) {
			enableButtons( true );
		}
	}

	// Preserve the public plugin name without maintaining a second submission lock.
	$.fn.preventDoubleSubmission = function () {
		return this.each( function () { submission.bind( $( this ) ); } );
	};

	function storeHiddenValue( name, value ) {
		let $input = $form.find( 'input' ).filter( function () { return this.name === name; } );
		if ( !$input.length ) {
			$input = $( '<input>' ).attr( { type: 'hidden', name: name } ).appendTo( $form );
		}
		$input.val( value );
	}

	function errorMessage( error ) {
		let result = error && ( error.pfResult || error.responseJSON );
		if ( !result && error && typeof error.responseText === 'string' ) {
			try {
				result = JSON.parse( error.responseText );
			} catch ( e ) {
				// Proxies may return HTML, or the network may return no body at all.
			}
		}
		const messages = [];
		if ( result && result.error ) {
			const message = result.error.info || result.error.message || result.error.code;
			if ( typeof message === 'string' ) {
				messages.push( message );
			}
		}
		if ( result && Array.isArray( result.errors ) ) {
			result.errors.forEach( ( errorItem ) => {
				if ( errorItem && ( errorItem.level === undefined || errorItem.level < 2 ) ) {
					const message = errorItem.message || errorItem.info || errorItem.text || errorItem.code;
					if ( typeof message === 'string' ) {
						messages.push( message );
					}
				}
			} );
		}
		return messages.length ? messages.join( '\n' ) : mw.msg( 'pf-save-and-continue-error' );
	}

	function isSuccessful( result ) {
		return result && Number( result.status ) === 200 && !result.error &&
			typeof result.target === 'string' && result.target.trim() !== '' &&
			result.form && typeof result.form.title === 'string' && result.form.title.trim() !== '' &&
			( !result.errors || ( Array.isArray( result.errors ) && result.errors.every( ( item ) =>
				item && Number( item.level ) > 0
			) ) );
	}

	function collectData( button ) {
		let params = $form.serializeArray();
		const get = ( name ) => {
			const matches = params.filter( ( param ) => param.name === name );
			return matches.length ? matches[ matches.length - 1 ].value : '';
		};
		const set = ( name, value ) => {
			params = params.filter( ( param ) => param.name !== name );
			params.push( { name: name, value: value } );
		};
		const summary = get( 'wpSummary' );
		const suffix = mw.msg( 'pf_formedit_saveandcontinue_summary', mw.msg( 'pf_formedit_saveandcontinueediting' ) );
		set( 'wpSummary', summary ? summary + ' (' + suffix + ')' : suffix );

		// Prefer the actual target returned by a previous save over the original URL.
		let target = get( 'target' );
		let form = get( 'form' );
		if ( mw.config.get( 'wgAction' ) === 'formedit' ) {
			target = target || mw.config.get( 'wgPageName' );
		} else if ( mw.config.get( 'wgCanonicalSpecialPageName' ) === 'FormEdit' ) {
			const parts = ( mw.config.get( 'wgPageName' ) || '' ).split( '/' );
			form = form || mw.util.getParamValue( 'form' ) || parts[ 1 ];
			target = target || mw.util.getParamValue( 'target' ) || parts.slice( 2 ).join( '/' );
		}
		if ( form ) {
			set( 'form', form );
		}
		if ( target ) {
			set( 'target', target );
		}
		set( 'wpMinoredit', '1' );
		set( 'wpSave', $( button ).val() || '' );
		return $.param( params );
	}

	function handleSaveAndContinue( event ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		if ( $( event.currentTarget ).hasClass( 'disabled' ) || !submission.begin( $form ) ) {
			return false;
		}
		const button = $( event.currentTarget ).find( 'button, input' )[ 0 ] || event.currentTarget;
		let saved = false;
		let failed = false;
		let version;
		let changedDuringSave = false;
		$sacButtons.addClass( 'pf-save_and_continue-wait' )
			.removeClass( 'pf-save_and_continue-changed pf-save_and_continue-ok pf-save_and_continue-error' );

		submission.prepare( $form ).then( ( valid ) => {
			if ( !valid ) {
				return;
			}
			version = editVersion;
			const snapshot = submission.snapshot( $form );
			const ticket = mw.pageFormsEditState.ticket( $form );
			const savedUniqueValues = [];
			$form.find( '.uniqueField:not(:disabled)' ).each( function () {
				savedUniqueValues.push( [ $( this ), $( this ).val() ] );
			} );
			$form.find( '.pf-submission-status' ).text( mw.msg( 'pf-form-saving' ) );
			mw.pageFormsEditState.phase( $form, 'saving' );
			return Promise.resolve( $.ajax( {
				type: 'POST',
				url: mw.util.wikiScript( 'api' ),
				data: { action: 'pfautoedit', format: 'json', query: collectData( button ) },
				dataType: 'json',
				timeout: 60000
			} ) ).then( ( result ) => {
				if ( !isSuccessful( result ) ) {
					const error = new Error( 'Save not confirmed' );
					error.pfResult = result;
					throw error;
				}
				changedDuringSave = version !== editVersion || submission.snapshot( $form ) !== snapshot;
				storeHiddenValue( 'target', result.target );
				storeHiddenValue( 'form', result.form.title );
				savedUniqueValues.forEach( ( item ) => item[ 0 ].data( 'pfUniqueSavedValue', item[ 1 ] ) );
				ticket.result = result;
				changedDuringSave = mw.pageFormsEditState.saved( $form, ticket );
				saved = true;
				$sacButtons.toggleClass( 'pf-save_and_continue-ok', !changedDuringSave )
					.toggleClass( 'pf-save_and_continue-changed', changedDuringSave );
			} );
		} ).catch( ( error ) => {
			failed = true;
			$sacButtons.addClass( 'pf-save_and_continue-error' ).removeClass( 'pf-save_and_continue-ok' );
			submission.showError( $form, errorMessage( error ) );
		} ).then( () => {
			submission.end( $form );
			$sacButtons.removeClass( 'pf-save_and_continue-wait' );
			if ( !saved && !failed ) {
				$sacButtons.addClass( 'pf-save_and_continue-changed' );
			}
			enableButtons( !saved || changedDuringSave );
		} );
		return false;
	}

	$( () => {
		if ( mw.config.get( 'wgAction' ) !== 'formedit' && mw.config.get( 'wgCanonicalSpecialPageName' ) !== 'FormEdit' ) {
			return;
		}
		$form = $( '#pfForm' );
		$sacButtons = $form.find( '.pf-save_and_continue' );
		$form.preventDoubleSubmission();
		mw.pageFormsEditState.bind( $form );
		$form.on( 'pfEditStateChange.pfSaveState', ( event, dirty ) => {
			$sacButtons.toggleClass( 'pf-save_and_continue-changed', dirty );
			if ( !$form.data( 'pfSubmissionPending' ) && !$form.data( 'submitted' ) ) {
				if ( dirty || $form.data( 'pfEditState' ).savedAt ) {
					enableButtons( dirty );
				}
			}
		} );
		$sacButtons.off( 'click.pfSaveContinue' ).on( 'click.pfSaveContinue', handleSaveAndContinue );
		$form.off( '.pfSaveContinue' )
			.on( 'input.pfSaveContinue change.pfSaveContinue', 'input, select, textarea', setChanged )
			.on( 'click.pfSaveContinue', '.multipleTemplateAdder', setChanged )
			.on( 'mousedown.pfSaveContinue', '.instanceRearranger, .removeButton', setChanged );
	} );
}( jQuery, mediaWiki ) );
