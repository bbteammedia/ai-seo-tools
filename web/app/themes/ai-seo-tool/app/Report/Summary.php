<?php
namespace BBSEO\Report;

use BBSEO\Helpers\Storage;

class Summary
{
    public static function build(string $project, string $run): array
    {
        $dir = Storage::runDir($project, $run);
        $pages = glob($dir . '/pages/*.json');
        $images = glob($dir . '/images/*.json');
        $errors = glob($dir . '/errors/*.json');

        $count = is_array($pages) ? count($pages) : 0;
        $imagesCount = is_array($images) ? count($images) : 0;
        $errorsCount = is_array($errors) ? count($errors) : 0;

        $statusBuckets = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $issuesTotal = 0;

        foreach ($pages as $p) {
            $data = json_decode(file_get_contents($p), true) ?: [];
            $status = (int) ($data['status'] ?? 0);

            if ($status >= 200 && $status < 300) {
                $statusBuckets['2xx']++;
            } elseif ($status >= 300 && $status < 400) {
                $statusBuckets['3xx']++;
            } elseif ($status >= 400 && $status < 500) {
                $statusBuckets['4xx']++;
            } elseif ($status >= 500 && $status < 600) {
                $statusBuckets['5xx']++;
            } else {
                $statusBuckets['other']++;
            }

            // If you store issues on page level later, sum them here.
            // $issuesTotal += count($data['issues'] ?? []);
        }

        $audit = json_decode(@file_get_contents($dir . '/audit.json'), true) ?: [];
        foreach (($audit['items'] ?? []) as $item) {
            $issuesTotal += count($item['issues'] ?? []);
        }

        $out = [
            'project' => $project,
            'run_id' => $run,
            'generated' => gmdate('c'),
            'pages' => $count,
            'images' => $imagesCount,
            'errors' => $errorsCount,
            'status' => $statusBuckets,
            'issues' => [
                'total' => $issuesTotal,
            ],
        ];

        file_put_contents($dir . '/summary.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $out;
    }

    public static function appendTimeseries(string $project, array $summary): array
    {
        $pdir = Storage::projectDir($project);
        $path = $pdir . '/timeseries.json';
        $ts = is_file($path) ? json_decode(file_get_contents($path), true) : ['items' => []];

        if (!isset($ts['items']) || !is_array($ts['items'])) {
            $ts['items'] = [];
        }

        $ts['items'][] = [
            'run_id' => $summary['run_id'],
            'date' => $summary['generated'],
            'pages' => $summary['pages'],
            'images' => $summary['images'],
            'errors' => $summary['errors'],
            '2xx' => $summary['status']['2xx'],
            '3xx' => $summary['status']['3xx'],
            '4xx' => $summary['status']['4xx'],
            '5xx' => $summary['status']['5xx'],
            'issues' => $summary['issues']['total'],
        ];

        file_put_contents($path, json_encode($ts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $ts;
    }
}
