<?php

namespace QUITests\Search;

use QUI\Search\Controls\SearchInput;

class TestableSearchInput extends SearchInput
{
    public function sanitize(): void
    {
        $this->sanitizeFields();
    }

    public function allFields(): array
    {
        return $this->getAllAvailableFields();
    }
}
