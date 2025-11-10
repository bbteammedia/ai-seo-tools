<?php
namespace BBSEO\Crawl;

use BBSEO\Helpers\Storage;

class Queue
{
    public static function init(string $project, array $urls, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $qdir = $dirs['queue'];
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

        foreach (glob($qdir . '/*.todo') as $f) {
            @unlink($f);
        }
        foreach (glob($qdir . '/*.done') as $f) {
            @unlink($f);
        }

        Storage::setLatestRun($project, $runId);

        $metaPath = $dirs['base'] . '/meta.json';
        $meta = [
            'run_id' => $runId,
            'project' => $project,
            'started_at' => gmdate('c'),
            'seed_urls' => $urls,
            'completed_at' => null,
        ];
        Storage::writeJson($metaPath, $meta);

        $added = self::enqueue($project, $urls, $runId);
        return ['queued' => $added, 'run_id' => $runId];
    }

    public static function next(string $project, string $runId): ?string
    {
        $qdir = Storage::runDir($project, $runId) . '/queue';
        $todos = glob($qdir . '/*.todo');
        return $todos ? $todos[0] : null;
    }

    public static function enqueue(string $project, array $urls, string $runId): int
    {
        $dirs = Storage::ensureRun($project, $runId);
        $qdir = $dirs['queue'];
        $pdir = $dirs['pages'];
        $edir = $dirs['errors'];
        $idir = $dirs['images'];
        $added = 0;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $hash = md5($url);
            $todo = $qdir . '/' . $hash . '.todo';
            $done = $qdir . '/' . $hash . '.done';
            $page = $pdir . '/' . $hash . '.json';
            $image = $idir . '/' . $hash . '.json';
            $error = $edir . '/' . $hash . '.json';
            if (file_exists($todo) || file_exists($done) || file_exists($page) || file_exists($image) || file_exists($error)) {
                continue;
            }
            file_put_contents($todo, $url);
            $added++;
        }
        return $added;
    }
}
