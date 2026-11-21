'use strict';

const { $, setup, settle } = require( './helpers/form' );
let $tree;
let $hidden;
let widget;
let options;

beforeEach( async () => {
	await setup();
	$tree = $( '<div class="pfTreeInput"></div>' ).attr( {
		data: JSON.stringify( [ { text: 'Alpha' }, { text: 'Beta' } ] ),
		params: JSON.stringify( { multiple: false, delimiter: ',', cur_value: 'Beta' } )
	} ).appendTo( '#pfForm' );
	$hidden = $( '<input class="PFTree_data" type="hidden" name="Item[Tree]" value="Beta">' )
		.insertAfter( $tree );
	widget = {
		get_json: jest.fn( () => [ { id: 'a', text: 'Alpha' }, { id: 'b', text: 'Beta' } ] ),
		deselect_all: jest.fn(),
		select_node: jest.fn()
	};
	options = null;
	$.fn.jstree = function ( opts ) {
		if ( opts === true ) {
			return widget;
		}
		options = opts;
		return this;
	};
	require( '../../libs/PF_tree.js' );
} );
afterEach( () => {
	delete $.fn.jstree;
	delete $.fn.applyJSTree;
} );

test( 'lazy tree ready event aligns visible nodes with the restored hidden value', () => {
	$tree.applyJSTree();
	expect( options.core.multiple ).toBe( false );
	$tree.trigger( 'ready.jstree', [ { instance: widget } ] );
	expect( widget.deselect_all ).toHaveBeenCalledWith( true );
	expect( widget.select_node ).toHaveBeenCalledWith( 'b', true, true );
	expect( $hidden.val() ).toBe( 'Beta' );
} );

test( 'ambiguous selection does not choose a random node', () => {
	widget.get_json.mockReturnValue( [ { id: 'x', text: 'Beta' }, { id: 'y', text: 'Beta' } ] );
	$tree.applyJSTree();
	$tree.trigger( 'ready.jstree', [ { instance: widget } ] );
	expect( widget.deselect_all ).not.toHaveBeenCalled();
	expect( widget.select_node ).not.toHaveBeenCalled();
	expect( $hidden.val() ).toBe( 'Beta' );
} );
