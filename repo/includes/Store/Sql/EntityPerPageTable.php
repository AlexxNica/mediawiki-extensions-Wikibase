<?php

namespace Wikibase\Repo\Store\Sql;

use InvalidArgumentException;
use LoadBalancer;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\EntityIdParser;
use Wikibase\DataModel\Entity\Int32EntityId;
use Wikibase\Lib\EntityIdComposer;
use Wikibase\Repo\Store\EntityPerPage;
use Wikibase\Repo\Store\EntitiesWithoutTermFinder;

/**
 * Represents a lookup database table that makes the link between entities and pages.
 * Corresponds to the wb_entities_per_page table.
 *
 * @since 0.2
 *
 * @license GPL-2.0+
 * @author Thomas Pellissier Tanon
 * @author Daniel Kinzler
 */
class EntityPerPageTable implements EntityPerPage, EntitiesWithoutTermFinder {

	/**
	 * @var EntityIdParser
	 */
	private $entityIdParser;

	/**
	 * @var EntityIdComposer
	 */
	private $entityIdComposer;

	/**
	 * @var LoadBalancer
	 */
	private $loadBalancer;

	/**
	 * @param LoadBalancer $loadBalancer
	 * @param EntityIdParser $entityIdParser
	 * @param EntityIdComposer $entityIdComposer
	 */
	public function __construct(
		LoadBalancer $loadBalancer,
		EntityIdParser $entityIdParser,
		EntityIdComposer $entityIdComposer
	) {
		$this->loadBalancer = $loadBalancer;
		$this->entityIdParser = $entityIdParser;
		$this->entityIdComposer = $entityIdComposer;
	}

	/**
	 * @see EntityPerPage::addEntityPage
	 *
	 * @param EntityId $entityId
	 * @param int $pageId
	 *
	 * @throws InvalidArgumentException
	 */
	public function addEntityPage( EntityId $entityId, $pageId ) {
		$this->addRow( $entityId, $pageId );
	}

	/**
	 * @see EntityPerPage::addEntityPage
	 *
	 * @param EntityId $entityId
	 * @param int $pageId
	 * @param EntityId $targetId
	 */
	public function addRedirectPage( EntityId $entityId, $pageId, EntityId $targetId ) {
		$this->addRow( $entityId, $pageId, $targetId );
	}

	/**
	 * @see EntityPerPage::addEntityPage
	 *
	 * @param EntityId $entityId
	 * @param int $pageId
	 * @param EntityId|null $targetId
	 *
	 * @throws InvalidArgumentException
	 */
	private function addRow( EntityId $entityId, $pageId, EntityId $targetId = null ) {
		if ( !( $entityId instanceof Int32EntityId ) ) {
			throw new InvalidArgumentException( '$entityId must be an Int32EntityId' );
		}
		if ( !is_int( $pageId ) ) {
			throw new InvalidArgumentException( '$pageId must be an int' );
		}
		if ( $pageId <= 0 ) {
			throw new InvalidArgumentException( '$pageId must be greater than 0' );
		}

		$redirectTarget = $targetId ? $targetId->getSerialization() : null;

		$values = array(
			'epp_entity_id' => $entityId->getNumericId(),
			'epp_entity_type' => $entityId->getEntityType(),
			'epp_page_id' => $pageId,
			'epp_redirect_target' => $redirectTarget
		);

		if ( !$this->rowExists( $values ) ) {
			$this->addRowInternal( $values );
		}
	}

	/**
	 * @param array $row
	 */
	private function addRowInternal( array $row ) {
		$dbw = $this->loadBalancer->getConnection( DB_MASTER );
		// Try to add the row and see it it conflicts on (id,type) or (page_id).
		// With innodb, this only sets IX gap and SH/EX record locks. This is useful for new
		// page/entity creation, as just doing DELETE+INSERT would put SH gap locks on the range
		// [highest page/entity ID, +infinity). Aside from serializing page creation, any case
		// where 2+ such transactions made it past DELETE would deadlock on IX/SH gap locks.
		$dbw->insert(
			'wb_entity_per_page',
			$row,
			__METHOD__,
			[ 'IGNORE' ]
		);
		if ( $dbw->affectedRows() > 0 ) {
			return; // no conflicts
		}

		// Delete the conflicting rows...
		$conflictConds = $this->getConflictingRowConditions( $row );
		$where = $dbw->makeList( $conflictConds, LIST_OR );
		$dbw->delete(
			'wb_entity_per_page',
			$where,
			__METHOD__
		);
		// ...and try to insert again
		$dbw->insert(
			'wb_entity_per_page',
			$row,
			__METHOD__
		);
	}

	/**
	 * @param array $row
	 *
	 * @return bool
	 */
	private function rowExists( array $row ) {
		$dbw = $this->loadBalancer->getConnection( DB_MASTER );

		return $dbw->selectRow( 'wb_entity_per_page', '1', $row, __METHOD__ ) !== false;
	}

	private function getConflictingRowConditions( array $values ) {
		$dbw = $this->loadBalancer->getConnection( DB_MASTER );
		$indexes = $this->getUniqueIndexes();

		$conditions = array();

		foreach ( $indexes as $indexFields ) {
			$indexValues = array_intersect_key( $values, array_flip( $indexFields ) );
			$conditions[] = $dbw->makeList( $indexValues, LIST_AND );
		}

		return $conditions;
	}

	/**
	 * Returns a list of unique indexes, each index being described by a list of fields.
	 * This is intended for use with DatabaseBase::replace().
	 *
	 * @return array[]
	 */
	private function getUniqueIndexes() {
		// CREATE UNIQUE INDEX /*i*/wb_epp_entity ON /*_*/wb_entity_per_page (epp_entity_id, epp_entity_type);
		// CREATE UNIQUE INDEX /*i*/wb_epp_page ON /*_*/wb_entity_per_page (epp_page_id);

		return array(
			'wb_epp_entity' => array( 'epp_entity_id', 'epp_entity_type' ),
			'wb_epp_page' => array( 'epp_page_id' ),
		);
	}

	/**
	 * @see EntityPerPage::deleteEntityPage
	 *
	 * @param EntityId $entityId
	 * @param int $pageId
	 *
	 * @return boolean Success indicator
	 */
	public function deleteEntityPage( EntityId $entityId, $pageId ) {
		$this->deleteEntity( $entityId );
	}

	/**
	 * @since 0.4
	 *
	 * @param EntityId $entityId
	 *
	 * @return boolean
	 */
	public function deleteEntity( EntityId $entityId ) {
		if ( !( $entityId instanceof Int32EntityId ) ) {
			throw new InvalidArgumentException( '$entityId must be an Int32EntityId' );
		}

		$dbw = $this->loadBalancer->getConnection( DB_MASTER );

		return $dbw->delete(
			'wb_entity_per_page',
			array(
				'epp_entity_id' => $entityId->getNumericId(),
				'epp_entity_type' => $entityId->getEntityType()
			),
			__METHOD__
		);
	}

	/**
	 * @see EntityPerPage::clear
	 *
	 * @since 0.2
	 *
	 * @return boolean Success indicator
	 */
	public function clear() {
		return $this->loadBalancer->getConnection( DB_MASTER )->delete( 'wb_entity_per_page', '*', __METHOD__ );
	}

	/**
	 * @see EntityPerPage::getEntitiesWithoutTerm
	 *
	 * @since 0.2
	 *
	 * @param string $termType Can be any member of the TermIndexEntry::TYPE_ enum
	 * @param string|null $language Restrict the search for one language. By default the search is done for all languages.
	 * @param string|null $entityType Can be "item", "property" or "query". By default the search is done for all entities.
	 * @param integer $limit Limit of the query.
	 * @param integer $offset Offset of the query.
	 *
	 * @return EntityId[]
	 */
	public function getEntitiesWithoutTerm( $termType, $language = null, $entityType = null, $limit = 50, $offset = 0 ) {
		$dbr = wfGetDB( DB_SLAVE );
		$conditions = array(
			'term_entity_type IS NULL'
		);

		$joinConditions = 'term_entity_id = epp_entity_id' .
			' AND term_entity_type = epp_entity_type' .
			' AND term_type = ' . $dbr->addQuotes( $termType ) .
			' AND epp_redirect_target IS NULL';

		if ( $language !== null ) {
			$joinConditions .= ' AND term_language = ' . $dbr->addQuotes( $language );
		}

		if ( $entityType !== null ) {
			$conditions[] = 'epp_entity_type = ' . $dbr->addQuotes( $entityType );
		}

		$rows = $dbr->select(
			array( 'wb_entity_per_page', 'wb_terms' ),
			array(
				'entity_id' => 'epp_entity_id',
				'entity_type' => 'epp_entity_type',
			),
			$conditions,
			__METHOD__,
			array(
				'OFFSET' => $offset,
				'LIMIT' => $limit,
				'ORDER BY' => 'epp_page_id DESC'
			),
			array( 'wb_terms' => array( 'LEFT JOIN', $joinConditions ) )
		);

		return $this->getEntityIdsFromRows( $rows );
	}

	protected function getEntityIdsFromRows( $rows ) {
		$entities = array();

		foreach ( $rows as $row ) {
			try {
				$entities[] = $this->entityIdComposer->composeEntityId( $row->entity_type, $row->entity_id );
			} catch ( InvalidArgumentException $ex ) {
				wfLogWarning( 'Unsupported entity type "' . $row->entity_type . '"' );
			}
		}

		return $entities;
	}

	/**
	 * @see EntityPerPage::listEntities
	 *
	 * @param null|string $entityType The entity type to look for.
	 * @param int $limit The maximum number of IDs to return.
	 * @param EntityId|null $after Only return entities with IDs greater than this.
	 * @param string $redirects A XXX_REDIRECTS constant (default is NO_REDIRECTS).
	 *
	 * @throws InvalidArgumentException
	 * @return EntityId[]
	 */
	public function listEntities( $entityType, $limit, EntityId $after = null, $redirects = self::NO_REDIRECTS ) {
		if ( $entityType === null ) {
			$where = array();
			//NOTE: needs to be id/type, not type/id, according to the definition of the relevant
			//      index in wikibase.sql: wb_entity_per_page (epp_entity_id, epp_entity_type);
			$orderBy = array( 'epp_entity_id', 'epp_entity_type' );
		} elseif ( !is_string( $entityType ) ) {
			throw new InvalidArgumentException( '$entityType must be a string (or null)' );
		} else {
			$where = array( 'epp_entity_type' => $entityType );
			// NOTE: If the type is fixed, don't use the type in the order;
			// before changing this, check index usage.
			$orderBy = array( 'epp_entity_id' );
		}

		if ( $redirects === self::NO_REDIRECTS ) {
			$where[] = 'epp_redirect_target IS NULL';
		} elseif ( $redirects === self::ONLY_REDIRECTS ) {
			$where[] = 'epp_redirect_target IS NOT NULL';
		}

		if ( !is_int( $limit ) || $limit < 1 ) {
			throw new InvalidArgumentException( '$limit must be a positive integer' );
		}

		$dbr = wfGetDB( DB_SLAVE );

		if ( $after ) {
			if ( !( $after instanceof Int32EntityId ) ) {
				throw new InvalidArgumentException( '$after must be an Int32EntityId' );
			}

			$numericId = (int)$after->getNumericId();

			if ( $entityType === null ) {
				// Ugly. About time we switch to qualified, string based IDs!
				// NOTE: this must be consistent with the sort order, see above!
				$where[] = '( ( epp_entity_type > ' . $dbr->addQuotes( $after->getEntityType() )
					. ' AND epp_entity_id = ' . $numericId . ' )'
					. ' OR epp_entity_id > ' . $numericId . ' )';
			} else {
				$where[] = 'epp_entity_id > ' . $numericId;
			}
		}

		$rows = $dbr->select(
			'wb_entity_per_page',
			array( 'entity_type' => 'epp_entity_type', 'entity_id' => 'epp_entity_id' ),
			$where,
			__METHOD__,
			array(
				'ORDER BY' => $orderBy,
				// MySQL tends to use the epp_redirect_target key which has a very low selectivity
				'USE INDEX' => 'wb_epp_entity',
				'LIMIT' => $limit
			)
		);

		$ids = $this->getEntityIdsFromRows( $rows );
		return $ids;
	}

}
