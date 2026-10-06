<?php

namespace QUI\Search;

use QUI\Utils\Security\Orthos;

use function implode;
use function preg_match_all;
use function preg_replace;
use function trim;

/**
 * Class Utils
 *
 * Utilities
 */
class Utils
{
    /**
     * Build boolean fulltext syntax from literal words, never from user-supplied operators.
     */
    public static function createBooleanSearchString(string $str, bool $requireAll = false): string
    {
        if (!preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}_]*/u', $str, $matches)) {
            return '';
        }

        if ($requireAll) {
            return '+' . implode(' +', $matches[0]);
        }

        return implode(' ', $matches[0]);
    }

    /**
     * Sanitizes a search string
     *
     * @param string $str
     * @return string - sanitized string
     */
    public static function sanitizeSearchString(string $str): string
    {
        /* http://www.regular-expressions.info/unicode.html#prop */
        $str = preg_replace(
            "/[^\p{L}\p{N}\p{P}\-\+]/iu",
            ' ',
            $str
        ) ?? '';

        $str = Orthos::clear($str);
        $str = preg_replace('#([ ]){2,}#', '$1', $str) ?? '';

        return trim($str);
    }
}
