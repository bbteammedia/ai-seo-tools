<?php
namespace BBSEO\Helpers;

class DataLoader
{
    public static function forReport(string $type, string $project, array $runs = [], string $pageUrl = ''): array
    {
        $out = [
            'type' => $type,
            'project' => $project,
            'runs' => [],
            'project_scope' => [
                'timeseries' => self::read(Storage::projectDir($project) . '/timeseries.json'),
                'ga_timeseries' => self::read(Storage::projectDir($project) . '/analytics/ga-timeseries.json'),
                'gsc_timeseries' => self::read(Storage::projectDir($project) . '/analytics/gsc-timeseries.json'),
            ],
        ];
        if (!$project) {
            return $out;
        }

        if (!$runs) {
            $latest = Storage::getLatestRun($project);
            if ($latest) {
                $runs = [$latest];
            }
        }

        foreach ($runs as $run) {
            $dir = Storage::runDir($project, $run);
            $runPack = [
                'run_id' => $run,
                'summary' => self::read($dir . '/summary.json') ?: [],
                'report' => self::read($dir . '/report.json') ?: [],
                'audit' => self::read($dir . '/audit.json') ?: [],
                'analytics' => [
                    'ga' => self::read($dir . '/analytics/ga.json'),
                    'gsc' => self::read($dir . '/analytics/gsc.json'),
                    'gsc_details' => self::read($dir . '/analytics/gsc-details.json'),
                ],
                'backlinks' => [
                    'provider' => self::read($dir . '/backlinks/provider.json'),
                ],
                'pages' => [],
            ];

            if ($type === 'per_page' && $pageUrl) {
                $pf = $dir . '/pages/' . md5($pageUrl) . '.json';
                $runPack['pages'] = is_file($pf) ? [self::read($pf) ?: []] : [];
            }

            $out['runs'][] = $runPack;
        }

        return $out;
    }

    private static function read(string $path)
    {
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
