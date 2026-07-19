<?php

/**
 * This file contains QUI\Tags\Controls\TagMenu
 */

namespace QUI\Search\Controls;

use QUI;
use QUI\Controls\ChildrenList;
use QUI\Controls\Navigating\Pagination;
use QUI\Exception;
use QUI\Projects\Site;
use QUI\Search\Fulltext;
use QUI\Utils\Security\Orthos;
use QUI\Utils\StringHelper;

use function array_flip;
use function array_keys;
use function array_merge;
use function array_values;
use function dirname;
use function explode;
use function file_exists;
use function implode;
use function in_array;
use function is_array;
use function is_null;
use function is_string;
use function json_decode;
use function json_encode;
use function round;
use function str_replace;
use function urldecode;

/**
 * Class Search
 *
 * Display search results
 *
 * @package QUI\Tags\Controls
 *
 * @phpstan-type SearchResult array{
 *     count: int,
 *     max: int,
 *     sheets: int,
 *     children: list<QUI\Interfaces\Projects\Site>,
 *     more: bool
 * }
 */
class Search extends QUI\Control
{
    const SEARCH_TYPE_OR = 'OR';
    const SEARCH_TYPE_AND = 'AND';

    const PAGINATION_TYPE_PAGINATION = 'pagination';
    const PAGINATION_TYPE_INFINITESCROLL = 'infinitescroll';

    /**
     * @deprecated Use PAGINATION_TYPE_INFINITESCROLL instead.
     */
    const PAGINATION_TYPE_INIFINITESCROLL = self::PAGINATION_TYPE_INFINITESCROLL;

    /**
     * Site the control is on
     */
    protected ?QUI\Interfaces\Projects\Site $Site = null;

    /**
     * Search results runtime cache
     *
     * @var SearchResult|null
     */
    protected ?array $searchResults = null;

    /**
     * constructor
     *
     * @param array<string, mixed> $attributes
     * @throws Exception
     */
    public function __construct(array $attributes = [])
    {
        if (
            isset($attributes['Site'])
            && $attributes['Site'] instanceof Site
        ) {
            $this->Site = $attributes['Site'];
            unset($attributes['Site']);
        } else {
            $this->Site = QUI::getRewrite()->getSite();
        }

        $directory = dirname(__FILE__, 5);

        $this->setAttributes([
            'search' => '',
            // search term
            'searchType' => $this::SEARCH_TYPE_OR,
            'max' => $this->Site->getAttribute('quiqqer.settings.search.list.max') ?: 10,
            'searchFields' => $this->getDefaultSearchFields(),
            'fieldConstraints' => [],
            // restrict search to certain site types
            'datatypes' => [],
            'sheet' => 1,
            // "pagination" or "infinitescroll" (determined by getPaginationType())
            'paginationType' => false,
            // use Fulltext relevance search
            'relevanceSearch' => true,
            'childrenListTemplate' => $directory . '/templates/SearchResultList.html',
            'childrenListCss' => $directory . '/templates/SearchResultList.css',
            'showResultCount' => true,
            'orderFields' => [],
            'showAllResultsOnEmptySearchString' => false
        ]);

        // set attributes
        parent::__construct($attributes);

        // sanitize attributes
        $this->sanitizeAttributes();

        // set javascript control data
        $this->setJavaScriptControl('package/quiqqer/search/bin/controls/Search');
        $this->setJavaScriptControlOption('searchparams', json_encode($this->getJavaScriptControlAttributes()));

        // set template data
        $this->addCSSClass('quiqqer-search');
        $this->addCSSFile(dirname(__FILE__) . '/Search.css');
    }

    /**
     * Execute search and return search result information
     *
     * @return SearchResult
     * @throws Exception
     */
    public function search(): array
    {
        if (!is_null($this->searchResults)) {
            return $this->searchResults;
        }

        $search = $this->getAttribute('search');
        $Project = $this->Site->getProject();
        $max = $this->getAttribute('max');
        $sheet = $this->getAttribute('sheet');
        $children = [];

        if (empty($search) && !$this->getAttribute('showAllResultsOnEmptySearchString')) {
            return [
                'count' => 0,
                'max' => $max,
                'sheets' => 0,
                'children' => [],
                'more' => false
            ];
        }

        $siteTypesFilter = $this->getAttribute('datatypes');

        if (empty($siteTypesFilter)) {
            $siteTypesFilter = $this->Site->getAttribute('quiqqer.settings.search.sitetypes.filter');

            if (!empty($siteTypesFilter)) {
                $siteTypesFilter = explode(';', $siteTypesFilter);
            } else {
                $siteTypesFilter = [];
            }
        }

        $FulltextSearch = new Fulltext([
            'limit' => (($sheet - 1) * $max) . ',' . $max,
            'fields' => $this->getAttribute('searchFields'),
            'fieldConstraints' => $this->getAttribute('fieldConstraints'),
            'searchtype' => $this->getAttribute('searchType'),
            'Project' => $this->Site->getProject(),
            'relevanceSearch' => $this->getAttribute('relevanceSearch'),
            'datatypes' => $siteTypesFilter,
            'orderFields' => $this->getAttribute('orderFields')
        ]);

        $result = $FulltextSearch->search($search);

        foreach ($result['list'] as $entry) {
            try {
                // immer neues site objekt
                // falls die gleiche seite mit unterschiedlichen url params existiert
                if ($entry['datatype'] === 'custom') {
                    $customData = json_decode($entry['custom_data'], true);

                    $ResultSite = new QUI\Search\Items\CustomSearchItem(
                        $entry['custom_id'],
                        $entry['origin'],
                        $customData['title'],
                        $customData['url'],
                        $customData['attributes']
                    );
                } else {
                    $ResultSite = $Project->get((int)$entry['siteId']);//new Site($Project, );
                }

                $urlParams = json_decode($entry['urlParameter'], true);

                if (!is_array($urlParams)) {
                    $urlParams = [];
                }

                $url = $ResultSite->getUrlRewritten($urlParams);
                $url = StringHelper::replaceDblSlashes($url);

                if (!isset($entry['relevance']) || $entry['relevance'] > 100) {
                    $entry['relevance'] = 100;
                }

                $ResultSite->setAttribute('search-name', $entry['name']);
                $ResultSite->setAttribute('search-title', $entry['title']);
                $ResultSite->setAttribute('search-short', $entry['short']);
                $ResultSite->setAttribute('search-relevance', round($entry['relevance'], 2));
                $ResultSite->setAttribute('search-url', $url);
                $ResultSite->setAttribute('search-icon', $entry['icon']);

                $children[] = $ResultSite;
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addDebug($Exception->getMessage());
            }
        }

        $sheets = (int)ceil($result['count'] / $max);
        $count = (int)$result['count'];

        $this->searchResults = [
            'count' => $count,
            'max' => $max,
            'sheets' => $sheets,
            'children' => $children,
            'more' => $sheet < $sheets
        ];

        return $this->searchResults;
    }

    /**
     * Get children list (search results)
     * @throws Exception
     */
    public function getChildrenList(): ChildrenList
    {
        $searchResult = $this->search();

        $params = [
            'showTitle' => false,
            'Site' => $this->Site,
            'limit' => $searchResult['max'],
            'showDate' => $this->Site->getAttribute('quiqqer.settings.sitetypes.list.showDate'),
            'showCreator' => $this->Site->getAttribute('quiqqer.settings.sitetypes.list.showCreator'),
            'showTime' => $this->Site->getAttribute('quiqqer.settings.sitetypes.list.showTime'),
            'showRelevance' => $this->Site->getAttribute('quiqqer.settings.sitetypes.list.showRelevance'),
            'showSheets' => false,
            'showImages' => $this->Site->getAttribute('quiqqer.settings.sitetypes.list.showImages'),
            'showShort' => true,
            'showHeader' => true,
            'showContent' => false,
            'itemtype' => 'https://schema.org/ItemList',
            'child-itemtype' => 'https://schema.org/ListItem',
            'display' => $this->Site->getAttribute('quiqqer.settings.sitetypes.list.template'),
            'children' => $searchResult['children'],
            'loadAllChildrenOnEmptyList' => false
        ];

        if (!$this->Site->getAttribute('quiqqer.settings.sitetypes.list.template')) {
            if ($this->getAttribute('childrenListTemplate')) {
                $params['displayTemplate'] = $this->getAttribute('childrenListTemplate');
            }

            if ($this->getAttribute('childrenListCss')) {
                $params['displayCss'] = $this->getAttribute('childrenListCss');
            }
        }

        if ($this->Site->getAttribute('quiqqer.settings.sitetypes.list.template') == 'standardSearch') {
            $params['displayTemplate'] = $this->getAttribute('childrenListTemplate');
            $params['displayCss'] = $this->getAttribute('childrenListCss');
        }

        $childrenListAttributes = $this->getAttribute('childrenListAttributes');

        if (
            !empty($childrenListAttributes)
            && is_array($childrenListAttributes)
        ) {
            $params = array_merge($childrenListAttributes, $params);
        }

        $List = new ChildrenList($params);
        $this->addCSSFiles($List->getCSSFiles());

        return $List;
    }

    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();
        $search = $this->getAttribute('search');
        $fields = $this->getAttribute('searchFields');
        $searchResult = $this->search();

        // sync pagination
        $Pagination = new Pagination([
            'Site' => $this->Site,
            'count' => $searchResult['count'],
            'showLimit' => false,
            'limit' => $searchResult['max'],
            'useAjax' => false
        ]);

        $Pagination->loadFromRequest();

        $Pagination->setGetParams('search', $search);
        $Pagination->setGetParams('searchIn', implode(',', $fields));

        $Engine->assign('Pagination', $Pagination);

        // async Pagination
        $PaginationAsync = new Pagination([
            'Site' => $this->Site,
            'count' => $searchResult['count'],
            'showLimit' => false,
            'limit' => $searchResult['max'],
            'useAjax' => true
        ]);

        $PaginationAsync->loadFromRequest();

        $PaginationAsync->setGetParams('search', $search);
        $PaginationAsync->setGetParams('searchIn', implode(',', $fields));

        $Engine->assign('PaginationAsync', $PaginationAsync);

        $Engine->assign([
            'count' => $searchResult['count'],
            'sheets' => $searchResult['sheets'],
            'more' => $searchResult['more'],
            'searchValue' => $search,
            'searchType' => $this->getAttribute('searchType'),
            'availableFields' => $this->Site->getAttribute('quiqqer.settings.search.list.fields'),
            'ChildrenList' => $this->getChildrenList(),
            'paginationType' => $this->getPaginationType(),
            'showResultCount' => $this->getAttribute('showResultCount')
        ]);

        $this->setJavaScriptControlOption('resultcount', $searchResult['count']);

        return $Engine->fetch(dirname(__FILE__) . '/Search.html');
    }

    /**
     * Set control attributes by reading $_REQUEST
     *
     * @return void
     */
    public function setAttributesFromRequest(): void
    {
        // requests
        if (isset($_REQUEST['sheet'])) {
            $this->setAttribute('sheet', $_REQUEST['sheet']);
        }

        if (isset($_REQUEST['max'])) {
            $this->setAttribute('max', (int)$_REQUEST['max']);
        }

        if (!empty($_REQUEST['fieldConstraints'])) {
            $this->setAttribute('fieldConstraints', json_decode($_REQUEST['fieldConstraints'], true));
        }

        if (isset($_REQUEST['search'])) {
            $this->setAttribute('search', $_REQUEST['search']);
        }

        if (
            isset($_REQUEST['searchType'])
            && $_REQUEST['searchType'] == self::SEARCH_TYPE_AND
        ) {
            $this->setAttribute('searchType', self::SEARCH_TYPE_AND);
        }

        $fields = [];

        // search fields
        if (isset($_REQUEST['searchIn'])) {
            if (!is_array($_REQUEST['searchIn'])) {
                $_REQUEST['searchIn'] = explode(',', urldecode($_REQUEST['searchIn']));
            }

            foreach ($_REQUEST['searchIn'] as $field) {
                if (!is_string($field)) {
                    continue;
                }

                $fields[] = Orthos::clear($field);
            }

            $this->setAttribute('searchFields', $fields);
        }

        $this->sanitizeAttributes();
    }

    /**
     * Clears the given search fields (remove invalid fields)
     *
     * @param array<array-key, mixed> $fields
     * @return array<array-key, mixed> Cleared fields
     */
    protected function clearSearchFields(array $fields): array
    {
        if (empty($fields)) {
            return $this->getDefaultSearchFields();
        }

        $allFields = [];

        foreach (Fulltext::getFieldList() as $entry) {
            $allFields[] = $entry['field'];
        }

        $settingsFields = $this->Site->getAttribute('quiqqer.settings.search.list.fields.selected');
        $filteredFields = [];

        if (!empty($settingsFields) && is_array($settingsFields)) {
            $settingsFields = array_flip($settingsFields);

            foreach ($fields as $field) {
                if (isset($settingsFields[$field])) {
                    $filteredFields[] = $field;
                }
            }

            return $filteredFields;
        }

        $allFields = array_flip($allFields);

        foreach ($fields as $field) {
            if (isset($allFields[$field])) {
                $filteredFields[] = $field;
            }
        }

        return $filteredFields;
    }

    /**
     * Check all attributes for validity (and sets invalid attributes to valid/default value)
     *
     * @return void
     */
    protected function sanitizeAttributes(): void
    {
        $attributes = $this->getAttributes();

        foreach ($attributes as $k => $v) {
            switch ($k) {
                case 'search':
                    if (!is_string($v)) {
                        $v = '';
                        break;
                    }
                    break;

                case 'searchType':
                    if ($v !== $this::SEARCH_TYPE_OR) {
                        $settingsFields = $this->Site->getAttribute('quiqqer.settings.search.list.fields');

                        if (is_array($settingsFields)) {
                            if (in_array('searchTypeAnd', $settingsFields)) {
                                $v = $this::SEARCH_TYPE_AND;
                            } else {
                                $v = $this::SEARCH_TYPE_OR;
                            }
                        } else {
                            $v = $this::SEARCH_TYPE_AND;
                        }
                    }
                    break;

                case 'max':
                case 'sheet':
                    $v = (int)$v;
                    break;

                case 'searchFields':
                    if (!is_array($v)) {
                        $v = $this->getDefaultSearchFields();
                        break;
                    }

                    $availableFields = $this->Site->getAttribute(
                        'quiqqer.settings.search.list.fields'
                    );
                    $selectedFields = $this->Site->getAttribute(
                        'quiqqer.settings.search.list.fields.selected'
                    );

                    if (empty($availableFields)) {
                        $availableFields = [];
                    }

                    if (!empty($selectedFields)) {
                        foreach ($selectedFields as $j => $field) {
                            if (!in_array($field, $v) && in_array($field, $availableFields)) {
                                unset($selectedFields[$j]);
                            }
                        }

                        $v = array_values($selectedFields);
                    }

                    $v = $this->clearSearchFields($v);
                    break;

                case 'orderFields':
                    if (!is_array($v)) {
                        $v = $this->getDefaultSearchFields();
                        break;
                    }
                    break;

                case 'suggestSearch':
                case 'relevanceSearch':
                    $v = (bool)$v;
                    break;

                case 'fieldConstraints':
                    if (!is_array($v)) {
                        $v = [];
                        break;
                    }

                    $fields = $this->clearSearchFields(array_keys($v));
                    $constraints = [];

                    foreach ($v as $field => $constraint) {
                        if (!in_array($field, $fields)) {
                            continue;
                        }

                        if (!is_array($constraint) && !is_string($constraint)) {
                            continue;
                        }

                        $constraints[$field] = [];

                        if (is_array($constraint)) {
                            if (isset($constraint['value']) || isset($constraint['type'])) {
                                $constraint = [$constraint];
                            }

                            foreach ($constraint as $value) {
                                if (!is_string($value) && !is_array($value)) {
                                    continue;
                                }

                                if (is_array($value)) {
                                    if (
                                        !isset($value['value'], $value['type'])
                                        || !is_string($value['value'])
                                        || $value['type'] !== 'LIKE'
                                    ) {
                                        continue;
                                    }

                                    $sanitizedValue = self::sanitizeSearchString($value['value']);

                                    if ($sanitizedValue === '') {
                                        continue;
                                    }

                                    $constraints[$field][] = [
                                        'value' => $sanitizedValue,
                                        'type' => 'LIKE'
                                    ];
                                } else {
                                    $sanitizedValue = self::sanitizeSearchString($value);

                                    if ($sanitizedValue !== '') {
                                        $constraints[$field][] = $sanitizedValue;
                                    }
                                }
                            }
                        } else {
                            $sanitizedValue = self::sanitizeSearchString($constraint);

                            if ($sanitizedValue !== '') {
                                $constraints[$field][] = $sanitizedValue;
                            }
                        }
                    }

                    $v = $constraints;
                    break;

                case 'paginationType':
                    if (empty($v)) {
                        $v = $this->getPaginationType();
                    }
                    break;

                case 'childrenListTemplate':
                    $directory = dirname(__FILE__, 5);

                    if (!file_exists($v)) {
                        $v = $directory . '/templates/SearchResultList.html';
                    }
                    break;

                case 'childrenListCss':
                    $directory = dirname(__FILE__, 5);

                    if (!file_exists($v)) {
                        $v = $directory . '/templates/SearchResultList.css';
                    }
                    break;
            }

            $attributes[$k] = $v;
        }

        $this->setAttributes($attributes);
    }

    /**
     * Get the default search fields
     *
     * @return array<array-key, mixed>
     */
    protected function getDefaultSearchFields(): array
    {
        $allFields = [];

        foreach (Fulltext::getFieldList() as $entry) {
            $allFields[] = $entry['field'];
        }

        $settingsFields = $this->Site->getAttribute('quiqqer.settings.search.list.fields');
        $settingsFieldsSelected = $this->Site->getAttribute(
            'quiqqer.settings.search.list.fields.selected'
        );

        if (!is_array($settingsFields)) {
            $settingsFields = [];
        }

        if (!is_array($settingsFieldsSelected)) {
            $settingsFieldsSelected = [];
        }

        // if no fields have been selected, use configured available fields
        // or fallback to all fields if no configuration exists
        if (empty($settingsFieldsSelected)) {
            if (!empty($settingsFields)) {
                return $settingsFields;
            }

            return $allFields;
        }

        return $settingsFieldsSelected;
    }

    /**
     * Get attributes for the javascript control
     *
     * @return array<string, mixed>
     */
    protected function getJavaScriptControlAttributes(): array
    {
        $attributes = $this->getAttributes();

        foreach ($attributes as $k => $v) {
            if (is_string($v)) {
                $attributes[$k] = str_replace(OPT_DIR, '', $v);
            }
        }

        return $attributes;
    }

    /**
     * Sanitizes a search string
     *
     * @param string $str
     * @return string - sanitized string
     */
    protected static function sanitizeSearchString(string $str): string
    {
        return QUI\Search\Utils::sanitizeSearchString($str);
    }

    /**
     * Get search list pagination type
     *
     * @return string|false - pagination type or false if no pagination required
     */
    protected function getPaginationType(): bool|string
    {
        $paginationType = $this->getAttribute('paginationType');

        if (empty($paginationType)) {
            $paginationType = $this->Site->getAttribute('quiqqer.search.sitetypes.search.pagination.type');
        }

        if (empty($paginationType)) {
            $paginationType = $this::PAGINATION_TYPE_PAGINATION;
        }

        return $paginationType;
    }
}
