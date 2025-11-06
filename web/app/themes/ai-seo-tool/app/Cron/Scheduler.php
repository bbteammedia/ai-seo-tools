<?php
namespace AISEO\Cron;

use AISEO\Helpers\RunId;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\Crawl\Worker;
use AISEO\Audit\Runner as AuditRunner;
use AISEO\Report\Builder as ReportBuilder;
use AISEO\Report\Summary;
use AISEO\Analytics\Dispatcher as AnalyticsDispatcher;

class Scheduler
{
    public const EVENT = 'aiseo_minutely_drain';

    public static function registerSchedules($schedules)
    {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'ai-seo-tool'),
        ];
        return $schedules;
    }

    public static function init(): void
    {
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + 60, 'every_minute', self::EVENT);
        }

        add_action(self::EVENT, [self::class, 'drain']);
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::EVENT);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::EVENT);
        }
    }

    public static function drain(): void
    {
        $envDisabled = getenv('AISEO_DISABLE_CRON');
        if (is_string($envDisabled) && strtolower(trim($envDisabled)) === 'true') {
            return;
        }

        $projects = self::collectProjects();
        if (!$projects) {
            return;
        }

        $stepsPerTick = (int) (getenv('AISEO_STEPS_PER_TICK') ?: 50);
        if ($stepsPerTick <= 0) {
            $stepsPerTick = 50;
        }

        foreach ($projects as $project => $cfg) {
            Storage::ensureProject($project);

            $enabled = $cfg === null ? true : ($cfg['enabled'] ?? true);
            if (!$enabled) {
                continue;
            }

            $latestRun = Storage::getLatestRun($project);
            if ($cfg !== null && self::shouldStartNewRun($project, $cfg, $latestRun)) {
                $seed = $cfg['seed_urls'] ?? [];
                if (!empty($seed)) {
                    $runId = RunId::new();
                    Queue::init($project, $seed, $runId);
                    Storage::setLatestRun($project, $runId);
                    $latestRun = $runId;
                }
            }

            if (!$latestRun) {
                continue;
            }

            $processed = false;
            for ($i = 0; $i < $stepsPerTick; $i++) {
                $next = Queue::next($project, $latestRun);
                if (!$next) {
                    break;
                }
                $processed = true;
                Worker::process($project, $latestRun);
            }

            $runDir = Storage::runDir($project, $latestRun);
            $metaPath = $runDir . '/meta.json';
            $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

            $queueEmpty = !Queue::next($project, $latestRun);
            if ($queueEmpty && empty($meta['completed_at'])) {
                $audit = AuditRunner::run($project, $latestRun);
                $report = ReportBuilder::build($project, $latestRun);
                $summary = Summary::build($project, $latestRun);
                Summary::appendTimeseries($project, $summary);
                AnalyticsDispatcher::syncProject($project, $latestRun);
                $meta['completed_at'] = gmdate('c');
                $meta['summary'] = [
                    'pages' => $summary['pages'],
                    'issues_total' => $summary['issues']['total'],
                    'status' => $summary['status'],
                ];
                Storage::writeJson($metaPath, $meta);
            } elseif ($processed) {
                $meta['last_tick_at'] = gmdate('c');
                Storage::writeJson($metaPath, $meta);
            }
        }
    }

    private static function collectProjects(): array
    {
        $baseDir = Storage::baseDir();
        $projects = [];

        // 1) From existing config.json files
        foreach (glob($baseDir . '/*/config.json') as $configPath) {
            $project = basename(dirname($configPath));
            $projects[$project] = json_decode(file_get_contents($configPath), true) ?: [];
        }

        // 2) Ensure all CPT slugs are present (even if config.json absent yet)
        $posts = get_posts([
            'post_type' => \AISEO\PostTypes\Project::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        foreach ($posts as $pid) {
            $slug = get_post_field('post_name', $pid);
            if (!isset($projects[$slug])) {
                Storage::ensureProject($slug);
                $cfgPath = Storage::projectDir($slug) . '/config.json';
                if (!is_file($cfgPath)) {
                    $schedule = \AISEO\PostTypes\Project::getSchedule($slug);
                    $baseUrl  = \AISEO\PostTypes\Project::getBaseUrl($slug);
                    $config = [
                        'enabled' => true,
                        'frequency' => $schedule ?: 'manual',
                        'seed_urls' => $baseUrl ? [$baseUrl] : [],
                    ];
                    Storage::writeJson($cfgPath, $config);
                }
                $projects[$slug] = json_decode(file_get_contents($cfgPath), true) ?: [];
            }
        }

        return $projects;
    }

    private static function shouldStartNewRun(string $project, array $cfg, ?string $latestRun): bool
    {
        $frequency = $cfg['frequency'] ?? 'manual';
        if ($frequency === 'manual') {
            return false;
        }

        if (!$latestRun) {
            return true;
        }

        $metaPath = Storage::runDir($project, $latestRun) . '/meta.json';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        $started = isset($meta['started_at']) ? strtotime($meta['started_at']) : 0;
        if (!$started) {
            return true;
        }

        if ($frequency === 'weekly') {
            return (time() - $started) >= 7 * 86400;
        }

        if ($frequency === 'monthly') {
            return date('Ym', $started) !== date('Ym');
        }

        return false;
    }
}
