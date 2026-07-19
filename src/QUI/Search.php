<?php

/**
 * This file contains QUI\Search
 */

namespace QUI;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use QUI;
use QUI\Database\Exception;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit as SiteEdit;
use QUI\Search\Database;
use QUI\Search\Fulltext;
use QUI\Search\Quicksearch;
use QUI\System\Log;

use function set_time_limit;
use function strtotime;

/**
 * Hauptsuche
 *
 * @author www.pcsg.de (Henning Leutz)
 */
class Search
{
    /**
     * quick search table
     *
     * @var string
     */
    const TABLE_SEARCH_QUICK = 'searchQuick';

    /**
     * fulltext search table
     *
     * @var string
     */
    const TABLE_SEARCH_FULL = 'searchFull';

    /**
     * Create the fulltext search table for the Project
     * Executes events and insert the standard
     *
     * @param Project $Project
     * @throws ExceptionStack
     */
    public function createFulltextSearch(Project $Project): void
    {
        $Fulltext = new Fulltext();
        $Fulltext->clearSearchTable($Project); // @todo muss raus

        QUI::getEvents()->fireEvent(
            'searchFulltextCreate',
            [$Fulltext, $Project]
        );
    }

    /**
     * Create the quick search table for the Project
     * Executes events and insert the standard
     *
     * @param Project $Project
     * @throws Exception
     * @throws ExceptionStack
     */
    public function createQuicksearch(Project $Project): void
    {
        $list = $Project->getSitesIds([
            'where' => [
                'active' => 1
            ]
        ]);

        $Quicksearch = new Quicksearch();
        $Quicksearch->clearSearchTable($Project);

        foreach ($list as $siteParams) {
            try {
                set_time_limit(0);

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

                $Quicksearch->setEntries($Project, $siteId, [
                    $Site->getAttribute('name') . ' ' . $Site->getAttribute('title'),
                ]);
            } catch (QUI\Exception $Exception) {
                Log::writeException($Exception);
            }
        }

        QUI::getEvents()->fireEvent(
            'searchQuicksearchCreate',
            [$Quicksearch, $Project]
        );
    }

    /**
     * Setup, create the extra fields
     * @throws Exception|\QUI\Exception
     */
    public static function setup(): void
    {
        try {
            self::setupDatabaseSchema();
        } catch (\Doctrine\DBAL\Exception $Exception) {
            throw Database::createException($Exception);
        }
    }

    private static function setupDatabaseSchema(): void
    {
        QUI\Cache\Manager::clear('quiqqer/search');

        $SchemaManager = QUI::getSchemaManager();
        $isMySQL = QUI::getDataBaseConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $Manager = QUI::getProjectManager();
        $projects = $Manager->getProjects(true);

        $fieldList = Search\Fulltext::getFieldList();
        $fields = [];
        $fulltext = [];
        $index = [];

        foreach ($fieldList as $fieldEntry) {
            $fields[$fieldEntry['field']] = self::getDoctrineColumnDefinition($fieldEntry['type']);

            if ($fieldEntry['fulltext']) {
                $fulltext[] = [
                    'field' => $fieldEntry['field'],
                    'package' => $fieldEntry['package']
                ];
            } else {
                $index[] = [
                    'field' => $fieldEntry['field'],
                    'package' => $fieldEntry['package']
                ];
            }
        }

        foreach ($projects as $_Project) {
            /* @var $_Project Project */
            $name = $_Project->getName();
            $langs = $_Project->getLanguages();

            foreach ($langs as $lang) {
                $Project = $Manager->getProject($name, $lang);

                $table = QUI::getDBProjectTableName(
                    self::TABLE_SEARCH_FULL,
                    $Project
                );

                if (!$SchemaManager->tablesExist([$table])) {
                    continue;
                }

                $Table = $SchemaManager->introspectTable($table);
                $addedColumns = [];

                foreach ($fields as $fieldName => $definition) {
                    if ($Table->hasColumn($fieldName)) {
                        continue;
                    }

                    $addedColumns[] = new Column(
                        $fieldName,
                        Type::getType($definition['type']),
                        $definition['options']
                    );
                }

                if (!empty($addedColumns)) {
                    $SchemaManager->alterTable(new TableDiff($Table, addedColumns: $addedColumns));
                    $Table = $SchemaManager->introspectTable($table);
                }

                if ($isMySQL) {
                    foreach ($fulltext as $field) {
                        if (self::hasColumnIndex($Table, $field['field'], true)) {
                            continue;
                        }

                        $indexName = self::getIndexName('search_fulltext', $field['field']);
                        $Table->addIndex([$field['field']], $indexName, ['fulltext']);
                        $SchemaManager->alterTable(new TableDiff(
                            $Table,
                            addedIndexes: [$Table->getIndex($indexName)]
                        ));
                        $Table = $SchemaManager->introspectTable($table);
                    }
                }

                foreach ($index as $field) {
                    if (self::hasColumnIndex($Table, $field['field'])) {
                        continue;
                    }

                    try {
                        $indexName = self::getIndexName('search_index', $field['field']);
                        $Table->addIndex([$field['field']], $indexName);
                        $SchemaManager->alterTable(new TableDiff(
                            $Table,
                            addedIndexes: [$Table->getIndex($indexName)]
                        ));
                        $Table = $SchemaManager->introspectTable($table);
                    } catch (\Exception $Exception) {
                        QUI\System\Log::addWarning(
                            self::class . ' :: setup() -> Could not create Index for Fulltext'
                            . ' search column "' . $field['field'] . '" (Package: ' . $field['package'] . ').'
                            . ' The search column may be needed to be defined as "fulltext" for this to work.'
                            . ' Error Message: ' . $Exception->getMessage()
                        );
                    }
                }
            }
        }
    }

    /**
     * @return array{type: string, options: array<string, mixed>}
     */
    private static function getDoctrineColumnDefinition(string $definition): array
    {
        $definition = strtolower(trim($definition));
        $options = ['notnull' => str_contains($definition, 'not null')];

        if (preg_match('/^(?:var)?char\s*\((\d+)\)/', $definition, $matches)) {
            $options['length'] = (int)$matches[1];

            return ['type' => 'string', 'options' => $options];
        }

        if (preg_match('/^(?:decimal|numeric)\s*\((\d+)\s*,\s*(\d+)\)/', $definition, $matches)) {
            $options['precision'] = (int)$matches[1];
            $options['scale'] = (int)$matches[2];

            return ['type' => 'decimal', 'options' => $options];
        }

        $type = preg_replace('/[\s(].*$/', '', $definition);

        return [
            'type' => match ($type) {
                'bigint' => 'bigint',
                'tinyint', 'smallint' => 'smallint',
                'int', 'integer', 'mediumint' => 'integer',
                'float', 'double', 'real' => 'float',
                'bool', 'boolean' => 'boolean',
                'date', 'datetime', 'time', 'json', 'guid', 'binary', 'blob' => $type,
                'timestamp' => 'datetime',
                default => 'text'
            },
            'options' => $options
        ];
    }

    private static function hasColumnIndex(Table $Table, string $field, bool $fulltext = false): bool
    {
        foreach ($Table->getIndexes() as $Index) {
            if ($Index->getColumns() !== [$field]) {
                continue;
            }

            if ($fulltext && !$Index->hasFlag('fulltext')) {
                continue;
            }

            if (!$fulltext && $Index->hasFlag('fulltext')) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function getIndexName(string $prefix, string $field): string
    {
        return substr($prefix . '_' . $field, 0, 50) . '_' . substr(sha1($field), 0, 8);
    }

    /**
     * Events
     */

    /**
     * event : on site deactivate
     *
     * @param QUI\Interfaces\Projects\Site $Site
     * @throws Exception
     */
    public static function onSiteDeactivate(QUI\Interfaces\Projects\Site $Site): void
    {
        $Project = $Site->getProject();

        $tableSearchFull = QUI::getDBProjectTableName(
            self::TABLE_SEARCH_FULL,
            $Project
        );

        $tableQuicksearch = QUI::getDBProjectTableName(
            self::TABLE_SEARCH_QUICK,
            $Project
        );

        // remove entries from tables
        Database::delete($tableSearchFull, [
            'siteId' => $Site->getId()
        ]);

        Database::delete($tableQuicksearch, [
            'siteId' => $Site->getId()
        ]);
    }

    /**
     * event : on site activate / change
     *
     * @param Site $Site
     * @throws ExceptionStack
     * @throws \Exception
     */
    public static function onSiteChange(Site $Site): void
    {
        // check default settings
        if ($Site->getAttribute('type') === 'quiqqer/search:types/search') {
            self::setSiteDefaultSettings($Site);
        }

        if (
            !$Site->getAttribute('active')
            || $Site->getAttribute('deleted')
            || $Site->getAttribute('quiqqer.settings.search.not.indexed')
        ) {
            self::onSiteDeactivate($Site);
            return;
        }

        /* @param $Site Site */
        $Project = $Site->getProject();

        $Quicksearch = new Quicksearch();
        $Fulltext = new Fulltext();

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

        // Fulltext
        $Fulltext->setEntry($Project, $Site->getId(), [
            'name' => $Site->getAttribute('name'),
            'title' => $Site->getAttribute('title'),
            'short' => $Site->getAttribute('short'),
            'data' => $Site->getAttribute('content'),
            'icon' => $Site->getAttribute('image_site'),
            'e_date' => $e_date,
            'c_date' => $c_date
        ]);

        // Quicksearch
        $Quicksearch->setEntries($Project, $Site->getId(), [
            $Site->getAttribute('title')
        ]);
    }

    /**
     * Set default search params for search sites
     *
     * @param Site $Site
     * @return void
     *
     * @throws QUI\Exception
     */
    protected static function setSiteDefaultSettings(Site $Site): void
    {
        $fields = $Site->getAttribute('quiqqer.settings.search.list.fields');
        $selectedFields = $Site->getAttribute('quiqqer.settings.search.list.fields.selected');

        if (!empty($fields) || !empty($selectedFields)) {
            return;
        }

        $selectedFields = ['name', 'title', 'short', 'data'];
        $Edit = $Site->getEdit();

        if (!$Edit instanceof SiteEdit) {
            throw new QUI\Exception('Could not obtain editable search site.');
        }

        $Edit->setAttribute('quiqqer.settings.search.list.fields', []);
        $Edit->setAttribute('quiqqer.settings.search.list.fields.selected', $selectedFields);

        $Edit->save();
    }

    /**
     * event onTemplateGetHeader
     *
     * @param QUI\Template $Template - Template object
     * @throws \Exception
     */
    public static function onTemplateGetHeader(QUI\Template $Template): void
    {
        $Project = $Template->getAttribute('Project');

        if (!$Project instanceof Project) {
            $Project = QUI::getProjectManager()->get();
        }

        if (!$Project instanceof Project) {
            return;
        }

        $result = $Project->getSites([
            'where' => [
                'type' => 'quiqqer/search:types/search'
            ],
            'limit' => 1
        ]);

        if (!isset($result[0])) {
            return;
        }

        $host = $Project->getVHost(true, true);

        /* @var $SearchSite Site */
        $SearchSite = $result[0];
        $searchUrl = $SearchSite->getUrlRewritten();
        $start = $Project->firstChild()->getUrlRewritten();

        if (!str_starts_with($searchUrl, 'http')) {
            $searchUrl = $host . $searchUrl;
            $start = $host . $start;
        }

        $jsonLd = self::buildWebsiteSearchJsonLd($start, $searchUrl);

        if ($jsonLd === null) {
            return;
        }

        $Template->extendHeader('<script type="application/ld+json">' . $jsonLd . '</script>');
    }

    /**
     * Build script-safe structured data for the website search action.
     */
    protected static function buildWebsiteSearchJsonLd(string $start, string $searchUrl): ?string
    {
        $json = json_encode(
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'url' => $start,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => $searchUrl . '?search={search}',
                    'query-input' => 'required name=search'
                ]
            ],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        return $json === false ? null : $json;
    }
}
