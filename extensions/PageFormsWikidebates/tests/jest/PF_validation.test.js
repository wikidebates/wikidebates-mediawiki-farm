'use strict';

const { $, setup, settle, submit } = require( './helpers/form.js' );
let env;

beforeEach( async () => { env = await setup(); } );
afterEach( () => { jest.restoreAllMocks(); } );

function changed() {
	$( '#unique' ).val( 'Nouvelle valeur' );
}

function complete( count, index = 0 ) {
	env.requests[ index ].deferred.resolve( { query: { meta: { count } } } );
}

test( 'blank and unchanged unique fields do not contact the server', async () => {
	expect( await window.validateAll() ).toBe( true );
	$( '#unique' ).val( '  ' );
	expect( await window.validateAll() ).toBe( true );
	expect( env.requests ).toHaveLength( 0 );
} );

test( 'uniqueness is asynchronous and the form waits for the answer', async () => {
	changed();
	const done = jest.fn();
	const validation = window.validateAll().then( done );
	await settle();
	expect( done ).not.toHaveBeenCalled();
	expect( env.requests ).toHaveLength( 1 );
	expect( env.requests[ 0 ].options ).toEqual( expect.objectContaining( {
		data: expect.objectContaining( { action: 'ask', query: '[[Has name::Nouvelle valeur]]|limit=1' } ),
		timeout: 15000
	} ) );
	expect( env.requests[ 0 ].options.async ).not.toBe( false );
	complete( 0 );
	await validation;
	expect( done ).toHaveBeenCalledWith( true );
} );

test.each( [ 1, '1' ] )( 'a real duplicate (%s) is reported as a duplicate', async ( count ) => {
	changed();
	const validation = window.validateAll();
	await settle();
	complete( count );
	expect( await validation ).toBe( false );
	expect( $( '#uniqueWrapper .errorMessage' ).text() ).toBe( 'pf_not_unique_error' );
} );

test.each( [ undefined, null, {}, { error: { code: 'badquery' } }, { query: {} }, { query: { meta: { count: null } } } ] )(
	'a malformed/API-error response is not a duplicate: %j', async ( response ) => {
		changed();
		const validation = window.validateAll();
		await settle();
		env.requests[ 0 ].deferred.resolve( response );
		expect( await validation ).toBe( false );
		expect( $( '#uniqueWrapper .errorMessage' ).text() ).toBe( 'pf-unique-check-error' );
	}
);

test( 'a network failure can be retried without a false duplicate', async () => {
	changed();
	let validation = window.validateAll();
	await settle();
	env.requests[ 0 ].deferred.reject( env.requests[ 0 ].xhr, 'timeout' );
	expect( await validation ).toBe( false );
	expect( $( '#uniqueWrapper .errorMessage' ).text() ).toBe( 'pf-unique-check-error' );
	validation = window.validateAll();
	await settle();
	complete( 0, 1 );
	expect( await validation ).toBe( true );
	expect( $( '#unique' ).hasClass( 'inputError' ) ).toBe( false );
	expect( $( '.errorMessage' ) ).toHaveLength( 0 );
} );

test( 'changing any form value invalidates the pending result', async () => {
	changed();
	const validation = window.validateAll();
	await settle();
	$( '#body' ).val( 'Modified while checking' );
	complete( 1 );
	expect( await validation ).toBe( false );
	expect( $( '#uniqueWrapper .errorMessage' ) ).toHaveLength( 0 );
	expect( $( '#form_error_header' ).text() ).toBe( 'pf-form-changed-during-validation' );
} );

test( 'a new validation cancels the old request and ignores its result', async () => {
	changed();
	const old = window.validateAll();
	await settle();
	const next = window.validateAll();
	await settle();
	expect( env.requests[ 0 ].xhr.abort ).toHaveBeenCalledTimes( 1 );
	complete( 0, 1 );
	expect( await old ).toBe( false );
	expect( await next ).toBe( true );
	expect( $( '.errorMessage' ) ).toHaveLength( 0 );
} );

test( 'local errors avoid uniqueness requests', async () => {
	changed();
	$( '#uniqueWrapper' ).addClass( 'mandatoryFieldSpan' );
	$( '#unique' ).addClass( 'mandatoryField' ).val( '' );
	expect( await window.validateAll() ).toBe( false );
	expect( env.requests ).toHaveLength( 0 );
	expect( $( '.errorMessage' ).text() ).toBe( 'pf_blank_error' );
} );

test.each( [ 'hiddenByPF', 'multipleTemplateStarter' ] )( 'unique inputs in %s are not checked', async ( className ) => {
	changed();
	$( '#uniqueWrapper' ).wrap( $( '<div>' ).addClass( className ) );
	expect( await window.validateAll() ).toBe( true );
	expect( env.requests ).toHaveLength( 0 );
} );

test( 'registered asynchronous validators are awaited and can reject submission', async () => {
	const deferred = $.Deferred();
	env.$form.data( 'PageForms', { validationFunctions: [ {
		input: 'body', valfunction: () => deferred.promise(), parameters: {}
	} ] } );
	const validation = window.validateAll();
	await settle();
	deferred.resolve( false );
	expect( await validation ).toBe( false );
	expect( $( '#form_error_header' ) ).toHaveLength( 1 );
} );

test( 'a rejected validator gives a recoverable validation error', async () => {
	env.$form.data( 'PageForms', { validationFunctions: [ {
		input: 'body', valfunction: () => Promise.reject( new Error( 'Unavailable' ) ), parameters: {}
	} ] } );
	expect( await window.validateAll() ).toBe( false );
	expect( $( '#form_error_header' ).text() ).toBe( 'pf-form-validation-error' );
} );

test( 'Cargo sends encoded parameters and quotes apostrophes inside the query', async () => {
	$( '[name="unique_unique_property"]' ).remove();
	$( '<input>' ).attr( { type: 'hidden', name: 'unique_unique_cargo_table', value: 'Items' } ).appendTo( env.$form );
	$( '<input>' ).attr( { type: 'hidden', name: 'unique_unique_cargo_field', value: 'Name' } ).appendTo( env.$form );
	$( '#unique' ).val( "L'ete & A+B" );
	const validation = window.validateAll();
	await settle();
	expect( env.requests[ 0 ].options.data ).toEqual( {
		action: 'cargoquery', format: 'json', tables: 'Items', fields: 'Name',
		where: "Name = 'L''ete & A+B'", limit: 1
	} );
	env.requests[ 0 ].deferred.resolve( { cargoquery: [] } );
	expect( await validation ).toBe( true );
} );

test.each( [ '#save', '#wpPreview', '#diff' ] )( 'native %s waits, preserves the submitter and only sends once', async ( button ) => {
	changed();
	const event = submit( button );
	expect( event.defaultPrevented ).toBe( true );
	expect( $( button ).prop( 'disabled' ) ).toBe( true );
	submit( button );
	await settle();
	expect( env.requests ).toHaveLength( 1 );
	expect( env.requestSubmit ).not.toHaveBeenCalled();
	complete( 0 );
	await settle();
	expect( env.requestSubmit ).toHaveBeenCalledTimes( 1 );
	expect( env.submitted[ 0 ].submitter ).toBe( $( button )[ 0 ] );
	expect( $( button ).prop( 'disabled' ) ).toBe( false );
	expect( env.$form.data( 'submitted' ) ).toBe( true );
	submit( button );
	await settle();
	expect( env.requestSubmit ).toHaveBeenCalledTimes( 1 );
} );

test( 'a failed native validation unlocks the form and a retry can succeed', async () => {
	changed();
	submit();
	await settle();
	env.requests[ 0 ].deferred.reject( env.requests[ 0 ].xhr, 'error' );
	await settle();
	expect( $( '#save' ).prop( 'disabled' ) ).toBe( false );
	expect( env.$form.data( 'pfSubmissionPending' ) ).toBeUndefined();
	expect( env.$form.data( 'submitted' ) ).not.toBe( true );
	submit();
	await settle();
	complete( 0, 1 );
	await settle();
	expect( env.submitted ).toHaveLength( 1 );
} );

test( 'jQuery synthetic submission also waits for validation', async () => {
	changed();
	env.$form.trigger( 'submit' );
	await settle();
	expect( env.requestSubmit ).not.toHaveBeenCalled();
	complete( 0 );
	await settle();
	expect( env.submitted ).toHaveLength( 1 );
} );

test( 'a third-party submit veto does not leave the form permanently submitted', async () => {
	const veto = ( event ) => event.preventDefault();
	env.$form[ 0 ].addEventListener( 'submit', veto );
	submit();
	await settle();
	expect( env.$form.data( 'submitted' ) ).toBe( false );
	expect( env.$form.data( 'pfValidatedSubmit' ) ).toBeUndefined();
	env.$form[ 0 ].removeEventListener( 'submit', veto );
	submit();
	await settle();
	expect( env.submitted ).toHaveLength( 1 );
} );

test( 'native fallback preserves the action without bypassing submit listeners', async () => {
	HTMLFormElement.prototype.requestSubmit = undefined;
	const saved = [];
	jest.spyOn( HTMLFormElement.prototype, 'submit' ).mockImplementation( function () {
		saved.push( new URLSearchParams( $( this ).serialize() ) );
	} );
	submit( '#diff' );
	await settle();
	expect( saved ).toHaveLength( 1 );
	expect( saved[ 0 ].get( 'wpDiff' ) ).toBe( 'Diff' );
	expect( env.$form.find( 'input[name="wpDiff"]' ) ).toHaveLength( 0 );
	expect( env.hooks[ 'pf.formSubmit' ].fire ).toHaveBeenCalledTimes( 1 );
} );

test( 'a veto preserves the edit warning; only an accepted replay fires the submit hook', async () => {
	const onSubmit = jest.fn();
	mediaWiki.hook( 'pf.formSubmit' ).add( onSubmit );
	const veto = ( event ) => event.preventDefault();
	env.$form.on( 'submit.testVeto', veto );
	submit();
	await settle();
	expect( onSubmit ).not.toHaveBeenCalled();
	env.$form.off( '.testVeto' );
	submit();
	await settle();
	expect( onSubmit ).toHaveBeenCalledTimes( 1 );
} );

test( 'existing disabled controls are not enabled when validation fails', async () => {
	$( '#diff' ).prop( 'disabled', true );
	changed();
	submit();
	await settle();
	complete( 1 );
	await settle();
	expect( $( '#diff' ).prop( 'disabled' ) ).toBe( true );
	expect( $( '#save' ).prop( 'disabled' ) ).toBe( false );
} );

test( 'the browser back-forward cache unlocks a previously submitted form', async () => {
	submit();
	await settle();
	expect( env.$form.data( 'submitted' ) ).toBe( true );
	const event = new Event( 'pageshow' );
	Object.defineProperty( event, 'persisted', { value: true } );
	window.dispatchEvent( event );
	expect( env.$form.data( 'submitted' ) ).toBe( false );
	submit();
	await settle();
	expect( env.submitted ).toHaveLength( 2 );
} );

test( 'parallel unique fields all settle before accepting the form', async () => {
	changed();
	const $other = $( '#uniqueWrapper' ).clone().attr( 'id', 'otherWrapper' ).appendTo( env.$form );
	$other.find( '.uniqueField' ).attr( { id: 'other', name: 'Item[Other]' } ).val( 'Other value' );
	$other.find( 'input[type="hidden"]' ).attr( 'name', 'other_unique_property' );
	const done = jest.fn();
	const validation = window.validateAll().then( done );
	await settle();
	expect( env.requests ).toHaveLength( 2 );
	complete( 0 );
	await settle();
	expect( done ).not.toHaveBeenCalled();
	complete( 0, 1 );
	await validation;
	expect( done ).toHaveBeenCalledWith( true );
} );
