<?php

/**
 * This file contains QUI\Search
 */

namespace QUI;

use QUI;
use QUI\Database\Exception;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Projects\Site\Edit as SiteEdit;
use QUI\Search\Fulltext;
use QUI\Search\Quicksearch;
use QUI\System\Log;

use function is_object;
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
        QUI\Cache\Manager::clear('quiqqer/search');

        $Table = QUI::getDataBase()->table();
        $Manager = QUI::getProjectManager();
        $projects = $Manager->getProjects(true);

        $fieldList = Search\Fulltext::getFieldList();
        $fields = [];
        $fulltext = [];
        $index = [];

        foreach ($fieldList as $fieldEntry) {
            $fields[$fieldEntry['field']] = $fieldEntry['type'];

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

                $Table->addColumn($table, $fields);

                foreach ($fulltext as $field) {
                    $Table->setFulltext($table, $field['field']);
                }

                foreach ($index as $field) {
                    try {
                        $Table->setIndex($table, $field['field']);
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
        QUI::getDataBase()->delete($tableSearchFull, [
            'siteId' => $Site->getId()
        ]);

        QUI::getDataBase()->delete($tableQuicksearch, [
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

        if (!$Site->getAttribute('active')) {
            return;
        }

        if ($Site->getAttribute('deleted')) {
            return;
        }

        if ($Site->getAttribute('quiqqer.settings.search.not.indexed')) {
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
        $Site = $Site->getEdit();

        $Site->setAttribute('quiqqer.settings.search.list.fields', []);
        $Site->setAttribute('quiqqer.settings.search.list.fields.selected', $selectedFields);

        $Site->save();
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

        if (!is_object($Project)) {
            $Project = QUI::getProjectManager()->get();
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
