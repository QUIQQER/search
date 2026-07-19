<?php

namespace QUITests\Search;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Exception;
use QUI\Search\Items\CustomSearchItem;

class CustomSearchItemTest extends TestCase
{
    private CustomSearchItem $Item;

    protected function setUp(): void
    {
        $this->Item = new CustomSearchItem(
            2_147_483_001,
            'quiqqer/search-phpunit',
            'Test title',
            '/search-test',
            [
                'short' => 'Test short text',
                'icon' => 'fa fa-search'
            ]
        );
    }

    public function testIdentityAndSerialization(): void
    {
        self::assertSame(2_147_483_001, $this->Item->getId());
        self::assertSame(2_147_483_001, $this->Item->getId('en'));
        self::assertSame('quiqqer/search-phpunit', $this->Item->getOrigin());
        self::assertSame('/search-test', $this->Item->getUrl());
        self::assertSame('/search-test', $this->Item->getUrlRewritten());
        self::assertSame('/search-test', $this->Item->getCanonical());
        self::assertSame('Test title', $this->Item->getAttribute('title'));
        self::assertSame('Test short text', $this->Item->getAttribute('short'));

        $data = $this->Item->toArray();

        self::assertSame(2_147_483_001, $data['id']);
        self::assertSame('quiqqer/search-phpunit', $data['origin']);
        self::assertSame('Test title', $data['title']);
        self::assertSame('/search-test', $data['url']);
        self::assertSame('fa fa-search', $data['attributes']['icon']);
    }

    public function testSiteInterfaceDefaults(): void
    {
        self::assertSame($this->Item, $this->Item->load());
        self::assertSame('', $this->Item->encode());
        self::assertFalse($this->Item->isLinked());
        self::assertFalse($this->Item->existLang('en'));
        self::assertSame([], $this->Item->getLangIds());
        self::assertSame([], $this->Item->getChildren());
        self::assertSame([], $this->Item->nextSiblings(2));
        self::assertSame([], $this->Item->previousSiblings(2));
        self::assertFalse($this->Item->firstChild());
        self::assertSame([], $this->Item->getNavigation());
        self::assertSame([], $this->Item->getChildrenIds());
        self::assertSame([], $this->Item->getChildrenIdsRecursive());
        self::assertSame(0, $this->Item->hasChildren());
        self::assertSame(1, $this->Item->getParentId());
        self::assertSame([1], $this->Item->getParentIds());
        self::assertSame([1], $this->Item->getParentIdTree());
        self::assertTrue($this->Item->hasPermission('quiqqer.search.test'));

        $this->Item->decode('{}');
        $this->Item->refresh();
        self::assertFalse($this->Item->delete());
        $this->Item->restore();
        $this->Item->destroy();
        $this->Item->deleteCache();
        $this->Item->createCache();
        $this->Item->checkPermission('quiqqer.search.test');

        self::assertTrue(true);
    }

    public function testProjectAndParentAccess(): void
    {
        $Project = QUI::getProjectManager()->get();
        $this->Item->setProject($Project);

        self::assertSame($Project, $this->Item->getProject());
        self::assertSame(1, $this->Item->getParent()->getId());
        self::assertSame(1, $this->Item->getParents()[0]->getId());
    }

    public function testSiblingMethodsThrow(): void
    {
        try {
            $this->Item->nextSibling();
            self::fail('nextSibling() must throw.');
        } catch (Exception) {
            self::assertTrue(true);
        }

        try {
            $this->Item->previousSibling();
            self::fail('previousSibling() must throw.');
        } catch (Exception) {
            self::assertTrue(true);
        }
    }

    public function testChildMethodsThrowErrorCode(): void
    {
        try {
            $this->Item->getChildIdByName('missing');
            self::fail('getChildIdByName() must throw.');
        } catch (Exception $Exception) {
            self::assertSame(705, $Exception->getCode());
        }

        try {
            $this->Item->getChild(123);
            self::fail('getChild() must throw.');
        } catch (Exception $Exception) {
            self::assertSame(705, $Exception->getCode());
        }
    }
}
