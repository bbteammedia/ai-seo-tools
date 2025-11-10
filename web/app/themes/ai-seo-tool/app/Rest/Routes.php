<?php
namespace BBSEO\Rest;

use WP_REST_Request;
use BBSEO\Helpers\Http;
use BBSEO\Helpers\RunId;
use BBSEO\Helpers\Storage;
use BBSEO\Crawl\Queue;
use BBSEO\Crawl\Worker;
use BBSEO\Audit\Runner as AuditRunner;
use BBSEO\Report\Builder as ReportBuilder;
use BBSEO\Report\Summary;
use BBSEO\Analytics\Dispatcher as AnalyticsDispatcher;
use BBSEO\Analytics\Store as AnalyticsStore;
use BBSEO\PostTypes\Project;

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

        register_rest_route('ai-seo-tool/v1', '/upload-ga', [
            'methods' => 'POST',
            'callback' => [self::class, 'uploadGa'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/upload-gsc', [
            'methods' => 'POST',
            'callback' => [self::class, 'uploadGsc'],
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
            $analytics = AnalyticsDispatcher::syncProject($project, $runId);
            $result['analytics'] = $analytics;
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
        $imagesDir = $runDir . '/images';
        $errorsDir = $runDir . '/errors';
        $todos = glob($queueDir . '/*.todo');
        $done = glob($queueDir . '/*.done');
        $pages = glob($pagesDir . '/*.json');
        $images = glob($imagesDir . '/*.json');
        $errors = glob($errorsDir . '/*.json');

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
            'images' => count($images),
            'errors' => count($errors),
            'base_url' => Project::getBaseUrl($project),
        ]);
    }

    private static function normalizeRunId(?string $runId): string
    {
        $runId = (string) $runId;
        return preg_replace('/[^A-Za-z0-9_\-]/', '', $runId);
    }

    public static function uploadGa(WP_REST_Request $req)
    {
        return self::handleAnalyticsUpload($req, 'ga');
    }

    public static function uploadGsc(WP_REST_Request $req)
    {
        return self::handleAnalyticsUpload($req, 'gsc');
    }

    private static function handleAnalyticsUpload(WP_REST_Request $req, string $target)
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

        $payload = self::resolveAnalyticsPayload($req);
        if ($payload === null) {
            return Http::fail('no analytics payload', 422);
        }

        $path = AnalyticsStore::save($project, $runId, $target, $payload);

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'saved' => true,
            'path' => $path,
        ]);
    }

    private static function resolveAnalyticsPayload(WP_REST_Request $req): ?array
    {
        $json = $req->get_json_params();
        if (is_array($json) && !empty($json)) {
            return $json;
        }

        $files = $req->get_file_params();
        if (!empty($files)) {
            $file = reset($files);
            if (is_array($file) && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                $contents = file_get_contents($file['tmp_name']);
                if ($contents === false || $contents === '') {
                    return null;
                }
                $decoded = json_decode($contents, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
                $rows = self::parseCsvToArray($contents);
                if ($rows !== null) {
                    return ['rows' => $rows];
                }
            }
        }

        return null;
    }

    private static function parseCsvToArray(string $contents): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($contents));
        if (!$lines || count($lines) < 2) {
            return null;
        }
        $header = str_getcsv(array_shift($lines));
        if (!$header) {
            return null;
        }

        $data = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line);
            if (!$row) {
                continue;
            }
            $data[] = array_combine($header, array_pad($row, count($header), null));
        }

        return $data ?: null;
    }
}
