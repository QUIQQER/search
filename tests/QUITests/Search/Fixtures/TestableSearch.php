<?php

namespace QUITests\Search;

use QUI\Search;

class TestableSearch extends Search
{
    public static function websiteSearchJsonLd(string $start, string $searchUrl): ?string
    {
        return self::buildWebsiteSearchJsonLd($start, $searchUrl);
    }
}
