'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const root = path.resolve( __dirname, '../..' );

// jQuery Deferred semantics used by System: concurrent requests share a pending
// promise and .always() runs on both success and failure.
function jqPromise( native ) {
	const wrapper = {
		then: ( success, failure ) => jqPromise( native.then( success, failure ) ),
		always: ( callback ) => {
			native.then( callback, callback );
			return wrapper;
		}
	};
	return wrapper;
}
function deferred() {
	let resolve, reject;
	const promise = jqPromise( new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} ) );
	return { promise, resolve, reject };
}
const title = { getPrefixedDb: () => 'Talk:Board' };
const context = {
	moment: { utc: ( value ) => ( {
		format: () => new Date( value ).toISOString().slice( 0, 19 ).replace( /[-T:]/g, '' )
	} ) },
	OO: {
		EventEmitter: function () {},
		initClass: () => {},
		mixinClass: () => {}
	},
	$: {
		Deferred: () => ( { resolve: ( value ) => ( {
			promise: () => jqPromise( Promise.resolve( value ) )
		} ) } ),
		isPlainObject: ( value ) => value !== null && typeof value === 'object'
	},
	mw: {
		Title: { newFromText: () => title },
		config: { get: () => 'Talk:Board' },
		flow: { dm: {
			Board: function () {
				this.items = [];
				this.connect = () => {};
				this.getPageTitle = () => title;
				this.getSortOrder = () => 'newest';
				this.getOffsetId = () => 'last-topic-id';
				this.getOffset = () => null;
				this.getItemById = () => null;
				this.addItems = ( items ) => this.items.push( ...items );
			},
			APIHandler: function () {},
			Topic: { static: { extractTopicsFromAPI: ( data ) => data.roots } },
			Post: { static: { createTopicReplyTree: () => [] } }
		} } }
};
for ( const name of [
	'modules/flow/dm/mw.flow.dm.System.js',
	'modules/flow/dm/api/mw.flow.dm.APIHandler.js'
] ) {
	vm.runInNewContext( fs.readFileSync( path.join( root, name ), 'utf8' ), context );
}

( async () => {
	const System = context.mw.flow.dm.System;
	const system = new System( { pageTitle: title, tocPostsLimit: 50 } );
	const requests = [];
	system.api = {
		getTopicList: ( sortBy, options ) => {
			const d = deferred();
			requests.push( { sortBy, options, d } );
			return d.promise;
		}
	};
	const first = system.fetchMoreTopics();
	assert.equal( system.fetchMoreTopics(), first, 'Concurrent calls share one pending request' );
	assert.equal( requests.length, 1 );
	assert.equal( requests[ 0 ].options.limit, 50, 'Uses the configured ToC request size' );
	requests[ 0 ].d.reject( new Error( 'Temporary network error' ) );
	await assert.rejects( Promise.resolve( first ), /Temporary network error/ );
	assert.equal( system.fetchPromise, null, 'A failure does not poison the next attempt' );

	const retry = system.fetchMoreTopics();
	assert.equal( requests.length, 2, 'The next attempt really sends another request' );
	requests[ 1 ].d.resolve( { roots: Array.from( { length: 50 }, ( _, i ) => String( i ) ) } );
	assert.equal( await retry, true, 'A full page means a further page may exist' );
	assert.equal( system.moreTopicsExistInApi, true );
	assert.equal( system.fetchPromise, null, 'Successful requests also release the pending promise' );

	const last = system.fetchMoreTopics();
	requests[ 2 ].d.resolve( { roots: [ 'last' ] } );
	assert.equal( await last, false, 'A short page ends further requests' );
	assert.equal( await system.fetchMoreTopics(), false, 'No extra request once the last page is known' );
	assert.equal( requests.length, 3 );

	// With updated ordering the request must carry both the time and topic ID.
	const lastUpdated = Date.UTC( 2026, 10, 21, 13, 58, 0 );
	const lastTopic = '0000000000000002';
	system.board.getSortOrder = () => 'updated';
	system.board.getOffset = () => lastUpdated;
	system.board.getOffsetId = () => lastTopic;
	system.moreTopicsExistInApi = true;
	const updatedRequest = system.fetchMoreTopics();
	assert.equal( requests[ 3 ].sortBy, 'updated' );
	assert.equal( requests[ 3 ].options.offset, lastUpdated );
	assert.equal( requests[ 3 ].options.offsetId, lastTopic );
	requests[ 3 ].d.resolve( { roots: [] } );
	assert.equal( await updatedRequest, false );

	system.moreTopicsExistInApi = true;
	system.emit = () => {};
	system.populateBoardTopicsFromJson( { roots: [] } );
	assert.equal( system.moreTopicsExistInApi, true,
		'A full-rendered-topic callback must not change ToC pagination state' );
	system.populateBoardTopicsFromJson( { roots: [] }, undefined, true );
	assert.equal( system.moreTopicsExistInApi, false,
		'Only an actual short ToC response may mark the ToC complete' );

	const handler = Object.create( context.mw.flow.dm.APIHandler.prototype );
	handler.page = 'Talk:Board';
	let apiParams;
	handler.get = ( method, params ) => {
		assert.equal( method, 'view-topiclist' );
		apiParams = params;
		return jqPromise( Promise.resolve( { topiclist: { roots: [] } } ) );
	};
	await handler.getTopicList( 'newest', { toconly: true, limit: 17 } );
	assert.equal( apiParams.vtllimit, 17, 'APIHandler passes through the requested limit' );
	await handler.getTopicList( 'newest', { toconly: true } );
	assert.equal( apiParams.vtllimit, 50, 'The old default limit stays unchanged' );
	await handler.getTopicList( 'updated', { offset: lastUpdated, offsetId: lastTopic } );
	assert.equal( apiParams.vtloffset, '20261121135800|' + lastTopic,
		'Updated-order pagination sends a deterministic two-part cursor' );
	await handler.getTopicList( 'updated', { offset: lastUpdated } );
	assert.equal( apiParams.vtloffset, '20261121135800',
		'Existing offset-only callers preserve timestamp-only pagination' );
	await handler.getTopicList( 'updated', {} );
	assert.equal( apiParams.vtloffset, undefined,
		'First-page requests have no invalid timestamp cursor' );

	console.log( 'OK: Flow ToC pagination, concurrency, retry, API request size and composite cursor' );
} )().catch( ( error ) => {
	console.error( error );
	process.exitCode = 1;
} );
