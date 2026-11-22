<?php

/**
 * Tests loaded together in a disposable MediaWiki 1.43 installation.
 *
 * @group WikidebatesFarm
 */
class WikidebatesFarmCombinedExtensionTest extends MediaWikiIntegrationTestCase {

	public function testCoreExtensionsCoexistWithPinnedSubmodules(): void {
		$registry = \MediaWiki\Registration\ExtensionRegistry::getInstance();
		foreach ( [
			'Wikidebates', 'VEForAll', 'VisualEditor', 'Flow', 'PageForms Wikidebates'
		] as $name ) {
			$this->assertTrue( $registry->isLoaded( $name ), "$name should load from the pinned farm" );
		}
	}

	public function testMediaWiki143AndResourceLoaderModules(): void {
		$this->assertStringStartsWith( '1.43.', MW_VERSION );
		$loader = \MediaWiki\MediaWikiServices::getInstance()->getResourceLoader();
		foreach ( [
			'ext.wikidebates.common.core',
			'ext.veforall.core',
			'ext.pageforms.editstate',
			'ext.flow',
		] as $name ) {
			$this->assertNotNull( $loader->getModule( $name ), "$name must resolve" );
		}
	}

	public function testImmediateAutoParentHookRemainsRegistered(): void {
		$this->assertTrue( method_exists(
			\MediaWiki\Extension\Wikidebates\Hooks::class, 'onPageSaveComplete'
		) );
		$this->assertTrue( class_exists(
			\MediaWiki\Extension\Wikidebates\AutoParentLink::class
		) );
	}

	public function testNoObsoleteMobileDependenciesDirectory(): void {
		$this->assertDirectoryDoesNotExist(
			MW_INSTALL_PATH . '/extensions/VEForAllMobileDeps'
		);
	}
}
