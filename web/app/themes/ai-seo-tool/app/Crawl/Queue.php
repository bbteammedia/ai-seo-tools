<?php
namespace BBSEO\Crawl;

use BBSEO\Helpers\Storage;

class Queue
{
    public static function init(string $project, array $urls, string $runId): array
    {
        $excludePatterns = self::excludePatterns($project);
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

        $added = self::enqueue($project, $urls, $runId, $excludePatterns);
        return ['queued' => $added, 'run_id' => $runId];
    }

    public static function next(string $project, string $runId): ?string
    {
        $qdir = Storage::runDir($project, $runId) . '/queue';
        $todos = glob($qdir . '/*.todo');
        return $todos ? $todos[0] : null;
    }

    public static function enqueue(string $project, array $urls, string $runId, array $excludePatterns = []): int
    {
        $dirs = Storage::ensureRun($project, $runId);
        $qdir = $dirs['queue'];
        $pdir = $dirs['pages'];
        $edir = $dirs['errors'];
        $idir = $dirs['images'];
        $odir = $dirs['others'];
        $added = 0;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            if (self::isExcluded($url, $excludePatterns)) {
                continue;
            }
            $hash = md5($url);
            $todo = $qdir . '/' . $hash . '.todo';
            $done = $qdir . '/' . $hash . '.done';
            $page = $pdir . '/' . $hash . '.json';
            $image = $idir . '/' . $hash . '.json';
            $error = $edir . '/' . $hash . '.json';
            $other = $odir . '/' . $hash . '.json';
            if (file_exists($todo) || file_exists($done) || file_exists($page) || file_exists($image) || file_exists($error) || file_exists($other)) {
                continue;
            }
            file_put_contents($todo, $url);
            $added++;
        }
        return $added;
    }

    /**
     * @return array<int,string>
     */
    public static function excludePatterns(string $project): array
    {
        $configPath = Storage::projectDir($project) . '/config.json';
        if (!is_file($configPath)) {
            return [];
        }
        $config = json_decode(file_get_contents($configPath), true);
        if (!is_array($config)) {
            return [];
        }
        $patterns = is_array($config['exclude_urls'] ?? null) ? $config['exclude_urls'] : [];
        return array_values(array_filter(array_map('trim', $patterns)));
    }

    /**
     * @param string $url
     * @param array<int,string> $patterns
     */
    private static function isExcluded(string $url, array $patterns): bool
    {
        if (!$patterns) {
            return false;
        }
        $urlLower = strtolower($url);
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if (strpos($pattern, '*') !== false) {
                if (fnmatch($pattern, $url, FNM_CASEFOLD | FNM_PATHNAME)) {
                    return true;
                }
            }
            if ($pattern === $urlLower || stripos($urlLower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
