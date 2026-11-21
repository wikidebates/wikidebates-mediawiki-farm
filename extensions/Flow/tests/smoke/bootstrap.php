<?php
/**
 * Minimal MediaWiki boundary doubles for standalone regression tests.
 * Production Flow classes are autoloaded unchanged. Not a MediaWiki integration test.
 */
namespace {
	if ( PHP_SAPI !== 'cli' || defined( 'MEDIAWIKI' ) ) {
		exit( "Run this test in a separate CLI process, without MediaWiki.\n" );
	}
	error_reporting( E_ALL );
	set_error_handler( static function ( $severity, $message, $file, $line ) {
		throw new \ErrorException( $message, 0, $severity, $file, $line );
	} );
	spl_autoload_register( static function ( $class ) {
		if ( str_starts_with( $class, 'Flow\\' ) ) {
			$file = __DIR__ . '/../../includes/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	} );
	/**
	 * Simulate a capacity-limited LRU for Flow smoke tests. The old unbounded
	 * double concealed evictions and let small production cache sizes pass.
	 */
	class MapCacheLRU {
		private array $values = [];
		public function __construct( private int $size ) {
		}
		public function get( $key ) {
			if ( !array_key_exists( $key, $this->values ) ) {
				return null;
			}
			$value = $this->values[$key];
			unset( $this->values[$key] );
			$this->values[$key] = $value;
			return $value;
		}
		public function set( $key, $value ) {
			unset( $this->values[$key] );
			$this->values[$key] = $value;
			if ( count( $this->values ) > $this->size ) {
				array_shift( $this->values );
			}
		}
		public function clear( $key ) {
			unset( $this->values[$key] );
		}
	}
	function check( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new \RuntimeException( $message );
		}
		$GLOBALS['flow_test_assertions'] = ( $GLOBALS['flow_test_assertions'] ?? 0 ) + 1;
	}
}
namespace MediaWiki\Api {
	interface ApiSerializable {
		public function serializeForApiResult();
	}
}
namespace MediaWiki\User {
	class User {
		public function __construct( public array $rights ) {
		}
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static int $checks = 0;
		public static function getInstance() {
			return new self;
		}
		public function getPermissionManager() {
			return $this;
		}
		public function userHasRight( $user, $right ) {
			return $this->userHasAnyRight( $user, $right );
		}
		public function userHasAnyRight( $user, ...$rights ) {
			self::$checks++;
			return (bool)array_intersect( $rights, $user->rights );
		}
	}
}
namespace Flow {
	class Container {
		public static array $services = [];
		public static function get( $name ) {
			return self::$services[$name];
		}
	}
}
namespace Flow\Collection {
	class PostCollection {
		public static array $collections = [];
		public static function newFromId( $id ) {
			return self::$collections[$id->getAlphadecimal()];
		}
	}
}
