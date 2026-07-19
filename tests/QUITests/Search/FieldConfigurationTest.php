<?php

namespace QUITests\Search;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Search\Fulltext;
use QUI\Search\Quicksearch;

class FieldConfigurationTest extends TestCase
{
    public function testSearchXmlAndFieldListsAreAvailableAndCached(): void
    {
        QUI\Cache\Manager::clear('quiqqer/search/xmlList');
        QUI\Cache\Manager::clear('quiqqer/search/fieldList');

        $xmlFiles = Fulltext::getSearchXmlList();
        $fields = Fulltext::getFieldList();

        self::assertArrayHasKey('quiqqer/search', $xmlFiles);
        self::assertFileExists($xmlFiles['quiqqer/search']);
        self::assertContains('name', array_column($fields, 'field'));
        self::assertContains('title', array_column($fields, 'field'));
        self::assertSame($xmlFiles, Fulltext::getSearchXmlList());
        self::assertSame($fields, Fulltext::getFieldList());
    }

    public function testManagerConstructorsApplyDefaultsAndOverrides(): void
    {
        $Fulltext = new Fulltext([
            'limit' => 25,
            'searchtype' => 'AND'
        ]);
        $Quicksearch = new Quicksearch([
            'siteTypes' => ['quiqqer/search:types/search']
        ]);

        self::assertSame(25, $Fulltext->getAttribute('limit'));
        self::assertSame('AND', $Fulltext->getAttribute('searchtype'));
        self::assertTrue($Fulltext->getAttribute('relevanceSearch'));
        self::assertSame(
            ['quiqqer/search:types/search'],
            $Quicksearch->getAttribute('siteTypes')
        );
    }
}
