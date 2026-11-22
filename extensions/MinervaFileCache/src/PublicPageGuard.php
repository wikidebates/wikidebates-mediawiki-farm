<?php

namespace MediaWiki\Extension\MinervaFileCache;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Conservative MediaWiki 1.43 guard for already-published anonymous HTML.
 *
 * MediaWiki's RevisionDelete does not necessarily change page_latest. The
 * deferred page_touched/HTML purge can be pending while the on-disk entry
 * still matches its original identity. Never replay or publish it unless the
 * current PRIMARY revision is fully visible to a reader without a session.
 */
final class PublicPageGuard {
	public static function matches(Title $title, int $revisionId, string $touched): bool {
		if (!$title->canExist() || $revisionId < 1 || $touched === '') {
			return false;
		}
		$services = MediaWikiServices::getInstance();
		$db = $services->getConnectionProvider()->getPrimaryDatabase();
		// Read identity and latest revision visibility in one PRIMARY snapshot.
		$page = $db->selectRow(
			['page', 'revision'],
			['page_id', 'page_latest', 'page_touched', 'page_is_redirect', 'rev_deleted'],
			['page_namespace' => $title->getNamespace(),
				'page_title' => $title->getDBkey()],
			__METHOD__,
			[],
			['revision' => ['JOIN', 'rev_id = page_latest AND rev_page = page_id']]
		);
		if (!$page || (int)$page->page_id < 1 ||
			(int)$page->page_latest !== $revisionId ||
			(string)$page->page_touched !== $touched ||
			(int)$page->page_is_redirect !== 0 ||
			(int)$page->rev_deleted !== 0) {
			return false;
		}
		return $services->getPermissionManager()->userCan('read',
			$services->getUserFactory()->newAnonymous(), $title);
	}
}
