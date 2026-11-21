<?php

/**
 * Private, bounded incident archives and compact notifications.
 *
 * No MediaWiki dependencies. Never serializes raw Apache logs in JSON or email.
 * The detailed historical diagnostic is stored ONLY on the server.
 */
final class ServerIncidentArchive {
	private const DIRECTORY = '/home/users/webmaster/.cache/wikidebates-server-incidents';
	private const FPM_PROBE = '/home/users/webmaster/.cache/wikidebates-fpm-probe.json';
	private const RETENTION_SECONDS = 14 * 86400;
	private const MAX_DETAILS_BYTES = 2 * 1024 * 1024;

	private string $directory;

	public function __construct( ?string $directory = null ) {
		$this->directory = $directory ?? self::DIRECTORY;
	}

	public static function newId(): string {
		return gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 6 ) );
	}

	public static function validId( mixed $id ): bool {
		return is_string( $id )
			&& preg_match( '/^[0-9]{14}-[a-f0-9]{12}$/D', $id ) === 1;
	}

	public static function loadRecentFpmProbe( ?string $path = null, ?int $now = null ): array {
		$file = $path ?? self::FPM_PROBE;
		$now = $now ?? time();
		if ( !is_file( $file ) || is_link( $file ) || !is_readable( $file ) || filesize( $file ) > 65536 ) {
			return [];
		}
		$json = @file_get_contents( $file );
		if ( $json === false ) {
			return [];
		}
		$data = json_decode( $json, true );
		if ( !is_array( $data ) || ( $data['schema'] ?? null ) !== 1 ) {
			return [];
		}
		$stamp = strtotime( (string)( $data['end_utc'] ?? '' ) );
		if ( $stamp === false || $stamp < $now - 900 || $stamp > $now + 60 ) {
			return [];
		}
		$fpm = $data['fpm'] ?? [];
		if ( !is_array( $fpm ) ) {
			return [];
		}
		$sites = [];
		foreach ( [ 'Ferme Wikidebates (wikis non distinguables)', 'Militotheque', 'Hyperdebat', 'Autre' ] as $site ) {
			if ( isset( $fpm['by_site'][$site] ) && is_numeric( $fpm['by_site'][$site] ) ) {
				$sites[$site] = max( 0, (int)$fpm['by_site'][$site] );
			}
		}
		$sources = [];
		foreach ( [ 'fpm_access', 'fpm_error', 'fpm_slow', 'monitor_state' ] as $key ) {
			$value = $data['sources'][$key] ?? 'unavailable';
			$sources[$key] = in_array( $value, [ 'ok', 'truncated', 'unavailable' ], true ) ? $value : 'unavailable';
		}
		return [
			'completed' => max( 0, (int)( $fpm['completed'] ?? 0 ) ),
			'p50_ms' => isset( $fpm['p50_ms'] ) && is_numeric( $fpm['p50_ms'] ) ? (float)$fpm['p50_ms'] : null,
			'p95_ms' => isset( $fpm['p95_ms'] ) && is_numeric( $fpm['p95_ms'] ) ? (float)$fpm['p95_ms'] : null,
			'over_3000ms' => max( 0, (int)( $fpm['over_3000ms'] ?? 0 ) ),
			'by_site' => $sites,
			'sources' => $sources,
			'end_utc' => gmdate( DATE_ATOM, $stamp ),
		];
	}

	/**
	 * Safelist diagnostic fields: no IP, User-Agent, Referer, URL or raw request lines.
	 */
	public static function snapshot(
		string $id,
		string $kind,
		DateTimeImmutable $now,
		int $windowMinutes,
		array $server,
		array $issues,
		array $logs,
		bool $pressure,
		array $probe = []
	): array {
		$safeServer = [];
		foreach ( [
			'apache', 'apacheBusyWorkers', 'apacheIdleWorkers', 'https', 'phpFpm',
			'phpFpmActive', 'phpFpmIdle', 'phpFpmListenQueue', 'phpFpmMaxChildrenReached',
		] as $key ) {
			$value = $server[$key] ?? null;
			$safeServer[$key] = is_numeric( $value ) ? (int)$value : null;
		}
		foreach ( [ 'apacheStatusAvailable', 'phpFpmStatusAvailable' ] as $key ) {
			$safeServer[$key] = ( $server[$key] ?? false ) === true;
		}
		$safeIssues = [];
		foreach ( $issues as $issue ) {
			$entry = [
				'type' => substr( (string)( $issue['type'] ?? 'unknown' ), 0, 90 ),
				'wiki' => substr( (string)( $issue['wiki'] ?? '' ), 0, 30 ),
			];
			foreach ( [ 'value', 'threshold', 'previousValue', 'requests', 'ordinary', 'fileCacheConfirmed', 'ipRatio' ] as $key ) {
				if ( isset( $issue[$key] ) && is_numeric( $issue[$key] ) ) {
					$entry[$key] = (float)$issue[$key];
				}
			}
			$safeIssues[] = $entry;
		}
		$wikis = [];
		foreach ( $logs as $wiki => $data ) {
			$entry = [];
			foreach ( [
				'totalRequests', 'rawTotalRequests', 'ordinaryPageRequests',
				'ordinaryPageFileCacheConfirmedRequests', 'dynamicRequests',
				'fastRejectedRequests', 'http429Requests', 'apiGetRequests', 'apiPostRequests',
			] as $key ) {
				$entry[$key] = max( 0, (int)( $data[$key] ?? 0 ) );
			}
			$entry['uniqueIps'] = count( $data['allIps'] ?? [] );
			$entry['dynamicUniqueIps'] = count( $data['dynamicIps'] ?? [] );
			$wikis[substr( (string)$wiki, 0, 30 )] = $entry;
		}
		return [
			'schema' => 1,
			'incident_id' => $id,
			'event' => $kind,
			'observed_utc' => $now->format( DATE_ATOM ),
			'window_minutes' => $windowMinutes,
			'pressure_active' => $pressure,
			'server' => $safeServer,
			'issues' => $safeIssues,
			'wikis' => $wikis,
			'fpm_probe' => $probe,
			'actions' => [ 'protection_change' => 'none' ],
		];
	}

	public function record( array $document, string $details ): string {
		if ( !self::validId( $document['incident_id'] ?? null ) ) {
			throw new RuntimeException( 'Invalid incident identifier' );
		}
		$event = (string)( $document['event'] ?? '' );
		if ( !in_array( $event, [ 'alert', 'update', 'recovery' ], true ) ) {
			throw new RuntimeException( 'Invalid incident event' );
		}
		if ( is_link( $this->directory ) ) {
			throw new RuntimeException( 'Archive directory is a symlink' );
		}
		if ( !is_dir( $this->directory ) && !@mkdir( $this->directory, 0700, true ) && !is_dir( $this->directory ) ) {
			throw new RuntimeException( 'Unable to create private incident directory' );
		}
		if ( !is_dir( $this->directory ) || is_link( $this->directory ) ) {
			throw new RuntimeException( 'Unsafe incident directory' );
		}
		@chmod( $this->directory, 0700 );
		$mode = @fileperms( $this->directory );
		if ( $mode === false || ( $mode & 0077 ) !== 0 ) {
			throw new RuntimeException( 'Incident directory is not private' );
		}
		$basename = 'incident-' . $document['incident_id'] . '-' . gmdate( 'YmdHis' )
			. '-' . bin2hex( random_bytes( 4 ) ) . '-' . $event;
		$json = json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		if ( strlen( $details ) > self::MAX_DETAILS_BYTES ) {
			$details = substr( $details, 0, self::MAX_DETAILS_BYTES ) . "\n[Diagnostic tronqué; journaux originaux conservés sur le serveur.]\n";
		}
		$this->atomicWrite( $basename . '.txt', $details );
		$this->atomicWrite( $basename . '.json', $json . "\n" );
		$this->prune();
		return $basename;
	}

	private function atomicWrite( string $name, string $contents ): void {
		$target = $this->directory . '/' . $name;
		$temp = $target . '.tmp-' . bin2hex( random_bytes( 6 ) );
		$oldUmask = umask( 0077 );
		try {
			$handle = @fopen( $temp, 'xb' );
			if ( $handle === false ) {
				throw new RuntimeException( 'Cannot create incident temporary file' );
			}
			try {
				if ( !chmod( $temp, 0600 ) ) {
					throw new RuntimeException( 'Cannot secure incident file' );
				}
				$offset = 0;
				while ( $offset < strlen( $contents ) ) {
					$written = fwrite( $handle, substr( $contents, $offset ) );
					if ( $written === false || $written === 0 ) {
						throw new RuntimeException( 'Cannot write complete incident archive' );
					}
					$offset += $written;
				}
				if ( !fflush( $handle ) ) {
					throw new RuntimeException( 'Cannot flush incident archive' );
				}
			} finally {
				fclose( $handle );
			}
			if ( !rename( $temp, $target ) ) {
				throw new RuntimeException( 'Cannot publish incident archive' );
			}
		} finally {
			umask( $oldUmask );
			if ( is_file( $temp ) ) {
				@unlink( $temp );
			}
		}
	}

	private function prune(): void {
		$files = @glob( $this->directory . '/incident-*' );
		if ( $files === false ) {
			return;
		}
		$cutoff = time() - self::RETENTION_SECONDS;
		foreach ( $files as $file ) {
			if ( is_link( $file ) || !is_file( $file ) ) {
				continue;
			}
			if ( !preg_match( '/\/incident-[0-9]{14}-[a-f0-9]{12}-[0-9]{14}-[a-f0-9]{8}-(?:alert|update|recovery)\.(?:json|txt)$/D', $file ) ) {
				continue;
			}
			$modified = @filemtime( $file );
			if ( $modified !== false && $modified < $cutoff ) {
				@unlink( $file );
			}
		}
	}

	public static function mail( array $data, bool $stored ): string {
		$server = $data['server'];
		$when = ( new DateTimeImmutable( $data['observed_utc'] ) )->setTimezone( new DateTimeZone( 'Europe/Paris' ) );
		$recovery = $data['event'] === 'recovery';
		$lines = [
			$recovery ? 'Wikidébats — retour à la normale' : 'Wikidébats — alerte serveur / trafic',
			'Incident : ' . $data['incident_id'],
			'Date : ' . $when->format( 'd/m/Y H:i:s T' ),
			'Fenêtre : ' . $data['window_minutes'] . ' minutes',
			'',
			'Pression active mesurée : ' . ( $data['pressure_active'] ? 'oui' : 'non au relevé' ),
			'Apache : ' . ( $server['apacheStatusAvailable'] ? (string)$server['apacheBusyWorkers'] . ' workers occupés' : 'mod_status indisponible' ),
			'HTTPS : ' . $server['https'] . ' connexions établies',
			'PHP-FPM webmaster : ' . ( $server['phpFpmStatusAvailable']
				? $server['phpFpmActive'] . ' actifs, file ' . $server['phpFpmListenQueue']
				: 'statut détaillé indisponible' ),
		];
		if ( !$recovery ) {
			$lines[] = '';
			$lines[] = 'Signaux observés :';
			foreach ( array_slice( $data['issues'], 0, 6 ) as $issue ) {
				$lines[] = '- ' . $issue['type'] . ( $issue['wiki'] !== '' ? ' [' . $issue['wiki'] . ']' : '' );
			}
			if ( count( $data['issues'] ) > 6 ) {
				$lines[] = '- ... ' . ( count( $data['issues'] ) - 6 ) . ' autres signaux';
			}
		}
		$probe = $data['fpm_probe'];
		if ( $probe ) {
			$lines[] = '';
			$lines[] = 'PHP terminé (sonde récente) : ' . $probe['completed']
				. ' requêtes ; p95 ' . ( $probe['p95_ms'] ?? '?' ) . ' ms ; >3s ' . $probe['over_3000ms'];
			foreach ( $probe['by_site'] as $site => $count ) {
				$lines[] = '- ' . $site . ' : ' . $count;
			}
			if ( ( $probe['sources']['fpm_access'] ?? '' ) !== 'ok' ) {
				$lines[] = 'Attention : accès PHP-FPM partiel ou indisponible.';
			}
		} else {
			$lines[] = 'Sonde des traitements PHP terminés : absente ou trop ancienne.';
		}
		$lines[] = '';
		$lines[] = $recovery
			? 'Conclusion : les signaux surveillés sont revenus sous leurs seuils au relevé.'
			: 'Conclusion : signaux détectés, mais la cause exacte et une éventuelle responsabilité du crawl ne sont pas établies.';
		$lines[] = 'Action automatique : aucune modification des protections.';
		$lines[] = $stored
			? 'Diagnostic détaillé et JSON conservés dans le dossier privé des incidents (14 jours).'
			: 'Attention : archivage local indisponible ; consulter les journaux originaux.';
		$lines[] = 'Intervention humaine : ' . ( $stored ? 'non requise à ce stade.' : 'vérifier les droits du dossier d’incidents.' );
		return implode( "\n", $lines ) . "\n";
	}
}
