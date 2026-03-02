<?php

/**
 * This file contains \QUI\Search\Fulltext
 *
 * @todo Search-Entry als Objekt umsetzen
 */

namespace QUI\Search;

use DOMElement;
use DOMXPath;
use PDO;
use QUI;
use QUI\Database\Exception;
use QUI\ExceptionStack;
use QUI\Projects\Project;
use QUI\Projects\Site\Edit as SiteEdit;
use QUI\Search;
use QUI\Search\Items\CustomSearchItem;
use QUI\System\Log;
use QUI\Utils\Security\Orthos;

use function array_filter;
use function array_flip;
use function array_keys;
use function array_merge;
use function count;
use function current;
use function explode;
use function file_exists;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_integer;
use function is_string;
use function json_encode;
use function key;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function set_time_limit;
use function str_replace;
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

        if (get_class($Project) !== 'QUI\Projects\Project') {
            $Project = QUI::getProjectManager()->get();
        }

        if (!$attrLimit || is_integer($attrLimit)) {
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
        $count = [
            'name' => 8,
            'title' => 10,
            'short' => 5,
            'content' => 3
        ];

        $PDO = QUI::getPDO();
        $table = QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $Project);
        $limit = QUI\Database\DB::createQueryLimit($attrLimit);
        $binds = [];

        // relevance match
        $relevanceMatch = [];
        $whereMatch = [];
        $relevanceSum = 0;

        foreach ($fulltextFields as $field) {
            $matchCount = 9;

            if (isset($count[$field])) {
                $matchCount = $count[$field];
            }

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

            $datatypeQuery = ' AND (';

            for ($i = 0, $len = count($datatypes); $i < $len; $i++) {
                $datatypeQuery .= ' datatype LIKE :type' . $i;

                if ($len - 1 > $i) {
                    $datatypeQuery .= ' OR ';
                }

                $binds['type' . $i] = [
                    'value' => $datatypes[$i],
                    'type' => PDO::PARAM_STR
                ];
            }

            $datatypeQuery .= ' )';
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
                            $constraintEntriesOr[] = $field . ' LIKE :constraint' . $i;
                            $binds['constraint' . $i] = [
                                'value' => '%' . $value['value'] . '%',
                                'type' => PDO::PARAM_STR
                            ];
                        }
                    } else {
                        $constraintEntriesOr[] = $field . ' = :constraint' . $i;
                        $binds['constraint' . $i] = [
                            'value' => $value,
                            'type' => PDO::PARAM_STR
                        ];
                    }

                    $i++;
                }

                if (!empty($constraintEntriesOr)) {
                    $fieldConstraintsEntries[] = "(" . implode(" OR ", $constraintEntriesOr) . ")";
                }
            }

            if (!empty($fieldConstraintsEntries)) {
                $whereFieldConstraints = ' AND (' . implode(" AND ", $fieldConstraintsEntries) . ')';
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
        $order = '';

        if (is_array($orderFields) && !empty($orderFields)) {
            $order = implode(',', $orderFields) . ',';

            foreach ($orderFields as $orderField) {
                $orderFieldParts = explode(' ', $orderField);
                $orderField = current($orderFieldParts);

                if (!in_array($orderField, $availableFields)) {
                    $availableFields[] = $orderField;
                }
            }
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
        ) {
            // filter $selectedFields
            $selectedFields = array_filter($selectedFields, function ($v) {
                return !in_array($v, ['urlParameter', 'siteId']);
            });

            $selectedFieldsSql = implode(',', $selectedFields);

            $query = "
                SELECT
                    siteId,
                    urlParameter,
                    custom_id,
                    custom_data,
                    origin,
                    100 / $relevanceSum * ($relevanceMatch) AS relevance,
                    {$selectedFieldsSql}
                FROM
                    {$table}
                WHERE
                    ({$whereMatch})
                    {$datatypeQuery}
                    {$whereFieldConstraints}
                GROUP BY
                    urlParameter,siteId,custom_id,custom_data,origin,{$selectedFieldsSql}
                ORDER BY
                    {$order}relevance DESC
            ";

            $search = str_replace('*', '', $search);

//            if (strlen($search) > 2 || $search == '%%') {
            $binds['search'] = [
                'value' => $search,
                'type' => PDO::PARAM_STR
            ];
//            }
            $searchMode = 'fulltext';
        } else {
            $likeQueryData = $this->buildLikeQuery(
                $selectedFields,
                $str,
                $fields,
                $table,
                $whereFieldConstraints,
                $datatypeQuery,
                $order,
                $binds
            );
            $query = $likeQueryData['query'];
            $binds = $likeQueryData['binds'];
        }

        Log::addDebug(
            self::class . '::search() fields=' . json_encode($fields)
            . ' relevance=' . ($this->getAttribute('relevanceSearch') ? '1' : '0')
            . ' mode=' . $searchMode
        );

        $runQuery = function (string $query, array $binds) use ($PDO, $limit): array {
            $selectQuery = "$query {$limit['limit']}";

            $countQuery = "
                SELECT COUNT(*) as count
                FROM ($query) as T
            ";

            $Statement = $PDO->prepare($selectQuery);
            $Statement->bindValue(
                ':limit1',
                $limit['prepare'][':limit1'][0],
                PDO::PARAM_INT
            );

            $Statement->bindValue(
                ':limit2',
                $limit['prepare'][':limit2'][0],
                PDO::PARAM_INT
            );

            foreach ($binds as $placeholder => $bind) {
                $Statement->bindValue(':' . $placeholder, $bind['value'], $bind['type']);
            }

            $Statement->execute();
            $result = $Statement->fetchAll(PDO::FETCH_ASSOC);

            $Statement = $PDO->prepare($countQuery);

            foreach ($binds as $placeholder => $bind) {
                $Statement->bindValue(':' . $placeholder, $bind['value'], $bind['type']);
            }

            $Statement->execute();
            $count = $Statement->fetchAll(PDO::FETCH_ASSOC);

            return [
                'list' => $result,
                'count' => $count[0]['count']
            ];
        };

        try {
            return $runQuery($query, $binds);
        } catch (\PDOException $Exception) {
            $mysqlErrorCode = $Exception->errorInfo[1] ?? null;

            if ($searchMode !== 'fulltext' || (int)$mysqlErrorCode !== 1191) {
                throw $Exception;
            }

            $likeQueryData = $this->buildLikeQuery(
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

            return $runQuery($likeQueryData['query'], $likeQueryData['binds']);
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
     * @param string $order
     * @param array $binds
     * @return array
     */
    private function buildLikeQuery(
        array $selectedFields,
        string $str,
        array $fields,
        string $table,
        string $whereFieldConstraints,
        string $datatypeQuery,
        string $order,
        array $binds
    ): array {
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
                $whereOr[] = $field . ' LIKE :search' . $k;
            }

            $likeBinds['search' . $k] = [
                'value' => '%' . $searchTerm . '%',
                'type' => PDO::PARAM_STR
            ];

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

        $resultFields = implode(',', $resultFields);

        $likeQuery = "
            SELECT e_date,urlParameter,siteId,custom_id,custom_data,origin,{$resultFields}
            FROM
                {$table}
            WHERE
                ($where)
                {$whereFieldConstraints}
                {$datatypeQuery}
            GROUP BY
                urlParameter,siteId,e_date,custom_id,custom_data,origin,{$resultFields}
            ORDER BY
                {$order}e_date DESC
        ";

        return [
            'query' => $likeQuery,
            'binds' => $likeBinds
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

        QUI::getDataBase()->delete($tbl, [
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


            QUI::getDataBase()->insert($table, [
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

        QUI::getDataBase()->update($table, $data, [
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

        QUI::getDataBase()->update($table, [
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

        $result = QUI::getDataBase()->fetch([
            'from' => $table,
            'where' => [
                'siteId' => $siteId,
                'urlParameter' => $urlParameter
            ]
        ]);

        if (!isset($result[0])) {
            throw new QUI\Exception(
                'Search entry not exists'
            );
        }

        return $result[0];
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
            QUI::getDataBase()->insert($table, [
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

        QUI::getDataBase()->update($table, $data, [
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

        $result = QUI::getDataBase()->fetch([
            'from' => $table,
            'where' => [
                'custom_id' => $CustomFulltextItem->getId(),
                'origin' => $CustomFulltextItem->getOrigin(),
            ]
        ]);

        if (!isset($result[0])) {
            throw new QUI\Exception(
                'Search entry not exists'
            );
        }

        return $result[0];
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
        QUI::getDataBase()->delete(
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
        QUI::getDataBase()->table()->truncate(
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
