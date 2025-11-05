<?php
namespace AISEO\Helpers;

class Storage
{
    public static function baseDir(): string
    {
        $dir = getenv('AISEO_STORAGE_DIR');
        return $dir ?: get_theme_file_path('storage/projects');
    }

    public static function projectDir(string $slug): string
    {
        return self::baseDir() . '/' . sanitize_title($slug);
    }

    public static function ensureProject(string $slug): array
    {
        $base = self::projectDir($slug);
        $dirs = [
            $base,
            $base . '/runs',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                wp_mkdir_p($d);
            }
        }
        return [
            'base' => $base,
            'runs' => $base . '/runs',
        ];
    }

    public static function historyDir(string $slug): string
    {
        return self::projectDir($slug) . '/history';
    }

    public static function runsDir(string $project): string
    {
        return self::ensureProject($project)['runs'];
    }

    public static function runDir(string $project, string $runId): string
    {
        return self::runsDir($project) . '/' . self::normalizeRunId($runId);
    }

    public static function ensureRun(string $project, string $runId): array
    {
        $base = self::runDir($project, $runId);
        $dirs = [
            $base,
            $base . '/queue',
            $base . '/pages',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                wp_mkdir_p($d);
            }
        }
        return [
            'base' => $base,
            'queue' => $base . '/queue',
            'pages' => $base . '/pages',
        ];
    }

    public static function latestRunPath(string $project): string
    {
        return self::projectDir($project) . '/latest_run.txt';
    }

    public static function setLatestRun(string $project, string $runId): void
    {
        $path = self::latestRunPath($project);
        file_put_contents($path, self::normalizeRunId($runId));
    }

    public static function getLatestRun(string $project): ?string
    {
        $path = self::latestRunPath($project);
        if (!file_exists($path)) {
            return null;
        }
        $run = trim(file_get_contents($path));
        return $run !== '' ? $run : null;
    }

    private static function normalizeRunId(string $runId): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '', $runId);
    }

    public static function writeJson(string $path, $data): bool
    {
        return (bool) file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public static function readJson(string $path, $default = [])
    {
        return file_exists($path) ? json_decode(file_get_contents($path), true) : $default;
    }
}
