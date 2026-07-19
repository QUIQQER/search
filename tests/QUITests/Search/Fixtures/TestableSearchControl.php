<?php

namespace QUITests\Search;

use QUI\Search\Controls\Search;

class TestableSearchControl extends Search
{
    public function clearFields(array $fields): array
    {
        return $this->clearSearchFields($fields);
    }

    public function sanitize(): void
    {
        $this->sanitizeAttributes();
    }

    public function defaultFields(): array
    {
        return $this->getDefaultSearchFields();
    }

    public function javaScriptAttributes(): array
    {
        return $this->getJavaScriptControlAttributes();
    }

    public function paginationType(): bool|string
    {
        return $this->getPaginationType();
    }

    public static function sanitizeString(string $value): string
    {
        return self::sanitizeSearchString($value);
    }
}
