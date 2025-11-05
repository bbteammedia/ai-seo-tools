<?php
namespace AISEO\Template;

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
        add_rewrite_rule('^ai-seo-report/([^/]+)/?$', 'index.php?aiseo_report=$matches[1]', 'top');
    }

    public static function registerQueryVar(array $vars): array
    {
        $vars[] = 'aiseo_report';
        return $vars;
    }

    public static function loadTemplate(string $template): string
    {
        $project = get_query_var('aiseo_report');
        if (!$project) {
            return $template;
        }

        $file = get_theme_file_path('templates/report.php');
        return file_exists($file) ? $file : $template;
    }
}
