'use strict';

// Execute the production mixin with small OOUI/jQuery boundary doubles.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const root = path.resolve( __dirname, '../..' );
let Mixin;
const context = {
	OO: { initClass: () => {} },
	mw: { flow: { mixinComponent: ( name, mixin ) => { Mixin = mixin; } } },
	$: { each: ( items, callback ) => {
		Object.keys( items ).forEach( ( key ) => callback( key, items[ key ] ) );
	} }
};
vm.runInNewContext( fs.readFileSync( path.join( root,
	'modules/engine/components/board/features/flow-board-loadmore.js' ), 'utf8' ), context );
let callbacks;
const board = Object.create( Mixin.prototype );
board.$board = {};
board.bindNodeHandlers = ( handlers ) => { board.nodeHandlers = handlers; };
board.bindComponentHandlers = ( handlers ) => { callbacks = handlers; };
Mixin.call( board );
let traversals = 0;
function topic( id, attached = true ) {
	return {
		attached,
		data: () => id,
		closest: function ( expectedBoard ) {
			assert.equal( expectedBoard, board.$board );
			traversals++;
			return { length: this.attached ? 1 : 0 };
		}
	};
}
for ( let i = 0; i < 200; i++ ) {
	board.nodeHandlers.loadHandlers.topic.call( board, topic( String( i ) ) );
}
assert.equal( traversals, 0, 'Registration must not rescan earlier topics' );
callbacks.makeContentInteractive.call( board );
assert.equal( traversals, 200, 'Exactly one membership check per topic per batch' );
assert.equal( Object.keys( board.renderedTopics ).length, 200 );

const replacement = topic( '3' );
board.renderedTopics[ '3' ].attached = false;
board.renderedTopics[ '4' ].attached = false;
board.nodeHandlers.loadHandlers.topic.call( board, replacement );
callbacks.makeContentInteractive.call( board );
assert.equal( board.renderedTopics[ '3' ], replacement, 'Keep the replacement, not its detached predecessor' );
assert.equal( board.renderedTopics[ '4' ], undefined, 'Remove detached nodes' );
assert.equal( traversals, 400, 'A second batch gets one cleanup pass' );
board.renderedTopics = {};
callbacks.makeContentInteractive.call( board );
assert.equal( traversals, 400, 'An empty board is safe' );

// Guard the two upstream REL1_43 security backports against accidental reversal.
for ( const [ file, key ] of [
	[ 'modules/flow/ui/widgets/mw.flow.ui.CategoriesWidget.js', 'colon-separator' ],
	[ 'modules/tours/flowOptIn.js', 'flow-guidedtour-optin-welcome-description' ]
] ) {
	const source = fs.readFileSync( path.join( root, file ), 'utf8' );
	assert.ok( source.includes( "mw.message( '" + key + "' ).escaped()" ) );
	assert.ok( !source.includes( "mw.msg( '" + key + "' )" ) );
}
console.log( 'OK: batch cleanup (200 checks instead of 20100), replacements, removal, empty board, escaping guards' );
