'use strict';

const $ = require( '../../../../resources/lib/jquery/jquery.js' );

jest.mock( 'ext.pageforms.sortable', () => ( { create: jest.fn() } ), { virtual: true } );

let element;
let widget;
let search;
let query;

beforeEach( () => {
	jest.resetModules();
	document.body.innerHTML = '';
	global.jQuery = $;
	global.mediaWiki = {
		config: {
			get: ( name ) => ( {
				wgPageFormsDependentFields: [],
				wgPageFormsAutocompleteValues: {},
				wgPageFormsAutocompleteOnAllChars: true
			} )[ name ]
		},
		msg: ( key ) => key
	};
	global.pageforms = {};
	delete $.fn.select2;
	require( '../../libs/foreign/select2/select2.js' );
	require( '../../libs/ext.pf.select2.base.js' );
	require( '../../libs/ext.pf.select2.tokens.js' );
	element = $( '<span class="inputSpan"><select id="tokens" name="Form[Tokens][]" multiple autocompletesettings="Accents,list,;"></select></span>' )
		.appendTo( document.body ).find( 'select' );
	new pageforms.select2.tokens().apply( element );
	widget = element.data( 'select2' );
	search = widget.selection.$search;
	query = jest.spyOn( widget.dataAdapter, 'query' );
	search[ 0 ].focus();
} );

afterEach( () => {
	element.select2( 'destroy' );
	document.body.innerHTML = '';
} );

function keyboard( type, key, code, isComposing = false ) {
	const event = new KeyboardEvent( type, {
		key, keyCode: code, which: code, isComposing,
		bubbles: true, cancelable: true
	} );
	search[ 0 ].dispatchEvent( event );
	return event;
}

function input( value, isComposing = false ) {
	search.val( value );
	search[ 0 ].dispatchEvent( new InputEvent( 'input', {
		bubbles: true, isComposing
	} ) );
}

test.each( [ 'ê', 'ö', 'É' ] )( 'a dead key waits for the completed character %s', ( value ) => {
	keyboard( 'keydown', 'Dead', 219 );
	keyboard( 'keyup', 'Dead', 219 );
	expect( query ).not.toHaveBeenCalled();
	expect( widget.isOpen() ).toBe( false );
	expect( document.activeElement ).toBe( search[ 0 ] );

	input( value );
	expect( query ).toHaveBeenLastCalledWith( expect.objectContaining( { term: value } ), expect.any( Function ) );
	expect( search.val() ).toBe( value );
	expect( widget.isOpen() ).toBe( true );
} );

test( 'composition leaves intermediate text and separators untouched until committed', () => {
	search.trigger( 'compositionstart' );
	input( 'intermédiaire;', true );
	keyboard( 'keyup', 'Process', 229, true );
	expect( query ).not.toHaveBeenCalled();
	expect( element.val() ).toEqual( [] );
	expect( search.val() ).toBe( 'intermédiaire;' );
	expect( widget.isOpen() ).toBe( false );

	search.val( 'ö' ).trigger( 'compositionend' );
	expect( query ).toHaveBeenLastCalledWith( expect.objectContaining( { term: 'ö' } ), expect.any( Function ) );
	expect( search.val() ).toBe( 'ö' );
} );

test( 'native composing input is ignored even without a compositionstart event', () => {
	input( '^', true );
	expect( query ).not.toHaveBeenCalled();
	input( 'ê' );
	expect( query ).toHaveBeenLastCalledWith( expect.objectContaining( { term: 'ê' } ), expect.any( Function ) );
} );

test.each( [ [ 'Enter', 13 ], [ 'Backspace', 8 ], [ 'Tab', 9 ] ] )( '%s during composition cannot select or remove a token', ( key, code ) => {
	element.append( new Option( 'Déjà choisi', 'Déjà choisi', true, true ) ).trigger( 'change' );
	input( 'Suggestion' );
	search.val( '' ).trigger( 'compositionstart' );
	const event = keyboard( 'keydown', key, code, true );
	keyboard( 'keyup', key, code, true );
	expect( event.defaultPrevented ).toBe( false );
	expect( element.val() ).toEqual( [ 'Déjà choisi' ] );
} );

test( 'legacy IME key code 229 does not start a search', () => {
	keyboard( 'keyup', 'Unidentified', 229 );
	expect( query ).not.toHaveBeenCalled();
} );

test( 'ordinary separators and Enter still select accented tokens', () => {
	input( 'forêt;Noël;' );
	expect( element.val() ).toEqual( [ 'forêt', 'Noël' ] );
	input( 'hôtel' );
	const event = keyboard( 'keydown', 'Enter', 13 );
	expect( event.defaultPrevented ).toBe( true );
	expect( element.val() ).toEqual( [ 'forêt', 'Noël', 'hôtel' ] );
} );

function settleMutations() {
	return new Promise( ( resolve ) => {
		setTimeout( resolve, 0 );
	} );
}

test.each( [ 'forê', '' ] )( 'deleting to "%s" does not refocus the search during a pending accent', async ( remainingText ) => {
	input( 'forêt' );
	await settleMutations();
	const focus = jest.fn();
	search[ 0 ].addEventListener( 'focus', focus );

	keyboard( 'keydown', 'Backspace', 8 );
	input( remainingText );
	keyboard( 'keyup', 'Backspace', 8 );
	keyboard( 'keydown', 'Dead', 219 );
	keyboard( 'keyup', 'Dead', 219 );
	// Removing the previous, unselected tag queues a native MutationObserver update.
	await settleMutations();
	expect( focus ).not.toHaveBeenCalled();
	expect( document.activeElement ).toBe( search[ 0 ] );
	input( remainingText + 'ö' );
	expect( search.val() ).toBe( remainingText + 'ö' );
} );

test( 'an asynchronous selection update keeps the composing input attached and focused', async () => {
	input( 'ancien' );
	await settleMutations();
	const focus = jest.fn();
	search[ 0 ].addEventListener( 'focus', focus );
	search.trigger( 'compositionstart' );
	input( 'nouveau', true );
	element.append( new Option( 'Déjà choisi', 'Déjà choisi', true, true ) ).trigger( 'change' );
	await settleMutations();

	expect( focus ).not.toHaveBeenCalled();
	expect( document.activeElement ).toBe( search[ 0 ] );
	expect( search.val() ).toBe( 'nouveau' );
	expect( element.val() ).toEqual( [ 'Déjà choisi' ] );
	expect( widget.$selection.find( '.select2-selection__choice' ).last().next()[ 0 ] )
		.toBe( widget.selection.$searchContainer[ 0 ] );
	search.val( 'nouvelö' ).trigger( 'compositionend' );
	expect( query ).toHaveBeenLastCalledWith( expect.objectContaining( { term: 'nouvelö' } ), expect.any( Function ) );
} );

test( 'Backspace restores a token for editing, then accepts accented replacement text', async () => {
	input( 'forêt;' );
	await settleMutations();
	const focus = jest.fn();
	search[ 0 ].addEventListener( 'focus', focus );
	keyboard( 'keydown', 'Backspace', 8 );
	keyboard( 'keyup', 'Backspace', 8 );
	// Closing the dropdown may focus synchronously; no later redraw may do so.
	focus.mockClear();
	await settleMutations();
	expect( element.val() ).toEqual( [] );
	expect( search.val() ).toBe( 'forêt' );
	expect( focus ).not.toHaveBeenCalled();
	input( '' );
	keyboard( 'keydown', 'Dead', 219 );
	keyboard( 'keyup', 'Dead', 219 );
	await settleMutations();
	input( 'ö;' );
	expect( element.val() ).toEqual( [ 'ö' ] );
} );

describe( 'token request lifecycle', () => {
	let requests;
	let token;
	beforeEach( () => {
		mediaWiki.util = { wikiScript: () => '/api.php' };
		requests = [];
		$.ajax = jest.fn( ( options ) => {
			const deferred = $.Deferred();
			const xhr = deferred.promise();
			xhr.abort = jest.fn( () => deferred.reject( xhr, 'abort' ) );
			requests.push( { options, deferred, xhr } );
			return xhr;
		} );
		token = element.data( 'pfTokensInstance' );
	} );
	function request( term, success = jest.fn(), failure = jest.fn() ) {
		return token.getAjaxOpts().transport( { data: { action: 'pfautocomplete', category: 'A', substr: term } }, success, failure );
	}
	test( 'a cache hit cancels an older HTTP request and ignores late responses', () => {
		request( 'cached' );
		requests[ 0 ].deferred.resolve( { pfautocomplete: [] } );
		const oldSuccess = jest.fn();
		request( 'old', oldSuccess );
		requests[ 1 ].xhr.abort.mockImplementation( () => {} );
		request( 'cached' );
		requests[ 1 ].deferred.resolve( { pfautocomplete: [ { title: 'Old' } ] } );
		expect( requests[ 1 ].xhr.abort ).toHaveBeenCalledTimes( 1 );
		expect( oldSuccess ).not.toHaveBeenCalled();
	} );

	test( 'an API error is not cached as an empty successful result', () => {
		const failure = jest.fn();
		request( 'retry', jest.fn(), failure );
		requests[ 0 ].deferred.resolve( { error: { code: 'category-limit' } } );
		expect( failure ).toHaveBeenCalledTimes( 1 );
		request( 'retry' );
		expect( requests ).toHaveLength( 2 );
	} );

	test( 'destruction cancels the transport and discards late callbacks', () => {
		const success = jest.fn();
		request( 'pending', success );
		requests[ 0 ].xhr.abort.mockImplementation( () => {} );
		token.destroy( element );
		requests[ 0 ].deferred.resolve( { pfautocomplete: [] } );
		expect( success ).not.toHaveBeenCalled();
		// Restore the fixture for the common teardown.
		new pageforms.select2.tokens().apply( element );
	} );

	test( 'dependent tokens consume preloaded data without synchronous AJAX', () => {
		const config = mediaWiki.config.get;
		mediaWiki.config.get = ( key ) => key === 'wgPageFormsDependentFields' ? [ [ 'Parent', 'Form[Tokens][]' ] ] : config( key );
		element.data( 'autocompleteitems', [ { title: 'Canonical', displaytitle: 'Label' } ] );
		expect( token.getData( 'Local,list,;' ) ).toEqual( [ { id: 'Canonical', text: 'Label', title: 'Canonical' } ] );
		expect( $.ajax ).not.toHaveBeenCalled();
	} );

	test( 'a dependent refresh preserves the actual composing search input', () => {
		const config = mediaWiki.config.get;
		mediaWiki.config.get = ( key ) => key === 'wgPageFormsDependentFields' ? [ [ 'Parent', 'Form[Tokens][]' ] ] : config( key );
		element.data( 'autocompletevalues', [ 'New' ] );
		const inputNode = search[ 0 ];
		search.trigger( 'compositionstart' );
		input( 'e', true );
		token.refresh( element );
		expect( element.data( 'select2' ) ).toBe( widget );
		expect( element.data( 'select2' ).selection.$search[ 0 ] ).toBe( inputNode );
		expect( document.activeElement ).toBe( inputNode );
		expect( search.val() ).toBe( 'e' );
	} );

	test( 'truncated suggestions do not erase a selected existing-only token', () => {
		const config = mediaWiki.config.get;
		mediaWiki.config.get = ( key ) => key === 'wgPageFormsDependentFields' ? [ [ 'Parent', 'Form[Tokens][]' ] ] : config( key );
		element.append( new Option( 'Kept', 'Kept', true, true ) ).attr( 'existingvaluesonly', 'true' );
		element.data( 'autocompletevalues', [ 'Other' ] ).data( 'autocompleteMore', true );
		token.refresh( element );
		expect( element.val() ).toEqual( [ 'Kept' ] );
	} );
} );
