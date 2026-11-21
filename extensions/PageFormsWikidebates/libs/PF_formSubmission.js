/**
 * Shared, asynchronous submission lifecycle for normal saves, previews and AJAX saves.
 * Validation is advisory: server-side permissions and edit checks remain authoritative.
 */
( function ( $, mw ) {
	'use strict';

	const submission = mw.pageFormsSubmission = {};

	submission.showError = function ( $form, message ) {
		$( '#form_error_header' ).remove();
		const $header = $( '<div>' ).attr( {
			id: 'form_error_header', role: 'alert'
		} ).addClass( 'errorbox pf-form-error-header cdx-message cdx-message--block cdx-message--error' )
			.text( message );
		let $target = $( '#contentSub' );
		if ( !$target.length ) {
			$target = $( '.page-heading .tagline' ).first();
		}
		if ( $target.length ) {
			$target.append( $header );
		} else {
			$form.prepend( $header );
		}
	};

	// Do not disable data fields: disabled fields would disappear from serialize().
	submission.begin = function ( $form ) {
		if ( $form.data( 'pfSubmissionPending' ) || $form.data( 'submitted' ) ) {
			return false;
		}
		const state = {
			buttons: [], widgets: [], busy: $form.attr( 'aria-busy' )
		};
		$form.find( ':submit, .pf-save_and_continue button, button.pf-save_and_continue, input.pf-save_and_continue' ).each( function () {
			state.buttons.push( [ this, this.disabled ] );
			this.disabled = true;
		} );
		$form.find( '.editButtons > .oo-ui-buttonElement, .pf-save_and_continue' ).each( function () {
			const $widget = $( this );
			state.widgets.push( [ $widget, $widget.hasClass( 'oo-ui-widget-enabled' ), $widget.hasClass( 'oo-ui-widget-disabled' ) ] );
			$widget.removeClass( 'oo-ui-widget-enabled' ).addClass( 'oo-ui-widget-disabled' );
		} );
		$form.data( 'pfSubmissionPending', state ).attr( 'aria-busy', 'true' );
		if ( mw.pageFormsEditState ) {
			mw.pageFormsEditState.phase( $form, 'validating' );
		}
		$( '<div>' ).addClass( 'pf-submission-status' ).attr( 'role', 'status' )
			.text( mw.msg( 'pf-form-validating' ) ).appendTo( $form );
		return true;
	};

	submission.end = function ( $form ) {
		const state = $form.data( 'pfSubmissionPending' );
		if ( !state ) {
			return;
		}
		state.buttons.forEach( ( item ) => { item[ 0 ].disabled = item[ 1 ]; } );
		state.widgets.forEach( ( item ) => {
			item[ 0 ].toggleClass( 'oo-ui-widget-enabled', item[ 1 ] )
				.toggleClass( 'oo-ui-widget-disabled', item[ 2 ] );
		} );
		if ( state.busy === undefined ) {
			$form.removeAttr( 'aria-busy' );
		} else {
			$form.attr( 'aria-busy', state.busy );
		}
		$form.removeData( 'pfSubmissionPending' ).find( '.pf-submission-status' ).remove();
		if ( mw.pageFormsEditState ) {
			mw.pageFormsEditState.phase( $form, null );
		}
	};

	function editorFields( $form ) {
		return $form.find( 'textarea.visualeditor' ).filter( function () {
			return !$( this ).closest( '.multipleTemplateStarter, .hiddenByPF' ).length;
		} );
	}

	submission.snapshot = function ( $form ) {
		const editorContents = [];
		editorFields( $form ).each( function () {
			const target = $( this ).data( 'veForAllTarget' );
			const surface = target && target.getSurface && target.getSurface();
			if ( surface && typeof surface.getHtml === 'function' ) {
				editorContents.push( surface.getHtml() );
			}
		} );
		return JSON.stringify( [ $form.serialize(), editorContents ] );
	};

	// Keep this public helper available without the optional save-and-continue module.
	mw.pageFormsActivateVEFields = function ( callback ) {
		const promise = Promise.resolve().then( () => {
			const updates = [];
			editorFields( $( '#pfForm' ) ).each( function () {
				const $field = $( this );
				const target = $field.data( 'veForAllTarget' );
				if ( target && typeof target.updateContent === 'function' ) {
					const html = () => {
						const surface = target.getSurface && target.getSurface();
						return surface && surface.getHtml ? surface.getHtml() : null;
					};
					const originalHtml = html();
					const wasPending = !!target.pendingUpdatePromise;
					updates.push( Promise.resolve( target.updateContent() ).then( () => {
						// An existing blur conversion may have captured an older surface.
						if ( wasPending ) {
							$field.addClass( 've-for-all-waiting-for-update' );
							return target.updateContent();
						}
					} ).then( () => {
						if ( html() !== originalHtml ) {
							// VEForAll can clear its dirty flag when an older conversion ends.
							// Keep it dirty so that retrying converts the latest surface.
							$field.addClass( 've-for-all-waiting-for-update' );
							throw new Error( 'Editor changed during conversion' );
						}
					} ) );
				} else if ( $field.data( 'veForAllInitializing' ) ) {
					throw new Error( 'VisualEditor is still initializing' );
				}
			} );
			return Promise.all( updates );
		} );
		return typeof callback === 'function' ? promise.then( callback ) : promise;
	};

	submission.prepare = function ( $form ) {
		// Bound the entire preparation, including third-party async validators.
		let timeout;
		let active = true;
		const preparation = new Promise( ( resolve, reject ) => {
			timeout = setTimeout( () => {
				active = false;
				$form.removeData( 'pfValidationGeneration' );
				$form.find( '.uniqueField' ).each( function () {
					const request = $( this ).data( 'pfUniqueRequest' );
					$( this ).removeData( 'pfUniqueRequest' );
					if ( request && request.xhr ) {
						request.xhr.abort();
					}
				} );
				reject( new Error( 'Form preparation timed out' ) );
			}, 30000 );
			mw.pageFormsActivateVEFields()
				.then( () => active && mw.pageFormsDependencies ? mw.pageFormsDependencies.settle( $form ) : undefined )
				.then( () => active ? window.validateAll() : false )
				.then( resolve, reject );
		} );
		return preparation.then( ( valid ) => {
			clearTimeout( timeout );
			return valid;
		}, () => {
			clearTimeout( timeout );
			submission.showError( $form, mw.msg( 'pf-form-prepare-error' ) );
			return false;
		} );
	};

	submission.bind = function ( $form ) {
		if ( !$form.length || $form.data( 'pfSubmitBound' ) ) {
			return;
		}
		$form.data( 'pfSubmitBound', true );
		const form = $form[ 0 ];

		function handleSubmit( event ) {
			const replay = $form.data( 'pfValidatedSubmit' );
			if ( replay ) {
				if ( !replay.event ) {
					replay.event = event;
				}
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			if ( !submission.begin( $form ) ) {
				return;
			}
			const nativeEvent = event.originalEvent || event;
			const submitter = nativeEvent.submitter || $form.find( ':submit[name="wpSave"]' )[ 0 ] || $form.find( ':submit' )[ 0 ];
			submission.prepare( $form ).then( async ( valid ) => {
				if ( !valid || !document.documentElement.contains( form ) ) {
					return;
				}
				// A resolved promise can run inside the browser's original submit
				// dispatch microtask checkpoint. requestSubmit() is then ignored as
				// reentrant. Resume in a new task, retaining the double-submit lock.
				const validatedSnapshot = submission.snapshot( $form );
				await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
				if ( !document.documentElement.contains( form ) ) { return; }
				if ( submission.snapshot( $form ) !== validatedSnapshot ) {
					submission.showError( $form, mw.msg( 'pf-form-changed-during-validation' ) );
					return;
				}
				// Restore the clicked button before submitting: its name/value matters.
				submission.end( $form );
				const attempt = {};
				$form.data( 'pfValidatedSubmit', attempt );
				try {
					if ( typeof HTMLFormElement.prototype.requestSubmit === 'function' ) {
						if ( submitter && submitter.form === form ) {
							HTMLFormElement.prototype.requestSubmit.call( form, submitter );
						} else {
							HTMLFormElement.prototype.requestSubmit.call( form );
						}
					} else {
						// Older browsers still need the submit event and the clicked action.
						const $action = submitter && submitter.name ? $( '<input>' ).attr( {
							type: 'hidden', name: submitter.name, value: submitter.value
						} ).appendTo( $form ) : $();
						try {
							if ( form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) ) ) {
								mw.hook( 'pf.formSubmit' ).fire( $form );
								attempt.notified = true;
								HTMLFormElement.prototype.submit.call( form );
							}
						} finally {
							$action.remove();
						}
					}
					const sentEvent = attempt.event;
					$form.data( 'submitted', !!sentEvent && !( sentEvent.defaultPrevented ||
						( sentEvent.isDefaultPrevented && sentEvent.isDefaultPrevented() ) ) );
					if ( $form.data( 'submitted' ) && !attempt.notified ) {
						mw.hook( 'pf.formSubmit' ).fire( $form );
					}
				} finally {
					$form.removeData( 'pfValidatedSubmit' );
				}
			} ).catch( () => {
				$form.data( 'submitted', false );
				submission.showError( $form, mw.msg( 'pf-form-prepare-error' ) );
			} ).then( () => submission.end( $form ) );
		}

		// Native capture prevents other submit handlers from seeing an unvalidated form.
		// jQuery's synthetic .trigger('submit') does not run native capture listeners.
		form.addEventListener( 'submit', handleSubmit, true );
		$form.off( 'submit.pfValidate' ).on( 'submit.pfValidate', handleSubmit );
		$( window ).on( 'pageshow.pfSubmission', ( event ) => {
			if ( !( event.originalEvent || event ).persisted ) {
				return;
			}
			$form.data( 'submitted', false );
			submission.end( $form );
		} );
	};
}( jQuery, mediaWiki ) );
