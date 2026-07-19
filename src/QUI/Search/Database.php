<?php

namespace QUI\Search;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Query\QueryBuilder;
use QUI;
use QUI\Database\Exception;
use QUI\System\Log;
use QUI\Utils\Doctrine;

final class Database
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchAllAssociative(QueryBuilder $QueryBuilder): array
    {
        try {
            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function fetchAssociative(QueryBuilder $QueryBuilder): array|false
    {
        try {
            return $QueryBuilder->executeQuery()->fetchAssociative();
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    public static function fetchOne(QueryBuilder $QueryBuilder): mixed
    {
        try {
            return $QueryBuilder->executeQuery()->fetchOne();
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insert(string $table, array $data): void
    {
        try {
            QUI::getDataBaseConnection()->insert(Doctrine::quoteIdentifier($table), $data);
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $criteria
     */
    public static function update(string $table, array $data, array $criteria): void
    {
        try {
            QUI::getDataBaseConnection()->update(Doctrine::quoteIdentifier($table), $data, $criteria);
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public static function delete(string $table, array $criteria): void
    {
        try {
            QUI::getDataBaseConnection()->delete(Doctrine::quoteIdentifier($table), $criteria);
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    public static function truncate(string $table): void
    {
        try {
            $Connection = QUI::getDataBaseConnection();
            $table = Doctrine::quoteIdentifier($table);

            $Connection->executeStatement(
                $Connection->getDatabasePlatform()->getTruncateTableSQL($table)
            );
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    /**
     * Remove indexed site entries that no longer belong to an indexable site.
     * Entries without a site ID, such as custom search items, are preserved.
     *
     * @param list<int> $siteIdsToKeep
     */
    public static function removeObsoleteSiteEntries(string $table, array $siteIdsToKeep): void
    {
        $siteIdColumn = Doctrine::quoteIdentifier('siteId');
        $QueryBuilder = QUI::getQueryBuilder();
        $QueryBuilder
            ->select($siteIdColumn . ' AS indexed_site_id')
            ->distinct()
            ->from(Doctrine::quoteIdentifier($table))
            ->where($QueryBuilder->expr()->isNotNull($siteIdColumn));

        $validSiteIds = [];

        foreach ($siteIdsToKeep as $siteId) {
            $validSiteIds[$siteId] = true;
        }

        foreach (self::fetchAllAssociative($QueryBuilder) as $row) {
            $siteId = (int)$row['indexed_site_id'];

            if (isset($validSiteIds[$siteId])) {
                continue;
            }

            self::delete($table, [
                'siteId' => $siteId
            ]);
        }
    }

    public static function createException(DBALException $Exception): Exception
    {
        Log::writeException($Exception);

        return new Exception('Search database operation failed.');
    }
}
