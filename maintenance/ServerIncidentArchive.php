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
	private const AGGREGATE_RETENTION_SECONDS = 90 * 86400;
	private const WIKIS = [ 'FR', 'EN', 'DE', 'ES', 'IT', 'PT', 'DEV', 'FARM', 'MILITOTHÈQUE' ];
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
		// Only fixed installation and endpoint labels, never request URLs or query values.
		$slowGroups = [];
		foreach ( array_slice( is_array( $fpm['top_slow'] ?? null ) ? $fpm['top_slow'] : [], 0, 10 ) as $row ) {
			if ( !is_array( $row ) || !is_numeric( $row['duration_ms'] ?? null ) ) {
				continue;
			}
			$duration = (float)$row['duration_ms'];
			$site = $row['site'] ?? '';
			if ( !in_array( $site, [ 'Ferme Wikidebates (wikis non distinguables)', 'Militotheque', 'Hyperdebat', 'Autre' ], true )
				|| !is_finite( $duration ) || $duration <= 3000 || $duration > 86400000 ) {
				continue;
			}
			$endpoint = explode( '?', (string)( $row['endpoint'] ?? '' ), 2 )[0];
			if ( !in_array( $endpoint, [ '/w/api.php', '/w/index.php', '/w/load.php', 'Autre PHP' ], true ) ) {
				$endpoint = 'Autre PHP';
			}
			$key = $site . '|' . $endpoint;
			$previous = $slowGroups[$key] ?? [ 'count' => 0, 'max_ms' => 0 ];
			$slowGroups[$key] = [
				'site' => $site,
				'endpoint' => $endpoint,
				'count' => $previous['count'] + 1,
				'max_ms' => max( $previous['max_ms'], $duration ),
			];
		}
		$slowGroups = array_values( $slowGroups );
		usort( $slowGroups, static fn ( array $a, array $b ): int => $b['max_ms'] <=> $a['max_ms'] );
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
			'slow_groups' => array_slice( $slowGroups, 0, 3 ),
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
		array $probe = [],
		array $apacheVhosts = []
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
			foreach ( [ 'special', 'index', 'api', 'load' ] as $bucket ) {
				$entry['traffic_' . $bucket] = max( 0, (int)( $data['trafficBucketCounts'][$bucket] ?? 0 ) );
			}
			$entry['uniqueIps'] = count( $data['allIps'] ?? [] );
			$entry['dynamicUniqueIps'] = count( $data['dynamicIps'] ?? [] );
			$wikis[substr( (string)$wiki, 0, 30 )] = $entry;
		}
		$activeWikis = [];
		foreach ( $apacheVhosts as $group ) {
			$wiki = $group['wiki'] ?? '';
			if ( in_array( $wiki, self::WIKIS, true ) ) {
				$activeWikis[$wiki] = ( $activeWikis[$wiki] ?? 0 ) + max( 0, (int)( $group['count'] ?? 0 ) );
			}
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
			'apache_active_wikis' => $activeWikis,
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
		$now = time();
		foreach ( $files as $file ) {
			if ( is_link( $file ) || !is_file( $file ) ) {
				continue;
			}
			if ( !preg_match( '/\/incident-[0-9]{14}-[a-f0-9]{12}-[0-9]{14}-[a-f0-9]{8}-(?:alert|update|recovery)\.(?:json|txt)$/D', $file ) ) {
				continue;
			}
			$cutoff = $now - ( str_ends_with( $file, '.json' ) ? self::AGGREGATE_RETENTION_SECONDS : self::RETENTION_SECONDS );
			$modified = @filemtime( $file );
			if ( $modified !== false && $modified < $cutoff ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Pure notification policy. Keep the highest notified values during an
	 * episode to avoid repeated alerts when measurements oscillate.
	 * Legacy state has no reliable evidence that a pressure mail was sent.
	 */
	public static function notificationPlan( array $state, array $issues, bool $pressure ): array {
		$steps = [
			'php-fpm-active' => 8,
			'php-fpm-queue' => 3,
			'apache-busy' => 25,
			'https' => 100,
			'php-fpm-max-children-increase' => 3,
		];
		$signals = [];
		foreach ( $issues as $issue ) {
			$type = $issue['type'] ?? '';
			if ( !isset( $steps[$type] ) || ( !$pressure && $type !== 'php-fpm-max-children-increase' ) ) {
				continue;
			}
			$value = max( 0, (int)( $issue['value'] ?? 0 ) );
			if ( $type === 'php-fpm-max-children-increase' ) {
				$value = max( 0, $value - (int)( $issue['previousValue'] ?? $value ) );
				if ( $value === 0 ) {
					continue;
				}
			}
			$signals[$type] = max( $signals[$type] ?? 0, $value );
		}
		$notified = ( $state['pressureNotified'] ?? false ) === true;
		$activeNotified = ( $state['activePressureNotified'] ?? false ) === true;
		$water = is_array( $state['notificationHighWater'] ?? null ) ? $state['notificationHighWater'] : [];
		$send = $signals !== [] && ( !$notified || ( $pressure && !$activeNotified ) );
		foreach ( $signals as $type => $value ) {
			if ( !isset( $water[$type] ) || $value >= (int)$water[$type] + $steps[$type] ) {
				$send = true;
			}
		}
		if ( $send ) {
			foreach ( $signals as $type => $value ) {
				$water[$type] = max( (int)( $water[$type] ?? 0 ), $value );
			}
		}
		return [
			'send' => $send,
			'notified' => $notified || $send,
			'active_notified' => $activeNotified || ( $send && $pressure ),
			'high_water' => $water,
		];
	}

	private static function pressureExplanation( array $issue ): ?string {
		$value = (int)( $issue['value'] ?? 0 );
		$threshold = isset( $issue['threshold'] ) ? ' (seuil : ' . (int)$issue['threshold'] . ')' : '';
		return match ( $issue['type'] ?? '' ) {
			'php-fpm-queue' => $value . ' demande(s) attendent qu’un processus PHP se libère. Le serveur ne peut pas traiter immédiatement toutes les demandes.',
			'php-fpm-active' => $value . ' traitements PHP sont en cours simultanément' . $threshold . '. Le nombre de processus occupés est élevé ; cela peut ralentir les pages.',
			'apache-busy' => $value . ' processus ou threads Apache sont occupés' . $threshold . '. Le serveur web est fortement sollicité, sans que cela prouve une saturation de PHP.',
			'https' => $value . ' connexions HTTPS sont établies' . $threshold . '. Ce nombre élevé est un signal d’activité réseau ; des connexions peuvent être inactives, ce n’est pas une preuve de saturation.',
			'php-fpm-max-children-increase' => 'PHP a atteint sa limite de traitements simultanés depuis le précédent relevé (' . max( 0, $value - (int)( $issue['previousValue'] ?? $value ) ) . ' nouveau(x) dépassement(s)). La saturation a pu être temporaire.',
			'php-fpm-fallback-total' => 'Le statut PHP détaillé est indisponible. Le total de processus (' . $value . ') ne prouve pas à lui seul une saturation.',
			default => null,
		};
	}

	private static function trafficExplanation( string $type ): string {
		return match ( $type ) {
			'crawler-wiki-ordinary-distributed' => 'consultations de pages ordinaires réparties sur de nombreuses adresses',
			'crawler-declared-bot-ordinary-heavy' => 'robot déclaré consultant beaucoup de pages ordinaires',
			'crawler-wiki-ordinary-short-burst' => 'rafale très rapide de consultations de pages ordinaires',
			'crawler-wiki-ordinary-concentrated' => 'consultations concentrées sur des adresses très actives',
			'crawler-wiki-dynamic-distributed' => 'nombreuses requêtes potentiellement dynamiques réparties entre plusieurs adresses',
			'crawler-rotating-ua', 'bot-identity-rotation' => 'changements inhabituels d’identité déclarée des robots ou navigateurs',
			'crawler-api-post-distributed' => 'nombreuses requêtes POST vers l’API, éventuellement légitimes',
			'crawler-ua' => 'comportement de robot distribué possible',
			'security-scan', 'security-scan-bot-rotation' => 'tentatives possibles de recherche de failles ou de fichiers sensibles',
			default => 'signal de trafic inhabituel détecté par le moniteur',
		};
	}

	public static function mail( array $data, bool $stored ): string {
		$server = $data['server'];
		$when = ( new DateTimeImmutable( $data['observed_utc'] ) )->setTimezone( new DateTimeZone( 'Europe/Paris' ) );
		$recovery = $data['event'] === 'recovery';
		$recent = false;
		$reasons = [];
		$traffic = [];
		$affected = [];
		foreach ( $data['issues'] as $issue ) {
			$reason = self::pressureExplanation( $issue );
			if ( $reason !== null ) {
				$reasons[$reason] = true;
				$recent = $recent || $issue['type'] === 'php-fpm-max-children-increase';
				continue;
			}
			$wiki = $issue['wiki'] ?? '';
			if ( in_array( $wiki, self::WIKIS, true ) ) {
				$affected[$wiki] = true;
				$traffic[$wiki][self::trafficExplanation( (string)$issue['type'] )] = true;
			}
		}
		$lines = [
			$recovery ? 'Wikidébats — retour à la normale' : 'Wikidébats — diagnostic serveur',
			'Incident : ' . $data['incident_id'],
			'Date : ' . $when->format( 'd/m/Y H:i:s T' ) . ' ; trafic observé sur ' . $data['window_minutes'] . ' minutes.',
			'',
			'CE QUI SE PASSE',
		];
		if ( $recovery ) {
			$lines[] = 'Les signaux de cet épisode sont revenus sous leurs seuils au relevé. Il n’y a plus de pression détectée.';
		} elseif ( $data['pressure_active'] ) {
			$lines[] = 'Le moniteur a détecté une pression serveur au relevé. Les visiteurs peuvent subir des ralentissements ; une indisponibilité n’est pas démontrée.';
		} elseif ( $recent ) {
			$lines[] = 'Une saturation PHP a été enregistrée entre deux relevés, mais aucune pression active n’est détectée actuellement.';
		} else {
			$lines[] = 'Aucune pression active ni saturation récente n’est démontrée par ce relevé.';
		}
		foreach ( array_keys( $reasons ) as $reason ) {
			$lines[] = '- ' . $reason;
		}
		if ( $server['phpFpmStatusAvailable'] ) {
			$lines[] = 'État PHP au relevé : ' . $server['phpFpmActive'] . ' actifs, file ' . $server['phpFpmListenQueue'] . ' (demandes en attente).';
		} else {
			$lines[] = 'Le statut détaillé de PHP est indisponible ; on ne connaît pas sa file d’attente.';
		}
		$lines[] = '';
		$lines[] = 'QUELS WIKIS ET QUELLE CAUSE ?';
		$lines[] = $affected
			? 'Trafic inhabituel détecté sur : ' . implode( ', ', array_keys( $affected ) ) . '.'
			: 'Aucun wiki n’a été identifié comme source d’un trafic inhabituel par les détecteurs.';
		foreach ( $traffic as $wiki => $descriptions ) {
			$lines[] = '- ' . $wiki . ' : ' . implode( ' ; ', array_keys( $descriptions ) ) . '.';
		}
		$activeWikis = $data['apache_active_wikis'] ?? [];
		if ( $activeWikis ) {
			arsort( $activeWikis, SORT_NUMERIC );
			$groups = [];
			foreach ( $activeWikis as $wiki => $count ) {
				if ( in_array( $wiki, self::WIKIS, true ) ) {
					$groups[] = $wiki . ' : ' . $count;
				}
			}
			if ( $groups ) {
				$lines[] = 'Activité Apache observée par wiki (instantané partiel, pas une mesure du coût PHP) : ' . implode( ', ', $groups ) . '.';
			}
		}
		$wikis = $data['wikis'];
		uksort( $wikis, static function ( $a, $b ) use ( $wikis, $affected ): int {
			return (int)isset( $affected[$b] ) <=> (int)isset( $affected[$a] )
				?: ( $wikis[$b]['dynamicRequests'] <=> $wikis[$a]['dynamicRequests'] )
				?: ( $wikis[$b]['totalRequests'] <=> $wikis[$a]['totalRequests'] );
		} );
		foreach ( $wikis as $wiki => $counts ) {
			if ( !in_array( $wiki, self::WIKIS, true ) || ( !$counts['totalRequests'] && !isset( $affected[$wiki] ) ) ) {
				continue;
			}
			$line = '- ' . $wiki . ' : ' . $counts['totalRequests'] . ' requêtes, '
				. $counts['dynamicRequests'] . ' classées dynamiques, '
				. $counts['ordinaryPageFileCacheConfirmedRequests'] . '/' . $counts['ordinaryPageRequests'] . ' pages ordinaires servies par le cache confirmé';
			$routes = [];
			foreach ( [ 'special' => 'pages spéciales', 'index' => 'index.php', 'api' => 'API', 'load' => 'ressources JS/CSS' ] as $key => $label ) {
				if ( !empty( $counts['traffic_' . $key] ) ) {
					$routes[] = $label . ' : ' . $counts['traffic_' . $key];
				}
			}
			if ( $routes ) {
				$line .= ' ; ' . implode( ', ', $routes );
			}
			if ( $counts['fastRejectedRequests'] || $counts['http429Requests'] ) {
				$line .= ' ; refus observés : HTTP 418=' . $counts['fastRejectedRequests'] . ', HTTP 429=' . $counts['http429Requests'];
			}
			$lines[] = $line . '.';
		}
		$lines[] = 'Les volumes de trafic ne prouvent pas quels traitements PHP ont causé la pression. Une page servie par le cache ne nécessite pas forcément un nouveau rendu ; un refus rapide n’est pas un rendu réussi.';
		$lines[] = $affected
			? 'Le trafic inhabituel peut être une piste d’explication, mais sa responsabilité reste à établir.'
			: 'La cause de la pression n’est pas établie ; elle peut être indépendante du crawl.';
		$probe = $data['fpm_probe'];
		if ( $probe ) {
			$lines[] = 'Sur la fenêtre récente de la sonde PHP, ' . $probe['completed'] . ' traitements sont terminés'
				. ( isset( $probe['p95_ms'] ) ? ' ; 95 % ont pris au plus environ ' . $probe['p95_ms'] . ' ms' : '' )
				. ' ; ' . $probe['over_3000ms'] . ' ont dépassé 3 secondes. Le pool est partagé et ne permet pas toujours de distinguer les langues.';
			foreach ( $probe['by_site'] ?? [] as $site => $count ) {
				$lines[] = '- Traitements PHP terminés : ' . $site . ' : ' . $count . '.';
			}
			foreach ( $probe['slow_groups'] ?? [] as $group ) {
				$lines[] = '- Parmi les dix traitements terminés les plus lents : ' . $group['site'] . ', '
					. $group['endpoint'] . ' : ' . $group['count'] . ' au-delà de 3 secondes, jusqu’à '
					. round( $group['max_ms'] / 1000, 1 ) . ' s. Piste à vérifier, pas une attribution de la pression.';
			}
			if ( ( $probe['sources']['fpm_access'] ?? '' ) !== 'ok' ) {
				$lines[] = 'Attention : accès aux données PHP partiel ou indisponible.';
			}
		} else {
			$lines[] = 'La sonde des traitements PHP terminés est absente ou trop ancienne.';
		}
		$lines[] = '';
		$lines[] = 'SUITE DONNÉE';
		$lines[] = $recovery ? 'Retour à la normale enregistré ; vérifier la récurrence de ce type d’épisode.'
			: ( $data['pressure_active'] || $recent ? 'Investigation conseillée : rapprocher les traitements PHP coûteux, leur durée et le trafic par wiki avant de modifier les protections.' : 'Surveillance et analyse locale ; aucune correction n’est justifiée par ce seul relevé.' );
		$lines[] = 'Aucune modification automatique des protections n’a été effectuée.';
		$lines[] = $stored
			? 'Diagnostic détaillé privé conservé 14 jours ; compteurs agrégés 90 jours.'
			: 'Attention : archivage local indisponible ; vérifier les droits et consulter les journaux originaux.';
		return implode( "\n", $lines ) . "\n";
	}
}
