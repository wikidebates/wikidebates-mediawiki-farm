/* Read-only browser form-performance report: paste into the browser console.
 * Compare the same form, device, network and browser, before and after the change.
 */
( function () {
	'use strict';
	const navigation = performance.getEntriesByType( 'navigation' )[ 0 ];
	const resources = performance.getEntriesByType( 'resource' );
	const pageForms = resources.filter( ( resource ) =>
		/pageforms|veforall|visualeditor|pfautocomplete/i.test( resource.name )
	);
	const sizes = pageForms.reduce( ( sum, resource ) => sum + ( resource.transferSize || 0 ), 0 );
	const marks = performance.getEntriesByType( 'mark' ).filter( ( item ) => /^pf-|^veforall-/.test( item.name ) )
		.map( ( item ) => ( { name: item.name, ms: Math.round( item.startTime ) } ) );
	const report = {
		time: new Date().toISOString(),
		title: document.title,
		navigation: navigation ? {
			domInteractiveMs: Math.round( navigation.domInteractive ),
			domContentLoadedMs: Math.round( navigation.domContentLoadedEventEnd ),
			loadMs: Math.round( navigation.loadEventEnd ),
			transferBytes: navigation.transferSize
		} : null,
		forms: document.querySelectorAll( '#pfForm' ).length,
		visualEditorFields: document.querySelectorAll( '#pfForm textarea.visualeditor' ).length,
		visualEditorReady: Array.from( document.querySelectorAll( '#pfForm textarea.visualeditor' ) )
			.filter( ( field ) => window.jQuery && window.jQuery( field ).data( 'veForAllTarget' ) ).length,
		pageFormsResources: pageForms.length,
		pageFormsTransferBytes: sizes,
		marks: marks
	};
	console.table( report );
	console.table( marks );
	return report;
}() );
