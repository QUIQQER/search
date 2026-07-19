<?php

/**
 * This file contains package_quiqqer_search_ajax_getSearchSiteUrl
 */

/**
 * Get Project main Search Site
 *
 * @param string $project - json encoded project
 * @param array $getParams - Search get params
 * @return string|false - SearchSite URL with search params or false if no SearchSite set
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_search_ajax_getSearchSiteUrl',
    function ($project, $getParams) {
        $getParams = json_decode($getParams, true);
        $Project = QUI::getProjectManager()->decode($project);
        $SearchSite = false;
        $defaultSearchSiteIds = $Project->getConfig('quiqqer_search_settings.defaultSearchSite');

        if (!empty($defaultSearchSiteIds)) {
            $defaultSearchSiteIds = json_decode($defaultSearchSiteIds, true);
            $lang = $Project->getLang();

            if (!empty($defaultSearchSiteIds[$lang])) {
                try {
                    $SearchSite = $Project->get($defaultSearchSiteIds[$Project->getLang()]);
                } catch (Exception) {
                    // nothing
                }
            }
        }

        if (!$SearchSite) {
            return false;
        }

        // set default search params
        $defaultSearchParams = [
            'quiqqer.settings.search.list.max' => 'max',
            'quiqqer.settings.search.list.fields' => 'searchFields'
        ];

        foreach ($defaultSearchParams as $siteParam => $searchParam) {
            if (isset($getParams[$searchParam])) {
                continue;
            }

            if ($SearchSite->getAttribute($siteParam)) {
                $attr = $SearchSite->getAttribute($siteParam);

                if (is_array($attr)) {
                    $attr = implode(',', $attr);
                }

                $getParams[$searchParam] = $attr;
            }
        }

        return $SearchSite->getUrlRewritten([], $getParams);
    },
    ['project', 'getParams']
);
