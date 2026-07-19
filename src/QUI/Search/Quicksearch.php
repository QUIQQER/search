<?php

/**
 * This file contains \QUI\Search\Quicksearch
 */

namespace QUI\Search;

use Doctrine\DBAL\Query\QueryBuilder;
use QUI;
use QUI\Database\Exception;
use QUI\ExceptionStack;
use QUI\Projects\Project;
use QUI\Search;
use QUI\Search\Items\CustomSearchItem;
use QUI\Utils\Doctrine;
use QUI\Utils\Security\Orthos;

use function is_array;
use function json_encode;

/**
 * Quicksearch Manager
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class Quicksearch extends QUI\QDOM
{
    /**
     * Search
     */

    /**
     * Constructor
     *
     * @param array $params - Attributes
     */
    public function __construct(array $params = [])
    {
        // defaults
        $this->setAttributes([
            'siteTypes' => false   // restrict search to certain site types
        ]);

        $this->setAttributes($params);
    }

    /**
     * Search something in a project
     *
     * @param string $str
     * @param Project $Project
     * @param array $params - Query params
     *                        $params['limit'] = default: 10
     *
     * @return array array(
     *        'list'   => array list of results
     *        'count'  => count of results
     * )
     */
    public function search(string $str, Project $Project, array $params = []): array
    {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        if (!isset($params['limit'])) {
            $params['limit'] = 10;
        }

        // restrict search to certain site types
        $siteTypes = $this->getAttribute('siteTypes');

        if ($siteTypes) {
            if (!is_array($siteTypes)) {
                $siteTypes = [$siteTypes];
            }
        } else {
            $siteTypes = [];
        }

        $group = !isset($params['group']) || $params['group'] !== false;
        $BaseQuery = $this->createSearchQuery($table, '%' . $str . '%', $siteTypes);
        $CountQuery = clone $BaseQuery;

        if ($group) {
            $GroupedIdsQuery = clone $BaseQuery;
            $GroupedIdsQuery
                ->select('MIN(quicksearch.' . Doctrine::quoteIdentifier('id') . ')')
                ->groupBy('quicksearch.' . Doctrine::quoteIdentifier('data'));

            $QueryBuilder = QUI::getQueryBuilder();
            $QueryBuilder
                ->select('quicksearch.*')
                ->from(Doctrine::quoteIdentifier($table), 'quicksearch')
                ->where(
                    'quicksearch.' . Doctrine::quoteIdentifier('id')
                    . ' IN (' . $GroupedIdsQuery->getSQL() . ')'
                )
                ->setParameters(
                    $GroupedIdsQuery->getParameters(),
                    $GroupedIdsQuery->getParameterTypes()
                );

            $CountQuery->select(
                'COUNT(DISTINCT quicksearch.' . Doctrine::quoteIdentifier('data') . ')'
            );
        } else {
            $QueryBuilder = clone $BaseQuery;
            $QueryBuilder->select('quicksearch.*');
            $CountQuery->select('COUNT(*)');
        }

        $QueryBuilder->orderBy('quicksearch.' . Doctrine::quoteIdentifier('id'));
        Doctrine::applyLimit($QueryBuilder, $params['limit']);

        return [
            'list' => Database::fetchAllAssociative($QueryBuilder),
            'count' => Database::fetchOne($CountQuery)
        ];
    }

    /**
     * @param list<string> $siteTypes
     */
    private function createSearchQuery(string $table, string $search, array $siteTypes): QueryBuilder
    {
        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->from(Doctrine::quoteIdentifier($table), 'quicksearch')
            ->where(
                $QueryBuilder->expr()->like(
                    'quicksearch.' . Doctrine::quoteIdentifier('data'),
                    ':search'
                )
            )
            ->setParameter('search', $search);

        if (empty($siteTypes)) {
            return $QueryBuilder;
        }

        $siteTypeExpressions = [];

        foreach ($siteTypes as $index => $siteType) {
            $parameter = 'siteType' . $index;
            $siteTypeExpressions[] = $QueryBuilder->expr()->like(
                'quicksearch.' . Doctrine::quoteIdentifier('siteType'),
                ':' . $parameter
            );
            $QueryBuilder->setParameter($parameter, $siteType);
        }

        $QueryBuilder->andWhere($QueryBuilder->expr()->or(...$siteTypeExpressions));

        return $QueryBuilder;
    }

    /**
     * Creation
     */

    /**
     * Add entries to the quick search table
     * Removes similar entries, with same siteId and siteParams
     *
     * @param Project $Project
     * @param integer $siteId
     * @param array $data - data array -> every array entry is a data entry
     * @param array $siteParams - optional; Parameter for the site link
     * @throws Exception
     * @throws ExceptionStack
     */
    public static function setEntries(
        Project $Project,
        int $siteId,
        array $data = [],
        array $siteParams = []
    ): void {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        if (!$siteId) {
            return;
        }

        if (empty($data)) {
            return;
        }

        // cannot set entry for inactive sites!
        try {
            $Site = $Project->get($siteId);

            if (!$Site->getAttribute('active')) {
                return;
            }
        } catch (\Exception) {
            return;
        }

        // clear the entries
        self::removeEntries($Project, $siteId);

        // url params
        $siteUrlParams = [];

        // site params
        if (!empty($siteParams)) {
            foreach ($siteParams as $key => $value) {
                $key = Orthos::clearMySQL($key, false);
                $value = Orthos::clearMySQL($value, false);

                $siteUrlParams[$key] = $value;
            }
        }

        $urlParameter = json_encode($siteUrlParams);

        // data
        foreach ($data as $dataEntry) {
            Database::insert($table, [
                'siteId' => $siteId,
                'urlParameter' => $urlParameter,
                'data' => Orthos::clearMySQL($dataEntry, false),
                'siteType' => $Site->getAttribute('type')
            ]);
        }

        QUI::getEvents()->fireEvent(
            'searchQuicksearchSetEntry',
            [$Project, $siteId, $siteParams]
        );
    }

    /**
     * Add an entry to the quick search table
     *
     * @param Project $Project
     * @param integer $siteId
     * @param string $data
     * @param array $siteParams
     * @throws Exception
     */
    public static function addEntry(
        Project $Project,
        int $siteId,
        string $data,
        array $siteParams = []
    ): void {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        if (!$siteId) {
            return;
        }

        if (empty($data)) {
            return;
        }

        // cannot set entry for inactive sites!
        try {
            $Site = $Project->get($siteId);

            if (!$Site->getAttribute('active')) {
                return;
            }
        } catch (\Exception) {
            return;
        }

        $urlParameter = json_encode($siteParams);

        // check if entry exists
        if (self::existsEntry($Project, $siteId, $data, $siteParams)) {
            Database::update(
                $table,
                [
                    'rights' => null, // @todo auf was richtiges setzen, wenn der parameter implementiert wird
                    'icon' => null,  // @todo auf was richtiges setzen, wenn der parameter implementiert wird
                    'siteType' => $Site->getAttribute('type')
                ],
                [
                    'siteId' => $siteId,
                    'urlParameter' => $urlParameter,
                    'data' => $data
                ]
            );

            return;
        }

        Database::insert($table, [
            'siteId' => $siteId,
            'urlParameter' => $urlParameter,
            'data' => $data,
            'siteType' => $Site->getAttribute('type')
        ]);
    }

    /**
     * Remove a search entry
     *
     * @param Project $Project
     * @param integer $siteId
     * @param array $siteParams
     * @throws Exception
     */
    public static function removeEntries(
        Project $Project,
        int $siteId,
        array $siteParams = []
    ): void {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        if (!$siteId) {
            return;
        }

        Database::delete($table, [
            'siteId' => $siteId,
            'urlParameter' => json_encode($siteParams)
        ]);
    }

    /**
     * Return a fulltext entry
     *
     * @param Project $Project
     * @param integer $siteId
     * @param array $siteParams
     *
     * @return array
     *
     * @throws QUI\Exception
     */
    public static function getEntry(
        Project $Project,
        int $siteId,
        array $siteParams = []
    ): array {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        $urlParameter = json_encode($siteParams);

        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->select('*')
            ->from(Doctrine::quoteIdentifier($table))
            ->where($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('siteId'), ':siteId'))
            ->andWhere($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('urlParameter'), ':urlParameter'))
            ->setParameter('siteId', $siteId)
            ->setParameter('urlParameter', $urlParameter)
            ->setMaxResults(1);
        $result = Database::fetchAssociative($QueryBuilder);

        if ($result === false) {
            throw new QUI\Exception(
                'Quicksearch entry not exists'
            );
        }

        return $result;
    }

    /**
     * Check if a quick search entry already exists
     *
     * @param Project $Project
     * @param int $siteId
     * @param string $data
     * @param array $siteParams
     * @return bool
     * @throws Exception
     */
    public static function existsEntry(
        Project $Project,
        int $siteId,
        string $data,
        array $siteParams = []
    ): bool {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        $urlParameter = json_encode($siteParams);

        $QueryBuilder = QUI::getQueryBuilder();

        $QueryBuilder
            ->select('COUNT(*)')
            ->from(Doctrine::quoteIdentifier($table))
            ->where($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('siteId'), ':siteId'))
            ->andWhere($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('data'), ':data'))
            ->andWhere($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('urlParameter'), ':urlParameter'))
            ->setParameter('siteId', $siteId)
            ->setParameter('data', $data)
            ->setParameter('urlParameter', $urlParameter);

        return (bool)Database::fetchOne($QueryBuilder);
    }

    // region Custom entries

    /**
     * Edit a Fulltext search custom entry
     *
     * @param Project $Project
     * @param CustomSearchItem $CustomFulltextItem
     * @param array $searchStrings - every item represents a searchable string
     */
    public static function setCustomEntry(
        Project $Project,
        CustomSearchItem $CustomFulltextItem,
        array $searchStrings
    ): void {
        $table = QUI::getDBProjectTableName(Search::TABLE_SEARCH_QUICK, $Project);

        $baseEntryData = [
            'custom_id' => $CustomFulltextItem->getId(),
            'custom_data' => json_encode($CustomFulltextItem->toArray()),
            'origin' => $CustomFulltextItem->getOrigin(),
            'siteType' => 'custom',
            'icon' => $CustomFulltextItem->getAttribute('icon') ?: null
        ];

        foreach ($searchStrings as $searchString) {
            if (empty($searchString)) {
                continue;
            }

            try {
                if (self::existsCustomEntry($Project, $CustomFulltextItem, $searchString)) {
                    Database::update(
                        $table,
                        [
                            'icon' => $baseEntryData['icon'],
                            'custom_data' => $baseEntryData['custom_data']
                        ],
                        [
                            'custom_id' => $CustomFulltextItem->getId(),
                            'origin' => $CustomFulltextItem->getOrigin(),
                            'data' => $searchString
                        ]
                    );
                } else {
                    $baseEntryData['data'] = $searchString;
                    Database::insert($table, $baseEntryData);
                }
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }
    }

    /**
     * Check if a custom entry already exists
     *
     * @param Project $Project
     * @param CustomSearchItem $CustomFulltextItem
     * @param string $searchString
     * @return bool
     *
     * @throws QUI\Exception
     */
    public static function existsCustomEntry(
        Project $Project,
        CustomSearchItem $CustomFulltextItem,
        string $searchString
    ): bool {
        $table = QUI::getDBProjectTableName(Search::TABLE_SEARCH_QUICK, $Project);

        $QueryBuilder = QUI::getQueryBuilder();

        $QueryBuilder
            ->select('COUNT(*)')
            ->from(Doctrine::quoteIdentifier($table))
            ->where($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('custom_id'), ':customId'))
            ->andWhere($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('origin'), ':origin'))
            ->andWhere($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('data'), ':data'))
            ->setParameter('customId', $CustomFulltextItem->getId())
            ->setParameter('origin', $CustomFulltextItem->getOrigin())
            ->setParameter('data', $searchString);

        return (bool)Database::fetchOne($QueryBuilder);
    }

    /**
     * Removes all entries specific to CustomSearchItem
     *
     * @param Project $Project
     * @param CustomSearchItem $CustomSearchItem
     * @return void
     * @throws Exception
     */
    public static function removeCustomEntries(Project $Project, CustomSearchItem $CustomSearchItem): void
    {
        Database::delete(
            QUI::getDBProjectTableName(Search::TABLE_SEARCH_QUICK, $Project),
            [
                'siteType' => 'custom',
                'custom_id' => $CustomSearchItem->getId(),
                'origin' => $CustomSearchItem->getOrigin()
            ]
        );
    }

    // endregion

    /**
     * Clear a complete fulltext search table
     *
     * @param Project $Project
     */
    public static function clearSearchTable(Project $Project): void
    {
        Database::truncate(
            QUI::getDBProjectTableName(Search::TABLE_SEARCH_QUICK, $Project)
        );
    }
}
