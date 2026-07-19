<?php

namespace QUITests\Search;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Cron\Manager;
use QUI\Package\Package;
use QUI\Search\Cron;
use QUI\Utils\Doctrine;

class CronTest extends TestCase
{
    public function testCreateSearchDatabaseRequiresProjectAndLanguage(): void
    {
        $Manager = $this->createStub(Manager::class);

        Cron::createSearchDatabase([], $Manager);
        Cron::createSearchDatabase(['project' => 'ibanTest'], $Manager);

        self::assertTrue(true);
    }

    public function testAutoCreatedSearchCronRunsSemiAnnually(): void
    {
        $Document = new DOMDocument();

        self::assertTrue($Document->load(dirname(__DIR__, 3) . '/cron.xml'));

        $interval = $Document->getElementsByTagName('interval')->item(0)?->textContent;

        self::assertSame('0 0 1 1,7 *', trim($interval ?? ''));
    }

    public function testPackageSetupMigratesOnlyLegacyDailySearchCrons(): void
    {
        $Connection = QUI::getDataBaseConnection();
        $table = Manager::table();
        $quotedTable = Doctrine::quoteIdentifier($table);
        $legacyParams = json_encode([
            ['name' => 'phpunit', 'value' => 'legacy-search-cron']
        ]);
        $customParams = json_encode([
            ['name' => 'phpunit', 'value' => 'custom-search-cron']
        ]);

        $Connection->beginTransaction();

        try {
            $Connection->insert($quotedTable, [
                'active' => 0,
                'exec' => '\\QUI\\Search\\Cron::createSearchDatabase',
                'title' => 'PHPUnit legacy search cron',
                'min' => '0',
                'hour' => '0',
                'day' => '*',
                'month' => '*',
                'dayOfWeek' => '*',
                'params' => $legacyParams
            ]);
            $Connection->insert($quotedTable, [
                'active' => 1,
                'exec' => '\\QUI\\Search\\Cron::createSearchDatabase',
                'title' => 'PHPUnit custom search cron',
                'min' => '15',
                'hour' => '2',
                'day' => '*',
                'month' => '*',
                'dayOfWeek' => '1',
                'params' => $customParams
            ]);

            $OtherPackage = $this->createStub(Package::class);
            $OtherPackage->method('getName')->willReturn('quiqqer/core');

            Cron::onPackageSetup($OtherPackage);

            $legacyCron = $Connection->fetchAssociative(
                'SELECT * FROM ' . $quotedTable . ' WHERE params = ?',
                [$legacyParams]
            );

            self::assertIsArray($legacyCron);
            self::assertSame('*', $legacyCron['day']);
            self::assertSame('*', $legacyCron['month']);

            $SearchPackage = $this->createStub(Package::class);
            $SearchPackage->method('getName')->willReturn('quiqqer/search');

            Cron::onPackageSetup($SearchPackage);

            $legacyCron = $Connection->fetchAssociative(
                'SELECT * FROM ' . $quotedTable . ' WHERE params = ?',
                [$legacyParams]
            );
            $customCron = $Connection->fetchAssociative(
                'SELECT * FROM ' . $quotedTable . ' WHERE params = ?',
                [$customParams]
            );

            self::assertIsArray($legacyCron);
            self::assertSame('1', (string)$legacyCron['day']);
            self::assertSame('1,7', $legacyCron['month']);
            self::assertSame('0', (string)$legacyCron['active']);
            self::assertSame($legacyParams, $legacyCron['params']);

            self::assertIsArray($customCron);
            self::assertSame('15', $customCron['min']);
            self::assertSame('2', $customCron['hour']);
            self::assertSame('*', $customCron['day']);
            self::assertSame('*', $customCron['month']);
            self::assertSame('1', $customCron['dayOfWeek']);
            self::assertSame('1', (string)$customCron['active']);
            self::assertSame($customParams, $customCron['params']);
        } finally {
            $Connection->rollBack();
        }
    }
}
