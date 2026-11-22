/** Warn against the last acknowledged save, including structural and VE edits. */
( function ( $, mw ) {
	'use strict';

	$( () => {
		const $form = $( '#pfForm' );
		if ( !$form.length || !mw.user.options.get( 'useeditwarning' ) ||
			( mw.config.get( 'wgCanonicalSpecialPageName' ) !== 'FormEdit' && mw.config.get( 'wgAction' ) !== 'formedit' ) ) {
			return;
		}
		mw.pageFormsEditState.bind( $form );
		let warning;
		function install() {
			if ( warning ) {
				warning.release();
			}
			warning = mw.confirmCloseWindow( {
				namespace: 'editwarning',
				test: () => !$form.data( 'submitted' ) && mw.pageFormsEditState.isDirty( $form )
			} );
		}
		install();
		mw.hook( 'pf.formSubmit' ).add( ( $submittedForm ) => {
			if ( !$submittedForm || $submittedForm[ 0 ] === $form[ 0 ] ) {
				warning.release();
			}
		} );
		$( window ).on( 'pageshow.pfEditWarning', ( event ) => {
			if ( ( event.originalEvent || event ).persisted ) {
				install();
			}
		} );
	} );
}( jQuery, mediaWiki ) );
