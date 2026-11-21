/** Reversible removals and keyboard/touch alternatives to drag-and-drop. */
( function ( $, mw ) {
	'use strict';

	const instances = mw.pageFormsInstances = {};
	let sequence = 0;
	const removed = new Map();

	function changed( $form ) {
		mw.hook( 'pf.formChanged' ).fire( $form );
	}

	function listItems( $list ) {
		return $list.children( '.multipleTemplateInstance' );
	}

	instances.update = function ( $root ) {
		$root.find( '.multipleTemplateList' ).addBack( '.multipleTemplateList' ).each( function () {
			const $list = $( this );
			const $items = listItems( $list );
			$items.each( function ( index ) {
				const $instance = $( this );
				// The server omits editing controls for read-only/disabled forms.
				if ( !$instance.find( '.removeButton' ).filter( function () {
					return $( this ).closest( '.multipleTemplateInstance' )[ 0 ] === $instance[ 0 ];
				} ).length || $instance.closest( '.multipleTemplateStarter' ).length ) {
					return;
				}
				$instance.attr( 'data-instance-order', index );
				$instance.find( 'a.removeButton, a.addAboveButton' ).attr( { role: 'button', tabindex: '0' } )
					.off( 'keydown.pfInstanceKeyboard' ).on( 'keydown.pfInstanceKeyboard', function ( event ) {
						if ( event.key === 'Enter' || event.key === ' ' ) { event.preventDefault(); $( this ).trigger( 'click' ); }
					} );
				let $actions = $instance.children( '.pf-instance-actions' );
				if ( !$actions.length ) {
					$actions = $( '<span>' ).addClass( 'pf-instance-actions' ).prependTo( $instance );
					[ [ -1, 'pf-instance-up' ], [ 1, 'pf-instance-down' ] ].forEach( ( action ) => {
						$( '<button>' ).attr( 'type', 'button' ).addClass( action[ 1 ] )
							.text( mw.msg( action[ 1 ] ) ).appendTo( $actions ).on( 'click', ( event ) => {
								event.stopPropagation();
								instances.move( $instance, action[ 0 ] );
							} );
					} );
				}
				$actions.find( '.pf-instance-up' ).prop( 'disabled', index === 0 );
				$actions.find( '.pf-instance-down' ).prop( 'disabled', index === $items.length - 1 );
			} );
		} );
	};

	instances.move = function ( $instance, direction ) {
		const $list = $instance.parent();
		const $other = direction < 0 ? $instance.prevAll( '.multipleTemplateInstance' ).first() : $instance.nextAll( '.multipleTemplateInstance' ).first();
		if ( !$other.length ) {
			return;
		}
		if ( direction < 0 ) {
			$instance.insertBefore( $other );
		} else {
			$instance.insertAfter( $other );
		}
		instances.update( $list );
		let $status = $list.children( '.pf-instance-position' );
		if ( !$status.length ) {
			$status = $( '<span>' ).addClass( 'pf-instance-position' ).attr( 'role', 'status' ).appendTo( $list );
		}
		$status.text( mw.msg( 'pf-instance-position', listItems( $list ).index( $instance ) + 1, listItems( $list ).length ) );
		changed( $instance.closest( 'form' ) );
	};

	instances.remove = function ( $instance ) {
		if ( !$instance.length || !document.documentElement.contains( $instance[ 0 ] ) ) {
			return;
		}
		const $form = $instance.closest( 'form' );
		const $list = $instance.parent();
		const id = ++sequence;
		const $notice = $( '<div>' ).addClass( 'pf-instance-undo' ).attr( 'role', 'status' )
			.text( mw.msg( 'pf-instance-removed' ) ).insertBefore( $instance );
		$instance.find( 'select' ).each( function () {
			if ( $( this ).data( 'select2' ) ) {
				$( this ).select2( 'close' );
			}
		} );
		mw.pageFormsLazyWidgets.pause( $instance );
		mw.pageFormsDependencies.cancel( $instance );
		$instance.addClass( 'pf-removed-instance' ).detach();
		removed.set( id, { $instance, $notice, $form } );
		const $undo = $( '<button>' ).attr( 'type', 'button' ).text( mw.msg( 'pf-instance-undo' ) ).appendTo( $notice );
		$undo.on( 'click', () => {
			const maximum = Number( $list.attr( 'maximumInstances' ) );
			if ( maximum && listItems( $list ).length >= maximum ) {
				mw.pageFormsSubmission.showError( $form, mw.msg( 'pf_too_many_instances_error', maximum ) );
				return;
			}
			$instance.removeClass( 'pf-removed-instance' ).insertBefore( $notice );
			$notice.remove();
			removed.delete( id );
			mw.pageFormsLazyWidgets.resume( $instance );
			$instance.find( 'input, select, textarea' ).each( function () {
				$( this ).setAutocompleteForDependentField( $( this ).attr( 'origname' ) !== undefined );
			} );
			instances.update( $list );
			$instance.children( '.pf-instance-actions' ).find( 'button:not(:disabled)' ).first().trigger( 'focus' );
			changed( $form );
		} );
		instances.update( $list );
		$undo.trigger( 'focus' );
		changed( $form );
	};

	instances.pendingRemovals = function ( $form ) {
		return Array.from( removed ).filter( ( item ) => item[ 1 ].$form[ 0 ] === $form[ 0 ] ).map( ( item ) => item[ 0 ] );
	};

	instances.dispose = function ( $instance ) {
		mw.pageFormsLazyWidgets.pause( $instance );
		mw.pageFormsDependencies.cancel( $instance );
		$instance.find( 'input, select, textarea' ).each( function () {
			const $field = $( this );
			$field.PageForms_unregisterInputInit();
			$field.PageForms_unregisterInputValidation();
			const widget = $field.data( 'pfTokensInstance' ) || $field.data( 'pfComboBoxInstance' );
			if ( widget && typeof widget.destroy === 'function' ) {
				widget.destroy( $field );
			} else if ( $field.data( 'select2' ) ) {
				$field.select2( 'destroy' );
			}
			if ( typeof $.fn.getVEInstances === 'function' ) {
				const editors = $.fn.getVEInstances();
				for ( let i = editors.length - 1; i >= 0; i-- ) {
					if ( editors[ i ].$node && editors[ i ].$node[ 0 ] === this ) {
						editors.splice( i, 1 );
					}
				}
			}
			const target = $field.data( 'veForAllTarget' );
			if ( target && typeof target.destroy === 'function' ) {
				target.destroy();
			}
		} );
		$instance.remove();
	};

	mw.hook( 'pf.formSaved' ).add( ( $form, ticket ) => {
		// Do not dispose blocks removed after the request was sent, or restored
		// while it was pending. Only the acknowledged deletion becomes final.
		( ticket.removed || [] ).forEach( ( id ) => {
			const entry = removed.get( id );
			if ( entry && entry.$form[ 0 ] === $form[ 0 ] ) {
				instances.dispose( entry.$instance );
				entry.$notice.remove();
				removed.delete( id );
			}
		} );
	} );
}( jQuery, mediaWiki ) );
