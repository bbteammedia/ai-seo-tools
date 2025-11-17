<?php
namespace BBSEO\Template;

class ChatbotExample
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
        add_rewrite_rule('^chatbot-example/?$', 'index.php?bbseo_chatbot_example=1', 'top');
    }

    public static function registerQueryVar(array $vars): array
    {
        $vars[] = 'bbseo_chatbot_example';
        return $vars;
    }

    public static function loadTemplate(string $template): string
    {
        if (!get_query_var('bbseo_chatbot_example')) {
            return $template;
        }
        $file = get_theme_file_path('templates/chatbot-example.php');
        if (!file_exists($file)) {
            return $template;
        }
        status_header(200);
        return $file;
    }
}
