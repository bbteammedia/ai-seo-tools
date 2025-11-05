<?php
namespace AISEO\Crawl;

use AISEO\Helpers\Storage;

class Queue
{
    public static function init(string $project, array $urls): array
    {
        $dirs = Storage::ensureProject($project);
        $qdir = $dirs['queue'];
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        // Clean old queue
        foreach (glob($qdir . '/*.todo') as $f) {
            @unlink($f);
        }
        foreach (glob($qdir . '/*.done') as $f) {
            @unlink($f);
        }
        // Seed queue
        $added = self::enqueue($project, $urls);
        return ['queued' => $added];
    }

    public static function next(string $project): ?string
    {
        $qdir = Storage::projectDir($project) . '/queue';
        $todos = glob($qdir . '/*.todo');
        return $todos ? $todos[0] : null;
    }

    public static function enqueue(string $project, array $urls): int
    {
        $dirs = Storage::ensureProject($project);
        $qdir = $dirs['queue'];
        $pdir = $dirs['pages'];
        $added = 0;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if (!$url) {
                continue;
            }
            $hash = md5($url);
            $todo = $qdir . '/' . $hash . '.todo';
            $done = $qdir . '/' . $hash . '.done';
            $page = $pdir . '/' . $hash . '.json';
            if (file_exists($todo) || file_exists($done) || file_exists($page)) {
                continue;
            }
            file_put_contents($todo, $url);
            $added++;
        }
        return $added;
    }
}
