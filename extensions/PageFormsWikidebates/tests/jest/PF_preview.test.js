'use strict';

const { $, setup, settle, submit, loadSave } = require( './helpers/form.js' );
let env;

beforeEach( async () => {
	env = await setup();
	$( '<div id="wikiPreview"></div>' ).prependTo( document.body );
	require( '../../libs/PF_preview.js' );
	await settle();
} );
afterEach( () => { jest.restoreAllMocks(); } );

test( 'AJAX preview waits for uniqueness and never submits the form natively', async () => {
	$( '#unique' ).val( 'New name' );
	$( '#wpPreview' ).trigger( 'click' );
	await settle();
	expect( env.requests ).toHaveLength( 1 );
	expect( env.requests[ 0 ].options.data.action ).toBe( 'ask' );
	submit();
	env.requests[ 0 ].deferred.resolve( { query: { meta: { count: 0 } } } );
	await settle();
	expect( env.requests ).toHaveLength( 2 );
	expect( env.requests[ 1 ].options.data.action ).toBe( 'pfautoedit' );
	const query = new URLSearchParams( env.requests[ 1 ].options.data.query );
	expect( query.get( 'wpPreview' ) ).toBe( 'Preview' );
	expect( query.has( 'wpSave' ) ).toBe( false );
	expect( env.requestSubmit ).not.toHaveBeenCalled();
	env.requests[ 1 ].deferred.resolve( { result: '<html><body><div id="wikiPreview">Preview text</div></body></html>' } );
	await settle();
	expect( $( '#wikiPreview iframe' ).contents().find( '#wikiPreview' ).text() ).toBe( 'Preview text' );
	expect( env.$form.data( 'pfSubmissionPending' ) ).toBeUndefined();
	expect( $( '#save' ).prop( 'disabled' ) ).toBe( false );
} );

test( 'a duplicate blocks AJAX preview and allows another attempt', async () => {
	$( '#unique' ).val( 'New name' );
	$( '#wpPreview' ).trigger( 'click' );
	await settle();
	env.requests[ 0 ].deferred.resolve( { query: { meta: { count: 1 } } } );
	await settle();
	expect( env.requests ).toHaveLength( 1 );
	expect( $( '#wpPreview' ).prop( 'disabled' ) ).toBe( false );
	expect( $( '.errorMessage' ).text() ).toBe( 'pf_not_unique_error' );
} );

test.each( [ 'network', 'malformed' ] )( 'preview %s failure restores buttons without losing fields', async ( type ) => {
	$( '#wpPreview' ).trigger( 'click' );
	await settle();
	if ( type === 'network' ) {
		env.requests[ 0 ].deferred.reject( env.requests[ 0 ].xhr, 'error' );
	} else {
		env.requests[ 0 ].deferred.resolve( { error: { info: 'Bad response' } } );
	}
	await settle();
	expect( $( '#form_error_header' ).text() ).toBe( 'pf-preview-error' );
	expect( $( '#body' ).val() ).toBe( 'Original body' );
	expect( $( '#wpPreview' ).prop( 'disabled' ) ).toBe( false );
	expect( $( '#unrelated' ) ).toHaveLength( 1 );
	expect( env.$form.data( 'pfSubmissionPending' ) ).toBeUndefined();
} );

test( 'save-and-continue cannot send while a preview is pending', async () => {
	const $save = await loadSave();
	$( '#wpPreview' ).trigger( 'click' );
	await settle();
	$save.closest( '.pf-save_and_continue' ).trigger( 'click' );
	await settle();
	expect( env.requests ).toHaveLength( 1 );
	env.requests[ 0 ].deferred.reject( env.requests[ 0 ].xhr );
	await settle();
	expect( $save.prop( 'disabled' ) ).toBe( false );
} );
