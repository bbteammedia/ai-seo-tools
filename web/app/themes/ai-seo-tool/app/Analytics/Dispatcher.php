<?php
namespace BBSEO\Analytics;

use BBSEO\Analytics\GoogleAnalytics;
use BBSEO\Analytics\SearchConsole;

class Dispatcher
{
    public static function syncProject(string $project, string $runId, array $providers = []): array
    {
        $results = [
            'ga' => null,
            'gsc' => null,
        ];

        $targets = $providers;
        if (empty($targets)) {
            $targets = ['ga', 'gsc'];
        }
        $targets = array_values(array_intersect(['ga', 'gsc'], $targets));

        foreach (array_unique($targets) as $target) {
            if ($target === 'ga') {
                if (!GoogleAnalytics::isConfigured($project)) {
                    continue;
                }
                try {
                    $payload = GoogleAnalytics::sync($project, $runId);
                    $results['ga'] = [
                        'synced' => true,
                        'metrics' => $payload['metrics'] ?? [],
                        'range' => $payload['range'] ?? [],
                    ];
                } catch (\Throwable $exception) {
                    $results['ga'] = [
                        'synced' => false,
                        'error' => $exception->getMessage(),
                    ];
                    self::recordError($project, 'ga', $exception->getMessage());
                }
            }

            if ($target === 'gsc') {
                if (!SearchConsole::isConfigured($project)) {
                    continue;
                }
                try {
                    $payload = SearchConsole::sync($project, $runId);
                    $results['gsc'] = [
                        'synced' => true,
                        'metrics' => $payload['metrics'] ?? [],
                        'range' => $payload['range'] ?? [],
                    ];
                } catch (\Throwable $exception) {
                    $results['gsc'] = [
                        'synced' => false,
                        'error' => $exception->getMessage(),
                    ];
                    self::recordError($project, 'gsc', $exception->getMessage());
                }
            }
        }

        return $results;
    }

    private static function recordError(string $project, string $key, string $message): void
    {
        $config = GoogleAnalytics::loadConfig($project);
        if (!isset($config['analytics']) || !is_array($config['analytics'])) {
            $config['analytics'] = [];
        }
        if (!isset($config['analytics'][$key]) || !is_array($config['analytics'][$key])) {
            $config['analytics'][$key] = [];
        }
        $config['analytics'][$key]['last_error'] = $message;
        GoogleAnalytics::writeConfig($project, $config);
    }
}
