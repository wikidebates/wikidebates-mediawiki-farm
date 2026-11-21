/**
 * Defines the applyJStree() function, which turns an HTML "tree" of
 * checkboxes or radiobuttons into a dynamic and collapsible tree of options
 * using the jsTree JS library.
 *
 * @param $
 * @param mw
 * @param pf
 * @author Mathias Lidal
 * @author Yaron Koren
 * @author Priyanshu Varshney
 * @author Amr El-Absy
 */

 ( function($, mw, pf) {

	pf.TreeInput = function(elem) {
		this.element = elem;
		this.id = $(this.element).attr('id');
	};

	const TreeInput_proto = new pf.TreeInput();

	TreeInput_proto.setOptions = function() {
		const data = $(this.element).attr('data');
		this.data = JSON.parse(data);
		const params = $(this.element).attr('params');
		this.params = JSON.parse(params);
		this.delimiter = this.params.delimiter;
		this.multiple = this.params.multiple;
		this.values = [];
		this.cur_value = this.params.cur_value;

		const options = {
			'plugins' :  [ 'checkbox' ],
			'core' : {
				'data' : this.data,
				'multiple': this.multiple,
				'themes' : {
					"icons": false
				}
			},
			'checkbox': {
				'three_state': false,
				'cascade': "none"
			}
		};

		return options;
	};

	TreeInput_proto.check = function( data ) {
		const $input = $(this.element).next('input.PFTree_data');

		if ( this.multiple ) {
			if ( !this.values.includes( data ) ) {
				this.values.push( data );
			}
			const data_string = this.values.join( this.delimiter );
			$input.val( data_string ).attr( 'value', data_string ).trigger( 'change' );
		} else {
			this.values = [ data ];
			$input.val( data ).attr( 'value', data ).trigger( 'change' );
		}
	};

	TreeInput_proto.uncheck = function( data ) {
		const $input = $( this.element ).next( 'input.PFTree_data' );

		const index = this.values.indexOf( data );
		if ( index === -1 ) {
			return;
		}
		this.values.splice( index, 1 );
		const data_string = this.values.join( this.delimiter );
		$input.val( data_string ).attr( 'value', data_string ).trigger( 'change' );
	};

	TreeInput_proto.setCurValue = function() {
		if ( this.cur_value !== null && this.cur_value !== undefined && this.cur_value !== "" ) {
			const $input = $( this.element ).next( 'input.PFTree_data' );

			$input.attr( 'value', this.cur_value );
			this.values = this.cur_value.split( this.delimiter );
		}
	};

	pf.TreeInput.prototype = TreeInput_proto;

} (jQuery, mediaWiki, pf) );

$.fn.extend({
	applyJSTree: function() {
		const tree = new pf.TreeInput(this);
		const options = tree.setOptions();

		const $element = $( this );
		// The saved value may have been restored before a lazy tree is created.
		// Apply that value to the visual widget once jsTree has loaded.
		tree.setCurValue();
		$element.on( 'ready.jstree.pfRestore', ( event, data ) => {
			const widget = data && data.instance ? data.instance : $element.jstree( true );
			if ( !widget || typeof widget.get_json !== 'function' ||
				typeof widget.deselect_all !== 'function' || typeof widget.select_node !== 'function' ) {
				return;
			}
			const nodes = widget.get_json( '#', { flat: true } );
			const chosen = tree.values.map( ( name ) => nodes.filter( ( node ) => node.text === name ) );
			if ( chosen.some( ( matches ) => matches.length !== 1 ) ) {
				// Never choose an arbitrary node when two labels are identical.
				return;
			}
			widget.deselect_all( true );
			chosen.forEach( ( matches ) => widget.select_node( matches[ 0 ].id, true, true ) );
		} );
		$element.jstree( options );

		$element.on( 'select_node.jstree', ( evt, data ) => {
			tree.check( data.node.text );
		} );
		$element.on( 'deselect_node.jstree', ( evt, data ) => {
			tree.uncheck( data.node.text );
		} );
	}
});
