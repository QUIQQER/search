<?php

/**
 * This file contains \QUI\Search\Cron
 */

namespace QUI\Search;

use QUI;
use QUI\Cron\Manager;
use QUI\Exception;
use QUI\Package\Package;
use QUI\Search;

/**
 * Search cron
 *
 * @author www.pcsg.de (Henning Leutz)
 * @author www.pcsg.de (Patrick Müller)
 * @todo search as jobs
 */
class Cron
{
    private const CREATE_SEARCH_DATABASE = '\\QUI\\Search\\Cron::createSearchDatabase';

    /**
     * Cron : create search database
     *
     * @param array{project?: string, lang?: string} $params
     * @param Manager $CronManager
     * @throws Exception
     */
    public static function createSearchDatabase(array $params, Manager $CronManager): void
    {
        if (!isset($params['project'])) {
            return;
        }

        if (!isset($params['lang'])) {
            return;
        }

        $Project = QUI::getProject($params['project'], $params['lang']);
        $Search = new Search();

        $Search->createFulltextSearch($Project);
        $Search->createQuicksearch($Project);
    }

    /**
     * Create search database for all projects and all languages
     *
     * @param array<string, mixed> $params
     * @param Manager $CronManager
     * @return void
     * @throws Exception
     */
    public static function createSearchDatabaseAllProjects(array $params, Manager $CronManager): void
    {
        $projects = QUI::getProjectManager()->getProjects();
        $Search = new Search();

        foreach ($projects as $project) {
            $Project = QUI::getProject($project);

            foreach ($Project->getLanguages() as $language) {
                $SearchProject = QUI::getProject($project, $language);

                $Search->createFulltextSearch($SearchProject);
                $Search->createQuicksearch($SearchProject);
            }
        }
    }

    /**
     * Update auto-created search crons that still use the former daily interval.
     */
    public static function onPackageSetup(Package $Package): void
    {
        if ($Package->getName() !== 'quiqqer/search') {
            return;
        }

        Database::update(
            Manager::table(),
            [
                'day' => '1',
                'month' => '1,7'
            ],
            [
                'exec' => self::CREATE_SEARCH_DATABASE,
                'min' => '0',
                'hour' => '0',
                'day' => '*',
                'month' => '*',
                'dayOfWeek' => '*'
            ]
        );
    }
}
