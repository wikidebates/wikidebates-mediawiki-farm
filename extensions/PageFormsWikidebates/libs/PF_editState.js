/** Shared semantic edit state. Never mark edits made during a save as saved. */
( function ( $, mw ) {
	'use strict';

	const edits = mw.pageFormsEditState = {};
	const instanceKeys = new WeakMap();
	let instanceSequence = 0;

	edits.isField = function ( element, includeStarter = false ) {
		const $field = $( element );
		const type = ( element.type || '' ).toLowerCase();
		const widgetValue = type === 'hidden' && ( $field.hasClass( 'PFTree_data' ) || $field.parent().hasClass( 'pfRatingWrapper' ) );
		return !!element.name && ( type !== 'hidden' || widgetValue ) && ![ 'password', 'file', 'submit', 'reset', 'button', 'image' ].includes( type ) &&
			!$field.closest( includeStarter ? '.pf-removed-instance' : '.multipleTemplateStarter, .pf-removed-instance' ).length &&
			!( element.disabled && !$field.hasClass( 'disabledByPF' ) && !$field.hasClass( 'visualeditor' ) ) &&
			!/token|captcha|password|secret/i.test( element.name );
	};

	edits.fields = function ( $root ) {
		return $root.find( 'input, select, textarea' ).filter( function () { return edits.isField( this ); } );
	};

	edits.read = function ( element, visual = true ) {
		const $field = $( element );
		const target = $field.data( 'veForAllTarget' );
		if ( visual && target && target.getSurface && ( !target.$node || $field.css( 'display' ) === 'none' ) ) {
			const surface = target.getSurface();
			if ( surface && typeof surface.getHtml === 'function' ) {
				return { visual: surface.getHtml(), source: $field.val(), synced: !$field.hasClass( 've-for-all-waiting-for-update' ) && !target.pendingUpdatePromise };
			}
		}
		const value = $field.val();
		if ( element.type === 'checkbox' || element.type === 'radio' ) {
			return { value, checked: element.checked };
		}
		return Array.isArray( value ) ? value.slice() : value;
	};

	edits.capture = function ( $form ) {
		const fields = edits.fields( $form ).map( function () {
			return { name: this.name, value: edits.read( this ) };
		} ).get();
		const structure = $form.find( '.multipleTemplateList' ).filter( function () {
			return !$( this ).closest( '.multipleTemplateStarter, .pf-removed-instance' ).length;
		} ).map( function () {
			return { instances: $( this ).children( '.multipleTemplateInstance' ).map( function () {
				if ( !instanceKeys.has( this ) ) {
					instanceKeys.set( this, ++instanceSequence );
				}
				return instanceKeys.get( this );
			} ).get() };
		} ).get();
		return JSON.stringify( { fields, structure } );
	};

	// Switching editor modes or completing a conversion is not itself an edit.
	edits.equivalent = function ( a, b ) {
		const left = JSON.parse( a );
		const right = JSON.parse( b );
		if ( JSON.stringify( left.structure ) !== JSON.stringify( right.structure ) || left.fields.length !== right.fields.length ) {
			return false;
		}
		return left.fields.every( ( field, i ) => {
			const other = right.fields[ i ];
			if ( field.name !== other.name ) { return false; }
			const x = field.value;
			const y = other.value;
			const xv = x && typeof x === 'object' && typeof x.visual === 'string';
			const yv = y && typeof y === 'object' && typeof y.visual === 'string';
			if ( xv && yv ) { return x.visual === y.visual; }
			if ( xv ) { return x.synced && x.source === y; }
			if ( yv ) { return y.source === x; }
			return JSON.stringify( x ) === JSON.stringify( y );
		} );
	};

	edits.isDirty = function ( $form ) {
		const state = $form.data( 'pfEditState' );
		return !!state && !edits.equivalent( edits.capture( $form ), state.baseline );
	};

	edits.refresh = function ( $form ) {
		const state = $form.data( 'pfEditState' );
		if ( !state || !document.documentElement.contains( $form[ 0 ] ) ) {
			return;
		}
		clearTimeout( state.timer );
		state.dirty = edits.isDirty( $form );
		if ( !state.phase ) {
			const key = state.dirty ? 'pf-edit-unsaved' : ( state.savedAt ? 'pf-edit-saved-at' : 'pf-edit-unchanged' );
			state.$status.text( mw.msg( key, state.savedAt ? state.savedAt.toLocaleTimeString() : undefined ) );
		}
		$form.triggerHandler( 'pfEditStateChange', [ state.dirty ] );
	};

	edits.touch = function ( $form ) {
		const state = $form.data( 'pfEditState' );
		if ( !state ) {
			return;
		}
		state.version++;
		if ( !state.phase ) {
			state.$status.text( mw.msg( 'pf-edit-unsaved' ) );
		}
		clearTimeout( state.timer );
		// Large VE documents are serialized after a short pause, not per key.
		state.timer = setTimeout( () => edits.refresh( $form ), 150 );
		mw.hook( 'pf.editStateChanged' ).fire( $form );
	};

	edits.phase = function ( $form, phase ) {
		const state = $form.data( 'pfEditState' );
		if ( !state ) {
			return;
		}
		state.phase = phase;
		if ( phase ) {
			state.$status.text( mw.msg( phase === 'saving' ? 'pf-form-saving' : 'pf-form-validating' ) );
		} else {
			edits.refresh( $form );
		}
	};

	edits.ticket = function ( $form ) {
		return {
			snapshot: edits.capture( $form ),
			removed: mw.pageFormsInstances ? mw.pageFormsInstances.pendingRemovals( $form ) : []
		};
	};

	edits.saved = function ( $form, ticket ) {
		const state = $form.data( 'pfEditState' );
		if ( state ) {
			state.baseline = ticket.snapshot;
			state.savedAt = new Date();
			edits.refresh( $form );
		}
		mw.hook( 'pf.formSaved' ).fire( $form, ticket );
		return edits.isDirty( $form );
	};

	edits.bind = function ( $form ) {
		if ( !$form.length || $form.data( 'pfEditState' ) ) {
			return;
		}
		const $status = $( '<p>' ).addClass( 'pf-edit-state' ).attr( { role: 'status', 'aria-live': 'polite' } );
		const $buttons = $form.find( '.editButtons' ).first();
		if ( $buttons.length ) {
			$status.insertBefore( $buttons );
		} else {
			$status.appendTo( $form );
		}
		$form.data( 'pfEditState', { baseline: edits.capture( $form ), $status, version: 0 } );
		$form.on( 'input.pfEditState change.pfEditState', 'input, select, textarea', function () {
			if ( edits.isField( this ) ) {
				edits.touch( $form );
			}
		} );
		edits.refresh( $form );
	};

	mw.hook( 'pf.formChanged' ).add( ( $form ) => edits.touch( $form ) );
	mw.hook( 'pf.addTemplateInstance' ).add( ( $instance ) => edits.touch( $instance.closest( 'form' ) ) );
	mw.hook( 'veForAll.targetCreated' ).add( ( target ) => {
		if ( !target || !target.getSurface || !target.getSurface() ) {
			return;
		}
		const $form = target.$node.closest( 'form' );
		if ( !$form.length ) {
			return;
		}
		const state = $form.data( 'pfEditState' );
		if ( state ) {
			const baseline = JSON.parse( state.baseline );
			const field = baseline.fields.find( ( item ) => item.name === target.$node.attr( 'name' ) && item.value === target.$node.val() );
			if ( field ) {
				field.value = edits.read( target.$node[ 0 ] );
				state.baseline = JSON.stringify( baseline );
			}
		}
		target.getSurface().getModel().getDocument().on( 'transact', () => edits.touch( $form ) );
	} );
}( jQuery, mediaWiki ) );
