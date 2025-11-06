<?php
namespace AISEO\Analytics;

use AISEO\Helpers\Storage;

class Dispatcher
{
    public static function syncProject(string $project, string $runId): array
    {
        $results = [
            'ga' => null,
            'gsc' => null,
        ];

        // Google Analytics
        if (GoogleAnalytics::isConfigured($project)) {
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

        // Google Search Console
        if (SearchConsole::isConfigured($project)) {
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
