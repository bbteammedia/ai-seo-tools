<?php
namespace AISEO\Helpers;

class DataLoader
{
    public static function forReport(string $type, string $project, array $runs, string $pageUrl = ''): array
    {
        $project = sanitize_title($project);
        $result = [
            'type' => $type,
            'project' => $project,
            'runs' => [],
        ];

        if (!$project) {
            return $result;
        }

        $useRuns = $runs;
        if (empty($useRuns)) {
            $latest = Storage::getLatestRun($project);
            if ($latest) {
                $useRuns = [$latest];
            }
        }

        foreach ($useRuns as $run) {
            $run = sanitize_text_field($run);
            if (!$run) {
                continue;
            }

            $dir = Storage::runDir($project, $run);
            $summary = self::read($dir . '/summary.json');
            $audit = self::read($dir . '/audit.json');
            $report = self::read($dir . '/report.json');
            $pages = [];

            if ($type === 'per_page' && $pageUrl) {
                $path = $dir . '/pages/' . md5($pageUrl) . '.json';
                $pages = is_file($path) ? [self::read($path)] : [];
            } else {
                foreach (glob($dir . '/pages/*.json') as $file) {
                    $pages[] = self::read($file);
                }
            }

            $result['runs'][] = [
                'run_id' => $run,
                'summary' => $summary,
                'audit' => $audit,
                'report' => $report,
                'pages' => $pages,
            ];
        }

        return $result;
    }

    private static function read(string $path)
    {
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }
}
