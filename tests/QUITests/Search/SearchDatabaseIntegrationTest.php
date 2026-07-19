<?php

namespace QUITests\Search;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Project;
use QUI\Projects\Site as ProjectSite;
use QUI\Search;
use QUI\Search\Controls\Search as SearchControl;
use QUI\Search\Database as SearchDatabase;
use QUI\Search\Fulltext;
use QUI\Search\Items\CustomSearchItem;
use QUI\Search\Quicksearch;

class SearchDatabaseIntegrationTest extends TestCase
{
    private const ORIGIN = 'quiqqer/search-phpunit';
    private const CUSTOM_ID = 2_147_483_002;
    private const CUSTOM_ID_SECOND = 2_147_483_003;
    private const EXCLUDED_SITE_ID = 2_147_483_004;
    private const URL_PARAMS = ['quiqqer_search_phpunit' => 'standard'];
    private const URL_PARAMS_SECOND = ['quiqqer_search_phpunit' => 'second'];

    private Project $Project;

    private Connection $Connection;

    private string $fulltextTable;

    private string $quicksearchTable;

    protected function setUp(): void
    {
        $this->Project = QUI::getProjectManager()->get();
        $this->Connection = QUI::getDataBaseConnection();
        $this->fulltextTable = QUI::getDBProjectTableName(Search::TABLE_SEARCH_FULL, $this->Project);
        $this->quicksearchTable = QUI::getDBProjectTableName(Search::TABLE_SEARCH_QUICK, $this->Project);

        $this->cleanFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();
    }

    public function testFulltextCustomEntryLifecycleAndSearch(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Alpha');

        try {
            Fulltext::getCustomEntry($this->Project, $Item);
            self::fail('The custom fixture must not exist before insertion.');
        } catch (QUI\Exception) {
            self::assertTrue(true);
        }

        Fulltext::setCustomEntry($this->Project, $Item, [
            'name' => 'search-phpunit-alpha',
            'title' => 'Search PHPUnit Alpha',
            'short' => 'A unique integration fixture',
            'data' => 'alpha needle searchable content',
            'e_date' => 1_700_000_001,
            'c_date' => 1_700_000_000,
            'icon' => 'fa fa-search'
        ]);

        $entry = Fulltext::getCustomEntry($this->Project, $Item);

        self::assertSame((string)self::CUSTOM_ID, (string)$entry['custom_id']);
        self::assertSame(self::ORIGIN, $entry['origin']);
        self::assertSame('custom', $entry['datatype']);
        self::assertSame('Search PHPUnit Alpha', $entry['title']);
        self::assertSame('alpha needle searchable content', $entry['data']);

        Fulltext::setCustomEntry($this->Project, $Item, [
            'title' => 'Search PHPUnit Alpha Updated',
            'data' => 'alpha needle updated content'
        ]);

        $updatedEntry = Fulltext::getCustomEntry($this->Project, $Item);

        self::assertSame('Search PHPUnit Alpha Updated', $updatedEntry['title']);
        self::assertSame('alpha needle updated content', $updatedEntry['data']);

        $Search = new Fulltext([
            'Project' => $this->Project,
            'limit' => '0,10',
            'fields' => ['name', 'title', 'short', 'data'],
            'fieldConstraints' => ['datatype' => 'custom'],
            'relevanceSearch' => false,
            'datatypes' => 'custom'
        ]);
        $result = $Search->search('alpha needle');

        self::assertGreaterThanOrEqual(1, (int)$result['count']);
        self::assertContains(
            (string)self::CUSTOM_ID,
            array_map('strval', array_column($result['list'], 'custom_id'))
        );

        Fulltext::removeCustomEntry($this->Project, $Item);

        $this->expectException(QUI\Exception::class);
        Fulltext::getCustomEntry($this->Project, $Item);
    }

    public function testQuicksearchCustomEntryLifecycleGroupingAndFilters(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Quick Alpha');
        $SecondItem = $this->createCustomItem(self::CUSTOM_ID_SECOND, 'Search PHPUnit Quick Beta');
        $sharedTerm = 'search-phpunit-shared-term';

        self::assertFalse(Quicksearch::existsCustomEntry($this->Project, $Item, $sharedTerm));

        Quicksearch::setCustomEntry($this->Project, $Item, [$sharedTerm, '', 'search-phpunit-alpha']);
        Quicksearch::setCustomEntry($this->Project, $SecondItem, [$sharedTerm]);

        self::assertTrue(Quicksearch::existsCustomEntry($this->Project, $Item, $sharedTerm));
        self::assertTrue(Quicksearch::existsCustomEntry($this->Project, $SecondItem, $sharedTerm));

        $Item->setAttribute('icon', 'fa fa-search-updated');
        Quicksearch::setCustomEntry($this->Project, $Item, [$sharedTerm]);

        $grouped = (new Quicksearch(['siteTypes' => 'custom']))->search(
            'search-phpunit-shared',
            $this->Project,
            ['limit' => 10]
        );
        $ungrouped = (new Quicksearch(['siteTypes' => ['custom', null, '', 123]]))->search(
            'search-phpunit-shared',
            $this->Project,
            ['limit' => '0,10', 'group' => false]
        );

        self::assertSame(1, count($grouped['list']));
        self::assertSame(1, (int)$grouped['count']);
        self::assertSame(2, count($ungrouped['list']));
        self::assertSame(2, (int)$ungrouped['count']);

        Quicksearch::removeCustomEntries($this->Project, $Item);
        Quicksearch::removeCustomEntries($this->Project, $SecondItem);

        self::assertFalse(Quicksearch::existsCustomEntry($this->Project, $Item, $sharedTerm));
        self::assertFalse(Quicksearch::existsCustomEntry($this->Project, $SecondItem, $sharedTerm));
    }

    public function testFulltextSearchModesConstraintsAndPagination(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Mode Alpha');
        $SecondItem = $this->createCustomItem(self::CUSTOM_ID_SECOND, 'Search PHPUnit Mode Beta');

        Fulltext::setCustomEntry($this->Project, $Item, [
            'name' => 'mode-alpha',
            'title' => 'Fulltext Mode Alpha',
            'short' => 'shared searchable phrase',
            'data' => 'fulltextmodealpha unique needle',
            'e_date' => 1_700_000_020
        ]);
        Fulltext::setCustomEntry($this->Project, $SecondItem, [
            'name' => 'mode-beta',
            'title' => 'Fulltext Mode Beta',
            'short' => 'shared searchable phrase',
            'data' => 'fulltextmodebeta unique needle',
            'e_date' => 1_700_000_021
        ]);

        $relevanceResult = (new Fulltext([
            'Project' => $this->Project,
            'limit' => '0,10',
            'fields' => ['short', 'data'],
            'relevanceSearch' => true,
            'datatypes' => ['custom']
        ]))->search('fulltextmodealpha');

        self::assertGreaterThanOrEqual(1, (int)$relevanceResult['count']);
        self::assertContains(
            (string)self::CUSTOM_ID,
            array_map('strval', array_column($relevanceResult['list'], 'custom_id'))
        );

        $constrainedResult = (new Fulltext([
            'Project' => $this->Project,
            'limit' => '0,1',
            'fields' => ['invalid-field'],
            'searchtype' => SearchControl::SEARCH_TYPE_AND,
            'relevanceSearch' => false,
            'datatypes' => 'custom',
            'fieldConstraints' => [
                'title' => [
                    ['value' => 'Mode Alpha', 'type' => 'LIKE'],
                    '',
                    ['value' => 'ignored', 'type' => 'INVALID']
                ],
                'invalid-field' => 'ignored'
            ],
            'orderFields' => [
                'e_date DESC',
                'title DESC, (SELECT 1)',
                'missing_field ASC',
                'name SIDEWAYS'
            ]
        ]))->search('shared phrase');

        self::assertSame(1, count($constrainedResult['list']));
        self::assertSame(1, (int)$constrainedResult['count']);
        self::assertSame((string)self::CUSTOM_ID, (string)$constrainedResult['list'][0]['custom_id']);

        $defaultResult = (new Fulltext([
            'Project' => $this->Project,
            'limit' => '0,5',
            'fields' => false,
            'relevanceSearch' => false
        ]))->search('fulltextmodebeta');

        self::assertContains(
            (string)self::CUSTOM_ID_SECOND,
            array_map('strval', array_column($defaultResult['list'], 'custom_id'))
        );
    }

    public function testFulltextFallsBackToDefaultProject(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Default Project');

        Fulltext::setCustomEntry($this->Project, $Item, [
            'name' => 'default-project-result',
            'title' => 'Search PHPUnit Default Project',
            'short' => 'default project fallback',
            'data' => 'defaultprojectneedle searchable content',
            'e_date' => 1_700_000_025
        ]);

        $Search = new Fulltext([
            'Project' => false,
            'limit' => '0,10',
            'fields' => ['name', 'title', 'short', 'data'],
            'relevanceSearch' => false
        ]);

        $result = $Search->search('defaultprojectneedle');

        self::assertSame(1, (int)$result['count']);
        self::assertSame((string)self::CUSTOM_ID, (string)$result['list'][0]['custom_id']);
    }

    public function testFulltextIntegerLimitRestrictsResultList(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Integer Limit Alpha');
        $SecondItem = $this->createCustomItem(self::CUSTOM_ID_SECOND, 'Search PHPUnit Integer Limit Beta');

        Fulltext::setCustomEntry($this->Project, $Item, [
            'name' => 'integer-limit-alpha',
            'title' => 'Search PHPUnit Integer Limit Alpha',
            'short' => 'integer limit shared fixture',
            'data' => 'integerlimitneedle alpha',
            'e_date' => 1_700_000_026
        ]);
        Fulltext::setCustomEntry($this->Project, $SecondItem, [
            'name' => 'integer-limit-beta',
            'title' => 'Search PHPUnit Integer Limit Beta',
            'short' => 'integer limit shared fixture',
            'data' => 'integerlimitneedle beta',
            'e_date' => 1_700_000_027
        ]);

        $Search = new Fulltext([
            'Project' => $this->Project,
            'limit' => 1,
            'fields' => ['name', 'title', 'short', 'data'],
            'datatypes' => ['custom'],
            'relevanceSearch' => false
        ]);

        $result = $Search->search('integerlimitneedle');

        self::assertSame(2, (int)$result['count']);
        self::assertCount(1, $result['list']);
    }

    public function testSearchControlBuildsCustomResultsAndCachesThem(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Control Result');

        Fulltext::setCustomEntry($this->Project, $Item, [
            'name' => 'control-result',
            'title' => 'Search PHPUnit Control Result',
            'short' => 'control integration result',
            'data' => 'controlneedle searchable content',
            'e_date' => 1_700_000_030,
            'icon' => 'fa fa-search'
        ]);

        $Site = $this->Project->get($this->getActiveSiteId());
        $Site->setAttribute('quiqqer.settings.search.list.fields', ['name', 'title', 'short', 'data']);
        $Site->setAttribute('quiqqer.settings.search.list.fields.selected', ['name', 'title', 'short', 'data']);

        $Control = new SearchControl([
            'Site' => $Site,
            'search' => 'controlneedle',
            'searchFields' => ['name', 'title', 'short', 'data'],
            'datatypes' => ['custom'],
            'max' => 1,
            'sheet' => 1,
            'relevanceSearch' => false
        ]);

        $result = $Control->search();

        self::assertSame(1, $result['count']);
        self::assertSame(1, $result['max']);
        self::assertSame(1, $result['sheets']);
        self::assertFalse($result['more']);
        self::assertCount(1, $result['children']);
        self::assertInstanceOf(CustomSearchItem::class, $result['children'][0]);
        self::assertSame('Search PHPUnit Control Result', $result['children'][0]->getAttribute('search-title'));
        self::assertSame($result, $Control->search());
        self::assertNotSame('', $Control->getBody());
    }

    public function testFulltextStandardEntryLifecycle(): void
    {
        $siteId = $this->getActiveSiteId();

        try {
            Fulltext::getEntry($this->Project, $siteId, self::URL_PARAMS);
            self::fail('The standard fixture must not exist before insertion.');
        } catch (QUI\Exception) {
            self::assertTrue(true);
        }

        Fulltext::setEntry($this->Project, $siteId, [
            'name' => 'search-phpunit-standard',
            'title' => 'Search PHPUnit Standard',
            'short' => 'Standard fixture',
            'data' => 'initial content',
            'e_date' => 1_700_000_010,
            'c_date' => 1_700_000_000,
            'icon' => 'fa fa-search'
        ], self::URL_PARAMS);

        $entry = Fulltext::getEntry($this->Project, $siteId, self::URL_PARAMS);

        self::assertSame('Search PHPUnit Standard', $entry['title']);
        self::assertSame('initial content', $entry['data']);

        Fulltext::appendFulltextSearchString(
            $this->Project,
            $siteId,
            'appended content',
            self::URL_PARAMS
        );

        $entry = Fulltext::getEntry($this->Project, $siteId, self::URL_PARAMS);
        self::assertSame('initial content appended content', $entry['data']);

        Fulltext::setEntryData(
            $this->Project,
            $siteId,
            ['title' => 'Search PHPUnit Standard Updated'],
            self::URL_PARAMS
        );

        $entry = Fulltext::getEntry($this->Project, $siteId, self::URL_PARAMS);
        self::assertSame('Search PHPUnit Standard Updated', $entry['title']);

        Fulltext::removeEntry($this->Project, $siteId, self::URL_PARAMS);

        $this->expectException(QUI\Exception::class);
        Fulltext::getEntry($this->Project, $siteId, self::URL_PARAMS);
    }

    public function testQuicksearchStandardEntryLifecycleAndPagination(): void
    {
        $siteId = $this->getActiveSiteId();
        $Site = $this->Project->get($siteId);

        Quicksearch::setEntries(
            $this->Project,
            $siteId,
            ['search-phpunit-standard-alpha', 'search-phpunit-standard-beta'],
            self::URL_PARAMS
        );

        self::assertTrue(Quicksearch::existsEntry(
            $this->Project,
            $siteId,
            'search-phpunit-standard-alpha',
            self::URL_PARAMS
        ));
        self::assertSame(
            (string)$siteId,
            (string)Quicksearch::getEntry($this->Project, $siteId, self::URL_PARAMS)['siteId']
        );

        Quicksearch::addEntry(
            $this->Project,
            $siteId,
            'search-phpunit-standard-alpha',
            self::URL_PARAMS
        );
        Quicksearch::addEntry(
            $this->Project,
            $siteId,
            'search-phpunit-standard-gamma',
            self::URL_PARAMS
        );

        $Search = new Quicksearch(['siteTypes' => $Site->getAttribute('type')]);
        $firstPage = $Search->search('search-phpunit-standard', $this->Project, [
            'limit' => '0,1',
            'group' => false
        ]);
        $secondPage = $Search->search('search-phpunit-standard', $this->Project, [
            'limit' => '1,1',
            'group' => false
        ]);

        self::assertSame(1, count($firstPage['list']));
        self::assertSame(1, count($secondPage['list']));
        self::assertSame(3, (int)$firstPage['count']);
        self::assertNotSame($firstPage['list'][0]['id'], $secondPage['list'][0]['id']);

        Quicksearch::removeEntries($this->Project, $siteId, self::URL_PARAMS);

        self::assertFalse(Quicksearch::existsEntry(
            $this->Project,
            $siteId,
            'search-phpunit-standard-alpha',
            self::URL_PARAMS
        ));

        $this->expectException(QUI\Exception::class);
        Quicksearch::getEntry($this->Project, $siteId, self::URL_PARAMS);
    }

    public function testInvalidStandardEntriesAreIgnored(): void
    {
        Quicksearch::setEntries($this->Project, 0, ['ignored'], self::URL_PARAMS_SECOND);
        Quicksearch::setEntries($this->Project, $this->getActiveSiteId(), [], self::URL_PARAMS_SECOND);
        Quicksearch::setEntries($this->Project, PHP_INT_MAX, ['ignored'], self::URL_PARAMS_SECOND);
        Quicksearch::addEntry($this->Project, 0, 'ignored', self::URL_PARAMS_SECOND);
        Quicksearch::addEntry($this->Project, $this->getActiveSiteId(), '', self::URL_PARAMS_SECOND);
        Quicksearch::addEntry($this->Project, PHP_INT_MAX, 'ignored', self::URL_PARAMS_SECOND);
        Quicksearch::removeEntries($this->Project, 0, self::URL_PARAMS_SECOND);
        Fulltext::setEntryData($this->Project, PHP_INT_MAX, ['title' => 'ignored'], self::URL_PARAMS_SECOND);
        Fulltext::appendFulltextSearchString($this->Project, PHP_INT_MAX, 'ignored', self::URL_PARAMS_SECOND);

        self::assertSame(0, $this->fixtureRowCount());
    }

    public static function excludedSiteProvider(): array
    {
        return [
            'inactive' => [false, false, false],
            'deleted' => [true, true, false],
            'not indexed' => [true, false, true]
        ];
    }

    #[DataProvider('excludedSiteProvider')]
    public function testSiteChangeRemovesExcludedSiteEntries(
        bool $active,
        bool $deleted,
        bool $notIndexed
    ): void {
        $fixture = [
            'siteId' => self::EXCLUDED_SITE_ID,
            'urlParameter' => json_encode(self::URL_PARAMS_SECOND),
            'origin' => self::ORIGIN
        ];

        $this->Connection->insert($this->fulltextTable, $fixture);
        $this->Connection->insert($this->quicksearchTable, $fixture);

        $Site = $this->createMock(ProjectSite::class);
        $Site->method('getProject')->willReturn($this->Project);
        $Site->method('getId')->willReturn(self::EXCLUDED_SITE_ID);
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $attribute): mixed => match ($attribute) {
                'type' => 'quiqqer/core:types/html',
                'active' => $active,
                'deleted' => $deleted,
                'quiqqer.settings.search.not.indexed' => $notIndexed,
                default => null
            }
        );

        self::assertSame(2, $this->fixtureRowCount());

        Search::onSiteChange($Site);

        self::assertSame(0, $this->fixtureRowCount());
    }

    public function testRebuildPreservesCustomEntriesAndRemovesObsoleteSites(): void
    {
        $Item = $this->createCustomItem(self::CUSTOM_ID, 'Search PHPUnit Rebuild');

        Fulltext::setCustomEntry($this->Project, $Item, [
            'title' => 'Search PHPUnit Rebuild',
            'data' => 'search-phpunit-rebuild'
        ]);
        Quicksearch::setCustomEntry($this->Project, $Item, [
            'search-phpunit-rebuild'
        ]);

        $obsoleteFixture = [
            'siteId' => self::EXCLUDED_SITE_ID,
            'urlParameter' => json_encode(self::URL_PARAMS_SECOND),
            'origin' => self::ORIGIN
        ];

        $this->Connection->insert($this->fulltextTable, $obsoleteFixture);
        $this->Connection->insert($this->quicksearchTable, $obsoleteFixture);

        $Search = new Search();
        $Search->createFulltextSearch($this->Project);
        $Search->createQuicksearch($this->Project);

        self::assertSame(
            (string)self::CUSTOM_ID,
            (string)Fulltext::getCustomEntry($this->Project, $Item)['custom_id']
        );
        self::assertTrue(Quicksearch::existsCustomEntry(
            $this->Project,
            $Item,
            'search-phpunit-rebuild'
        ));
        self::assertSame(0, $this->siteEntryCount($this->fulltextTable, self::EXCLUDED_SITE_ID));
        self::assertSame(0, $this->siteEntryCount($this->quicksearchTable, self::EXCLUDED_SITE_ID));
    }

    public function testDatabaseExceptionsAreWrapped(): void
    {
        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier($this->fulltextTable . '_missing'));

        $this->expectException(QUI\Database\Exception::class);
        SearchDatabase::fetchAllAssociative($QueryBuilder);
    }

    private function createCustomItem(int $id, string $title): CustomSearchItem
    {
        $Item = new CustomSearchItem(
            $id,
            self::ORIGIN,
            $title,
            '/search-phpunit/' . $id,
            ['icon' => 'fa fa-search']
        );
        $Item->setProject($this->Project);

        return $Item;
    }

    private function getActiveSiteId(): int
    {
        $sites = $this->Project->getSitesIds([
            'where' => ['active' => 1],
            'limit' => 1
        ]);

        self::assertNotEmpty($sites, 'The integration project needs one active site.');

        return (int)$sites[0]['id'];
    }

    private function cleanFixtures(): void
    {
        $this->Connection->delete($this->fulltextTable, ['origin' => self::ORIGIN]);
        $this->Connection->delete($this->quicksearchTable, ['origin' => self::ORIGIN]);

        foreach ([self::URL_PARAMS, self::URL_PARAMS_SECOND] as $params) {
            $criteria = ['urlParameter' => json_encode($params)];
            $this->Connection->delete($this->fulltextTable, $criteria);
            $this->Connection->delete($this->quicksearchTable, $criteria);
        }
    }

    private function fixtureRowCount(): int
    {
        $countRows = function (string $table): int {
            $QueryBuilder = QUI::getQueryBuilder();

            return (int)$QueryBuilder
                ->select('COUNT(*)')
                ->from(QUI\Utils\Doctrine::quoteIdentifier($table))
                ->where($QueryBuilder->expr()->eq('origin', ':origin'))
                ->orWhere($QueryBuilder->expr()->eq('urlParameter', ':urlParameter'))
                ->setParameter('origin', self::ORIGIN)
                ->setParameter('urlParameter', json_encode(self::URL_PARAMS_SECOND))
                ->executeQuery()
                ->fetchOne();
        };

        return $countRows($this->fulltextTable) + $countRows($this->quicksearchTable);
    }

    private function siteEntryCount(string $table, int $siteId): int
    {
        $QueryBuilder = QUI::getQueryBuilder();

        return (int)$QueryBuilder
            ->select('COUNT(*)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier($table))
            ->where($QueryBuilder->expr()->eq('siteId', ':siteId'))
            ->setParameter('siteId', $siteId)
            ->executeQuery()
            ->fetchOne();
    }
}
