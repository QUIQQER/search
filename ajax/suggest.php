<?php

/**
 * This file contains package_quiqqer_search_ajax_suggest
 */

/**
 * Return the suggest html search result
 *
 * @param string $project - project data
 * @param string $search - search string
 *
 * @return string
 */
QUI::getAjax()->registerFunction(
    'package_quiqqer_search_ajax_suggest',
    function ($project, $siteId, $search) {
        $Project = QUI::getProjectManager()->decode($project);
        $Site = $Project->get($siteId);
        $siteTypesFilter = $Site->getAttribute('quiqqer.settings.search.sitetypes.filter');

        if (!empty($siteTypesFilter)) {
            $siteTypesFilter = explode(';', $siteTypesFilter);
        } else {
            $siteTypesFilter = [];
        }

        $QuickSearch = new QUI\Search\Quicksearch([
            'siteTypes' => $siteTypesFilter
        ]);

        $result = $QuickSearch->search($search, $Project, [
            'limit' => 10
        ]);

        if (empty($result['list'])) {
            return false;
        }

        $list = '<ul>';

        foreach ($result['list'] as $entry) {
            try {
                if ($entry['siteType'] === 'custom') {
                    $customData = json_decode($entry['custom_data'], true);

                    $Site = new QUI\Search\Items\CustomSearchItem(
                        $entry['custom_id'],
                        $entry['origin'],
                        $customData['title'],
                        $customData['url'],
                        $customData['attributes']
                    );
                } else {
                    $Site = $Project->get($entry['siteId']);
                }

                $url = $Site->getUrlRewritten();
            } catch (QUI\Exception) {
                continue;
            }

            $list .= '<li data-id="' . $entry['id'] . '" data-url="' . $url . '">';
            $list .= '<div class="quiqqer-search-suggest-icon">';

            if (empty($entry['icon'])) {
                $list .= '<span class="fa fa-file-o"></span>';
            } else {
                $list .= '<span class="fa ' . $entry['icon'] . '"></span>';
            }

            $list .= '</div>';
            $list .= '<div class="quiqqer-search-suggest-text">';
            $list .= $Site->getAttribute('title');
            $list .= '</div>';
            $list .= '</li>';
        }

        $list .= '</ul>';

        return $list;
    },
    ['project', 'siteId', 'search']
);
