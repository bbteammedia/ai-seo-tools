<?php
namespace BBSEO\Template;

use BBSEO\PostTypes\Report as ReportPostType;
use Dompdf\Dompdf;
use Dompdf\Options;

class Report
{
    private static bool $booted = false;

    public static function register(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('init', [self::class, 'addRewriteRule']);
        add_filter('query_vars', [self::class, 'registerQueryVar']);
        add_filter('template_include', [self::class, 'loadTemplate']);
    }

    public static function addRewriteRule(): void
    {
        add_rewrite_rule('^ai-seo-report/([^/]+)/?$', 'index.php?BBSEO_report=$matches[1]', 'top');
    }

    public static function registerQueryVar(array $vars): array
    {
        $vars[] = 'BBSEO_report';
        return $vars;
    }

    public static function loadTemplate(string $template): string
    {
        $project = get_query_var('BBSEO_report');
        if (!$project) {
            return $template;
        }

        $reportPost = get_page_by_path($project, OBJECT, ReportPostType::POST_TYPE);
        if (!$reportPost instanceof \WP_Post) {
            return $template;
        }

        $file = get_theme_file_path('templates/report.php');
        if (!file_exists($file)) {
            return $template;
        }

        global $wp_query, $post;
        $post = $reportPost;

        if ($wp_query instanceof \WP_Query) {
            $wp_query->is_404 = false;
            $wp_query->is_home = false;
            $wp_query->is_page = false;
            $wp_query->is_single = true;
            $wp_query->is_singular = true;
            $wp_query->posts = [$reportPost];
            $wp_query->post = $reportPost;
            $wp_query->queried_object = $reportPost;
            $wp_query->queried_object_id = $reportPost->ID;
            $wp_query->found_posts = 1;
            $wp_query->post_count = 1;
            $wp_query->max_num_pages = 1;
        }

        setup_postdata($reportPost);
        $GLOBALS['BBSEO_report_post'] = $reportPost;
        status_header(200);

        $format = isset($_GET['format']) ? sanitize_key($_GET['format']) : '';
        if ($format === 'pdf') {
            self::renderPdf($reportPost, $file);
            exit;
        }

        return $file;
    }

    private static function renderPdf(\WP_Post $reportPost, string $file): void
    {
        ob_start();
        include $file;
        $html = ob_get_clean() ?: '';

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sanitize_title(get_the_title($reportPost) ?: 'report');
        $dompdf->stream($filename . '.pdf', ['Attachment' => true]);
        exit;
    }
}
