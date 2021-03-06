<?php

namespace Wikibase\Client\RecentChanges;

/**
 * Represents a revision on a site
 *
 * @todo Merge this into ExternalChange
 *
 * @license GPL-2.0+
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Daniel Kinzler
 */
class RevisionData {

	/**
	 * @var string
	 */
	protected $userName;

	/**
	 * @var string
	 */
	protected $timestamp;

	/**
	 * @var string wikitext
	 */
	protected $comment;

	/**
	 * @var string|null HTML
	 */
	protected $commentHtml;

	/**
	 * @var array
	 */
	protected $changeParams;

	/**
	 * @param string $userName
	 * @param string $timestamp
	 * @param string $comment
	 * @param string|null $commentHtml
	 * @param string $siteId
	 * @param array $changeParams
	 */
	public function __construct(
		$userName,
		$timestamp,
		$comment,
		$commentHtml,
		$siteId,
		array $changeParams
	) {
		$this->userName = $userName;
		$this->timestamp = $timestamp;
		$this->comment = $comment;
		$this->commentHtml = $commentHtml;
		$this->siteId = $siteId;
		$this->changeParams = $changeParams;
	}

	/**
	 * @return string
	 */
	public function getUserName() {
		return $this->userName;
	}

	/**
	 * @return int
	 */
	public function getPageId() {
		return $this->changeParams['page_id'];
	}

	/**
	 * @return int
	 */
	public function getRevId() {
		return $this->changeParams['rev_id'];
	}

	/**
	 * @return int
	 */
	public function getParentId() {
		return $this->changeParams['parent_id'];
	}

	/**
	 * @return string
	 */
	public function getTimestamp() {
		return $this->timestamp;
	}

	/**
	 * @return string
	 */
	public function getComment() {
		return $this->comment;
	}

	/**
	 * @return string|null
	 */
	public function getCommentHtml() {
		return $this->commentHtml;
	}

	/**
	 * @return string
	 */
	public function getSiteId() {
		return $this->siteId;
	}

	/**
	 * @return array
	 */
	public function getChangeParams() {
		return $this->changeParams;
	}

}
