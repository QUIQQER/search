<?php

namespace QUITests\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\Search\Utils;

class UtilsTest extends TestCase
{
    public static function booleanSearchStringProvider(): array
    {
        return [
            'plain words' => ['alpha beta', 'alpha beta'],
            'email address' => ['alice@example.com', 'alice example com'],
            'scan for AWS credentials' => [
                '/@fs/home/ec2-user/.aws/credentials?raw??',
                'fs home ec2 user aws credentials raw'
            ],
            'encoded traversal scan' => [
                '/@fs/..%252f..%252f..%252f..%252f..%252froot/.env?raw??',
                'fs 252f 252f 252f 252f 252froot env raw'
            ],
            'boolean operators' => [
                '++alpha -beta +*gamma (~delta) >epsilon <zeta',
                'alpha beta gamma delta epsilon zeta'
            ],
            'unclosed phrase and proximity' => ['"alpha beta" @8 "gamma', 'alpha beta 8 gamma'],
            'punctuation' => ["foo-bar O'Reilly file_name", 'foo bar O Reilly file_name'],
            'unicode' => ["Grüße cafe\u{0301} 日本語 123", "Grüße cafe\u{0301} 日本語 123"],
            'no words' => ['@ + - () " * ~ > < _ / % ?', ''],
            'empty' => ['', ''],
            'invalid UTF-8' => ["alpha\xFF", '']
        ];
    }

    #[DataProvider('booleanSearchStringProvider')]
    public function testBooleanSearchTreatsInputAsLiteralWords(string $input, string $expected): void
    {
        self::assertSame($expected, Utils::createBooleanSearchString($input));

        $required = $expected === '' ? '' : '+' . str_replace(' ', ' +', $expected);
        self::assertSame($required, Utils::createBooleanSearchString($input, true));
    }

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
