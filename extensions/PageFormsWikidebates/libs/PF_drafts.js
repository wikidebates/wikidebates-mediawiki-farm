/** Opt-in local drafts. Structured user values only; never tokens or form HTML. */
( function ( $, mw ) {
	'use strict';

	const drafts = mw.pageFormsDrafts = {};
	const ttl = 7 * 24 * 60 * 60 * 1000;
	const maxBytes = 1024 * 1024;
	const maxInstances = 500;
	const maxDepth = 6;

	function bounded( operation ) {
		let timer;
		return Promise.race( [ Promise.resolve().then( operation ), new Promise( ( resolve, reject ) => {
			timer = setTimeout( () => reject( new Error( 'Draft operation timed out' ) ), 30000 );
		} ) ] ).finally( () => clearTimeout( timer ) );
	}

	function nameOf( field ) {
		return ( $( field ).attr( 'origname' ) || field.name || '' ).replace( /^([^\[]+)\[(?:num|\d+[ab])\]/, '$1' );
	}

	function ownFields( $scope, starter = false ) {
		let $fields = $scope.find( 'input, select, textarea' ).filter( function () {
			return mw.pageFormsEditState.isField( this, starter ) && !this.readOnly;
		} );
		$fields = $fields.filter( function () {
			const instance = $( this ).closest( '.multipleTemplateInstance, .multipleTemplateStarter' )[ 0 ];
			return $scope.is( '.multipleTemplateInstance, .multipleTemplateStarter' ) ? instance === $scope[ 0 ] : !instance;
		} );
		return $fields;
	}

	function ownGroups( $scope ) {
		return $scope.find( '.multipleTemplateWrapper' ).filter( function () {
			const instance = $( this ).closest( '.multipleTemplateInstance, .multipleTemplateStarter' )[ 0 ];
			return $scope.is( '.multipleTemplateInstance, .multipleTemplateStarter' ) ? instance === $scope[ 0 ] : !instance;
		} );
	}

	function listOf( $group ) {
		return $group.find( '.multipleTemplateList' ).filter( function () {
			return $( this ).closest( '.multipleTemplateWrapper' )[ 0 ] === $group[ 0 ];
		} ).first();
	}

	drafts.capture = function ( $scope, depth = 0, quota = { remaining: maxInstances } ) {
		if ( depth > maxDepth ) {
			throw new Error( 'Draft nesting limit' );
		}
		return {
			fields: ownFields( $scope ).map( function () {
				const result = { name: nameOf( this ), type: this.type, value: mw.pageFormsEditState.read( this, false ) };
				if ( this.tagName === 'SELECT' ) {
					result.options = $( this ).find( 'option:selected' ).map( function () {
						return { value: this.value, label: this.textContent };
					} ).get();
				}
				return result;
			} ).get(),
			groups: ownGroups( $scope ).map( function () {
				const $list = listOf( $( this ) );
				const $items = $list.children( '.multipleTemplateInstance' );
				if ( $items.length > maxInstances || $items.length > quota.remaining ) {
					throw new Error( 'Draft instance limit' );
				}
				quota.remaining -= $items.length;
				return { instances: $items.map( function () { return drafts.capture( $( this ), depth + 1, quota ); } ).get() };
			} ).get()
		};
	};

	function treeElement( element ) {
		const $field = $( element );
		return element.type === 'hidden' && $field.hasClass( 'PFTree_data' ) ?
			$field.prev( '.pfTreeInput' ) : $();
	}

	function treeChoices( $tree ) {
		const params = JSON.parse( $tree.attr( 'params' ) || 'null' );
		const source = JSON.parse( $tree.attr( 'data' ) || 'null' );
		if ( !params || !Array.isArray( source ) || typeof params.delimiter !== 'string' || !params.delimiter ) {
			throw new Error( 'Invalid tree schema' );
		}
		const labels = new Map();
		let remaining = 2500;
		function visit( nodes, depth ) {
			if ( depth > 30 || !Array.isArray( nodes ) ) {
				throw new Error( 'Invalid tree depth' );
			}
			nodes.forEach( ( node ) => {
				if ( !node || typeof node.text !== 'string' || --remaining < 0 ) {
					throw new Error( 'Invalid tree node' );
				}
				labels.set( node.text, ( labels.get( node.text ) || 0 ) + 1 );
				if ( node.children !== undefined ) {
					visit( node.children, depth + 1 );
				}
			} );
		}
		visit( source, 0 );
		return { params, labels };
	}

	function ratingElement( element ) {
		const $field = $( element );
		return element.type === 'hidden' && $field.parent().hasClass( 'pfRatingWrapper' ) ?
			$field.siblings( '.pfRating' ).first() : $();
	}

	function validateField( element, saved ) {
		if ( !saved || saved.name !== nameOf( element ) || saved.type !== element.type ) {
			throw new Error( 'Form field schema changed' );
		}
		if ( element.type === 'hidden' ) {
			const $rating = ratingElement( element );
			if ( $rating.length ) {
				const max = Number( $rating.attr( 'data-numstars' ) || 5 );
				const value = saved.value === '' ? 0 : Number( saved.value );
				const step = $rating.is( '[data-allows-half]' ) ? 2 : 1;
				if ( typeof saved.value !== 'string' || !Number.isFinite( value ) ||
					!Number.isFinite( max ) || max <= 0 || value < 0 || value > max ||
					!Number.isInteger( value * step ) ) {
					throw new Error( 'Invalid rating value' );
				}
			} else {
				const $tree = treeElement( element );
				if ( !$tree.length || typeof saved.value !== 'string' ) {
					throw new Error( 'This widget requires manual draft recovery' );
				}
				const choices = treeChoices( $tree );
				const values = saved.value === '' ? [] : saved.value.split( choices.params.delimiter );
				if ( ( !choices.params.multiple && values.length > 1 ) ||
					values.some( ( value ) => choices.labels.get( value ) !== 1 ) ||
					new Set( values ).size !== values.length ) {
					// Ambiguous names, renamed nodes and invalid values stay export-only.
					throw new Error( 'Draft tree selection cannot be restored safely' );
				}
			}
		}
		if ( element.type === 'checkbox' || element.type === 'radio' ) {
			if ( !saved.value || typeof saved.value.checked !== 'boolean' || saved.value.value !== element.value ) {
				throw new Error( 'Choice schema changed' );
			}
		} else if ( typeof saved.value !== 'string' && saved.value !== null &&
			!( Array.isArray( saved.value ) && saved.value.every( ( value ) => typeof value === 'string' ) ) ) {
			throw new Error( 'Invalid saved value' );
		}
		if ( element.tagName === 'SELECT' ) {
			if ( !Array.isArray( saved.options ) || !saved.options.every( ( option ) => option && typeof option.value === 'string' && typeof option.label === 'string' ) ) {
				throw new Error( 'Invalid saved choices' );
			}
			if ( !$( element ).is( '.pfTokens, .pfComboBox' ) ) {
				const available = Array.from( element.options ).map( ( option ) => option.value );
				const values = Array.isArray( saved.value ) ? saved.value : [ saved.value ];
				if ( values.some( ( value ) => value !== null && !available.includes( value ) ) ) {
					throw new Error( 'An enum choice is no longer available' );
				}
			}
		}
	}

	// Preflight the entire tree before modifying a single value. New instances
	// are validated against the current server-rendered starter, never saved HTML.
	drafts.validate = function ( $scope, saved, depth = 0, starter = false, quota = { remaining: maxInstances } ) {
		if ( depth > maxDepth || !saved || !Array.isArray( saved.fields ) || !Array.isArray( saved.groups ) ) {
			throw new Error( 'Invalid draft shape' );
		}
		const $fields = ownFields( $scope, starter );
		const $groups = ownGroups( $scope );
		if ( $fields.length !== saved.fields.length || $groups.length !== saved.groups.length ) {
			throw new Error( 'Form schema changed' );
		}
		$fields.each( function ( index ) { validateField( this, saved.fields[ index ] ); } );
		$groups.each( function ( index ) {
			const $list = listOf( $( this ) );
			const group = saved.groups[ index ];
			const maximum = Number( $list.attr( 'maximumInstances' ) ) || maxInstances;
			if ( !group || !Array.isArray( group.instances ) ||
				group.instances.length > Math.min( maxInstances, maximum ) || group.instances.length > quota.remaining ) {
				throw new Error( 'Draft exceeds instance limit' );
			}
			const $items = $list.children( '.multipleTemplateInstance' );
			const $starter = $list.children( '.multipleTemplateStarter' ).first();
			if ( group.instances.length > $items.length && !$( this ).find( '.multipleTemplateAdder' ).length ) {
				throw new Error( 'Form does not allow new instances' );
			}
			if ( group.instances.length < $items.length && $items.slice( group.instances.length ).filter( function () { return !$( this ).find( '.removeButton' ).length; } ).length ) {
				throw new Error( 'Form does not allow removing instances' );
			}
			quota.remaining -= group.instances.length;
			group.instances.forEach( ( instance, i ) => {
				const $target = $items.eq( i );
				if ( !$target.length && !$starter.length ) {
					throw new Error( 'No starter for missing instance' );
				}
				drafts.validate( $target.length ? $target : $starter, instance, depth + 1, !$target.length, quota );
			} );
		} );
	};

	function replaceEditor( $field ) {
		const target = $field.data( 'veForAllTarget' );
		if ( !target ) {
			return;
		}
		if ( typeof $.fn.getVEInstances === 'function' ) {
			const editors = $.fn.getVEInstances();
			for ( let i = editors.length - 1; i >= 0; i-- ) {
				if ( editors[ i ].$node && editors[ i ].$node[ 0 ] === $field[ 0 ] ) {
					editors.splice( i, 1 );
				}
			}
		}
		if ( typeof target.destroy === 'function' ) {
			target.destroy();
		}
		$field.removeData( 'veForAllTarget veForAllInitializing' ).removeClass( 've-for-all-waiting-for-update' ).prop( 'disabled', false ).show();
	}

	function applyScope( $scope, saved, changedFields ) {
		ownFields( $scope ).each( function ( index ) {
			const $field = $( this );
			const item = saved.fields[ index ];
			replaceEditor( $field );
			if ( this.type === 'checkbox' || this.type === 'radio' ) {
				$field.prop( 'checked', item.value.checked );
			} else {
				if ( this.tagName === 'SELECT' ) {
					item.options.forEach( ( option ) => {
						let $option = $field.find( 'option' ).filter( function () { return this.value === option.value; } ).first();
						if ( !$option.length ) {
							$option = $( new Option( option.label, option.value ) ).appendTo( $field );
						}
						if ( $field.hasClass( 'pfTokens' ) ) {
							$option.appendTo( $field );
						}
					} );
				}
				$field.val( item.value );
				const $tree = treeElement( this );
				if ( $tree.length ) {
					const choices = treeChoices( $tree );
					choices.params.cur_value = item.value;
					$tree.attr( 'params', JSON.stringify( choices.params ) );
					const widget = $tree.data( 'jstree' );
					if ( widget && typeof widget.get_json === 'function' &&
						typeof widget.deselect_all === 'function' && typeof widget.select_node === 'function' ) {
						const nodes = widget.get_json( '#', { flat: true } );
						const values = item.value === '' ? [] : item.value.split( choices.params.delimiter );
						widget.deselect_all( true );
						values.forEach( ( label ) => {
							const node = nodes.find( ( candidate ) => candidate.text === label );
							widget.select_node( node.id, true, true );
						} );
					}
				}
				const $rating = ratingElement( this );
				if ( $rating.length ) {
					$rating.attr( 'data-curvalue', item.value );
					if ( $rating.data( 'rateYo' ) && typeof $.fn.rateYo === 'function' ) {
						$rating.rateYo( 'rating', Number( item.value || 0 ) );
					}
				}
				if ( $field.hasClass( 'pfComboBox' ) ) {
					$field.attr( { 'data-value': item.value || '', 'data-label': $field.find( 'option:selected' ).text() } );
				}
			}
			changedFields.push( this );
		} );
		ownGroups( $scope ).each( function ( index ) {
			const $group = $( this );
			const $list = listOf( $group );
			const data = saved.groups[ index ].instances;
			$list.children( '.multipleTemplateInstance' ).slice( data.length ).each( function () {
				mw.pageFormsInstances.dispose( $( this ) );
			} );
			while ( $list.children( '.multipleTemplateInstance' ).length < data.length ) {
				const $adder = $group.find( '.multipleTemplateAdder' ).filter( function () {
					return $( this ).closest( '.multipleTemplateWrapper' )[ 0 ] === $group[ 0 ];
				} ).first();
				const added = $adder.addInstance( false );
				if ( !added || !added.length ) {
					throw new Error( 'Could not restore a new instance' );
				}
			}
			$list.children( '.multipleTemplateInstance' ).each( function ( i ) { applyScope( $( this ), data[ i ], changedFields ); } );
		} );
	}

	drafts.restore = function ( $form, saved ) {
		drafts.validate( $form, saved );
		const changedFields = [];
		$form.data( 'pfRestoringDraft', true );
		try {
			applyScope( $form, saved, changedFields );
			changedFields.forEach( ( element ) => $( element ).trigger( 'change' ) );
		} finally {
			$form.removeData( 'pfRestoringDraft' );
			mw.pageFormsInstances.update( $form );
			mw.pageFormsInitializeWidgets( $form );
			if ( typeof $.fn.applyVisualEditor === 'function' ) {
				$form.find( '.visualeditor' ).not( '.multipleTemplateStarter .visualeditor' ).applyVisualEditor();
			}
			mw.pageFormsEditState.touch( $form );
		}
	};

	function parseRecord( text, identity, context ) {
		if ( !text || text.length > maxBytes ) {
			return null;
		}
		try {
			const record = JSON.parse( text );
			return record.version === 1 && record.identity === identity && record.context === context &&
				Number.isFinite( record.time ) && Date.now() - record.time < ttl && record.time <= Date.now() + 60000 &&
				record.data && typeof record.data === 'object' ? record : null;
		} catch ( error ) {
			return null;
		}
	}

	drafts.bind = function ( $form ) {
		const context = mw.config.get( 'wgPageFormsDraftContext' );
		if ( !$form.length || $form.data( 'pfDraftController' ) || !context || !context.enabled || !context.form ) {
			return;
		}
		const identity = JSON.stringify( [ context.wiki, context.user ] );
		const prefix = 'pageforms-draft-v1:' + encodeURIComponent( identity ) + ':';
		const enabledKey = prefix + 'enabled';
		const id = ( window.crypto && window.crypto.randomUUID ) ? window.crypto.randomUUID() : Date.now() + '-' + Math.random().toString( 36 ).slice( 2 );
		const key = prefix + id;
		let contextKey = JSON.stringify( [ context.form, context.target ] );
		let baseRevision = Number( context.targetRevision );
		let restoringKey;
		let timer;
		let pending = false;
		let recovering = false;
		let frozen = false;
		let enabled = false;
		let record;
		const $panel = $( '<details>' ).addClass( 'pf-drafts' );
		$( '<summary>' ).text( mw.msg( 'pf-draft-title' ) ).appendTo( $panel );
		$( '<p>' ).text( mw.msg( 'pf-draft-privacy' ) ).appendTo( $panel );
		const $toggle = $( '<input>' ).attr( 'type', 'checkbox' );
		$( '<label>' ).append( $toggle, document.createTextNode( mw.msg( 'pf-draft-enable' ) ) ).appendTo( $panel );
		const $status = $( '<p>' ).attr( 'role', 'status' ).appendTo( $panel );
		const $actions = $( '<div>' ).addClass( 'pf-draft-recovery' ).appendTo( $panel );
		const $buttons = $form.find( '.editButtons' ).first();
		if ( $buttons.length ) {
			$panel.insertBefore( $buttons );
		} else {
			$panel.appendTo( $form );
		}

		function message( keyName ) { $status.text( mw.msg( keyName ) ); }
		function storageError() {
			message( 'pf-draft-storage-error' );
			$panel.prop( 'open', true );
		}
		function put( data ) {
			const value = JSON.stringify( {
				version: 1, identity, context: contextKey, time: Date.now(),
				baseRevision, formRevision: context.formRevision, data
			} );
			if ( value.length * 2 > maxBytes ) {
				throw new Error( 'Draft size limit' );
			}
			localStorage.setItem( key, value );
			if ( restoringKey && restoringKey !== key ) {
				localStorage.removeItem( restoringKey );
				restoringKey = null;
			}
			message( 'pf-draft-saved' );
		}

		async function save() {
			clearTimeout( timer );
			if ( !enabled || frozen || pending || !mw.pageFormsEditState.isDirty( $form ) || $form.data( 'pfRestoringDraft' ) || $form.data( 'pfSubmissionPending' ) ) {
				return;
			}
			pending = true;
			message( 'pf-draft-pending' );
			try {
				// Store wikitext, not executable editor DOM. Parsoid conversion is
				// read-only and debounced; this never calls edit or pfautoedit.
				await bounded( () => mw.pageFormsActivateVEFields() );
				if ( enabled && !frozen && mw.pageFormsEditState.isDirty( $form ) && !$form.data( 'pfSubmissionPending' ) ) {
					put( drafts.capture( $form ) );
				}
			} catch ( error ) {
				storageError();
			} finally {
				pending = false;
			}
		}
		function schedule() {
			clearTimeout( timer );
			if ( enabled && !frozen ) {
				message( 'pf-draft-pending' );
				timer = setTimeout( save, 2500 );
			}
		}

		async function currentRevisions() {
			const titles = [ context.form ];
			if ( context.target ) {
				titles.push( context.target );
			}
			const response = await new mw.Api().get( { action: 'query', prop: 'info', titles: titles.join( '|' ), formatversion: 2 }, { timeout: 15000 } );
			if ( !response || response.error || !response.query || !Array.isArray( response.query.pages ) ) {
				throw new Error( 'Cannot verify current revisions' );
			}
			const find = ( title ) => response.query.pages.find( ( page ) => page.title === title );
			const form = find( context.form );
			const target = context.target ? find( context.target ) : { missing: true };
			if ( !form || !target || form.invalid || target.invalid ) {
				throw new Error( 'Cannot resolve the draft context' );
			}
			return { form: Number( form.lastrevid || 0 ), target: Number( target.lastrevid || 0 ) };
		}

		async function recover( candidate ) {
			if ( pending || recovering || $form.data( 'pfSubmissionPending' ) ) {
				return;
			}
			recovering = true;
			try {
				drafts.validate( $form, candidate.data );
				const revisions = await bounded( currentRevisions );
				if ( revisions.target !== baseRevision || revisions.form !== Number( context.formRevision ) ) {
					message( 'pf-draft-reload' );
					return;
				}
				const old = revisions.target !== Number( candidate.baseRevision ) || revisions.form !== Number( candidate.formRevision );
				if ( !window.confirm( mw.msg( old ? 'pf-draft-confirm-old' : 'pf-draft-confirm' ) ) ) {
					return;
				}
				if ( !mw.pageFormsSubmission.begin( $form ) ) {
					return;
				}
				try {
					await bounded( () => mw.pageFormsActivateVEFields() );
					// Keep a separate pre-restore recovery copy if a third-party
					// initializer throws after schema preflight. Fail closed on quota.
					const backupKey = key + '-before-restore-' + Date.now();
					const backup = JSON.stringify( { version: 1, identity, context: contextKey, time: Date.now(),
						baseRevision, formRevision: context.formRevision, data: drafts.capture( $form ) } );
					if ( backup.length * 2 > maxBytes ) { throw new Error( 'Recovery backup too large' ); }
					localStorage.setItem( backupKey, backup );
					drafts.restore( $form, candidate.data );
					localStorage.removeItem( backupKey );
					frozen = false;
					$actions.empty();
					message( 'pf-draft-restored' );
					schedule();
				} finally {
					mw.pageFormsSubmission.end( $form );
				}
			} catch ( error ) {
				// Retain the record, including when a schema no longer matches.
				message( 'pf-draft-restore-error' );
			} finally {
				recovering = false;
			}
		}

		try {
			enabled = localStorage.getItem( enabledKey ) === 'true';
			let newest;
			for ( let i = localStorage.length - 1; i >= 0; i-- ) {
				const storedKey = localStorage.key( i );
				if ( !storedKey || !storedKey.startsWith( prefix ) || storedKey === enabledKey ) {
					continue;
				}
				const text = localStorage.getItem( storedKey );
				try {
					const age = JSON.parse( text ).time;
					if ( !Number.isFinite( age ) || Date.now() - age >= ttl ) {
						localStorage.removeItem( storedKey );
						continue;
					}
				} catch ( error ) {
					localStorage.removeItem( storedKey );
					continue;
				}
				const candidate = parseRecord( text, identity, contextKey );
				if ( candidate && ( !newest || candidate.time > newest.time ) ) {
					newest = candidate;
					restoringKey = storedKey;
				}
			}
			if ( newest ) {
				record = newest;
				frozen = true;
				$panel.prop( 'open', true );
				message( 'pf-draft-found' );
				$( '<button>' ).attr( 'type', 'button' ).text( mw.msg( 'pf-draft-restore' ) )
					.appendTo( $actions ).on( 'click', () => recover( record ) );
				$( '<button>' ).attr( 'type', 'button' ).text( mw.msg( 'pf-draft-discard' ) )
					.appendTo( $actions ).on( 'click', () => {
						try {
							localStorage.removeItem( restoringKey );
							restoringKey = null;
							frozen = false;
							$actions.empty();
							message( 'pf-draft-discarded' );
						} catch ( error ) { storageError(); }
					} );
				// An incompatible form never destroys the recovery data. Export is
				// plain JSON containing only the same explicitly permitted values.
				$( '<button>' ).attr( 'type', 'button' ).text( mw.msg( 'pf-draft-export' ) )
					.appendTo( $actions ).on( 'click', () => {
						const url = URL.createObjectURL( new Blob( [ JSON.stringify( record.data, null, '\t' ) ], { type: 'application/json' } ) );
						const link = document.createElement( 'a' );
						link.href = url;
						link.download = 'pageforms-draft.json';
						link.click();
						setTimeout( () => URL.revokeObjectURL( url ), 1000 );
					} );
			}
		} catch ( error ) { storageError(); }
		$toggle.prop( 'checked', enabled ).on( 'change', () => {
			try {
				enabled = $toggle.prop( 'checked' );
				localStorage.setItem( enabledKey, String( enabled ) );
				if ( enabled ) {
					schedule();
				} else {
					clearTimeout( timer );
					localStorage.removeItem( key );
					message( 'pf-draft-disabled' );
				}
			} catch ( error ) { storageError(); }
		} );
		$form.data( 'pfDraftController', { save, recover, key } );
		mw.hook( 'pf.editStateChanged' ).add( ( $changedForm ) => { if ( $changedForm[ 0 ] === $form[ 0 ] ) { schedule(); } } );
		mw.hook( 'pf.formSaved' ).add( ( $savedForm, ticket ) => {
			if ( $savedForm[ 0 ] !== $form[ 0 ] ) {
				return;
			}
			if ( ticket.result && ticket.result.target ) {
				context.target = ticket.result.target;
				baseRevision = Number( ticket.result.revision || baseRevision );
				contextKey = JSON.stringify( [ context.form, context.target ] );
			}
			try {
				// Only this tab's acknowledged draft is removed, never another tab's.
				if ( !mw.pageFormsEditState.isDirty( $form ) ) {
					localStorage.removeItem( key );
					if ( restoringKey && !frozen ) {
						localStorage.removeItem( restoringKey );
						restoringKey = null;
					}
					message( 'pf-draft-cleared' );
				} else {
					schedule();
				}
			} catch ( error ) { storageError(); }
		} );
		$( window ).on( 'storage.pfDrafts', ( event ) => {
			const change = event.originalEvent || event;
			if ( change.key === enabledKey && change.newValue !== 'true' ) {
				enabled = false;
				$toggle.prop( 'checked', false );
				clearTimeout( timer );
				message( 'pf-draft-disabled' );
			}
		} );
		$( window ).on( 'pagehide.pfDrafts', () => {
			clearTimeout( timer );
			if ( enabled && !frozen && !pending && mw.pageFormsEditState.isDirty( $form ) && !$form.find( '.ve-for-all-waiting-for-update' ).length ) {
				try { put( drafts.capture( $form ) ); } catch ( error ) { /* Keep the previous complete record. */ }
			}
		} );
	};
}( jQuery, mediaWiki ) );
