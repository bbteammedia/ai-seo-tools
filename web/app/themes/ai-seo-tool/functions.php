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

// Register custom post types
add_action('init', [\BBSEO\PostTypes\Project::class, 'register']);
add_action('init', [\BBSEO\PostTypes\Report::class, 'register']);

// Register front-end routes/templates
\BBSEO\Template\Report::register();

add_action('after_switch_theme', function () {
    \BBSEO\Template\Report::register();
    flush_rewrite_rules();
});
// Register admin dashboard
add_action('admin_menu', [\BBSEO\Admin\Dashboard::class, 'register']);
add_action('admin_init', [\BBSEO\Admin\Dashboard::class, 'registerActions']);
if (is_admin()) {
    \BBSEO\Admin\Analytics::bootstrap();
}

add_action('init', [\BBSEO\Admin\ReportMetaBox::class, 'boot']);
add_action('init', [\BBSEO\Admin\ReportSectionsUI::class, 'boot']);
add_action('admin_init', [\BBSEO\Admin\ReportAdminList::class, 'boot']);

// Register run history page
add_action('admin_menu', [\BBSEO\Admin\RunHistoryPage::class, 'register_menu']);

// Cron scheduler
add_filter('cron_schedules', [\BBSEO\Cron\Scheduler::class, 'registerSchedules']);
add_action('init', [\BBSEO\Cron\Scheduler::class, 'init']);
add_action('switch_theme', [\BBSEO\Cron\Scheduler::class, 'deactivate']);
add_action('init', [\BBSEO\Cron\AnalyticsSync::class, 'init']);
add_action('switch_theme', [\BBSEO\Cron\AnalyticsSync::class, 'deactivate']);


// Ensure storage base dir exists on theme load
add_action('after_setup_theme', function () {
    $dir = getenv('BBSEO_STORAGE_DIR') ?: get_theme_file_path('storage/projects');
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
});
