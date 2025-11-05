<?php
namespace AISEO\Report;

use AISEO\Helpers\Storage;
use AISEO\PostTypes\Project;

class Builder
{
    public static function build(string $project, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $audit = Storage::readJson($dirs['base'] . '/audit.json', []);
        $crawl = [
            'pages_count' => count(glob($dirs['pages'] . '/*.json')),
            'status_buckets' => $audit['summary']['status_buckets'] ?? [],
        ];
        $topIssues = [];
        if (!empty($audit['summary']['issue_counts'])) {
            $counts = $audit['summary']['issue_counts'];
            arsort($counts);
            $topIssues = array_slice($counts, 0, 10, true);
        }
        $data = [
            'run_id' => $runId,
            'project' => $project,
            'base_url' => Project::getBaseUrl($project),
            'generated_at' => gmdate('c'),
            'crawl' => $crawl,
            'audit' => $audit,
            'top_issues' => $topIssues,
        ];
        Storage::writeJson($dirs['base'] . '/report.json', $data);
        return $data;
    }
}
