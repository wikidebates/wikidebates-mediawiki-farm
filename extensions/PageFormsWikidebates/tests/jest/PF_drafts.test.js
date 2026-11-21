'use strict';

const { $, setup, settle } = require( './helpers/form' );
let context;
let controller;
const base = { enabled: true, wiki: 'frwikidebates', user: 42, form: 'Form:Argument', formRevision: 5, target: 'Argument', targetRevision: 10 };
const identity = JSON.stringify( [ base.wiki, base.user ] );
const prefix = 'pageforms-draft-v1:' + encodeURIComponent( identity ) + ':';

beforeEach( async () => {
	localStorage.clear();
	context = await setup( { wgPageFormsDraftContext: Object.assign( {}, base ) } );
	mw.Api = jest.fn( () => ( { get: jest.fn().mockResolvedValue( { query: { pages: [
		{ title: base.form, lastrevid: 5 }, { title: base.target, lastrevid: 10 }
	] } } ) } ) );
	jest.spyOn( window, 'confirm' ).mockReturnValue( true );
} );
afterEach( () => {
	jest.restoreAllMocks();
	delete $.fn.applyVisualEditor;
	delete $.fn.getVEInstances;
} );
function bind( enable = false ) {
	if ( enable ) { localStorage.setItem( prefix + 'enabled', 'true' ); }
	mw.pageFormsDrafts.bind( context.$form );
	controller = context.$form.data( 'pfDraftController' );
	return controller;
}
function record( options = {} ) {
	return Object.assign( { version: 1, identity, context: JSON.stringify( [ base.form, base.target ] ),
		time: Date.now(), baseRevision: 10, formRevision: 5, data: mw.pageFormsDrafts.capture( context.$form ) }, options );
}
function draftRecords() {
	return Object.keys( localStorage ).filter( ( key ) => key.startsWith( prefix ) && key !== prefix + 'enabled' );
}
function multiple() {
	return $( '<div class="multipleTemplateWrapper"><div class="multipleTemplateList" maximumInstances="3">' +
		'<div class="multipleTemplateInstance"><div class="multipleTemplateInstanceTable"><div class="instanceMain">' +
		'<textarea id="input_11" name="Quote[0a][Text]" origname="Quote[Text]">First</textarea>' +
		'</div><a class="removeButton"></a></div></div>' +
		'<div class="multipleTemplateStarter pf-hidden"><div class="multipleTemplateInstanceTable"><div class="instanceMain">' +
		'<textarea id="input_12" name="Quote[num][Text]" origname="Quote[Text]"></textarea>' +
		'</div><a class="removeButton"></a></div></div></div>' +
		'<button type="button" class="multipleTemplateAdder">Add</button></div>' ).appendTo( context.$form );
}

test( 'is opt-in and never writes form data before enabling', async () => {
	bind();
	$( '#body' ).val( 'Unsaved text' );
	await controller.save();
	expect( draftRecords() ).toHaveLength( 0 );
	expect( $( '.pf-drafts input' ).prop( 'checked' ) ).toBe( false );
} );

test( 'stores structured text but excludes hidden fields, secrets, files and starter values', async () => {
	context.$form.append( '<input name="password" type="password" value="secret"><input name="secretField" value="private"><input type="file" name="upload"><input type="hidden" name="returnto" value="sensitive"><div class="multipleTemplateStarter"><input name="Starter[Text]" value="template"></div>' );
	bind( true );
	$( '#body' ).val( 'A <script> string' );
	await controller.save();
	const text = localStorage.getItem( controller.key );
	expect( text ).toContain( 'A <script> string' );
	for ( const forbidden of [ 'test-token', 'wpEditToken', 'secretField', 'password', 'returnto', 'Starter[Text]', 'upload' ] ) {
		expect( text ).not.toContain( forbidden );
	}
	expect( context.requests ).toHaveLength( 0 );
} );

test( 'an unchanged form does not create a recovery copy', async () => {
	bind( true );
	await controller.save();
	expect( draftRecords() ).toHaveLength( 0 );
} );

test( 'retains the previous complete draft on a storage quota exception', async () => {
	bind( true );
	$( '#body' ).val( 'First local version' );
	await controller.save();
	const previous = localStorage.getItem( controller.key );
	jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => { throw new Error( 'Quota' ); } );
	$( '#body' ).val( 'Later version' );
	await controller.save();
	expect( localStorage.getItem( controller.key ) ).toBe( previous );
	expect( $( '.pf-drafts [role="status"]' ).text() ).toBe( 'pf-draft-storage-error' );
} );

test( 'unavailable browser storage does not break form editing', () => {
	jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => { throw new Error( 'Denied' ); } );
	expect( () => bind() ).not.toThrow();
	expect( $( '.pf-drafts' ).prop( 'open' ) ).toBe( true );
	$( '#body' ).val( 'Still editable' );
	expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( true );
} );

test( 'does not offer records from another wiki, user, page or form', () => {
	localStorage.setItem( prefix + 'otherPage', JSON.stringify( record( { context: JSON.stringify( [ base.form, 'Other page' ] ) } ) ) );
	localStorage.setItem( prefix + 'otherUser', JSON.stringify( record( { identity: JSON.stringify( [ base.wiki, 99 ] ) } ) ) );
	localStorage.setItem( 'pageforms-draft-v1:other:record', JSON.stringify( record() ) );
	bind();
	expect( $( '.pf-draft-recovery button' ) ).toHaveLength( 0 );
} );

test( 'expires old drafts and safely ignores malformed records', () => {
	localStorage.setItem( prefix + 'old', JSON.stringify( record( { time: Date.now() - 8 * 86400000 } ) ) );
	localStorage.setItem( prefix + 'malformed', '{' );
	bind();
	expect( localStorage.getItem( prefix + 'old' ) ).toBeNull();
	expect( localStorage.getItem( prefix + 'malformed' ) ).toBeNull();
	expect( $( '.pf-draft-recovery button' ) ).toHaveLength( 0 );
} );

test( 'finding a recovery copy never automatically overwrites current values or the saved copy', async () => {
	const saved = record();
	saved.data.fields.find( ( field ) => field.name === 'Item[Body]' ).value = 'Recovered';
	localStorage.setItem( prefix + 'olderTab', JSON.stringify( saved ) );
	bind( true );
	$( '#body' ).val( 'Current unsaved' );
	await controller.save();
	expect( $( '#body' ).val() ).toBe( 'Current unsaved' );
	expect( draftRecords() ).toEqual( [ prefix + 'olderTab' ] );
} );

test( 'restoration checks revisions, asks consent and only restores permitted values', async () => {
	const saved = record();
	saved.data.fields.find( ( field ) => field.name === 'Item[Body]' ).value = '<script>restored text</script>';
	localStorage.setItem( prefix + 'olderTab', JSON.stringify( saved ) );
	bind( true );
	await controller.recover( saved );
	expect( window.confirm ).toHaveBeenCalledWith( 'pf-draft-confirm' );
	expect( $( '#body' ).val() ).toBe( '<script>restored text</script>' );
	expect( $( '[name="wpEditToken"]' ).val() ).toBe( 'test-token' );
	expect( context.$form.find( 'script' ) ).toHaveLength( 0 );
	expect( context.$form.data( 'pfSubmissionPending' ) ).toBeUndefined();
	expect( mw.pageFormsEditState.isDirty( context.$form ) ).toBe( true );
} );

test( 'cancelling restoration preserves both current entries and the recovery copy', async () => {
	const saved = record();
	localStorage.setItem( prefix + 'olderTab', JSON.stringify( saved ) );
	bind();
	$( '#body' ).val( 'Do not replace' );
	window.confirm.mockReturnValue( false );
	await controller.recover( saved );
	expect( $( '#body' ).val() ).toBe( 'Do not replace' );
	expect( localStorage.getItem( prefix + 'olderTab' ) ).not.toBeNull();
} );

test( 'requires a reload when the wiki changed after the current form was opened', async () => {
	const saved = record();
	bind();
	mw.Api = jest.fn( () => ( { get: () => Promise.resolve( { query: { pages: [ { title: base.form, lastrevid: 5 }, { title: base.target, lastrevid: 11 } ] } } ) } ) );
	await controller.recover( saved );
	expect( window.confirm ).not.toHaveBeenCalled();
	expect( $( '.pf-drafts [role="status"]' ).text() ).toBe( 'pf-draft-reload' );
} );

test( 'an old draft needs a distinct explicit conflict confirmation', async () => {
	const saved = record( { baseRevision: 8 } );
	bind();
	await controller.recover( saved );
	expect( window.confirm ).toHaveBeenCalledWith( 'pf-draft-confirm-old' );
} );

test( 'revision network failure cannot mutate any current field', async () => {
	bind();
	mw.Api = jest.fn( () => ( { get: () => Promise.reject( new Error( 'Offline' ) ) } ) );
	const before = context.$form.serialize();
	await controller.recover( record() );
	expect( context.$form.serialize() ).toBe( before );
	expect( $( '.pf-drafts [role="status"]' ).text() ).toBe( 'pf-draft-restore-error' );
} );

test( 'schema preflight prevents partial restoration when a later field is incompatible', () => {
	const data = mw.pageFormsDrafts.capture( context.$form );
	data.fields[ 0 ].value = 'Should not be written';
	data.fields[ 1 ].name = 'No longer the same field';
	expect( () => mw.pageFormsDrafts.restore( context.$form, data ) ).toThrow();
	expect( $( '#unique' ).val() ).toBe( 'Original' );
} );

test( 'unsupported enum choices are rejected rather than silently cleared', () => {
	$( '<select name="Item[Kind]"><option value="one">One</option></select>' ).appendTo( context.$form );
	const data = mw.pageFormsDrafts.capture( context.$form );
	data.fields[ data.fields.length - 1 ].value = 'gone';
	expect( () => mw.pageFormsDrafts.validate( context.$form, data ) ).toThrow();
} );

test( 'recreates a removed instance using the server starter and keeps order/text', () => {
	const $group = multiple();
	const data = mw.pageFormsDrafts.capture( context.$form );
	data.groups[ 0 ].instances.push( JSON.parse( JSON.stringify( data.groups[ 0 ].instances[ 0 ] ) ) );
	data.groups[ 0 ].instances[ 1 ].fields[ 0 ].value = 'Second restored block';
	mw.pageFormsDrafts.restore( context.$form, data );
	const $items = $group.find( '.multipleTemplateInstance' );
	expect( $items ).toHaveLength( 2 );
	expect( $items.eq( 0 ).find( 'textarea' ).val() ).toBe( 'First' );
	expect( $items.eq( 1 ).find( 'textarea' ).val() ).toBe( 'Second restored block' );
	expect( $items.eq( 1 ).find( 'textarea' ).attr( 'name' ) ).toMatch( /^Quote\[\d+b\]\[Text\]$/ );
} );

test( 'draft instance counts obey current server limits before changing anything', () => {
	multiple();
	const data = mw.pageFormsDrafts.capture( context.$form );
	data.groups[ 0 ].instances = Array( 4 ).fill( data.groups[ 0 ].instances[ 0 ] );
	expect( () => mw.pageFormsDrafts.restore( context.$form, data ) ).toThrow();
	expect( context.$form.find( '.multipleTemplateInstance' ) ).toHaveLength( 1 );
} );

test( 'waits for VisualEditor conversion before storing wikitext, never editor HTML', async () => {
	bind( true );
	let finish;
	mw.pageFormsActivateVEFields = jest.fn( () => new Promise( ( resolve ) => { finish = resolve; } ) );
	$( '#body' ).val( 'Before conversion' );
	const saving = controller.save();
	await settle();
	expect( localStorage.getItem( controller.key ) ).toBeNull();
	$( '#body' ).val( "'''Wikitext'''" );
	finish();
	await saving;
	expect( localStorage.getItem( controller.key ) ).toContain( "'''Wikitext'''" );
} );

test( 'a rejected VisualEditor conversion keeps the last local copy', async () => {
	bind( true );
	$( '#body' ).val( 'Prior copy' );
	await controller.save();
	const previous = localStorage.getItem( controller.key );
	mw.pageFormsActivateVEFields = jest.fn().mockRejectedValue( new Error( 'Conversion failed' ) );
	$( '#body' ).val( 'New contents' );
	await controller.save();
	expect( localStorage.getItem( controller.key ) ).toBe( previous );
} );

test( 'acknowledged saves remove only this tab draft, never another tab copy', async () => {
	bind( true );
	$( '#body' ).val( 'Saved' );
	await controller.save();
	localStorage.setItem( prefix + 'otherTab', JSON.stringify( record() ) );
	mw.pageFormsEditState.saved( context.$form, mw.pageFormsEditState.ticket( context.$form ) );
	expect( localStorage.getItem( controller.key ) ).toBeNull();
	expect( localStorage.getItem( prefix + 'otherTab' ) ).not.toBeNull();
} );

test( 'disabling local drafts removes this tab copy and stops future writes', async () => {
	bind( true );
	$( '#body' ).val( 'Private draft' );
	await controller.save();
	$( '.pf-drafts input' ).prop( 'checked', false ).trigger( 'change' );
	await controller.save();
	expect( localStorage.getItem( controller.key ) ).toBeNull();
} );

test( 'disabling the preference in another tab stops this tab too', async () => {
	bind( true );
	window.dispatchEvent( new StorageEvent( 'storage', { key: prefix + 'enabled', newValue: 'false' } ) );
	$( '#body' ).val( 'Do not store' );
	await controller.save();
	expect( localStorage.getItem( controller.key ) ).toBeNull();
	expect( $( '.pf-drafts input' ).prop( 'checked' ) ).toBe( false );
} );

test( 'the feature can be disabled by site configuration', () => {
	context.values.wgPageFormsDraftContext.enabled = false;
	bind();
	expect( controller ).toBeUndefined();
	expect( $( '.pf-drafts' ) ).toHaveLength( 0 );
} );


function nested() {
	const $outer = multiple();
	const $instance = $outer.find( '.multipleTemplateList > .multipleTemplateInstance' ).first();
	const $inner = $( '<div class="multipleTemplateWrapper"><div class="multipleTemplateList" maximumInstances="3">' +
		'<div class="multipleTemplateInstance"><div class="instanceMain">' +
		'<textarea name="Citation[0a][Detail]" origname="Citation[Detail]">Nested original</textarea>' +
		'</div><a class="removeButton"></a></div>' +
		'<div class="multipleTemplateStarter pf-hidden"><div class="instanceMain">' +
		'<textarea name="Citation[num][Detail]" origname="Citation[Detail]"></textarea>' +
		'</div><a class="removeButton"></a></div></div>' +
		'<button class="multipleTemplateAdder" type="button">Add</button></div>' );
	$instance.children( '.multipleTemplateInstanceTable' ).find( '.instanceMain' ).first().append( $inner );
	return $inner;
}

test( 'nested repeating instances can be restored without saved HTML', () => {
	const $inner = nested();
	const saved = mw.pageFormsDrafts.capture( context.$form );
	const nestedInstances = saved.groups[ 0 ].instances[ 0 ].groups[ 0 ].instances;
	expect( nestedInstances ).toHaveLength( 1 );
	nestedInstances[ 0 ].fields[ 0 ].value = 'Restored nested value';
	nestedInstances.push( JSON.parse( JSON.stringify( nestedInstances[ 0 ] ) ) );
	nestedInstances[ 1 ].fields[ 0 ].value = 'Restored second nested value';
	mw.pageFormsDrafts.restore( context.$form, saved );
	const $items = $inner.children( '.multipleTemplateList' ).children( '.multipleTemplateInstance' );
	expect( $items ).toHaveLength( 2 );
	expect( $items.eq( 0 ).find( 'textarea' ).val() ).toBe( 'Restored nested value' );
	expect( $items.eq( 1 ).find( 'textarea' ).val() ).toBe( 'Restored second nested value' );
	expect( $items.eq( 1 ).find( 'textarea' ).attr( 'name' ) ).toMatch( /^Citation\[\d+[ab]\]\[Detail\]$/ );
} );

test( 'invalid nested schema rejects the entire restore without partial changes', () => {
	nested();
	const saved = mw.pageFormsDrafts.capture( context.$form );
	saved.fields[ 0 ].value = 'Do not write this';
	saved.groups[ 0 ].instances[ 0 ].groups[ 0 ].instances[ 0 ].fields[ 0 ].name = 'Different';
	const before = context.$form.serialize();
	expect( () => mw.pageFormsDrafts.restore( context.$form, saved ) ).toThrow();
	expect( context.$form.serialize() ).toBe( before );
} );

test( 'rating draft restoration preserves both input and visual widget state', () => {
	const $widget = $( '<div class="pfRatingWrapper"><div class="pfRating" data-curvalue="2" ' +
		'data-numstars="5" data-starwidth="24px"></div>' +
		'<input type="hidden" name="Item[Rating]" value="2"></div>' ).appendTo( context.$form );
	const saved = mw.pageFormsDrafts.capture( context.$form );
	const rating = saved.fields.find( ( field ) => field.name === 'Item[Rating]' );
	expect( rating.value ).toBe( '2' );
	rating.value = '4';
	mw.pageFormsDrafts.restore( context.$form, saved );
	expect( $widget.find( 'input' ).val() ).toBe( '4' );
	expect( $widget.find( '.pfRating' ).attr( 'data-curvalue' ) ).toBe( '4' );
} );

test( 'invalid rating values fail closed before changing any field', () => {
	$( '<div class="pfRatingWrapper"><div class="pfRating" data-curvalue="2" ' +
		'data-numstars="5"></div><input type="hidden" name="Item[Rating]" value="2"></div>' )
		.appendTo( context.$form );
	const saved = mw.pageFormsDrafts.capture( context.$form );
	saved.fields[ 0 ].value = 'Must remain unchanged';
	saved.fields.find( ( field ) => field.name === 'Item[Rating]' ).value = '4.3';
	expect( () => mw.pageFormsDrafts.restore( context.$form, saved ) ).toThrow();
	expect( $( '#unique' ).val() ).toBe( 'Original' );
} );
