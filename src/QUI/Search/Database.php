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
    public static function fetchAllAssociative(QueryBuilder $QueryBuilder): array
    {
        try {
            return $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

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

    public static function insert(string $table, array $data): void
    {
        try {
            QUI::getDataBaseConnection()->insert(Doctrine::quoteIdentifier($table), $data);
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

    public static function update(string $table, array $data, array $criteria): void
    {
        try {
            QUI::getDataBaseConnection()->update(Doctrine::quoteIdentifier($table), $data, $criteria);
        } catch (DBALException $Exception) {
            throw self::createException($Exception);
        }
    }

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

    public static function createException(DBALException $Exception): Exception
    {
        Log::writeException($Exception);

        return new Exception('Search database operation failed.');
    }
}
