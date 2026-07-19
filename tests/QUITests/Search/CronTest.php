<?php

namespace QUITests\Search;

use PHPUnit\Framework\TestCase;
use QUI\Cron\Manager;
use QUI\Search\Cron;

class CronTest extends TestCase
{
    public function testCreateSearchDatabaseRequiresProjectAndLanguage(): void
    {
        $Manager = $this->createStub(Manager::class);

        Cron::createSearchDatabase([], $Manager);
        Cron::createSearchDatabase(['project' => 'ibanTest'], $Manager);

        self::assertTrue(true);
    }
}
