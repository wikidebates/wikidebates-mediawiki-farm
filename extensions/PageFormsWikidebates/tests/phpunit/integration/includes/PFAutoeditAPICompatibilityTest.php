<?php

use MediaWiki\WikiMap\WikiMap;

/**
 * Guard against removed MediaWiki global helpers in the form-edit draft context.
 *
 * @group PageForms
 */
class PFAutoeditAPICompatibilityTest extends MediaWikiIntegrationTestCase {

	public function testDraftWikiIdentifierUsesSupportedWikiMap(): void {
		$wikiId = WikiMap::getCurrentWikiId();
		$this->assertNotSame( '', $wikiId );

		$source = file_get_contents(
			dirname( __DIR__, 4 ) . '/includes/PF_AutoeditAPI.php'
		);
		$this->assertNotFalse( $source );

		$this->assertStringContainsString( 'use MediaWiki\WikiMap\WikiMap;', $source );
		$this->assertStringContainsString(
			"'wiki' => WikiMap::getCurrentWikiId(),",
			$source
		);
		$this->assertStringNotContainsString( 'wfWikiID(', $source );
	}
}
