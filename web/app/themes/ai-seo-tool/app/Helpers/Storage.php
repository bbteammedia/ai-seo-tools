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
            $base . '/queue',
            $base . '/pages',
            $base . '/history',
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
            'history' => $base . '/history',
        ];
    }

    public static function historyDir(string $slug): string
    {
        return self::projectDir($slug) . '/history';
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
