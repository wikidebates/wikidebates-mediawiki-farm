'use strict';

// Exercise the production click handler with a minimal Flow DOM/MediaWiki boundary.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const root = path.resolve( __dirname, '../..' );
let Mixin;
let activeBoard;
const requests = [];
const pending = [];

class FakeNode {
	constructor( role ) {
		this.role = role;
		this.length = 1;
		this.attributes = {};
		this.fields = {};
		this.childrenBySelector = {};
		this.calls = [];
	}
	closest( selector ) {
		assert.equal( selector, '.flow-light-topic' );
		return this.preview;
	}
	data( key, value ) {
		if ( arguments.length === 2 ) {
			this.fields[ key ] = value;
			return this;
		}
		return this.fields[ key ];
	}
	removeData( key ) {
		delete this.fields[ key ];
		return this;
	}
	find( selector ) {
		this.calls.push( selector );
		return this.childrenBySelector[ selector ] || ( this.childrenBySelector[ selector ] = new FakeNode( selector ) );
	}
	attr( key, value ) {
		this.attributes[ key ] = value;
		return this;
	}
	removeAttr( key ) {
		delete this.attributes[ key ];
		this.calls.push( 'removeAttr:' + key );
		return this;
	}
	text( message ) {
		this.label = message;
		return this;
	}
	children( selector ) {
		this.calls.push( 'children:' + selector );
		return this.child;
	}
	replaceWith( newNode ) {
		this.replacement = newNode;
		return this;
	}
	first() {
		return this;
	}
	trigger( event ) {
		this.calls.push( event );
		return this;
	}
}

function fakeGet( params ) {
	requests.push( params );
	const item = { success: null, failure: null, finish: null };
	pending.push( item );
	return {
		then( success ) {
			item.success = success;
			return this;
		},
		fail( fn ) {
			item.failure = fn;
			return this;
		},
		always( fn ) {
			item.finish = fn;
			return this;
		}
	};
}
function complete( item, data, error = false ) {
	try {
		if ( error ) {
			throw new Error( 'Network failure' );
		}
		item.success( data );
	} catch ( ex ) {
		item.failure( ex );
	} finally {
		item.finish();
	}
}
const context = {
	OO: { initClass: () => {} },
	$: ( value ) => value,
	mw: {
		flow: {
			mixinComponent: ( name, mixin ) => { Mixin = mixin; },
			getPrototypeMethod: () => () => activeBoard
		},
		Api: function () { this.get = fakeGet; },
		Title: function ( title ) { this.getPrefixedDb = () => 'Topic:' + title; },
		msg: ( key ) => key
	}
};
vm.runInNewContext( fs.readFileSync( path.join( root,
	'modules/engine/components/board/features/flow-board-loadmore.js' ), 'utf8' ), context );
const handler = Mixin.UI.events.interactiveHandlers.loadLightTopic;
assert.equal( typeof handler, 'function', 'Handler is registered by the real Flow mixin' );
const rendered = new FakeNode( 'rendered' );
let emitCalls = [];
activeBoard = {
	renderedTopics: {},
	constructor: {
		static: {
			TemplateEngine: {
				processTemplateGetFragment( name, data ) {
					assert.equal( name, 'flow_topiclist_loop.partial' );
					assert.equal( data.roots.length, 1 );
					const fragment = new FakeNode( 'fragment' );
					fragment.child = rendered;
					return fragment;
				}
			}
		}
	},
	emitWithReturn( name, element ) {
		emitCalls.push( name );
		assert.equal( element, rendered );
	},
	emit( name ) {
		emitCalls.push( name );
	}
};
function previewLink( id ) {
	const link = new FakeNode( 'link' );
	link.preview = new FakeNode( 'preview' );
	link.preview.fields[ 'flow-id' ] = id;
	return link;
}
const id = '0000000000000002';
const link = previewLink( id );
let defaultPrevented = 0;
const event = { type: 'click', preventDefault: () => { defaultPrevented++; } };
handler.call( link, event );
handler.call( link, event );
assert.equal( requests.length, 1, 'Repeated clicks do not cause duplicate requests' );
assert.equal( defaultPrevented, 2, 'Clicks intercepted only while interactive handler exists' );
assert.equal( requests[ 0 ].submodule, 'view-topic' );
assert.equal( requests[ 0 ].page, 'Topic:' + id );
assert.equal( link.preview.fields[ 'flow-light-loading' ], true );
const topic = { roots: [ id ], posts: { [ id ]: [ 'rev' ] }, revisions: { rev: {} } };
complete( pending[ 0 ], { flow: { 'view-topic': { result: { topic: topic } } } } );
assert.equal( link.preview.replacement, rendered, 'Full thread replaces only the preview' );
assert.equal( activeBoard.renderedTopics[ id ], rendered );
assert.deepEqual( emitCalls, [ 'makeContentInteractive', 'loadmore' ] );
assert.equal( link.preview.fields[ 'flow-light-loading' ], undefined );
assert.equal( rendered.find( '.flow-topic-title' ).calls.includes( 'focus' ), true );

const failed = previewLink( '0000000000000003' );
handler.call( failed, event );
complete( pending[ 1 ], {}, true );
assert.equal( failed.preview.find( '.flow-light-topic-error' ).label, 'flow-light-topic-failed' );
assert.equal( failed.preview.find( '.flow-light-topic-open' ).calls.includes(
	'removeAttr:data-flow-interactive-handler' ), true, 'Native topic links restored after failure' );
assert.equal( failed.preview.find( '.flow-light-topic-open' ).fields[ 'flow-interactive-handler' ], undefined,
	'jQuery cached handlers must also be cleared after an API failure' );
assert.equal( failed.preview.fields[ 'flow-light-loading' ], undefined );

const invalid = previewLink( '0000000000000004' );
handler.call( invalid, event );
complete( pending[ 2 ], { flow: { 'view-topic': { result: { topic: { roots: [] } } } } } );
assert.equal( invalid.preview.find( '.flow-light-topic-open' ).calls.includes(
	'removeAttr:data-flow-interactive-handler' ), true, 'Malformed responses fall back to topic links' );

const extension = JSON.parse( fs.readFileSync( path.join( root, 'extension.json' ), 'utf8' ) );
assert.equal( extension.config.FlowLightTopicList.value, false, 'Experiment disabled by default' );
assert.ok( extension.ResourceModules[ 'ext.flow.templating' ].templates.includes(
	'handlebars/flow_topic_preview.partial.handlebars' ) );
const html = fs.readFileSync( path.join( root, 'handlebars/flow_topic_preview.partial.handlebars' ), 'utf8' );
assert.ok( html.includes( 'href="{{links.topic.url}}"' ), 'Native links work without JavaScript' );
const init = fs.readFileSync( path.join( root, 'modules/flow-initialize.js' ), 'utf8' );
assert.ok( init.includes( 'location.replace( fullUri.toString() )' ), 'Direct anchors reopen the full board' );
console.log( 'OK: topic preview hydration, duplicate clicks, permissions-independent native fallback, invalid response, config, deep links' );
