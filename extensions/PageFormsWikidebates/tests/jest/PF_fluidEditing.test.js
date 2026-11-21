'use strict';

const { $, setup, settle } = require( './helpers/form' );
let context;

beforeEach( async () => {
	context = await setup();
} );
afterEach( () => {
	jest.restoreAllMocks();
	delete window.IntersectionObserver;
	delete $.fn.applyVisualEditor;
	delete $.fn.getVEInstances;
} );

function block( text, index = 0 ) {
	return $( '<div class="multipleTemplateInstance"><div class="multipleTemplateInstanceTable">' +
		'<div class="instanceRearranger"></div><div class="instanceMain">' +
		'<span class="inputSpan"><textarea name="Quote[' + index + 'a][Text]" id="quote_' + index + '"></textarea></span>' +
		'</div><div class="instanceRemove"><a class="removeButton"></a></div></div></div>' )
		.find( 'textarea' ).val( text ).end();
}
function list() {
	return $( '<div class="multipleTemplateWrapper"><div class="multipleTemplateList"></div></div>' )
		.appendTo( context.$form ).find( '.multipleTemplateList' );
}
function rebaseline() {
	context.$form.data( 'pfEditState' ).baseline = mw.pageFormsEditState.capture( context.$form );
}

describe( 'per-element lazy loading', () => {
	function observer() {
		const io = { observe: jest.fn(), unobserve: jest.fn() };
		window.IntersectionObserver = jest.fn( ( callback ) => { io.callback = callback; return io; } );
		return io;
	}
	test( 'initializes only the intersecting field, excluding hidden starters', async () => {
		const io = observer();
		const $root = $( '<div><input id="one"><input id="two"><div class="multipleTemplateStarter"><input id="starter"></div></div>' ).appendTo( context.$form );
		const init = jest.fn();
		mw.pageFormsLazyWidgets.register( $root.find( 'input' ), init );
		expect( io.observe ).toHaveBeenCalledTimes( 2 );
		io.callback( [ { target: $( '#one' )[ 0 ], isIntersecting: true } ] );
		await settle();
		expect( init ).toHaveBeenCalledTimes( 1 );
		expect( init.mock.calls[ 0 ][ 0 ][ 0 ] ).toBe( $( '#one' )[ 0 ] );
	} );

	test( 'coalesces focus and intersection while a module is pending', async () => {
		const io = observer();
		const $field = $( '<input>' ).appendTo( context.$form );
		let done;
		const init = jest.fn( () => new Promise( ( resolve ) => { done = resolve; } ) );
		mw.pageFormsLazyWidgets.register( $field, init );
		$field.trigger( 'focus' );
		io.callback( [ { target: $field[ 0 ], isIntersecting: true } ] );
		await settle();
		expect( init ).toHaveBeenCalledTimes( 1 );
		expect( $field.attr( 'aria-busy' ) ).toBe( 'true' );
		done();
		await settle();
		expect( $field.attr( 'aria-busy' ) ).toBeUndefined();
	} );

	test( 'a failed initializer can be retried instead of staying marked ready', async () => {
		observer();
		const $field = $( '<input>' ).appendTo( context.$form );
		const init = jest.fn().mockRejectedValueOnce( new Error( 'module unavailable' ) ).mockResolvedValue( undefined );
		mw.pageFormsLazyWidgets.register( $field, init );
		await mw.pageFormsLazyWidgets.run( $field[ 0 ] );
		expect( $( '.pf-widget-retry' ) ).toHaveLength( 1 );
		$( '.pf-widget-retry' ).trigger( 'click' );
		await settle();
		expect( init ).toHaveBeenCalledTimes( 2 );
		expect( $( '.pf-widget-retry' ) ).toHaveLength( 0 );
	} );

	test( 'never initializes a detached field from an already queued observer', async () => {
		const io = observer();
		const $field = $( '<input>' ).appendTo( context.$form );
		const init = jest.fn();
		mw.pageFormsLazyWidgets.register( $field, init );
		$field.detach();
		io.callback( [ { target: $field[ 0 ], isIntersecting: true } ] );
		await settle();
		expect( init ).not.toHaveBeenCalled();
	} );

	test( 'falls back to progressive enhancement without IntersectionObserver', async () => {
		const $field = $( '<input>' ).appendTo( context.$form );
		const init = jest.fn();
		mw.pageFormsLazyWidgets.register( $field, init );
		await settle();
		expect( init ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'dependent suggestions', () => {
	let $field;
	beforeEach( () => {
		context.values.wgPageFormsFieldProperties = { parent: 'Parent property', child: 'Child property' };
		$field = $( '<select name="child"><option value="saved" selected>Saved</option></select>' ).appendTo( context.$form );
	} );
	function update( value ) { return mw.pageFormsDependencies.update( $field, 'child', 'parent', value ); }

	test( 'coalesces change and blur for one parent value and encodes parameters separately', async () => {
		const first = update( 'A & B + "C"' );
		expect( update( 'A & B + "C"' ) ).toBe( first );
		expect( context.requests ).toHaveLength( 1 );
		expect( context.requests[ 0 ].options.data.basevalue ).toBe( 'A & B + "C"' );
		expect( context.requests[ 0 ].options.async ).not.toBe( false );
		context.requests[ 0 ].deferred.resolve( { pfautocomplete: [ { title: 'Answer' } ] } );
		await first;
		update( 'A & B + "C"' );
		expect( context.requests ).toHaveLength( 1 );
		expect( $field.data( 'autocompletevalues' ) ).toEqual( [ 'Answer' ] );
	} );

	test( 'out-of-order results cannot replace newer suggestions', async () => {
		update( 'old' );
		context.requests[ 0 ].xhr.abort.mockImplementation( () => {} );
		update( 'new' );
		context.requests[ 1 ].deferred.resolve( { pfautocomplete: [ { title: 'New' } ] } );
		context.requests[ 0 ].deferred.resolve( { pfautocomplete: [ { title: 'Old' } ] } );
		await settle();
		expect( $field.data( 'autocompletevalues' ) ).toEqual( [ 'New' ] );
		expect( $field.data( 'pfDependentState' ).value ).toBe( 'new' );
	} );

	test.each( [ null, { error: { code: 'offline' } }, { pfautocomplete: {} }, { pfautocomplete: [ null ] } ] )(
		'response %p is an error, not an empty set of choices', async ( response ) => {
			update( 'base' );
			context.requests[ 0 ].deferred.resolve( response );
			await settle();
			expect( $field.val() ).toBe( 'saved' );
			expect( $field.data( 'pfDependentState' ).error ).toBe( true );
			expect( $( '.pf-dependent-status button' ) ).toHaveLength( 1 );
			await expect( mw.pageFormsDependencies.settle( context.$form ) ).rejects.toThrow();
		} );

	test( 'timeout allows an explicit retry and retains current selections', async () => {
		update( 'base' );
		expect( context.requests[ 0 ].options.timeout ).toBe( 15000 );
		context.requests[ 0 ].deferred.reject( context.requests[ 0 ].xhr, 'timeout' );
		$( '.pf-dependent-status button' ).trigger( 'click' );
		expect( context.requests ).toHaveLength( 2 );
		context.requests[ 1 ].deferred.resolve( { pfautocomplete: [] } );
		await settle();
		expect( $field.data( 'pfDependentState' ).error ).toBeUndefined();
	} );

	test( 'expired completed requests are rechecked', async () => {
		update( 'base' );
		context.requests[ 0 ].deferred.resolve( { pfautocomplete: [] } );
		await settle();
		$field.data( 'pfDependentState' ).completedAt -= 31000;
		update( 'base' );
		expect( context.requests ).toHaveLength( 2 );
	} );

	test( 'submission waits for dependent options before validation', async () => {
		update( 'base' );
		let done = false;
		const promise = mw.pageFormsDependencies.settle( context.$form ).then( () => { done = true; } );
		await settle();
		expect( done ).toBe( false );
		context.requests[ 0 ].deferred.resolve( { pfautocomplete: [] } );
		await promise;
		expect( done ).toBe( true );
	} );

	test( 'cancelling a removed block aborts pending requests without affecting another field', async () => {
		update( 'base' );
		const $other = $field.clone().appendTo( context.$form );
		mw.pageFormsDependencies.update( $other, 'child', 'parent', 'base' );
		mw.pageFormsDependencies.cancel( $field );
		expect( context.requests[ 0 ].xhr.abort ).toHaveBeenCalledTimes( 1 );
		expect( context.requests[ 1 ].xhr.abort ).not.toHaveBeenCalled();
		expect( $field.data( 'pfDependentState' ) ).toBeUndefined();
	} );
} );

describe( 'errors and block ergonomics', () => {
	test( 'summary opens the actual nested block markup and focuses the first invalid field', async () => {
		const $item = block( '', 1 ).addClass( 'minimized' ).appendTo( list() );
		$item.find( '.instanceMain' ).addClass( 'pf-hidden' );
		$item.find( '.instanceRearranger' ).after( '<div class="fieldValuesDisplay">Summary</div>' );
		$item.find( '.inputSpan' ).addErrorMessage( 'pf_blank_error' );
		mw.pageFormsErrors.summarize( context.$form );
		await settle();
		expect( $item.hasClass( 'minimized' ) ).toBe( false );
		expect( $item.find( '.instanceMain' ).hasClass( 'pf-hidden' ) ).toBe( false );
		expect( $item.find( '.fieldValuesDisplay' ) ).toHaveLength( 0 );
		expect( document.activeElement ).toBe( $item.find( 'textarea' )[ 0 ] );
		expect( $( '#pf-error-summary button' ) ).toHaveLength( 1 );
	} );

	test( 'repairing a reported field removes its error but preserves help descriptions', async () => {
		const $wrapper = $( '#uniqueWrapper' ).addClass( 'mandatoryFieldSpan' );
		const $field = $( '#unique' ).addClass( 'mandatoryField' ).attr( 'aria-describedby', 'help-text' ).val( '' );
		$wrapper.addErrorMessage( 'pf_blank_error' );
		expect( $field.attr( 'aria-invalid' ) ).toBe( 'true' );
		$field.val( 'Fixed' ).trigger( 'input' );
		await settle();
		expect( $wrapper.find( '.errorMessage' ) ).toHaveLength( 0 );
		expect( $field.attr( 'aria-describedby' ) ).toBe( 'help-text' );
		expect( $field.attr( 'aria-invalid' ) ).toBeUndefined();
	} );

	test( 'does not validate untouched fields at every keystroke', async () => {
		$( '#unique' ).val( 'typing' ).trigger( 'input' );
		await settle();
		expect( context.requests ).toHaveLength( 0 );
		expect( $( '.errorMessage' ) ).toHaveLength( 0 );
	} );

	test( 'removal is excluded from submission and undo restores the same DOM and values', () => {
		const $list = list();
		const $item = block( 'Unsaved citation' ).appendTo( $list );
		const node = $item[ 0 ];
		mw.pageFormsInstances.update( $list );
		mw.pageFormsInstances.remove( $item );
		expect( context.$form.serialize() ).not.toContain( 'Unsaved' );
		expect( $list.children( '.multipleTemplateInstance' ) ).toHaveLength( 0 );
		$list.find( '.pf-instance-undo button' ).trigger( 'click' );
		expect( $list.children( '.multipleTemplateInstance' )[ 0 ] ).toBe( node );
		expect( $item.find( 'textarea' ).val() ).toBe( 'Unsaved citation' );
	} );

	test( 'a save only finalizes deletions included in that request', () => {
		const $list = list();
		const $first = block( 'First', 1 ).appendTo( $list );
		const $second = block( 'Second', 2 ).appendTo( $list );
		mw.pageFormsInstances.remove( $first );
		const ticket = mw.pageFormsEditState.ticket( context.$form );
		mw.pageFormsInstances.remove( $second );
		mw.pageFormsEditState.saved( context.$form, ticket );
		expect( $list.find( '.pf-instance-undo' ) ).toHaveLength( 1 );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( true );
		$list.find( '.pf-instance-undo button' ).trigger( 'click' );
		expect( $list.find( 'textarea' ).val() ).toBe( 'Second' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
	} );

	test( 'moving a block changes serialization order without renaming or losing data', () => {
		const $list = list();
		const $first = block( 'First', 1 ).appendTo( $list );
		const $second = block( 'Second', 2 ).appendTo( $list );
		mw.pageFormsInstances.update( $list );
		rebaseline();
		$second.find( '.pf-instance-up' ).trigger( 'click' );
		expect( $list.children( '.multipleTemplateInstance' )[ 0 ] ).toBe( $second[ 0 ] );
		expect( $first.find( 'textarea' ).attr( 'name' ) ).toBe( 'Quote[1a][Text]' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( true );
		$second.find( '.pf-instance-down' ).trigger( 'click' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
	} );

	test( 'read-only blocks do not acquire new editing controls', () => {
		const $item = block( 'Read only' ).appendTo( list() );
		$item.find( '.removeButton' ).remove();
		mw.pageFormsInstances.update( context.$form );
		expect( $item.find( '.pf-instance-actions' ) ).toHaveLength( 0 );
	} );

	test( 'collapsed summaries render user text literally', () => {
		const $list = list().addClass( 'minimizeAll' );
		block( '<img src=x onerror=alert(1)>' ).appendTo( $list );
		$list.possiblyMinimizeAllOpenInstances();
		expect( $list.find( '.fieldValuesDisplay img' ) ).toHaveLength( 0 );
		expect( $list.find( '.fieldValuesDisplay' ).text() ).toContain( '<img' );
	} );
} );

describe( 'semantic edit state', () => {
	test( 'no-op input and token changes do not create unsaved edits', () => {
		$( '#body' ).trigger( 'input' );
		$( '[name="wpEditToken"]' ).val( 'rotated' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
	} );

	test( 'checkboxes and changes made during a save remain tracked', () => {
		const $box = $( '<input type="checkbox" name="Item[Check]" value="yes">' ).appendTo( context.$form );
		rebaseline();
		$box.prop( 'checked', true ).trigger( 'change' );
		const ticket = mw.pageFormsEditState.ticket( context.$form );
		$( '#body' ).val( 'Later edit' ).trigger( 'input' );
		mw.pageFormsEditState.saved( context.$form, ticket );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( true );
		$( '#body' ).val( 'Original body' ).trigger( 'input' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
	} );

	test( 'VisualEditor initialization, undo and mode switching are not spurious edits', () => {
		let html = '<p>Original body</p>';
		const $field = $( '#body' ).addClass( 'visualeditor' ).hide();
		const target = { $node: $field, getSurface: () => ( { getHtml: () => html, getModel: () => ( { getDocument: () => ( { on: jest.fn() } ) } ) } ) };
		$field.data( 'veForAllTarget', target );
		mw.hook( 'veForAll.targetCreated' ).fire( target );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
		html = '<p>Changed</p>';
		$field.addClass( 've-for-all-waiting-for-update' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( true );
		html = '<p>Original body</p>';
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
		$field.show().removeClass( 've-for-all-waiting-for-update' );
		expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( false );
	} );

	test( 'successful save resets the close warning without releasing future protection', async () => {
		let options;
		mw.user.options.get = () => true;
		const release = jest.fn();
		mw.confirmCloseWindow = jest.fn( ( settings ) => { options = settings; return { release }; } );
		require( '../../libs/PF_editWarning.js' );
		await settle();
		$( '#body' ).val( 'Saved body' );
		expect( options.test() ).toBe( true );
		mw.pageFormsEditState.saved( context.$form, mw.pageFormsEditState.ticket( context.$form ) );
		expect( options.test() ).toBe( false );
		$( '#body' ).val( 'New edit' );
		expect( options.test() ).toBe( true );
		expect( release ).not.toHaveBeenCalled();
	} );
} );
