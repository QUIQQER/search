<?php

namespace QUITests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\Search\Utils;

class UtilsTest extends TestCase
{
    public static function searchStringProvider(): array
    {
        return [
            'trim and collapse whitespace' => ['  foo   bar  ', 'foo bar'],
            'keep unicode letters and numbers' => ['Grüße 123', 'Grüße 123'],
            'replace markup delimiters' => ['<b>Search</b>', 'b Search /b'],
            'replace control characters' => ["foo\n\tbar", 'foo bar'],
            'keep punctuation' => ['foo-bar+baz?', 'foo-bar+baz?']
        ];
    }

    #[DataProvider('searchStringProvider')]
    public function testSanitizeSearchString(string $input, string $expected): void
    {
        self::assertSame($expected, Utils::sanitizeSearchString($input));
    }
}
