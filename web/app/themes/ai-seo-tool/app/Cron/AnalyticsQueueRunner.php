<?php
namespace BBSEO\Cron;

use BBSEO\Analytics\Dispatcher as AnalyticsDispatcher;
use BBSEO\Analytics\Queue as AnalyticsQueue;
use BBSEO\Audit\Runner as AuditRunner;
use BBSEO\Cron\Scheduler;
use BBSEO\Helpers\Storage;

class AnalyticsQueueRunner
{
    public const EVENT = 'bbseo_minutely_analytics_queue';

    public static function init(): void
    {
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + 60, 'every_minute', self::EVENT);
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
        $tasksPerTick = (int) (getenv('BBSEO_ANALYTICS_TASKS_PER_TICK') ?: 1);
        if ($tasksPerTick <= 0) {
            $tasksPerTick = 1;
        }

        $tasks = AnalyticsQueue::dequeue($tasksPerTick);
        foreach ($tasks as $task) {
            self::processTask($task);
        }
    }

    private static function processTask(array $task): void
    {
        $project = $task['project'] ?? null;
        $runId = $task['run'] ?? null;
        $provider = $task['provider'] ?? null;

        if (!$project || !$runId || !$provider) {
            return;
        }

        $runDir = Storage::runDir($project, $runId);
        if (!is_dir($runDir)) {
            return;
        }

        $results = AnalyticsDispatcher::syncProject($project, $runId, [$provider]);
        $result = $results[$provider] ?? null;
        if (!is_array($result)) {
            $result = ['synced' => false];
        }

        $metaPath = $runDir . '/meta.json';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        if (!isset($meta['analytics']) || !is_array($meta['analytics'])) {
            $meta['analytics'] = [];
        }

        $meta['analytics'][$provider] = [
            'synced_at' => gmdate('c'),
            'result' => $result,
        ];

        if (!empty($result['synced']) && empty($meta['analytics_refresh_triggered'])) {
            if (Scheduler::shouldRefreshAudit([$provider => $result])) {
                $meta['analytics_refresh_triggered'] = true;
                AuditRunner::run($project, $runId);
            }
        }

        Storage::writeJson($metaPath, $meta);
    }
}
