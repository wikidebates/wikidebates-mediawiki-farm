<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\FileModule;

/**
 * Verify the ResourceLoader dependency that must initialize the PageForms edit state
 * before PageForms.js and the edit warning can access it.
 *
 * @covers \MediaWiki\ResourceLoader\FileModule
 * @group PageForms
 */
class PFResourceLoaderDependencyTest extends MediaWikiIntegrationTestCase {

	public function testMainWaitsForEditStateBeforeRunning(): void {
		$resourceLoader = MediaWikiServices::getInstance()->getResourceLoader();

		$editState = $resourceLoader->getModule( 'ext.pageforms.editstate' );
		$main = $resourceLoader->getModule( 'ext.pageforms.main' );
		$editWarning = $resourceLoader->getModule( 'ext.pageforms.editwarning' );

		$this->assertInstanceOf( FileModule::class, $editState );
		$this->assertInstanceOf( FileModule::class, $main );
		$this->assertInstanceOf( FileModule::class, $editWarning );

		$this->assertContains( 'ext.pageforms.editstate', $main->getDependencies() );
		$this->assertContains( 'ext.pageforms.main', $editWarning->getDependencies() );
		$this->assertNotContains( 'ext.pageforms.main', $editState->getDependencies() );
	}

	public function testEditStateIsNotDuplicatedInMainScriptBundle(): void {
		$manifest = json_decode(
			file_get_contents( dirname( __DIR__, 4 ) . '/extension.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertSame(
			'libs/PF_editState.js',
			$manifest['ResourceModules']['ext.pageforms.editstate']['scripts']
		);
		$this->assertNotContains(
			'libs/PF_editState.js',
			$manifest['ResourceModules']['ext.pageforms.main']['scripts']
		);
	}
}
