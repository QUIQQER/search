<?php

/**
 * This file contains QUI\Search\Bricks\Search
 */

namespace QUI\Search\Bricks;

use QUI;

use function count;
use function dirname;

/**
 * Class Search
 *
 * @package namerobot/template-namingservice
 */
class Search extends QUI\Control
{
    /**
     * constructor
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        // default options
        $this->setAttributes([
            'class' => 'search-brick',
            'resultSite' => false,
            'suggestSearch' => false

        ]);

        parent::__construct($attributes);
    }

    /**
     * Return the inner body of the element
     * Can be overwritten
     *
     * @return String
     */
    public function getBody(): string
    {
        //QUI::getPackage('quiqqer/search');
        $Engine = QUI::getTemplateManager()->getEngine();
        $Site = $this->getAttribute('Site');
        $Project = $Site->getProject();

        $resultSite = $this->getAttribute('resultSite');

        if (!$resultSite) {
            $types = [
                'quiqqer/sitetypes:types/search',
                'quiqqer/search:types/search'
            ];

            $searchSites = $Project->getSites([
                'where' => [
                    'type' => [
                        'type' => 'IN',
                        'value' => $types
                    ]
                ],
                'limit' => 1
            ]);

            if (count($searchSites)) {
                $resultSite = $searchSites[0]->getUrlRewritten();
            }
        }

        $suggestSearch = '';

        if ($this->getAttribute('suggestSearch')) {
            $suggestSearch = 'package/quiqqer/search/bin/controls/SuggestLazy';
        }


        $Engine->assign([
            'this' => $this,
            'resultSite' => $resultSite,
            'suggestSearch' => $suggestSearch
        ]);

        $this->addCSSFile(dirname(__FILE__) . '/Search.css');

        return $Engine->fetch(dirname(__FILE__) . '/Search.html');
    }
}
