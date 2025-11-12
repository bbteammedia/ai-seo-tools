<?php
namespace BBSEO\Analytics;

use BBSEO\Helpers\Storage;

class Queue
{
    private const QUEUE_FILE = '/analytics-queue.json';
    private const PROVIDERS = ['ga', 'gsc'];

    public static function enqueue(string $project, string $runId, array $providers): void
    {
        $targets = array_values(array_intersect(self::PROVIDERS, $providers));
        if (empty($targets)) {
            return;
        }

        $queue = self::load();
        $dirty = false;
        foreach ($targets as $provider) {
            if (self::containsTask($queue, $project, $runId, $provider)) {
                continue;
            }
            $queue[] = [
                'project' => $project,
                'run' => $runId,
                'provider' => $provider,
                'queued_at' => gmdate('c'),
            ];
            $dirty = true;
        }

        if ($dirty) {
            self::save($queue);
        }
    }

    public static function dequeue(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $queue = self::load();
        if (empty($queue)) {
            return [];
        }

        $batch = array_slice($queue, 0, $limit);
        $remaining = array_slice($queue, $limit);
        self::save($remaining);

        return $batch;
    }

    private static function load(): array
    {
        $path = self::queuePath();
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? array_values($data) : [];
    }

    private static function save(array $queue): void
    {
        self::ensureBaseDir();

        file_put_contents(
            self::queuePath(),
            json_encode(array_values($queue), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function queuePath(): string
    {
        return Storage::baseDir() . self::QUEUE_FILE;
    }

    private static function ensureBaseDir(): void
    {
        $dir = dirname(self::queuePath());
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    private static function containsTask(array $queue, string $project, string $runId, string $provider): bool
    {
        foreach ($queue as $entry) {
            if (
                isset($entry['project'], $entry['run'], $entry['provider'])
                && $entry['project'] === $project
                && $entry['run'] === $runId
                && $entry['provider'] === $provider
            ) {
                return true;
            }
        }
        return false;
    }
}
