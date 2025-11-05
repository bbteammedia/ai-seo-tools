<?php
namespace AISEO\Rest;

use WP_REST_Request;
use AISEO\Helpers\Http;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\Crawl\Worker;
use AISEO\Audit\Runner as AuditRunner;
use AISEO\Report\Builder as ReportBuilder;
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
                if ($u) {
                    $urls[] = $u;
                }
            }
        }

        $baseUrl = Project::getBaseUrl($project);
        if ($baseUrl) {
            $urls[] = $baseUrl;
        }

        $urls = array_values(array_filter(array_map('esc_url_raw', array_unique($urls))));
        if (!$project || empty($urls)) {
            return Http::fail('project requires at least one valid URL (use Primary Site URL field or pass urls[])', 422);
        }
        $res = Queue::init($project, $urls);
        return Http::ok($res);
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
        $out = Worker::process($project);

        // Auto trigger audit+report when queue is empty
        $qdir = Storage::projectDir($project) . '/queue';
        $remaining = glob($qdir . '/*.todo');
        if (!$remaining) {
            AuditRunner::run($project);
            ReportBuilder::build($project);
        }
        return Http::ok(['processed' => $out]);
    }

    public static function audit(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        return $project ? Http::ok(AuditRunner::run($project)) : Http::fail('project required', 422);
    }

    public static function report(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        return $project ? Http::ok(ReportBuilder::build($project)) : Http::fail('project required', 422);
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
        $base = Storage::projectDir($project);
        $todos = glob($base . '/queue/*.todo');
        $done = glob($base . '/queue/*.done');
        $pages = glob($base . '/pages/*.json');
        return Http::ok([
            'project' => $project,
            'base_url' => Project::getBaseUrl($project),
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
        ]);
    }
}
