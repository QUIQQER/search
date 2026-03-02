<?php

/**
 * This file contains the category site type
 *
 * @var QUI\Projects\Project $Project
 * @var QUI\Projects\Site $Site
 * @var QUI\Interfaces\Template\EngineInterface $Engine
 * @var QUI\Template $Template
 **/

use QUI\Search\Controls\Search;
use QUI\Search\Controls\SearchInput;

//
// 404 Error Site
//

if (
    isset($_REQUEST['sheet'])
    && is_numeric($_REQUEST['sheet'])
    && (int)$_REQUEST['sheet'] > 1

    || isset($_REQUEST['limit'])
) {
    $Site->setAttribute('meta.robots', 'noindex,follow');
}

if (QUI::getRewrite()->getHeaderCode() === 404) {
    if (isset($_REQUEST['_url'])) {
        $requestUrl = $_REQUEST['_url'];
        $path = pathinfo($requestUrl);

        $search = array_values($path);              // get only the values
        $search = implode(' ', $search);            // create a string
        $search = str_replace('-', ' ', $search);   // replace all "-" with " " (space)
        $search = str_replace('.', '', $search);    // remove all -
        $search = trim($search);

        $search = explode(' ', $search);
        $search = array_unique($search);
        $search = implode(' ', $search);

        $_REQUEST['search'] = $search;
    }
}

/**
 * Settings
 */
$fields = $Site->getAttribute('quiqqer.settings.search.list.fields');
$searchType = Search::SEARCH_TYPE_OR;

if (is_string($fields)) {
    $fields = json_decode($fields, true);
}

if (!is_array($fields)) {
    $fields = [];
}

if (in_array('searchTypeAnd', $fields)) {
    $searchType = Search::SEARCH_TYPE_AND;
}

$SearchInput = new SearchInput([
    'suggestSearch' => $Site->getAttribute('quiqqer.search.sitetypes.search.suggestSearch'),
    'availableFields' => $fields,
    'fields' => $Site->getAttribute('quiqqer.settings.search.list.fields.selected'),
    'searchType' => $searchType,
    'showFieldSettings' => !$Site->getAttribute('quiqqer.settings.search.list.hideSettings')
]);

$Search = new Search();

$Search->setAttributesFromRequest();
$SearchInput->setAttributesFromRequest();

$Engine->assign([
    'SearchInput' => $SearchInput,
    'Search' => $Search
]);
