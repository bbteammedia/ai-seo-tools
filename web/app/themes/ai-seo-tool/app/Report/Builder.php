<?php
namespace AISEO\Report;

use AISEO\Helpers\Storage;
use AISEO\PostTypes\Project;

class Builder
{
    public static function build(string $project): array
    {
        $base = Storage::projectDir($project);
        $audit = Storage::readJson($base . '/audit.json', []);
        $crawl = [
            'pages_count' => count(glob($base . '/pages/*.json')),
            'status_buckets' => $audit['summary']['status_buckets'] ?? [],
        ];
        $topIssues = [];
        if (!empty($audit['summary']['issue_counts'])) {
            arsort($audit['summary']['issue_counts']);
            $topIssues = array_slice($audit['summary']['issue_counts'], 0, 10, true);
        }
        $data = [
            'project' => $project,
            'base_url' => Project::getBaseUrl($project),
            'generated_at' => gmdate('c'),
            'crawl' => $crawl,
            'audit' => $audit,
            'top_issues' => $topIssues,
        ];
        Storage::writeJson($base . '/report.json', $data);
        return $data;
    }
}
