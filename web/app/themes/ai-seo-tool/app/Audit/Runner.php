<?php
namespace BBSEO\Audit;

use BBSEO\Helpers\Storage;

class Runner
{
    public static function run(string $project, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $pdir = $dirs['pages'];
        $audits = [];
        $issueCounts = [];
        $statusBuckets = [
            '2xx' => 0,
            '3xx' => 0,
            '4xx' => 0,
            '5xx' => 0,
            'other' => 0,
        ];

        foreach (glob($pdir . '/*.json') as $f) {
            $page = Storage::readJson($f, []);
            if (!$page) {
                continue;
            }

            [$issues, $bucket] = self::assessPage($page);

            if (isset($statusBuckets[$bucket])) {
                $statusBuckets[$bucket]++;
            } else {
                $statusBuckets['other']++;
            }

            foreach ($issues as $issue) {
                $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
            }

            $audits[] = [
                'url' => $page['url'] ?? '',
                'status' => $page['status'] ?? null,
                'issues' => $issues,
            ];
        }

        $out = [
            'run_id' => $runId,
            'project' => $project,
            'generated_at' => gmdate('c'),
            'summary' => [
                'total_pages' => count($audits),
                'status_buckets' => $statusBuckets,
                'issue_counts' => $issueCounts,
            ],
            'items' => $audits,
        ];
        Storage::writeJson($dirs['base'] . '/audit.json', $out);
        return $out;
    }

    private static function assessPage(array $page): array
    {
        $issues = [];
        $status = (int)($page['status'] ?? 0);
        $bucket = self::bucketStatus($status);

        if ($status >= 500) {
            $issues[] = 'Server error (5xx)';
        } elseif ($status >= 400) {
            $issues[] = 'Client error (4xx)';
        } elseif ($status >= 300) {
            $issues[] = 'Redirect (3xx)';
        } elseif ($status === 0) {
            $issues[] = 'Missing status code';
        }

        $title = trim((string)($page['title'] ?? ''));
        $titleLen = mb_strlen($title);
        if ($title === '') {
            $issues[] = 'Missing title tag';
        } elseif ($titleLen > 70) {
            $issues[] = 'Title longer than 70 characters';
        } elseif ($titleLen < 30) {
            $issues[] = 'Title shorter than 30 characters';
        }

        $meta = trim((string)($page['meta_description'] ?? ''));
        $metaLen = mb_strlen($meta);
        if ($meta === '') {
            $issues[] = 'Missing meta description';
        } elseif ($metaLen > 160) {
            $issues[] = 'Meta description longer than 160 characters';
        } elseif ($metaLen < 50) {
            $issues[] = 'Meta description shorter than 50 characters';
        }

        if (empty($page['canonical'])) {
            $issues[] = 'Missing canonical URL';
        }

        $headings = $page['headings'] ?? [];
        $h1s = is_array($headings['h1'] ?? null) ? $headings['h1'] : [];
        if (count($h1s) === 0) {
            $issues[] = 'Missing H1 heading';
        } elseif (count($h1s) > 1) {
            $issues[] = 'Multiple H1 headings';
        }

        $contentLength = (int)($page['content_length'] ?? 0);
        if ($contentLength > 1024 * 1024) {
            $issues[] = 'Content size greater than 1MB';
        }

        $images = $page['images'] ?? [];
        if (is_array($images)) {
            $missingAlt = array_filter($images, fn($img) => trim((string)($img['alt'] ?? '')) === '');
            if (count($missingAlt) > 0) {
                $issues[] = 'Images without ALT text';
            }
        }

        $og = $page['open_graph'] ?? [];
        if (!self::hasKeys($og, ['og:title', 'og:description'])) {
            $issues[] = 'Missing OG title or description';
        }
        if (!self::hasKeys($og, ['og:image'])) {
            $issues[] = 'Missing OG image';
        }

        $schemas = $page['structured_data'] ?? [];
        if (is_array($schemas) && count($schemas) === 0) {
            $issues[] = 'No structured data';
        }

        return [$issues, $bucket];
    }

    private static function bucketStatus(int $status): string
    {
        if ($status >= 500) {
            return '5xx';
        }
        if ($status >= 400) {
            return '4xx';
        }
        if ($status >= 300) {
            return '3xx';
        }
        if ($status >= 200 && $status < 300) {
            return '2xx';
        }
        return 'other';
    }

    private static function hasKeys(array $haystack, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($haystack[$key]) || trim((string)$haystack[$key]) === '') {
                return false;
            }
        }
        return true;
    }
}
