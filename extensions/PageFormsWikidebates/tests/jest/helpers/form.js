'use strict';

const $ = require( '../../../../../resources/lib/jquery/jquery.js' );

async function settle() {
	// jQuery Deferred.then() adds a macrotask around its callbacks.
	for ( let i = 0; i < 5; i++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
}

function markup() {
	return '<div id="unrelated" class="errorbox">Unrelated notice</div>' +
		'<form id="pfForm">' +
		'<span class="inputSpan uniqueFieldSpan" id="uniqueWrapper">' +
		'<input id="unique" class="uniqueField" name="Item[Name]" value="Original">' +
		'<input type="hidden" name="unique_unique_property" value="Has name">' +
		'</span>' +
		'<textarea id="body" name="Item[Body]">Original body</textarea>' +
		'<input id="wpSummary" name="wpSummary" value="A &amp; B + C">' +
		'<input name="wpEditToken" value="test-token" type="hidden">' +
		'<div class="editButtons">' +
		'<span class="oo-ui-buttonElement oo-ui-widget-enabled"><button id="save" type="submit" name="wpSave" value="Save">Save</button></span>' +
		'<span class="oo-ui-buttonElement oo-ui-widget-enabled"><button id="wpPreview" type="submit" name="wpPreview" value="Preview">Preview</button></span>' +
		'<span class="oo-ui-buttonElement oo-ui-widget-enabled"><button id="diff" type="submit" name="wpDiff" value="Diff">Diff</button></span>' +
		'<span class="pf-save_and_continue oo-ui-buttonElement oo-ui-widget-disabled"><button type="button" name="wpSaveAndContinue" disabled>Save and continue</button></span>' +
		'</div></form>';
}

async function setup( config = {} ) {
	jest.resetModules();
	$( window ).off( '.pfSubmission' );
	document.body.innerHTML = '';
	global.jQuery = $;
	global.$ = $;
	global.pageforms = {};
	global.pf = pageforms;
	window.scroll = jest.fn();
	const values = Object.assign( {
		wgAction: 'formedit',
		wgPageName: 'Original page',
		wgNamespaceIds: { '': 0, main: 0, item: 3000 },
		wgPageFormsDependentFields: [],
		wgPageFormsAutocompleteValues: {},
		wgPageFormsShowOnSelect: {},
		'ext.pf.initFunctionData': {},
		'ext.pf.validationFunctionData': {}
	}, config );
	const hooks = {};
	global.mediaWiki = global.mw = {
		config: { get: ( name ) => values[ name ] },
		msg: ( key, ...args ) => [ key ].concat( args.filter( ( value ) => value !== undefined ) ).join( ':' ),
		message: ( key ) => ( { text: () => key, escaped: () => key } ),
		util: { wikiScript: () => '/api.php', getParamValue: () => null },
		loader: { using: () => Promise.resolve() },
		user: { options: { get: () => false } },
		hook: ( name ) => {
			if ( !hooks[ name ] ) {
				const handlers = [];
				hooks[ name ] = {
					add: ( handler ) => { handlers.push( handler ); },
					fire: jest.fn( ( ...args ) => handlers.forEach( ( handler ) => handler( ...args ) ) )
				};
			}
			return hooks[ name ];
		}
	};
	const requests = [];
	$.ajax = jest.fn( ( options ) => {
		const deferred = $.Deferred();
		const xhr = deferred.promise();
		xhr.abort = jest.fn( () => deferred.reject( xhr, 'abort' ) );
		deferred.done( options.success ).fail( options.error );
		requests.push( { options, deferred, xhr } );
		return xhr;
	} );
	require( '../../../libs/PF_formSubmission.js' );
	require( '../../../libs/PageForms.js' );
	// Let the normal DOM-ready initializer see an empty document. The tests bind
	// real submission handlers explicitly without initializing unrelated widgets.
	await settle();
	document.body.innerHTML = markup();
	const $form = $( '#pfForm' );
	mediaWiki.pageFormsSubmission.bind( $form );
	const submitted = [];
	const requestSubmit = jest.spyOn( HTMLFormElement.prototype, 'requestSubmit' )
		.mockImplementation( function ( submitter ) {
			const event = submitEvent( submitter );
			if ( this.dispatchEvent( event ) ) {
				submitted.push( { submitter, data: $( this ).serialize() } );
			}
		} );
	return { $, $form, requests, hooks, values, submitted, requestSubmit };
}

function submitEvent( submitter ) {
	const event = new Event( 'submit', { bubbles: true, cancelable: true } );
	Object.defineProperty( event, 'submitter', { value: submitter } );
	return event;
}

function submit( selector = '#save' ) {
	const button = $( selector )[ 0 ];
	const event = submitEvent( button );
	button.form.dispatchEvent( event );
	return event;
}

async function loadSave() {
	require( '../../../libs/PF_submit.js' );
	await settle();
	$( '#body' ).trigger( 'input' );
	return $( '.pf-save_and_continue button' );
}

module.exports = { $, setup, settle, submit, submitEvent, loadSave };
