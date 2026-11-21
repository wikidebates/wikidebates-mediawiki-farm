/** Accessible, field-linked errors; recheck only fields already reported invalid. */
( function ( $, mw ) {
	'use strict';

	const errors = mw.pageFormsErrors = {};
	let nextId = 0;
	const validators = {
		pf_blank_error: function ( $wrapper ) {
			if ( $wrapper.hasClass( 'comboboxSpan' ) ) {
				return $wrapper.validateMandatoryComboBox();
			}
			if ( $wrapper.hasClass( 'dateInput' ) ) {
				return $wrapper.validateMandatoryDateField();
			}
			if ( $wrapper.hasClass( 'radioButtonSpan' ) ) {
				return $wrapper.validateMandatoryRadioButton();
			}
			if ( $wrapper.hasClass( 'checkboxesSpan' ) ) {
				return $wrapper.validateMandatoryCheckboxes();
			}
			if ( $wrapper.hasClass( 'pfTreeInputWrapper' ) ) {
				return $wrapper.validateMandatoryTree();
			}
			if ( $wrapper.hasClass( 'pfPicker' ) ) {
				return $wrapper.validateMandatoryDatePicker();
			}
			return $wrapper.validateMandatoryField();
		},
		pf_bad_url_error: ( $wrapper ) => $wrapper.validateURLField(),
		pf_bad_email_error: ( $wrapper ) => $wrapper.validateEmailField(),
		pf_bad_number_error: ( $wrapper ) => $wrapper.validateNumberField(),
		pf_bad_integer_error: ( $wrapper ) => $wrapper.validateIntegerField(),
		pf_bad_date_error: ( $wrapper ) => $wrapper.validateDateField(),
		pf_pipe_error: ( $wrapper ) => $wrapper.checkForPipes()
	};

	function controls( $wrapper ) {
		return $wrapper.find( 'input:not([type="hidden"]), select, textarea, .select2-selection, [contenteditable="true"]' );
	}

	function field( $wrapper ) {
		return $wrapper.find( 'input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)' ).first();
	}

	errors.clear = function ( $root ) {
		const $messages = $root.find( '.errorMessage' );
		const ids = new Set( $messages.map( function () { return this.id; } ).get() );
		$root.find( '[aria-describedby]' ).each( function () {
			const $control = $( this );
			const remaining = ( $control.attr( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( ( id ) => id && !ids.has( id ) );
			if ( remaining.length ) {
				$control.attr( 'aria-describedby', remaining.join( ' ' ) );
			} else {
				$control.removeAttr( 'aria-describedby' );
			}
			if ( $control.data( 'pfErrorMarked' ) ) {
				const original = $control.data( 'pfOriginalInvalid' );
				if ( original === undefined ) {
					$control.removeAttr( 'aria-invalid' );
				} else {
					$control.attr( 'aria-invalid', original );
				}
				$control.removeData( 'pfErrorMarked pfOriginalInvalid' );
			}
		} );
		$root.find( '.inputError' ).removeClass( 'inputError' );
		$messages.each( function () { $( this ).parent().removeData( 'pfErrorRules' ); } ).remove();
		$root.find( '.pf-instance-error' ).removeClass( 'pf-instance-error' );
	};

	errors.add = function ( $wrapper, key, value ) {
		const id = 'pf-field-error-' + ( ++nextId );
		const $message = $( '<div>' ).attr( 'id', id ).addClass( 'errorMessage' ).text( mw.msg( key, value ) );
		$wrapper.append( $message );
		const rules = $wrapper.data( 'pfErrorRules' ) || [];
		rules.push( { key, value } );
		$wrapper.data( 'pfErrorRules', rules );
		controls( $wrapper ).each( function () {
			const $control = $( this );
			if ( !$control.data( 'pfErrorMarked' ) ) {
				$control.data( 'pfOriginalInvalid', $control.attr( 'aria-invalid' ) ).data( 'pfErrorMarked', true );
			}
			const descriptions = ( $control.attr( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
			descriptions.push( id );
			$control.attr( { 'aria-invalid': 'true', 'aria-describedby': descriptions.join( ' ' ) } ).addClass( 'inputError' );
		} );
		$wrapper.parents( '.multipleTemplateInstance' ).addClass( 'pf-instance-error' );
	};

	function label( $wrapper ) {
		const $field = field( $wrapper );
		const id = $field.attr( 'id' );
		const $label = $( 'label' ).filter( function () { return id && this.htmlFor === id; } ).first();
		const name = $field.attr( 'name' ) || '';
		return $label.text().trim() || $field.attr( 'aria-label' ) ||
			name.replace( /\[\]$/, '' ).split( /[\[\]]/ ).filter( Boolean ).pop() || mw.msg( 'pf-error-field' );
	}

	errors.reveal = function ( $wrapper ) {
		$wrapper.parents( '.multipleTemplateInstance.minimized' ).each( function () {
			const instance = this;
			const owned = ( selector ) => $( instance ).find( selector ).filter( function () {
				return $( this ).closest( '.multipleTemplateInstance' )[ 0 ] === instance;
			} );
			$( instance ).removeClass( 'minimized' );
			owned( '.fieldValuesDisplay' ).remove();
			owned( '.instanceMain' ).removeClass( 'pf-hidden' ).show();
		} );
		$wrapper.parents( '.mw-collapsible.mw-collapsed' ).each( function () {
			const collapsible = $( this ).data( 'mw-collapsible' );
			if ( collapsible && typeof collapsible.expand === 'function' ) {
				collapsible.expand();
			}
		} );
		const $screen = $wrapper.closest( '.pf-wizard-screen' );
		if ( $screen.length && typeof $.fn.displayWizardScreen === 'function' ) {
			const $screens = $wrapper.closest( 'form' ).find( '.pf-wizard-screen' );
			$screens.displayWizardScreen( $screens.index( $screen ) + 1, $wrapper.closest( 'form' ).find( '.pf-wizard-navigation' ) );
		}
		const $field = field( $wrapper );
		const ready = $field.length && mw.pageFormsLazyWidgets ? mw.pageFormsLazyWidgets.run( $field[ 0 ] ) : Promise.resolve();
		return ready.then( () => {
			const widget = $field.data( 'select2' );
			const target = $field.data( 'veForAllTarget' );
			let $focus = $field;
			if ( widget ) {
				$focus = widget.selection.$search || widget.selection.$selection;
			} else if ( target && target.getSurface && target.getSurface() ) {
				target.getSurface().getView().focus();
				$focus = $wrapper;
			}
			if ( !$focus.length ) {
				$focus = $wrapper.attr( 'tabindex', '-1' );
			}
			$focus.trigger( 'focus' );
			if ( $focus[ 0 ] && typeof $focus[ 0 ].scrollIntoView === 'function' ) {
				$focus[ 0 ].scrollIntoView( { block: 'center' } );
			}
		} );
	};

	errors.summarize = function ( $form, focus = true ) {
		const $messages = $form.find( '.errorMessage' ).filter( function () {
			return !$( this ).closest( '.multipleTemplateStarter, .pf-removed-instance, .hiddenByPF' ).length;
		} );
		$form.find( '.pf-instance-error' ).each( function () {
			$( this ).toggleClass( 'pf-instance-error', $( this ).find( '.errorMessage' ).length > 0 );
		} );
		$( '#pf-error-summary' ).remove();
		if ( !$messages.length ) {
			$( '#form_error_header[data-pf-errors]' ).remove();
			return;
		}
		$( '#form_error_header' ).remove();
		const $header = $( '<div>' ).attr( {
			id: 'form_error_header', role: 'alert', 'data-pf-errors': 'true'
		} ).addClass( 'errorbox pf-form-error-header cdx-message cdx-message--error' ).text( mw.msg( 'pf-errors-summary', $messages.length ) );
		const $list = $( '<ul>' ).attr( 'id', 'pf-error-summary' ).appendTo( $header );
		$messages.each( function () {
			const $wrapper = $( this ).parent();
			const $button = $( '<button>' ).attr( 'type', 'button' )
				.text( label( $wrapper ) + ': ' + $( this ).text() )
				.on( 'click', () => errors.reveal( $wrapper ) );
			$( '<li>' ).append( $button ).appendTo( $list );
		} );
		$form.prepend( $header );
		if ( focus ) {
			errors.reveal( $messages.first().parent() );
		}
	};

	errors.bind = function ( $form ) {
		$form.off( '.pfErrors' ).on( 'input.pfErrors change.pfErrors focusout.pfErrors', 'input, select, textarea', function ( event ) {
			if ( $form.data( 'pfSubmissionPending' ) || ( event.originalEvent && event.originalEvent.isComposing ) ) {
				return;
			}
			const $wrapper = $( this ).parents().filter( function () { return !!$( this ).data( 'pfErrorRules' ); } ).first();
			const rules = ( $wrapper.data( 'pfErrorRules' ) || [] ).slice();
			if ( !rules.length ) {
				return;
			}
			const unique = rules.some( ( rule ) => rule.key === 'pf_not_unique_error' || rule.key === 'pf-unique-check-error' );
			// Avoid network checks at every keystroke. A new submit always rechecks.
			if ( unique && event.type !== 'focusout' ) {
				return;
			}
			errors.clear( $wrapper );
			let check = Promise.resolve();
			if ( unique ) {
				const original = $( this ).val();
				check = $wrapper.validateUniqueField( () => document.documentElement.contains( this ) && $( this ).val() === original );
			}
			new Set( rules.map( ( rule ) => rule.key ) ).forEach( ( key ) => {
				if ( validators[ key ] ) {
					validators[ key ]( $wrapper );
				} else if ( key !== 'pf_not_unique_error' && key !== 'pf-unique-check-error' ) {
					const rule = rules.find( ( item ) => item.key === key );
					errors.add( $wrapper, rule.key, rule.value );
				}
			} );
			Promise.resolve( check ).then( () => errors.summarize( $form, false ) );
		} );
	};
}( jQuery, mediaWiki ) );
