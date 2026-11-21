'use strict';

const { $, setup, settle, submit, loadSave } = require( './helpers/form.js' );
let env;
let $button;
const success = { status: 200, target: 'Generated page', form: { title: 'Item' } };

beforeEach( async () => {
	env = await setup();
	$button = await loadSave();
} );
afterEach( () => { jest.restoreAllMocks(); } );

async function click() {
	$button.trigger( 'click' );
	await settle();
}

function unlocked() {
	expect( env.$form.data( 'pfSubmissionPending' ) ).toBeUndefined();
	expect( $( '#save' ).prop( 'disabled' ) ).toBe( false );
	expect( $button.prop( 'disabled' ) ).toBe( false );
	expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-wait' ) ).toBe( false );
}

test( 'saves twice using result.target rather than the stale page name', async () => {
	await click();
	const first = new URLSearchParams( env.requests[ 0 ].options.data.query );
	expect( first.get( 'target' ) ).toBe( 'Original page' );
	expect( first.get( 'wpEditToken' ) ).toBe( 'test-token' );
	expect( first.get( 'wpSummary' ) ).toContain( 'A & B + C' );
	expect( first.has( 'wpSave' ) ).toBe( true );
	expect( $( '#wpSummary' ).val() ).toBe( 'A & B + C' );
	env.requests[ 0 ].deferred.resolve( success );
	await settle();
	expect( $( '[name="target"]' ).val() ).toBe( success.target );
	expect( $( '[name="form"]' ).val() ).toBe( success.form.title );
	expect( $button.prop( 'disabled' ) ).toBe( true );
	$( '#body' ).val( 'Next version' ).trigger( 'input' );
	await click();
	const second = new URLSearchParams( env.requests[ 1 ].options.data.query );
	expect( second.getAll( 'target' ) ).toEqual( [ success.target ] );
	expect( second.getAll( 'form' ) ).toEqual( [ success.form.title ] );
	expect( second.get( 'Item[Body]' ) ).toBe( 'Next version' );
	env.requests[ 1 ].deferred.resolve( success );
	await settle();
	expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-ok' ) ).toBe( true );
	expect( env.requestSubmit ).not.toHaveBeenCalled();
} );

test( 'save waits for uniqueness and prevents all competing submissions', async () => {
	$( '#unique' ).val( 'New name' );
	await click();
	expect( env.requests[ 0 ].options.data.action ).toBe( 'ask' );
	$( '.pf-save_and_continue' ).trigger( 'click' );
	submit();
	await settle();
	expect( env.requests ).toHaveLength( 1 );
	env.requests[ 0 ].deferred.resolve( { query: { meta: { count: 0 } } } );
	await settle();
	expect( env.requests ).toHaveLength( 2 );
	expect( env.requests[ 1 ].options.data.action ).toBe( 'pfautoedit' );
	$( '#body' ).val( 'Typed while saving' ).trigger( 'input' );
	expect( $button.prop( 'disabled' ) ).toBe( true );
	$( '.pf-save_and_continue' ).trigger( 'click' );
	await settle();
	expect( env.requests ).toHaveLength( 2 );
	env.requests[ 1 ].deferred.resolve( success );
	await settle();
	unlocked();
} );

test( 'a successfully saved unique value is not rechecked against itself', async () => {
	$( '#unique' ).val( 'New name' );
	await click();
	env.requests[ 0 ].deferred.resolve( { query: { meta: { count: 0 } } } );
	await settle();
	env.requests[ 1 ].deferred.resolve( success );
	await settle();
	$( '#body' ).val( 'Another edit' ).trigger( 'input' );
	await click();
	expect( env.requests ).toHaveLength( 3 );
	expect( env.requests[ 2 ].options.data.action ).toBe( 'pfautoedit' );
	env.requests[ 2 ].deferred.resolve( success );
	await settle();
} );

test( 'edits typed during the save are kept and not marked saved', async () => {
	await click();
	$( '#body' ).val( 'Not sent yet' ).trigger( 'input' );
	env.requests[ 0 ].deferred.resolve( success );
	await settle();
	expect( $( '#body' ).val() ).toBe( 'Not sent yet' );
	expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-changed' ) ).toBe( true );
	expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-ok' ) ).toBe( false );
	unlocked();
} );

test.each( [ '', '<html>Gateway error</html>', '{broken', '{}', '{"errors":null}' ] )(
	'a nonstandard HTTP error is recoverable: %s', async ( responseText ) => {
		await click();
		Object.assign( env.requests[ 0 ].xhr, { responseText } );
		env.requests[ 0 ].deferred.reject( env.requests[ 0 ].xhr, 'error' );
		await settle();
		unlocked();
		expect( $( '#form_error_header' ).text() ).toBe( 'pf-save-and-continue-error' );
		expect( $( '#unrelated' ) ).toHaveLength( 1 );
		expect( $( '#body' ).val() ).toBe( 'Original body' );
		await click();
		env.requests[ 1 ].deferred.resolve( success );
		await settle();
		expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-ok' ) ).toBe( true );
	}
);

test.each( [
	null, {}, { status: 400 }, { status: 200, target: 'T' },
	{ ...success, target: '' }, { ...success, error: { code: 'badtoken' } },
	{ ...success, errors: [ { level: 0, message: 'Denied' } ] }
] )( 'a JSON/HTTP success is not enough to acknowledge a save: %j', async ( response ) => {
	await click();
	env.requests[ 0 ].deferred.resolve( response );
	await settle();
	unlocked();
	expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-ok' ) ).toBe( false );
	expect( $( '#form_error_header' ).text() ).not.toBe( '' );
	expect( $( '[name="target"]' ) ).toHaveLength( 0 );
} );

test.each( [ 'responseJSON', 'responseText' ] )( 'error details from %s are displayed as text', async ( source ) => {
	const message = '<img src=x onerror="window.pfInjected=1"> & denied';
	await click();
	const response = { errors: [ { level: 0, message }, null ] };
	env.requests[ 0 ].xhr[ source ] = source === 'responseJSON' ? response : JSON.stringify( response );
	env.requests[ 0 ].deferred.reject( env.requests[ 0 ].xhr );
	await settle();
	expect( $( '#form_error_header' ).text() ).toBe( message );
	expect( $( '#form_error_header img' ) ).toHaveLength( 0 );
	unlocked();
} );

test( 'VisualEditor conversion completes before validation and serialization', async () => {
	const deferred = $.Deferred();
	$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', {
		getSurface: () => ( { getHtml: () => '<p>New editor text</p>' } ),
		updateContent: jest.fn( () => deferred.promise() )
	} );
	await click();
	expect( env.requests ).toHaveLength( 0 );
	$( '#body' ).val( 'New editor text' );
	deferred.resolve();
	await settle();
	expect( new URLSearchParams( env.requests[ 0 ].options.data.query ).get( 'Item[Body]' ) ).toBe( 'New editor text' );
	env.requests[ 0 ].deferred.resolve( success );
	await settle();
} );

test( 'VisualEditor conversion failure leaves the data and buttons usable', async () => {
	$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', {
		updateContent: () => Promise.reject( new Error( 'No connection' ) )
	} );
	await click();
	expect( env.requests ).toHaveLength( 0 );
	expect( $( '#form_error_header' ).text() ).toBe( 'pf-form-prepare-error' );
	unlocked();
} );

test( 'a live editor change while checking uniqueness prevents the save', async () => {
	let html = '<p>Original</p>';
	$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', {
		getSurface: () => ( { getHtml: () => html } ),
		updateContent: () => Promise.resolve()
	} );
	$( '#unique' ).val( 'New name' );
	await click();
	html = '<p>New text, not converted yet</p>';
	env.requests[ 0 ].deferred.resolve( { query: { meta: { count: 0 } } } );
	await settle();
	expect( env.requests ).toHaveLength( 1 );
	expect( $( '#form_error_header' ).text() ).toBe( 'pf-form-changed-during-validation' );
	unlocked();
} );

test( 'editing during editor conversion does not submit a stale textarea', async () => {
	const conversion = $.Deferred();
	let html = '<p>Before</p>';
	$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', {
		getSurface: () => ( { getHtml: () => html } ),
		updateContent: () => conversion.promise()
	} );
	await click();
	html = '<p>After</p>';
	conversion.resolve();
	await settle();
	expect( env.requests ).toHaveLength( 0 );
	expect( $( '#body' ).hasClass( 've-for-all-waiting-for-update' ) ).toBe( true );
	unlocked();
} );

test( 'a pending blur conversion is followed by an update of the current editor', async () => {
	const blur = $.Deferred();
	const latest = $.Deferred();
	const target = {
		pendingUpdatePromise: blur.promise(),
		getSurface: () => ( { getHtml: () => '<p>Latest</p>' } ),
		updateContent: jest.fn().mockReturnValueOnce( blur.promise() ).mockReturnValueOnce( latest.promise() )
	};
	$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', target );
	await click();
	target.pendingUpdatePromise = null;
	blur.resolve();
	await settle();
	expect( target.updateContent ).toHaveBeenCalledTimes( 2 );
	expect( env.requests ).toHaveLength( 0 );
	$( '#body' ).val( 'Latest' );
	latest.resolve();
	await settle();
	expect( new URLSearchParams( env.requests[ 0 ].options.data.query ).get( 'Item[Body]' ) ).toBe( 'Latest' );
	env.requests[ 0 ].deferred.resolve( success );
	await settle();
} );

test( 'a live editor edit during saving is not marked saved even without a change event', async () => {
	let html = '<p>Before</p>';
	$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', {
		getSurface: () => ( { getHtml: () => html } ),
		updateContent: () => Promise.resolve()
	} );
	await click();
	html = '<p>After</p>';
	env.requests[ 0 ].deferred.resolve( success );
	await settle();
	expect( $( '.pf-save_and_continue' ).hasClass( 'pf-save_and_continue-ok' ) ).toBe( false );
	unlocked();
} );

test( 'Special:FormEdit with a generated title saves the same page the second time', async () => {
	env.values.wgAction = 'view';
	env.values.wgCanonicalSpecialPageName = 'FormEdit';
	env.values.wgPageName = 'Special:FormEdit/Item';
	await click();
	const first = new URLSearchParams( env.requests[ 0 ].options.data.query );
	expect( first.get( 'form' ) ).toBe( 'Item' );
	expect( first.has( 'target' ) ).toBe( false );
	env.requests[ 0 ].deferred.resolve( success );
	await settle();
	$( '#body' ).trigger( 'input' );
	await click();
	expect( new URLSearchParams( env.requests[ 1 ].options.data.query ).get( 'target' ) ).toBe( success.target );
	env.requests[ 1 ].deferred.resolve( success );
	await settle();
} );

test.each( [ 'editor', 'validator' ] )( 'a stalled %s times out and late completion cannot save', async ( type ) => {
	let resolve;
	const pending = new Promise( ( done ) => { resolve = done; } );
	if ( type === 'editor' ) {
		$( '#body' ).addClass( 'visualeditor' ).data( 'veForAllTarget', { updateContent: () => pending } );
	} else {
		env.$form.data( 'PageForms', { validationFunctions: [ {
			input: 'body', valfunction: () => pending, parameters: {}
		} ] } );
	}
	jest.useFakeTimers();
	try {
		$button.trigger( 'click' );
		await jest.advanceTimersByTimeAsync( 30001 );
		unlocked();
		expect( $( '#form_error_header' ).text() ).toBe( 'pf-form-prepare-error' );
		resolve( true );
		await jest.advanceTimersByTimeAsync( 10 );
		expect( env.requests ).toHaveLength( 0 );
	} finally {
		jest.useRealTimers();
	}
} );
