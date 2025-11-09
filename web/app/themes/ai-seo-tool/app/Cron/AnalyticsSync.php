<?php
namespace BBSEO\Cron;

use BBSEO\Analytics\GoogleAnalytics;
use BBSEO\Analytics\SearchConsole;
use BBSEO\PostTypes\Project;

class AnalyticsSync
{
    public const EVENT = 'BBSEO_daily_ga_sync';

    public static function init(): void
    {
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::EVENT);
        }
        add_action(self::EVENT, [self::class, 'run']);
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::EVENT);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::EVENT);
        }
    }

    public static function run(): void
    {
        if (!GoogleAnalytics::clientId() || !GoogleAnalytics::clientSecret()) {
            return;
        }

        $projects = get_posts([
            'post_type' => Project::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($projects as $projectId) {
            $slug = get_post_field('post_name', $projectId);
            if (!$slug) {
                continue;
            }

            $gaConfigured = GoogleAnalytics::isConfigured($slug);
            $gscConfigured = SearchConsole::isConfigured($slug);

            if (!$gaConfigured && !$gscConfigured) {
                continue;
            }

            if ($gaConfigured) {
                try {
                    GoogleAnalytics::sync($slug);
                } catch (\Throwable $exception) {
                    $config = GoogleAnalytics::loadConfig($slug);
                    if (!isset($config['analytics']) || !is_array($config['analytics'])) {
                        $config['analytics'] = [];
                    }
                    if (!isset($config['analytics']['ga']) || !is_array($config['analytics']['ga'])) {
                        $config['analytics']['ga'] = [];
                    }
                    $config['analytics']['ga']['last_error'] = $exception->getMessage();
                    GoogleAnalytics::writeConfig($slug, $config);
                }
            }

            if ($gscConfigured) {
                try {
                    SearchConsole::sync($slug);
                } catch (\Throwable $exception) {
                    $config = GoogleAnalytics::loadConfig($slug);
                    if (!isset($config['analytics']) || !is_array($config['analytics'])) {
                        $config['analytics'] = [];
                    }
                    if (!isset($config['analytics']['gsc']) || !is_array($config['analytics']['gsc'])) {
                        $config['analytics']['gsc'] = [];
                    }
                    $config['analytics']['gsc']['last_error'] = $exception->getMessage();
                    GoogleAnalytics::writeConfig($slug, $config);
                }
            }
        }
    }
}
