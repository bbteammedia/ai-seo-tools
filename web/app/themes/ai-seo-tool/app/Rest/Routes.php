<?php
namespace AISEO\Rest;

use WP_REST_Request;
use AISEO\Helpers\Http;
use AISEO\Helpers\RunId;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\Crawl\Worker;
use AISEO\Audit\Runner as AuditRunner;
use AISEO\Report\Builder as ReportBuilder;
use AISEO\Report\Summary;
use AISEO\PostTypes\Project;

class Routes
{
    public static function register()
    {
        register_rest_route('ai-seo-tool/v1', '/start-crawl', [
            'methods' => 'POST',
            'callback' => [self::class, 'startCrawl'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/crawl-step', [
            'methods' => 'GET',
            'callback' => [self::class, 'crawlStep'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/audit', [
            'methods' => 'POST',
            'callback' => [self::class, 'audit'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/report', [
            'methods' => 'POST',
            'callback' => [self::class, 'report'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function startCrawl(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        $urlsParam = $req->get_param('urls');
        $urls = [];
        if (is_array($urlsParam)) {
            foreach ($urlsParam as $u) {
                $clean = esc_url_raw((string) $u);
                if ($clean) {
                    $urls[] = $clean;
                }
            }
        }

        $baseUrl = Project::getBaseUrl($project);
        if ($baseUrl) {
            $urls[] = $baseUrl;
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if (!$project || empty($urls)) {
            return Http::fail('project requires at least one valid URL', 422);
        }

        $runId = $req->get_param('run_id');
        $runId = $runId ? self::normalizeRunId($runId) : RunId::new();

        $result = Queue::init($project, $urls, $runId);
        Storage::setLatestRun($project, $runId);

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'queued' => $result['queued'] ?? 0,
        ]);
    }

    public static function crawlStep(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : (Storage::getLatestRun($project) ?? '');
        if (!$runId) {
            return Http::fail('run not found', 404);
        }

        $result = Worker::process($project, $runId);

        if (!Queue::next($project, $runId)) {
            $audit = AuditRunner::run($project, $runId);
            $report = ReportBuilder::build($project, $runId);
            $summary = Summary::build($project, $runId);
            Summary::appendTimeseries($project, $summary);
            $result['audit'] = $audit['summary'] ?? [];
            $result['report'] = $report['crawl'] ?? [];
            $result['summary'] = [
                'pages' => $summary['pages'],
                'status' => $summary['status'],
                'issues' => $summary['issues']['total'],
            ];
        }

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'processed' => $result,
        ]);
    }

    public static function audit(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : (Storage::getLatestRun($project) ?? '');
        if (!$runId) {
            return Http::fail('run not found', 404);
        }

        return Http::ok(AuditRunner::run($project, $runId));
    }

    public static function report(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : (Storage::getLatestRun($project) ?? '');
        if (!$runId) {
            return Http::fail('run not found', 404);
        }

        return Http::ok(ReportBuilder::build($project, $runId));
    }

    public static function status(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : Storage::getLatestRun($project);
        if (!$runId) {
            return Http::ok([
                'project' => $project,
                'message' => 'no runs yet',
            ]);
        }

        $runDir = Storage::runDir($project, $runId);
        $queueDir = $runDir . '/queue';
        $pagesDir = $runDir . '/pages';
        $todos = glob($queueDir . '/*.todo');
        $done = glob($queueDir . '/*.done');
        $pages = glob($pagesDir . '/*.json');

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
            'base_url' => Project::getBaseUrl($project),
        ]);
    }

    private static function normalizeRunId(?string $runId): string
    {
        $runId = (string) $runId;
        return preg_replace('/[^A-Za-z0-9_\-]/', '', $runId);
    }

}
