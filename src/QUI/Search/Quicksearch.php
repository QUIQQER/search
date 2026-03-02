<?php

/**
 * This file contains \QUI\Search\Quicksearch
 */

namespace QUI\Search;

use PDO;
use QUI;
use QUI\Database\Exception;
use QUI\ExceptionStack;
use QUI\Projects\Project;
use QUI\Search;
use QUI\Search\Items\CustomSearchItem;
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
        $PDO = QUI::getPDO();
        $table = QUI::getDBProjectTableName(
            Search::TABLE_SEARCH_QUICK,
            $Project
        );

        if (!isset($params['limit'])) {
            $params['limit'] = 10;
        }

        $search = '%' . $str . '%';
        $limit = QUI\Database\DB::createQueryLimit($params['limit']);
        $binds = [];

        // restrict search to certain site types
        $siteTypes = $this->getAttribute('siteTypes');
        $siteTypesQuery = '';

        if ($siteTypes) {
            if (!is_array($siteTypes)) {
                $siteTypes = [$siteTypes];
            }

            $siteTypesQuery = ' AND (';

            for ($i = 0, $len = count($siteTypes); $i < $len; $i++) {
                $siteTypesQuery .= ' siteType LIKE :type' . $i;

                if ($len - 1 > $i) {
                    $siteTypesQuery .= ' OR ';
                }

                $binds['type' . $i] = [
                    'value' => $siteTypes[$i],
                    'type' => PDO::PARAM_STR
                ];
            }

            $siteTypesQuery .= ' )';
        }

        if (version_compare(QUI::getDataBase()->getVersion(), '5.7.0') >= 0) {
            $query = "
                SELECT ANY_VALUE(id) AS id, 
                    siteId, 
                    urlParameter, 
                    ANY_VALUE(data) AS data, 
                    ANY_VALUE(rights) AS rights, 
                    ANY_VALUE(icon) AS icon,
                    siteType,
                    custom_id,
                    custom_data,
                    origin
            ";
        } else {
            $query = "SELECT id, siteId, urlParameter, data, rights, icon, siteType, custom_id, custom_data, origin";
        }


        $query .= "
            FROM
                {$table}
            WHERE
                data LIKE :search
                {$siteTypesQuery}
            GROUP BY siteId, urlParameter, id
        ";

        $selectQuery = "$query {$limit['limit']}";

        $countQuery = "
            SELECT COUNT(*) as count
            FROM ($query) as T
        ";

        // search
        $Statement = $PDO->prepare($selectQuery);
        $Statement->bindValue(':search', $search);

        foreach ($binds as $placeholder => $bind) {
            $Statement->bindValue(':' . $placeholder, $bind['value'], $bind['type']);
        }

        if (isset($limit['prepare'][':limit1'])) {
            $Statement->bindValue(
                ':limit1',
                $limit['prepare'][':limit1'][0],
                PDO::PARAM_INT
            );
        }

        if (isset($limit['prepare'][':limit2'])) {
            $Statement->bindValue(
                ':limit2',
                $limit['prepare'][':limit2'][0],
                PDO::PARAM_INT
            );
        }

        $Statement->execute();

        $result = $Statement->fetchAll(PDO::FETCH_ASSOC);

        if (!isset($params['group']) || $params['group'] !== false) {
            $groups = [];

            foreach ($result as $k => $row) {
                if (isset($groups[$row['data']])) {
                    unset($result[$k]);
                    continue;
                }

                $groups[$row['data']] = true;
            }
        }

        // count
        $Statement = $PDO->prepare($countQuery);
        $Statement->bindValue(':search', $search);

        foreach ($binds as $placeholder => $bind) {
            $Statement->bindValue(':' . $placeholder, $bind['value'], $bind['type']);
        }

        $Statement->execute();

        $count = $Statement->fetchAll(PDO::FETCH_ASSOC);

        return [
            'list' => $result,
            'count' => $count[0]['count']
        ];
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
            QUI::getDataBase()->insert($table, [
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
//        $data         = QUI::getPDO()->quote($data);

        // check if entry exists
        if (self::existsEntry($Project, $siteId, $data, $siteParams)) {
            QUI::getDataBase()->update(
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

        QUI::getDataBase()->insert($table, [
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

        QUI::getDataBase()->delete($table, [
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

        $result = QUI::getDataBase()->fetch([
            'from' => $table,
            'where' => [
                'siteId' => $siteId,
                'urlParameter' => $urlParameter
            ]
        ]);

        if (!isset($result[0])) {
            throw new QUI\Exception(
                'Quicksearch entry not exists'
            );
        }

        return $result[0];
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

        $result = QUI::getDataBase()->fetch([
            'count' => 1,
            'from' => $table,
            'where' => [
                'siteId' => $siteId,
                'data' => $data,
                'urlParameter' => $urlParameter
            ]
        ]);

        return boolval(current(current($result)));
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
                    QUI::getDataBase()->update(
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
                    QUI::getDataBase()->insert($table, $baseEntryData);
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

        $result = QUI::getDataBase()->fetch([
            'from' => $table,
            'where' => [
                'custom_id' => $CustomFulltextItem->getId(),
                'origin' => $CustomFulltextItem->getOrigin(),
                'data' => $searchString
            ]
        ]);

        return !empty($result);
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
        QUI::getDataBase()->delete(
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
        QUI::getDataBase()->table()->truncate(
            QUI::getDBProjectTableName(Search::TABLE_SEARCH_QUICK, $Project)
        );
    }
}
