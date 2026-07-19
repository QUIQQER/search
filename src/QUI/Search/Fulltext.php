<?php

/**
 * This file contains \QUI\Search\Fulltext
 *
 * @todo Search-Entry als Objekt umsetzen
 */

namespace QUI\Search;

use DOMElement;
use DOMXPath;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use QUI;
use QUI\Database\Exception;
use QUI\ExceptionStack;
use QUI\Projects\Project;
use QUI\Projects\Site\Edit as SiteEdit;
use QUI\Search;
use QUI\Search\Items\CustomSearchItem;
use QUI\System\Log;
use QUI\Utils\Doctrine;
use QUI\Utils\Security\Orthos;

use function array_filter;
use function array_flip;
use function array_keys;
use function array_merge;
use function count;
use function explode;
use function file_exists;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function key;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function preg_split;
use function set_time_limit;
use function str_replace;
use function strtoupper;
use function strtotime;
use function trim;

/**
 * Fulltextsearch Manager
 *
 * @package QUI\Search\Fulltext
 * @author  www.pcsg.de (Henning Leutz) <info@pcsg.de>
 */
class Fulltext extends QUI\QDOM
{
    private const RELEVANCE_WEIGHTS = [
        'name' => 8,
        'title' => 10,
        'short' => 5,
        'data' => 3
    ];

    /**
     * Constructor
     *
     * @param array $params - Attributes
     */
    public function __construct(array $params = [])
    {
        // defaults
        $this->setAttributes([
            'Project' => false,   // Project
            'limit' => 10,      // limit of results
            'fields' => false,   // array list of fields
            'fieldConstraints' => [], // restrict certain search fields to specific values
            'searchtype' => 'OR',    // search type: OR / AND
            'datatypes' => false,   // restrict search to certain site types
            'relevanceSearch' => true,     // use relevance search (if search string has minimum length),
            'orderFields' => []   // fields that the search results are ordered by (ordered by priority)
        ]);

        $this->setAttributes($params);
    }

    /**
     * Search
     */

    /**
     * Search something in a project
     *
     * @param string $str - search string
     *
     * @return array array(
     *        'list'   => array list of results
     *        'count'  => count of results
     * )
     *
     * @throws QUI\Exception
     */
    public function search(string $str = ''): array
    {
        $str = $this->sanitizeSearchString($str);
        $Project = $this->getAttribute('Project');
        $attrLimit = $this->getAttribute('limit');
        $attrFields = $this->getAttribute('fields');

        if (!$Project instanceof Project) {
            $Project = QUI::getProjectManager()->get();
        }

        if (!$attrLimit) {
            $attrLimit = 10;
        }

        $strParts = explode(' ', $str);

        foreach ($strParts as $key => $part) {
            $strParts[$key] = '*' . $part . '*';
        }

        $search = match ($this->getAttribute('searchtype')) {
            'AND', 'and' => '+' . implode(' +', $strParts),
            default => implode(' ', $strParts),
        };


        // fields
        $fields = [];
        $fieldList = self::getFieldList();

        $availableFields = [];
        $fulltextAvailableFields = [];

        // filter
        foreach ($fieldList as $entry) {
            $type = mb_strtolower($entry['type']);
            $field = Orthos::clearNoneCharacters($entry['field'], ['_']);

            if (mb_strpos($type, 'varchar') !== false || mb_strpos($type, 'text') !== false) {
                $availableFields[] = $field;
            }

            if (!empty($entry['fulltext'])) {
                $fulltextAvailableFields[] = $field;
            }
        }

        if (!$attrFields || !is_array($attrFields)) {
            $fields = $availableFields;
        } else {
            $availableFieldsTmp = array_flip($availableFields);

            foreach ($attrFields as $field) {
                if (isset($availableFieldsTmp[$field])) {
                    $fields[] = Orthos::clearNoneCharacters($field);
                }
            }
        }

        if (empty($fields)) {
            $fields = $availableFields;
        }

        foreach ($fields as $key => $value) {
            $fields[$key] = Orthos::clearNoneCharacters($value, ['_']);
        }

        $fulltextFields = [];
        $fulltextAvailableFieldsTmp = array_flip($fulltextAvailableFields);

        foreach ($fields as $field) {
            if (isset($fulltextAvailableFieldsTmp[$field])) {
                $fulltextFields[] = $field;
            }
        }

        // sql
        $Connection = QUI::getDataBaseConnection();
        $table = QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project);
        $binds = [];

        // relevance match
        $relevanceMatch = [];
        $whereMatch = [];
        $relevanceSum = 0;

        foreach ($fulltextFields as $field) {
            $matchCount = self::RELEVANCE_WEIGHTS[$field] ?? 9;
            $field = Doctrine::quoteIdentifier($field);

            $relevanceMatch[] = "MATCH($field) AGAINST (:search IN BOOLEAN MODE) * $matchCount";
            $whereMatch[] = "MATCH($field) AGAINST (:search IN BOOLEAN MODE)";
            $relevanceSum = $relevanceSum + $matchCount;
        }

        $relevanceMatch = implode(' + ', $relevanceMatch);
        $whereMatch = implode(' OR ', $whereMatch);

        // restrict search to certain site types
        $datatypes = $this->getAttribute('datatypes');
        $datatypeQuery = '';

        if ($datatypes) {
            if (!is_array($datatypes)) {
                $datatypes = [$datatypes];
            }

            $datatypeExpressions = [];

            for ($i = 0, $len = count($datatypes); $i < $len; $i++) {
                $datatypeExpressions[] = Doctrine::quoteIdentifier('datatype') . ' LIKE :type' . $i;
                $binds['type' . $i] = $datatypes[$i];
            }

            $datatypeQuery = '(' . implode(' OR ', $datatypeExpressions) . ')';
        }

        // field constraints
        $fieldConstraints = $this->getAttribute('fieldConstraints');
        $whereFieldConstraints = '';

        if (!empty($fieldConstraints)) {
            $fieldConstraintsEntries = [];
            $i = 0;

            foreach ($fieldConstraints as $field => $constraintValues) {
                if (!in_array($field, $availableFields)) {
                    continue;
                }

                if (is_string($constraintValues)) {
                    $constraintValues = [$constraintValues];
                }

                $constraintEntriesOr = [];

                foreach ($constraintValues as $value) {
                    if (empty($value)) {
                        continue;
                    }

                    if (
                        is_array($value)
                        && !empty($value['value'])
                        && !empty($value['type'])
                    ) {
                        if ($value['type'] === 'LIKE') {
                            $constraintEntriesOr[] = Doctrine::quoteIdentifier($field) . ' LIKE :constraint' . $i;
                            $binds['constraint' . $i] = '%' . $value['value'] . '%';
                        }
                    } else {
                        $constraintEntriesOr[] = Doctrine::quoteIdentifier($field) . ' = :constraint' . $i;
                        $binds['constraint' . $i] = $value;
                    }

                    $i++;
                }

                if (!empty($constraintEntriesOr)) {
                    $fieldConstraintsEntries[] = "(" . implode(" OR ", $constraintEntriesOr) . ")";
                }
            }

            if (!empty($fieldConstraintsEntries)) {
                $whereFieldConstraints = '(' . implode(" AND ", $fieldConstraintsEntries) . ')';
            }
        }

        $minWordLength = QUI::getPackage('quiqqer/search')
            ->getConfig()
            ->get('search', 'booleanSearchMaxLength');

        // fallback
        if (!$minWordLength) {
            $minWordLength = 3;
        }

        $match = str_replace(['*', '+'], '', $search);

        // order Fields
        $orderFields = $this->getAttribute('orderFields');
        $order = [];

        if (is_array($orderFields) && !empty($orderFields)) {
            $sortableFields = [];

            foreach ($fieldList as $entry) {
                $field = Orthos::clearNoneCharacters($entry['field'], ['_']);
                $sortableFields[$field] = true;
            }

            $validatedOrderFields = [];

            foreach ($orderFields as $orderField) {
                if (!is_string($orderField)) {
                    continue;
                }

                $orderFieldParts = preg_split('/\s+/', trim($orderField));

                if (
                    $orderFieldParts === false
                    || count($orderFieldParts) < 1
                    || count($orderFieldParts) > 2
                ) {
                    continue;
                }

                $orderField = $orderFieldParts[0];

                if (!isset($sortableFields[$orderField])) {
                    continue;
                }

                $direction = '';

                if (isset($orderFieldParts[1])) {
                    $direction = strtoupper($orderFieldParts[1]);

                    if (!in_array($direction, ['ASC', 'DESC'], true)) {
                        continue;
                    }
                }

                $validatedOrderFields[] = [
                    'field' => Doctrine::quoteIdentifier($orderField),
                    'direction' => $direction
                ];

                if (!in_array($orderField, $availableFields)) {
                    $availableFields[] = $orderField;
                }
            }

            $order = $validatedOrderFields;
        }

        // query
        if (is_int(key($availableFields))) {
            $selectedFields = $availableFields;
        } else {
            $selectedFields = array_keys($availableFields);
        }

        // Relevance search (MATCH.. AGAINST)
        $searchMode = 'like';

        if (
            $this->getAttribute('relevanceSearch')
            && mb_strlen($match) >= $minWordLength
            && !empty($fulltextFields)
            && $Connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
        ) {
            // filter $selectedFields
            $selectedFields = array_filter($selectedFields, function ($v) {
                return !in_array($v, ['urlParameter', 'siteId']);
            });

            $selectedFieldsSql = array_map(
                static fn(string $field): string => Doctrine::quoteIdentifier($field),
                $selectedFields
            );

            $groupFields = array_merge([
                Doctrine::quoteIdentifier('urlParameter'),
                Doctrine::quoteIdentifier('siteId'),
                Doctrine::quoteIdentifier('custom_id'),
                Doctrine::quoteIdentifier('custom_data'),
                Doctrine::quoteIdentifier('origin')
            ], $selectedFieldsSql);

            $query = QUI::getQueryBuilder();
            $query
                ->select(
                    Doctrine::quoteIdentifier('siteId'),
                    Doctrine::quoteIdentifier('urlParameter'),
                    Doctrine::quoteIdentifier('custom_id'),
                    Doctrine::quoteIdentifier('custom_data'),
                    Doctrine::quoteIdentifier('origin'),
                    "100 / $relevanceSum * ($relevanceMatch) AS relevance",
                    ...$selectedFieldsSql
                )
                ->from(Doctrine::quoteIdentifier($table))
                ->where("($whereMatch)")
                ->groupBy(...$groupFields);

            $this->applySearchFilters($query, $datatypeQuery, $whereFieldConstraints, $binds);
            $this->applySearchOrder($query, $order, 'relevance', 'DESC');

            $search = str_replace('*', '', $search);
            $query->setParameter('search', $search);
            $searchMode = 'fulltext';
        } else {
            $query = $this->buildLikeQuery(
                $selectedFields,
                $str,
                $fields,
                $table,
                $whereFieldConstraints,
                $datatypeQuery,
                $order,
                $binds
            );
        }

        Log::addDebug(
            self::class . '::search() fields=' . json_encode($fields)
            . ' relevance=' . ($this->getAttribute('relevanceSearch') ? '1' : '0')
            . ' mode=' . $searchMode
        );

        try {
            return $this->executeSearchQuery($query, $attrLimit);
        } catch (DriverException $Exception) {
            if ($searchMode !== 'fulltext' || $Exception->getCode() !== 1191) {
                throw Database::createException($Exception);
            }

            $query = $this->buildLikeQuery(
                $selectedFields,
                $str,
                $fields,
                $table,
                $whereFieldConstraints,
                $datatypeQuery,
                $order,
                $binds
            );

            Log::addWarning(
                self::class . '::search() FULLTEXT unavailable (1191), using LIKE fallback'
            );

            try {
                return $this->executeSearchQuery($query, $attrLimit);
            } catch (\Doctrine\DBAL\Exception $Exception) {
                throw Database::createException($Exception);
            }
        } catch (\Doctrine\DBAL\Exception $Exception) {
            throw Database::createException($Exception);
        }
    }

    /**
     * Sanitizes a search string
     *
     * @param string $str
     * @return string - sanitized string
     */
    protected function sanitizeSearchString(string $str): string
    {
        return Utils::sanitizeSearchString($str);
    }

    /**
     * Build LIKE search query and binds
     *
     * @param array $selectedFields
     * @param string $str
     * @param array $fields
     * @param string $table
     * @param string $whereFieldConstraints
     * @param string $datatypeQuery
     * @param list<array{field: string, direction: string}> $order
     * @param array $binds
     */
    private function buildLikeQuery(
        array $selectedFields,
        string $str,
        array $fields,
        string $table,
        string $whereFieldConstraints,
        string $datatypeQuery,
        array $order,
        array $binds
    ): QueryBuilder {
        $where = [];

        $searchFields = [
            'name',
            'title',
            'short',
            'data'
        ];

        $searchFields = array_merge($searchFields, $fields);
        $searchTerms = explode(' ', $str);
        $likeBinds = $binds;

        foreach ($searchTerms as $k => $searchTerm) {
            $whereOr = [];

            foreach ($searchFields as $field) {
                $whereOr[] = Doctrine::quoteIdentifier($field) . ' LIKE :search' . $k;
            }

            $likeBinds['search' . $k] = '%' . $searchTerm . '%';

            $where[] = "(" . implode(" OR ", $whereOr) . ")";
        }

        if ($this->getAttribute('searchtype') === Search\Controls\Search::SEARCH_TYPE_AND) {
            $where = implode(" AND ", $where);
        } else {
            $where = implode(" OR ", $where);
        }

        $resultFields = array_filter($selectedFields, function ($v) {
            return !in_array($v, ['e_date', 'urlParameter', 'siteId']);
        });

        $resultFields = array_map(
            static fn(string $field): string => Doctrine::quoteIdentifier($field),
            $resultFields
        );

        $groupFields = array_merge([
            Doctrine::quoteIdentifier('urlParameter'),
            Doctrine::quoteIdentifier('siteId'),
            Doctrine::quoteIdentifier('e_date'),
            Doctrine::quoteIdentifier('custom_id'),
            Doctrine::quoteIdentifier('custom_data'),
            Doctrine::quoteIdentifier('origin')
        ], $resultFields);

        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->select(
                Doctrine::quoteIdentifier('e_date'),
                Doctrine::quoteIdentifier('urlParameter'),
                Doctrine::quoteIdentifier('siteId'),
                Doctrine::quoteIdentifier('custom_id'),
                Doctrine::quoteIdentifier('custom_data'),
                Doctrine::quoteIdentifier('origin'),
                ...$resultFields
            )
            ->from(Doctrine::quoteIdentifier($table))
            ->where("($where)")
            ->groupBy(...$groupFields);

        $this->applySearchFilters($QueryBuilder, $datatypeQuery, $whereFieldConstraints, $likeBinds);
        $this->applySearchOrder($QueryBuilder, $order, Doctrine::quoteIdentifier('e_date'), 'DESC');

        return $QueryBuilder;
    }

    /**
     * @param array<string, mixed> $binds
     */
    private function applySearchFilters(
        QueryBuilder $QueryBuilder,
        string $datatypeQuery,
        string $whereFieldConstraints,
        array $binds
    ): void {
        if ($whereFieldConstraints !== '') {
            $QueryBuilder->andWhere($whereFieldConstraints);
        }

        if ($datatypeQuery !== '') {
            $QueryBuilder->andWhere($datatypeQuery);
        }

        foreach ($binds as $parameter => $value) {
            $QueryBuilder->setParameter($parameter, $value);
        }
    }

    /**
     * @param list<array{field: string, direction: string}> $order
     */
    private function applySearchOrder(
        QueryBuilder $QueryBuilder,
        array $order,
        string $fallbackField,
        string $fallbackDirection
    ): void {
        foreach ($order as $orderEntry) {
            $QueryBuilder->addOrderBy(
                $orderEntry['field'],
                $orderEntry['direction'] ?: null
            );
        }

        $QueryBuilder->addOrderBy($fallbackField, $fallbackDirection);
    }

    private function executeSearchQuery(QueryBuilder $QueryBuilder, mixed $limit): array
    {
        $ListQuery = clone $QueryBuilder;
        Doctrine::applyLimit($ListQuery, $limit);

        $CountSourceQuery = clone $QueryBuilder;
        $CountSourceQuery->resetOrderBy();

        $CountQuery = QUI::getQueryBuilder();
        $CountQuery
            ->select('COUNT(*)')
            ->from('(' . $CountSourceQuery->getSQL() . ')', 'searchResults')
            ->setParameters(
                $CountSourceQuery->getParameters(),
                $CountSourceQuery->getParameterTypes()
            );

        return [
            'list' => $ListQuery->executeQuery()->fetchAllAssociative(),
            'count' => $CountQuery->executeQuery()->fetchOne()
        ];
    }

    /**
     * Creation
     */

    /**
     * Add or set an entry to the fulltext search table
     *
     * @param Project $Project
     * @param integer $siteId
     * @param array $params
     * @param array $siteParams - optional; Parameter for the site link
     * @throws ExceptionStack|Exception
     */
    public static function setEntry(
        Project $Project,
        int $siteId,
        array $params = [],
        array $siteParams = []
    ): void {
        self::setEntryData($Project, $siteId, $params, $siteParams);

        QUI::getEvents()->fireEvent(
            'searchFulltextSetEntry',
            [$Project, $siteId, $siteParams]
        );
    }

    /**
     * Delete an entry from the search table
     *
     * @param Project $Project - Project
     * @param integer $siteId - ID of the site
     * @param array $siteParams - (optional); Parameter for the site link
     *
     * @return void
     * @throws Exception
     */
    public static function removeEntry(
        Project $Project,
        int $siteId,
        array $siteParams = []
    ): void {
        $tbl = QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project);

        Database::delete($tbl, [
            'siteId' => $siteId,
            'urlParameter' => json_encode($siteParams)
        ]);
    }

    /**
     * Edit an entry to the fulltext search table
     *
     * @param Project $Project
     * @param integer $siteId
     * @param array $params
     * @param array $siteParams - optional; Parameter for the site link
     * @throws Exception
     */
    public static function setEntryData(
        Project $Project,
        int $siteId,
        array $params = [],
        array $siteParams = []
    ): void {
        $table = QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project);
        $fields = self::getFieldList();

        $urlParameter = json_encode($siteParams);

        // cannot set entry for inactive sites!
        try {
            $Site = $Project->get($siteId);

            if (!$Site->getAttribute('active')) {
                return;
            }
        } catch (\Exception) {
            return;
        }

        try {
            $data = self::getEntry($Project, $siteId, $siteParams);

            unset($data['siteId']);
            unset($data['urlParameter']);
        } catch (QUI\Exception) {
            $siteUrlParams = [];

            // site params
            if (!empty($siteParams)) {
                foreach ($siteParams as $urlKey => $urlValue) {
                    $urlValue = Orthos::clear($urlValue);
                    $urlKey = Orthos::clear($urlKey);

                    $siteUrlParams[$urlKey] = $urlValue;
                }
            }

            $urlParameter = json_encode($siteUrlParams);


            Database::insert($table, [
                'siteId' => $siteId,
                'urlParameter' => $urlParameter
            ]);

            $data = [];
        }

        // data
        foreach ($fields as $entry) {
            $field = $entry['field'];

            if (!isset($params[$field])) {
                continue;
            }

            $data[$field] = $params[$field];
        }

        $data['datatype'] = $Site->getAttribute('type');

        Database::update($table, $data, [
            'siteId' => $siteId,
            'urlParameter' => $urlParameter
        ]);
    }

    /**
     * Append the data field of an specific search entry
     *
     * @param Project $Project
     * @param integer $siteId
     * @param string $data
     * @param array $siteParams
     * @throws Exception
     * @throws QUI\Exception
     */
    public static function appendFulltextSearchString(
        Project $Project,
        int $siteId,
        string $data = '',
        array $siteParams = []
    ): void {
        // cannot set entry for inactive sites!
        try {
            $Site = $Project->get($siteId);

            if (!$Site->getAttribute('active')) {
                return;
            }
        } catch (\Exception) {
            return;
        }

        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_FULL,
            $Project
        );

        $entry = self::getEntry($Project, $siteId, $siteParams);
        $content = $entry['data'];

        $content = $content . ' ' . $data;
        $urlParameter = json_encode($siteParams);

        Database::update($table, [
            'data' => $content
        ], [
            'siteId' => $siteId,
            'urlParameter' => $urlParameter
        ]);
    }

    /**
     * Return an fulltext entry
     *
     * @param Project $Project
     * @param integer $siteId
     * @param array $siteParams
     *
     * @return mixed
     * @throws Exception
     * @throws QUI\Exception
     */
    public static function getEntry(
        Project $Project,
        int $siteId,
        array $siteParams = []
    ): mixed {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_FULL,
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
                'Search entry not exists'
            );
        }

        return $result;
    }

    // region Custom entries

    /**
     * Edit a Fulltext search custom entry
     *
     * @param Project $Project
     * @param CustomSearchItem $CustomFulltextItem
     * @param array $params (optional) - fulltext search table column values
     * @throws Exception
     */
    public static function setCustomEntry(
        Project $Project,
        CustomSearchItem $CustomFulltextItem,
        array $params = []
    ): void {
        $table = QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project);
        $fields = self::getFieldList();

        try {
            $data = self::getCustomEntry($Project, $CustomFulltextItem);
        } catch (QUI\Exception) {
            Database::insert($table, [
                'custom_id' => $CustomFulltextItem->getId(),
                'custom_data' => json_encode($CustomFulltextItem->toArray()),
                'origin' => $CustomFulltextItem->getOrigin(),
                'datatype' => 'custom'
            ]);

            $data = [];
        }

        // data
        foreach ($fields as $entry) {
            $field = $entry['field'];

            if (!isset($params[$field])) {
                continue;
            }

            $data[$field] = $params[$field];
        }

        $data['datatype'] = 'custom';
        $data['custom_data'] = json_encode($CustomFulltextItem->toArray());

        Database::update($table, $data, [
            'custom_id' => $CustomFulltextItem->getId(),
            'origin' => $CustomFulltextItem->getOrigin()
        ]);
    }

    /**
     * Return a fulltext custom entry
     *
     * @param Project $Project
     * @param CustomSearchItem $CustomFulltextItem
     * @return array - Entry data as array (straight from db)
     *
     * @throws QUI\Exception
     */
    public static function getCustomEntry(Project $Project, CustomSearchItem $CustomFulltextItem): array
    {
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_FULL,
            $Project
        );

        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->select('*')
            ->from(Doctrine::quoteIdentifier($table))
            ->where($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('custom_id'), ':customId'))
            ->andWhere($QueryBuilder->expr()->eq(Doctrine::quoteIdentifier('origin'), ':origin'))
            ->setParameter('customId', $CustomFulltextItem->getId())
            ->setParameter('origin', $CustomFulltextItem->getOrigin())
            ->setMaxResults(1);
        $result = Database::fetchAssociative($QueryBuilder);

        if ($result === false) {
            throw new QUI\Exception(
                'Search entry not exists'
            );
        }

        return $result;
    }

    /**
     * Remove a custom entry from fulltext search table
     *
     * @param Project $Project
     * @param CustomSearchItem $CustomFulltextItem
     * @return void
     *
     * @throws QUI\Database\Exception
     */
    public static function removeCustomEntry(Project $Project, CustomSearchItem $CustomFulltextItem): void
    {
        Database::delete(
            QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project),
            [
                'custom_id' => $CustomFulltextItem->getId(),
                'origin' => $CustomFulltextItem->getOrigin(),
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
            QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project)
        );
    }

    /**
     * event : onSearchFulltextCreation
     *
     * @param Fulltext $Fulltext
     * @param Project $Project
     * @throws Exception
     */
    public static function onSearchFulltextCreate(
        Fulltext $Fulltext,
        Project $Project
    ): void {
        $list = $Project->getSitesIds([
            'active' => 1
        ]);

        foreach ($list as $siteParams) {
            set_time_limit(0);

            try {
                $siteId = (int)$siteParams['id'];
                $Site = new SiteEdit($Project, $siteId);

                if (!$Site->getAttribute('active')) {
                    continue;
                }

                if ($Site->getAttribute('deleted')) {
                    continue;
                }

                if ($Site->getAttribute('quiqqer.settings.search.not.indexed')) {
                    continue;
                }

                $e_date = $Site->getAttribute('e_date');
                $e_date = strtotime($e_date);

                if (!$e_date) {
                    $e_date = 0;
                }

                $c_date = $Site->getAttribute('c_date');
                $c_date = strtotime($c_date);

                if (!$c_date) {
                    $c_date = 0;
                }

                $Fulltext->setEntry($Project, $siteId, [
                    'name' => $Site->getAttribute('name'),
                    'title' => $Site->getAttribute('title'),
                    'siteType' => $Site->getAttribute('type'),
                    'short' => $Site->getAttribute('short'),
                    'data' => $Site->getAttribute('content'),
                    'datatype' => $Site->getAttribute('type'),
                    'icon' => $Site->getAttribute('image_site'),
                    'e_date' => $e_date,
                    'c_date' => $c_date
                ]);
            } catch (QUI\Exception $Exception) {
                Log::writeException($Exception);
            }
        }
    }

    /**
     * Utils
     */

    /**
     * Return the search fields
     *
     * @return array
     */
    public static function getFieldList(): array
    {
        $cache = 'quiqqer/search/fieldList';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $result = [];
        $files = self::getSearchXmlList();

        foreach ($files as $package => $file) {
            $Dom = QUI\Utils\Text\XML::getDomFromXml($file);
            $Path = new DOMXPath($Dom);

            $fields = $Path->query("//quiqqer/search/searchfields/field");

            foreach ($fields as $Field) {
                if (method_exists($Field, 'getAttribute')) {
                    $result[] = [
                        'field' => trim($Field->nodeValue),
                        'type' => $Field->getAttribute('type'),
                        'fulltext' => (bool)$Field->getAttribute('fulltext'),
                        'package' => $package
                    ];
                }
            }
        }

        QUI\Cache\Manager::set($cache, $result);

        return $result;
    }

    /**
     * Return the plugins with a search.xml file
     *
     * @return array
     */
    public static function getSearchXmlList(): array
    {
        $cache = 'quiqqer/search/xmlList';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Exception) {
        }

        $packages = QUI::getPackageManager()->getInstalled();
        $result = [];

        foreach ($packages as $package) {
            $xmlFile = OPT_DIR . $package['name'] . '/search.xml';

            if (file_exists($xmlFile)) {
                $result[$package['name']] = $xmlFile;
            }
        }

        if (!isset($result['quiqqer/search'])) {
            $result['quiqqer/search'] = OPT_DIR . '/quiqqer/search/search.xml';
        }

        QUI\Cache\Manager::set($cache, $result);

        return $result;
    }
}
