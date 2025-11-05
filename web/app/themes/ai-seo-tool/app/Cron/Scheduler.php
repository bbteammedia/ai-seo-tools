<?php
namespace AISEO\Cron;

use AISEO\PostTypes\Project;
use AISEO\Crawl\Queue;
use AISEO\Crawl\Worker;
use AISEO\Audit\Runner as AuditRunner;
use AISEO\Report\Builder as ReportBuilder;
use AISEO\Helpers\Storage;

class Scheduler
{
    public const CRON_HOOK = 'aiseo_cron_tick';
    public const OPTION_ENABLED = 'aiseo_scheduler_enabled';

    public static function init(): void
    {
        if (!self::isEnabled()) {
            self::deactivate();
            return;
        }

        add_action(self::CRON_HOOK, [self::class, 'process']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function process(): void
    {
        $projects = get_posts([
            'post_type' => Project::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($projects as $postId) {
            $slug = get_post_field('post_name', $postId);
            $schedule = get_post_meta($postId, Project::META_SCHEDULE, true) ?: 'manual';
            $schedule = Project::sanitizeSchedule($schedule);
            if ($schedule === 'manual') {
                continue;
            }

            $lastRun = (int) get_post_meta($postId, Project::META_LAST_RUN, true);
            if (!self::isDue($schedule, $lastRun)) {
                continue;
            }

            if (self::runProject($slug, true)) {
                update_post_meta($postId, Project::META_LAST_RUN, time());
            }
        }
    }

    public static function runProject(string $slug, bool $processQueue = false, int $maxSteps = 50): bool
    {
        $baseUrl = Project::getBaseUrl($slug);
        if (!$baseUrl) {
            return false;
        }

        Queue::init($slug, [$baseUrl]);

        if ($processQueue) {
            $steps = 0;
            while ($steps < $maxSteps) {
                $result = Worker::process($slug);
                $steps++;
                if (($result['message'] ?? '') === 'queue-empty') {
                    break;
                }
            }

            AuditRunner::run($slug);
            ReportBuilder::build($slug);
            self::snapshot($slug);
        }

        if ($processQueue) {
            Project::updateLastRun($slug, time());
        }

        return true;
    }

    public static function isEnabled(): bool
    {
        $env = getenv('AISEO_DISABLE_CRON');
        if (is_string($env) && $env !== '') {
            $value = strtolower(trim($env));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return false;
            }
        }

        $option = get_option(self::OPTION_ENABLED, '1');
        return $option !== '0';
    }

    public static function setEnabled(bool $enabled): void
    {
        update_option(self::OPTION_ENABLED, $enabled ? '1' : '0');
        if (!$enabled) {
            self::deactivate();
        }
    }

    private static function isDue(string $schedule, int $lastRun): bool
    {
        if ($schedule === 'weekly') {
            return $lastRun <= 0 || (time() - $lastRun) >= WEEK_IN_SECONDS;
        }
        if ($schedule === 'monthly') {
            return $lastRun <= 0 || (time() - $lastRun) >= 30 * DAY_IN_SECONDS;
        }
        return false;
    }

    private static function snapshot(string $slug): void
    {
        $dirs = Storage::ensureProject($slug);
        $historyBase = $dirs['history'] ?? Storage::historyDir($slug);
        if (!is_dir($historyBase)) {
            wp_mkdir_p($historyBase);
        }
        $timestamp = gmdate('Ymd-His');
        $target = $historyBase . '/' . $timestamp;
        wp_mkdir_p($target);

        $files = ['audit.json', 'report.json'];
        foreach ($files as $file) {
            $source = $dirs['base'] . '/' . $file;
            if (file_exists($source)) {
                copy($source, $target . '/' . $file);
            }
        }

        $report = Storage::readJson($dirs['base'] . '/report.json', []);
        $summary = [
            'run_at' => gmdate('c'),
            'project' => $slug,
            'pages' => $report['crawl']['pages_count'] ?? 0,
            'status_buckets' => $report['crawl']['status_buckets'] ?? [],
            'top_issues' => $report['top_issues'] ?? [],
        ];
        Storage::writeJson($target . '/summary.json', $summary);
    }
}
