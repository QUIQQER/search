<?php

namespace QUI\Search;

use QUI\Utils\Security\Orthos;

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
