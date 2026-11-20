<?php
/**
 * Ensure that each Parser instance receives the interlanguage parser function.
 *
 * Run with: php extensions/Interlanguage/tests/InterlanguageParserSmokeTest.php
 */
namespace MediaWiki {
	class InterlanguageTestHookContainer {
		public array $handlers = [];

		public function register( string $hook, $handler ): void {
			$this->handlers[$hook][] = $handler;
		}
	}

	class MediaWikiServices {
		private static ?self $instance = null;
		private InterlanguageTestHookContainer $hooks;

		private function __construct() {
			$this->hooks = new InterlanguageTestHookContainer();
		}

		public static function getInstance(): self {
			return self::$instance ??= new self();
		}

		public function getHookContainer(): InterlanguageTestHookContainer {
			return $this->hooks;
		}
	}
}

namespace {
	class Parser {
		public const SFH_NO_HASH = 1;
		public array $parserFunctions = [];

		public function setFunctionHook( string $name, callable $handler, int $flags ): void {
			$this->parserFunctions[$name] = [ $handler, $flags ];
		}
	}

	require_once dirname( __DIR__ ) . '/InterlanguageExtension.php';

	$wgExtensionCredits = [];
	$wgMessagesDirs = [];
	$wgExtensionMessagesFiles = [];
	$wgAutoloadClasses = [];
	$wgAutoloadLocalClasses = [];
	$wgHooks = [];
	$wgResourceModules = [];
	require_once dirname( __DIR__ ) . '/Interlanguage.php';

	function assertInterlanguageParser( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$first = new Parser();
	$second = new Parser();
	wfInterlanguageExtension( $first );
	wfInterlanguageExtension( $second );

	assertInterlanguageParser(
		isset( $first->parserFunctions['interlanguage'] ) &&
			isset( $second->parserFunctions['interlanguage'] ),
		'Parser function must be registered on every parser instance'
	);
	assertInterlanguageParser(
		$first->parserFunctions['interlanguage'][1] === Parser::SFH_NO_HASH &&
			$second->parserFunctions['interlanguage'][1] === Parser::SFH_NO_HASH,
		'Parser function flags changed'
	);

	$hooks = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer()->handlers;
	assertInterlanguageParser(
		count( $hooks['OutputPageParserOutput'] ?? [] ) === 2 &&
			count( $hooks['ContentAlterParserOutput'] ?? [] ) === 1 &&
			count( $hooks['EditPage::showEditForm:fields'] ?? [] ) === 1 &&
			count( $hooks['SkinTemplateOutputPageBeforeExec'] ?? [] ) === 1,
		'Output hooks must be registered once for the shared extension instance'
	);

	echo "Interlanguage parser smoke tests: OK\n";
}
