<?php
/**
 * Backward-compatible entry point for the central wiki.
 *
 * Load the manifest instead of maintaining separate hook and job registries.
 * This ensures that LinksUpdate, LanguageLinks and purgeDependentWikis are
 * registered when a wiki uses the historical InterlanguageCentral.php include.
 */
$wgInterlanguageCentralExtensionIndexUrl = '';

wfLoadExtension( 'Interlanguage', __DIR__ . '/extension.json' );
