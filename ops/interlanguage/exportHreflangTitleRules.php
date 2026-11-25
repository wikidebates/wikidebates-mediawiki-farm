<?php

/**
 * Export receiver-local public namespace/title rules for WikiSEO.
 * No HTTP, remote database, configuration secrets or writes.
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require_once dirname( __DIR__, 2 ) . '/maintenance/Maintenance.php';

class WikidebatesExportHreflangTitleRules extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Export effective namespace alias and case settings for this wiki as JSON.' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$language = $services->getContentLanguage();
		$nsInfo = $services->getNamespaceInfo();
		$localized = $language->getNamespaces();
		$aliases = [];
		$case = [];
		$names = array_merge(
			array_values( $nsInfo->getCanonicalNamespaces() ),
			array_values( $localized ),
			array_keys( $language->getNamespaceAliases() )
		);
		foreach ( $names as $name ) {
			$name = str_replace( ' ', '_', (string)$name );
			if ( $name === '' ) {
				continue;
			}
			// Use core precedence: canonical names win over local alias conflicts.
			$index = $language->getNsIndex( $name );
			if ( $index === false || $index < 0 || !isset( $localized[$index] ) ) {
				continue;
			}
			$prefix = str_replace( ' ', '_', $localized[$index] );
			if ( $prefix !== '' ) {
				$aliases[$name] = $prefix;
			}
		}
		foreach ( $localized as $index => $name ) {
			if ( $index >= 0 ) {
				$case[str_replace( ' ', '_', $name )] = $nsInfo->isCapitalized( $index ) ? 'first-letter' : 'case-sensitive';
			}
		}
		ksort( $aliases, SORT_STRING );
		ksort( $case, SORT_STRING );
		$this->output( json_encode( [
			'language' => $language->getCode(),
			'titleRules' => [
				'namespaceAliases' => (object)$aliases,
				'namespaceCase' => (object)$case,
				'ucfirstOverrides' => (object)$services->getMainConfig()->get( 'OverrideUcfirstCharacters' ),
			],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n" );
	}
}

$maintClass = WikidebatesExportHreflangTitleRules::class;
require_once RUN_MAINTENANCE_IF_MAIN;
