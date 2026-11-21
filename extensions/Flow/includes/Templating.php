<?php

namespace Flow;

use Flow\Exception\FlowException;
use Flow\Exception\InvalidParameterException;
use Flow\Exception\PermissionException;
use Flow\Model\AbstractRevision;
use Flow\Model\PostRevision;
use Flow\Parsoid\ContentFixer;
use Flow\Repository\UserNameBatch;
use MediaWiki\Linker\Linker;
use MediaWiki\WikiMap\WikiMap;

/**
 * This class is slowly being deprecated. It used to house a minimalist
 * php templating system, it is now just a few of the helpers that were
 * reused in the new api responses and other parts of Flow.
 */
class Templating {
	/**
	 * @var UserNameBatch
	 */
	private $usernames;

	/**
	 * @var RevisionActionPermissions
	 */
	private $permissions;

	/**
	 * @var ContentFixer
	 */
	private $contentFixer;

	/** @var callable|null Optional read-only benchmark observer; disabled in normal rendering. */
	private $benchmarkTimer;

	/**
	 * @param UserNameBatch $usernames
	 * @param ContentFixer $contentFixer
	 * @param RevisionActionPermissions $permissions
	 */
	public function __construct(
		UserNameBatch $usernames,
		ContentFixer $contentFixer,
		RevisionActionPermissions $permissions
	) {
		$this->usernames = $usernames;
		$this->contentFixer = $contentFixer;
		$this->permissions = $permissions;
	}

	/**
	 * Keep all ContentFixer timing opt-in and limited to the benchmark window.
	 * The content fixer is shared by rendering helpers in this process.
	 */
	public function setBenchmarkTimer( ?callable $observer ): void {
		$this->benchmarkTimer = $observer;
		$this->contentFixer->setBenchmarkTimer( $observer );
	}

	/**
	 * Temporarily force an A/B variant inside the maintenance benchmark.
	 * Null clears the override and restores the site configuration.
	 */
	public function setBenchmarkReuseDecodedDom( ?bool $reuse ): void {
		$this->contentFixer->setBenchmarkReuseDecodedDom( $reuse );
	}

	/** Opt-in, maintenance-only comparison of WikiLink title resolution. */
	public function setBenchmarkReusePreloadedWikiLinkTitles( bool $reuse ): void {
		$this->contentFixer->setBenchmarkReusePreloadedWikiLinkTitles( $reuse );
	}

	/**
	 * Returns pretty-printed user links + user tool links for history and
	 * RecentChanges pages.
	 *
	 * Moderation-aware.
	 *
	 * @param AbstractRevision $revision Revision to display
	 * @return string HTML
	 * @throws PermissionException
	 */
	public function getUserLinks( AbstractRevision $revision ) {
		if ( !$revision->isModerated() && !$this->permissions->isAllowed( $revision, 'history' ) ) {
			throw new PermissionException( 'Insufficient permissions to see userlinks for rev_id = ' .
				$revision->getRevisionId()->getAlphadecimal() );
		}

		// Convert to use MapCacheLRU?
		// if this specific revision is moderated, its usertext can always be
		// displayed, since it will be the moderator user
		static $cache = [];
		$userid = $revision->getUserId();
		$userip = $revision->getUserIp() ?? '';
		if ( isset( $cache[$userid][$userip] ) ) {
			return $cache[$userid][$userip];
		}
		$username = $this->usernames->get( WikiMap::getCurrentWikiId(), $userid, $userip );
		$cache[$userid][$userip] = $username ?
			Linker::userLink( $userid, $username ) . Linker::userToolLinks( $userid, $username ) :
			'';
		return $cache[$userid][$userip];
	}

	/**
	 * Usually the revisions's content can just be displayed. In the event
	 * of moderation, however, that info should not be exposed.
	 *
	 * If a specific i18n message is available for a certain moderation level,
	 * that message will be returned (well, unless the user actually has the
	 * required permissions to view the full content). Otherwise, in normal
	 * cases, the full content will be returned.
	 *
	 * The content-type of the return value varies on the $format parameter.
	 * Further processing in the final output stage must escape all formats
	 * other than the default 'html' and 'fixed-html'.
	 *
	 * @param AbstractRevision $revision Revision to display content for
	 * @param string $format Format to output content in one of:
	 *   (fixed-html|html|wikitext|topic-title-html|topic-title-wikitext|topic-title-plaintext)
	 * @return string HTML if requested, otherwise plain text
	 * @throws InvalidParameterException
	 */
	public function getContent( AbstractRevision $revision, $format = 'fixed-html' ) {
		if ( !in_array( $format, [ 'fixed-html', 'html', 'wikitext', 'topic-title-html',
				'topic-title-wikitext', 'topic-title-plaintext' ]
			)
		) {
			throw new InvalidParameterException( 'Invalid format: ' . $format );
		}

		$mainPermissionAction = ( $revision instanceof PostRevision && $revision->isTopicTitle() ) ?
			'view-topic-title' :
			'view';

		$allowed = $this->permissions->isAllowed( $revision, $mainPermissionAction );
		// Posts require view access to the topic title as well
		if ( $allowed && $revision instanceof PostRevision && !$revision->isTopicTitle() ) {
			if ( $this->benchmarkTimer === null ) {
				$allowed = $this->permissions->isAllowed(
					$revision->getRootPost(),
					'view'
				);
			} else {
				// Measure the existing safety-critical root lookup and check separately.
				// Keep the direct uninstrumented path identical to production.
				$started = hrtime( true );
				$root = $revision->getRootPost();
				( $this->benchmarkTimer )(
					'format_detail_content_root_lookup',
					round( ( hrtime( true ) - $started ) / 1000000, 3 )
				);
				$started = hrtime( true );
				$allowed = $this->permissions->isAllowed( $root, 'view' );
				( $this->benchmarkTimer )(
					'format_detail_content_root_view_permission',
					round( ( hrtime( true ) - $started ) / 1000000, 3 )
				);
			}
		}

		if ( !$allowed ) {
			// failsafe - never output content if permissions aren't satisfied!
			return '';
		}

		if ( $format === 'fixed-html' ) {
			// Parsoid doesn't render redlinks & doesn't strip bad images
			$content = $this->contentFixer->getContent( $revision );
		} else {
			$content = $revision->getContent( $format );
		}

		return $content;
	}

	public function getModeratedRevision( AbstractRevision $revision ) {
		if ( $revision->isModerated() ) {
			return $revision;
		} else {
			try {
				return Container::get( 'collection.cache' )->getLastRevisionFor( $revision );
			} catch ( FlowException $e ) {
				wfDebugLog( 'Flow', "Failed loading last revision for revid " .
					$revision->getRevisionId()->getAlphadecimal() . " with collection id " .
					$revision->getCollectionId()->getAlphadecimal() );
				throw $e;
			}
		}
	}
}
