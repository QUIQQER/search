<?php

namespace QUITests\Search;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Projects\Site;
use QUI\Search\Bricks\Search as SearchBrick;
use QUI\Search\Controls\Search as SearchControl;

require_once __DIR__ . '/Fixtures/TestableSearchControl.php';
require_once __DIR__ . '/Fixtures/TestableSearchInput.php';

class ControlTest extends TestCase
{
    private array $requestBackup;

    protected function setUp(): void
    {
        $this->requestBackup = $_REQUEST;
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_REQUEST = $this->requestBackup;
    }

    public function testSearchControlSanitizesAttributesAndRequest(): void
    {
        $Site = $this->getSite();
        $Site->setAttribute('quiqqer.settings.search.list.fields', [
            'name',
            'title',
            'short',
            'data',
            'searchTypeAnd'
        ]);
        $Site->setAttribute('quiqqer.settings.search.list.fields.selected', [
            'name',
            'title',
            'data'
        ]);
        $Site->setAttribute('quiqqer.search.sitetypes.search.pagination.type', 'infinitescroll');

        $Control = new TestableSearchControl([
            'Site' => $Site,
            'search' => ['invalid'],
            'searchType' => 'invalid',
            'max' => '25',
            'sheet' => '2',
            'searchFields' => ['name', 'invalid'],
            'orderFields' => 'invalid',
            'relevanceSearch' => 1,
            'fieldConstraints' => [
                'name' => [
                    'Alpha',
                    123,
                    ['value' => 'Beta', 'type' => 'LIKE'],
                    ['value' => 'ignored'],
                    ['type' => 'LIKE'],
                    ['value' => 'ignored', 'type' => 'INVALID'],
                    ['value' => '', 'type' => 'LIKE']
                ],
                'invalid' => 'ignored'
            ],
            'childrenListTemplate' => '/missing/template.html',
            'childrenListCss' => '/missing/style.css'
        ]);

        self::assertSame('', $Control->getAttribute('search'));
        self::assertSame(SearchControl::SEARCH_TYPE_AND, $Control->getAttribute('searchType'));
        self::assertSame(25, $Control->getAttribute('max'));
        self::assertSame(2, $Control->getAttribute('sheet'));
        self::assertSame(['name'], $Control->getAttribute('searchFields'));
        self::assertSame($Control->defaultFields(), $Control->getAttribute('orderFields'));
        self::assertTrue($Control->getAttribute('relevanceSearch'));
        self::assertSame(
            ['Alpha', ['value' => 'Beta', 'type' => 'LIKE']],
            $Control->getAttribute('fieldConstraints')['name']
        );
        self::assertStringEndsWith('/templates/SearchResultList.html', $Control->getAttribute('childrenListTemplate'));
        self::assertStringEndsWith('/templates/SearchResultList.css', $Control->getAttribute('childrenListCss'));
        self::assertSame('infinitescroll', $Control->paginationType());
        self::assertSame('Alpha Beta', TestableSearchControl::sanitizeString(' Alpha   Beta '));
        self::assertSame($Control->search(), $Control->search());

        $Control->setAttribute('fieldConstraints', [
            'name' => ['value' => 'Gamma', 'type' => 'LIKE']
        ]);
        $Control->sanitize();

        self::assertSame(
            [['value' => 'Gamma', 'type' => 'LIKE']],
            $Control->getAttribute('fieldConstraints')['name']
        );

        $_REQUEST = [
            'sheet' => '3',
            'max' => '7',
            'fieldConstraints' => '{"title":"Needle"}',
            'search' => 'Needle',
            'searchType' => 'AND',
            'searchIn' => 'title,invalid,42'
        ];

        $Control->setAttributesFromRequest();

        self::assertSame(3, $Control->getAttribute('sheet'));
        self::assertSame(7, $Control->getAttribute('max'));
        self::assertSame('Needle', $Control->getAttribute('search'));
        self::assertSame(['title'], $Control->getAttribute('searchFields'));
        self::assertSame(['Needle'], $Control->getAttribute('fieldConstraints')['title']);
        self::assertArrayHasKey('childrenListTemplate', $Control->javaScriptAttributes());
    }

    public function testEmptySearchRendersControlBody(): void
    {
        $Site = $this->getSite();
        $Site->setAttribute('quiqqer.settings.search.list.fields', ['name', 'title']);
        $Site->setAttribute('quiqqer.settings.search.list.fields.selected', ['name', 'title']);

        $Control = new TestableSearchControl([
            'Site' => $Site,
            'search' => '',
            'showAllResultsOnEmptySearchString' => false
        ]);

        $result = $Control->search();

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['children']);
        self::assertNotSame('', $Control->getBody());
        self::assertInstanceOf(QUI\Controls\ChildrenList::class, $Control->getChildrenList());
    }

    public function testSearchControlUsesFieldAndPaginationFallbacks(): void
    {
        self::assertSame('infinitescroll', SearchControl::PAGINATION_TYPE_INFINITESCROLL);
        self::assertSame(
            SearchControl::PAGINATION_TYPE_INFINITESCROLL,
            SearchControl::PAGINATION_TYPE_INIFINITESCROLL
        );

        $Site = $this->getSite();
        $Site->setAttribute('quiqqer.settings.search.list.fields', []);
        $Site->setAttribute('quiqqer.settings.search.list.fields.selected', []);
        $Site->setAttribute('quiqqer.search.sitetypes.search.pagination.type', '');

        $Control = new TestableSearchControl([
            'Site' => $Site,
            'searchType' => SearchControl::SEARCH_TYPE_AND,
            'searchFields' => []
        ]);

        self::assertContains('name', $Control->defaultFields());
        self::assertContains('title', $Control->clearFields([]));
        self::assertSame(['name'], $Control->clearFields(['name', 'invalid']));
        self::assertSame(SearchControl::SEARCH_TYPE_OR, $Control->getAttribute('searchType'));
        self::assertSame(SearchControl::PAGINATION_TYPE_PAGINATION, $Control->paginationType());

        $Site->setAttribute('quiqqer.settings.search.list.fields', false);

        $Control = new TestableSearchControl([
            'Site' => $Site,
            'searchType' => SearchControl::SEARCH_TYPE_AND,
            'searchFields' => ['name']
        ]);

        self::assertSame(SearchControl::SEARCH_TYPE_AND, $Control->getAttribute('searchType'));
    }

    public function testSearchInputSanitizesFieldsAndRequest(): void
    {
        $Input = new TestableSearchInput([
            'availableFields' => ['name', 'title', 'invalid'],
            'fields' => ['title', 'invalid']
        ]);

        self::assertContains('name', $Input->allFields());
        self::assertSame(['name', 'title'], array_values($Input->getAttribute('availableFields')));
        self::assertSame(['title'], array_values($Input->getAttribute('fields')));

        $_REQUEST = [
            'searchterms' => 'Alpha',
            'searchType' => 'AND',
            'searchIn' => ['name', ['invalid'], 'not-a-field']
        ];

        $Input->setAttributesFromRequest();

        self::assertSame('Alpha', $Input->getAttribute('search'));
        self::assertSame(SearchControl::SEARCH_TYPE_AND, $Input->getAttribute('searchType'));
        self::assertSame(['name'], array_values($Input->getAttribute('fields')));
        self::assertNotSame('', $Input->getBody());
    }

    public function testSearchInputEscapesDynamicAttributeValues(): void
    {
        $attack = '"><script>alert(1)</script>';
        $fieldAttack = 'name" autofocus onfocus="alert(1)';
        $Input = new TestableSearchInput([
            'search' => $attack,
            'placeholder' => $attack
        ]);
        $Input->setAttribute('availableFields', [$fieldAttack]);

        $body = $Input->getBody();

        self::assertStringNotContainsString('value="' . $attack . '"', $body);
        self::assertStringNotContainsString('value="' . $fieldAttack . '"', $body);
        self::assertStringContainsString(
            htmlspecialchars($attack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $body
        );
        self::assertStringContainsString(
            htmlspecialchars($fieldAttack, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $body
        );
    }

    public function testSearchBrickRendersWithExplicitResultSite(): void
    {
        $Brick = new SearchBrick([
            'Site' => $this->getSite(),
            'resultSite' => '/search-results',
            'suggestSearch' => true
        ]);

        $body = $Brick->getBody();

        self::assertNotSame('', $body);
        self::assertStringContainsString('/search-results', $body);
    }

    private function getSite(): Site
    {
        $Project = QUI::getProjectManager()->get();

        return $Project->get(1);
    }
}
