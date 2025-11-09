<?php
namespace BBSEO\Analytics;

use BBSEO\Helpers\Storage;

class Store
{
    public static function save(string $project, string $runId, string $target, array $payload): string
    {
        $dirs = Storage::ensureRun($project, $runId);
        $analyticsDir = $dirs['base'] . '/analytics';
        if (!is_dir($analyticsDir)) {
            wp_mkdir_p($analyticsDir);
        }

        $path = $analyticsDir . '/' . $target . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        self::appendTimeseries($project, $target, $payload);

        return $path;
    }

    public static function appendTimeseries(string $project, string $target, array $payload): void
    {
        $series = $payload['timeseries'] ?? null;
        if (!is_array($series) || empty($series)) {
            return;
        }

        $projectDir = Storage::projectDir($project);
        $analyticsDir = $projectDir . '/analytics';
        if (!is_dir($analyticsDir)) {
            wp_mkdir_p($analyticsDir);
        }

        $path = $analyticsDir . '/' . $target . '-timeseries.json';
        $existing = is_file($path) ? json_decode(file_get_contents($path), true) : ['items' => []];
        if (!is_array($existing) || !isset($existing['items']) || !is_array($existing['items'])) {
            $existing = ['items' => []];
        }

        foreach ($series as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $date = $entry['date'] ?? null;
            if (!$date) {
                continue;
            }

            $existing['items'] = array_values(array_filter(
                $existing['items'],
                static function ($item) use ($date) {
                    return !isset($item['date']) || $item['date'] !== $date;
                }
            ));

            $existing['items'][] = $entry;
        }

        usort($existing['items'], static function ($a, $b) {
            return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
        });

        file_put_contents($path, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function writeExtra(string $project, string $runId, string $filename, array $payload): string
    {
        $dirs = Storage::ensureRun($project, $runId);
        $analyticsDir = $dirs['base'] . '/analytics';
        if (!is_dir($analyticsDir)) {
            wp_mkdir_p($analyticsDir);
        }

        $path = $analyticsDir . '/' . ltrim($filename, '/');
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}
