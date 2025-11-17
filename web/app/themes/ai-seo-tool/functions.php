<?php

// Ensure theme classes autoload via project-level Composer
$projectAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($projectAutoload)) {
    require_once $projectAutoload;
} else {
    $themeAutoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($themeAutoload)) {
        require_once $themeAutoload;
    }
}

// Register REST routes
add_action('rest_api_init', [\BBSEO\Rest\Routes::class, 'register']);
add_action('rest_api_init', [\BBSEO\Chatbot\Routes::class, 'register']);

// Register custom post types
add_action('init', [\BBSEO\PostTypes\Project::class, 'register']);
add_action('init', [\BBSEO\PostTypes\Report::class, 'register']);

// Register front-end routes/templates
\BBSEO\Template\Report::register();
\BBSEO\Template\ChatbotExample::register();

add_action('after_switch_theme', function () {
    \BBSEO\Template\Report::register();
    \BBSEO\Template\ChatbotExample::register();
    flush_rewrite_rules();
});
// Register admin dashboard
add_action('admin_menu', [\BBSEO\Admin\Dashboard::class, 'register']);
add_action('admin_init', [\BBSEO\Admin\Dashboard::class, 'registerActions']);
if (is_admin()) {
    \BBSEO\Admin\Analytics::bootstrap();
    \BBSEO\Admin\ArticleGeneratorPage::bootstrap();
}

add_action('init', [\BBSEO\Admin\ReportMetaBox::class, 'boot']);
add_action('init', [\BBSEO\Admin\ReportSectionsUI::class, 'boot']);
add_action('admin_init', [\BBSEO\Admin\ReportAdminList::class, 'boot']);
add_action('admin_menu', [\BBSEO\Admin\ChatbotSettings::class, 'registerPage']);
add_action('admin_menu', [\BBSEO\Admin\ChatbotHistoryPage::class, 'register']);
add_action('admin_post_bbseo_save_chatbot_context', [\BBSEO\Admin\ChatbotSettings::class, 'handleSave']);

// Register run history page
add_action('admin_menu', [\BBSEO\Admin\RunHistoryPage::class, 'register_menu']);

// Cron scheduler
add_filter('cron_schedules', [\BBSEO\Cron\Scheduler::class, 'registerSchedules']);
add_action('init', [\BBSEO\Cron\Scheduler::class, 'init']);
add_action('switch_theme', [\BBSEO\Cron\Scheduler::class, 'deactivate']);
add_action('init', [\BBSEO\Cron\AnalyticsSync::class, 'init']);
add_action('switch_theme', [\BBSEO\Cron\AnalyticsSync::class, 'deactivate']);
add_action('init', [\BBSEO\Cron\AnalyticsQueueRunner::class, 'init']);
add_action('switch_theme', [\BBSEO\Cron\AnalyticsQueueRunner::class, 'deactivate']);
\BBSEO\AI\ArticleGenerator::bootstrap();


// Ensure storage base dir exists on theme load
add_action('after_setup_theme', function () {
    $dir = getenv('BBSEO_STORAGE_DIR') ?: get_theme_file_path('storage/projects');
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    \BBSEO\Chatbot\SessionStore::ensureBaseDir();
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails', ['post', \BBSEO\PostTypes\Project::POST_TYPE]);
});

/**
 * Retrieve the asset path from the manifest file.
 *
 * @param string $key The asset key to look up.
 * @return string|null The asset path or null if not found.
 */
function aiseotool_manifest_get($key)
{
    $base_dir = get_stylesheet_directory() . '/assets/dist';
    $manifest = $base_dir . '/manifest.json';
    static $map = null;

    if (is_null($map)) {
        if (file_exists($manifest)) {
            $json = file_get_contents($manifest);
            $map  = json_decode($json, true);
        } else {
            $map = [];
        }
    }
    return $map[$key] ?? null;
}

add_action('wp_enqueue_scripts', function () {
    if (! is_admin() && ! is_user_logged_in()) {
        // example condition, change as you like (e.g., is_front_page() || is_page_template(...))
    }
    $theme_uri = get_stylesheet_directory_uri();
    $css = aiseotool_manifest_get('public.css') ?: 'css/public.css';
    $js  = aiseotool_manifest_get('public.js') ?: 'js/public.js';
    $ven = aiseotool_manifest_get('vendor.js');
    if ($ven) {
        wp_enqueue_script('ai-seo-tool-vendor', $theme_uri . '/assets/dist/' . $ven, [], null, true);
    }
    wp_enqueue_style('ai-seo-tool-public', $theme_uri . '/assets/dist/' . $css, [], null);
    wp_enqueue_script('ai-seo-tool-public', $theme_uri . '/assets/dist/' . $js, ['ai-seo-tool-vendor'], null, true);
}, 20);

add_action('admin_enqueue_scripts', function ($hook) {
    if ('wp-login.php' === $GLOBALS['pagenow']) {
        return;
    } // just in case
    $theme_uri = get_stylesheet_directory_uri();
    $css = aiseotool_manifest_get('admin.css') ?: 'css/admin.css';
    $js  = aiseotool_manifest_get('admin.js') ?: 'js/admin.js';
    $ven = aiseotool_manifest_get('vendor.js');
    $deps = [];

    if ($ven) {
        wp_enqueue_script('ai-seo-tool-vendor', $theme_uri . '/assets/dist/' . $ven, [], null, true);
        $deps[] = 'ai-seo-tool-vendor';
    }
    wp_enqueue_style('ai-seo-tool-admin', $theme_uri . '/assets/dist/' . $css, [], null);
    wp_enqueue_script('ai-seo-tool-admin', $theme_uri . '/assets/dist/' . $js, $deps, null, true);
}, 20);
