<?php

/**
 * This file contains package_quiqqer_search_ajax_search
 */

use QUI\Output;
use QUI\Search\Controls\Search;
use QUI\Utils\Security\Orthos;

/**
 * Execute search and result ChildrenList HTML
 *
 * @param string $searchParams - search parameters
 * @return array - search result data
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_search_ajax_search',
    function ($project, $siteId, $searchParams) {
        $Project = QUI::getProjectManager()->decode($project);
        $Site = $Project->get($siteId);

        //        $searchParams         = Orthos::clearArray();
        $searchParams = json_decode($searchParams, true);
        $searchParams['Site'] = $Site;

        // clear certain attributes
        foreach ($searchParams as $k => $v) {
            switch ($k) {
                case 'childrenListTemplate':
                case 'childrenListCss':
                    if (!is_string($v)) {
                        $v = '';
                    }

                    $v = OPT_DIR . $v;
                    $v = Orthos::clear($v);
                    break;
            }

            $searchParams[$k] = $v;
        }

        $Search = new Search($searchParams);
        $searchResult = $Search->search();

        $Output = new Output();
        $childrenListHtml = $Output->parse($Search->getChildrenList()->create());

        return [
            'childrenListHtml' => $childrenListHtml,
            'sheets' => $searchResult['sheets'],
            'count' => $searchResult['count'],
            'more' => $searchResult['more']
        ];
    },
    ['project', 'siteId', 'searchParams']
);
