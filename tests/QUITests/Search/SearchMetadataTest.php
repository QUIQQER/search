<?php

namespace QUITests\Search;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/TestableSearch.php';

class SearchMetadataTest extends TestCase
{
    public function testWebsiteSearchJsonLdIsValidAndScriptSafe(): void
    {
        $start = 'https://example.test/"quoted"/<script>alert(1)</script>';
        $searchUrl = 'https://example.test/search/</script><script>alert(2)</script>';
        $json = TestableSearch::websiteSearchJsonLd($start, $searchUrl);

        self::assertIsString($json);

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('https://schema.org', $data['@context']);
        self::assertSame('WebSite', $data['@type']);
        self::assertSame($start, $data['url']);
        self::assertSame('SearchAction', $data['potentialAction']['@type']);
        self::assertSame(
            $searchUrl . '?search={search}',
            $data['potentialAction']['target']
        );
        self::assertStringNotContainsString('<script', $json);
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('"quoted"', $json);
        self::assertStringContainsString('\\u003C', $json);
        self::assertStringContainsString('\\u0022', $json);
    }

    public function testInvalidUtf8SuppressesWebsiteSearchJsonLd(): void
    {
        self::assertNull(TestableSearch::websiteSearchJsonLd("invalid\xB1", '/search'));
    }
}
