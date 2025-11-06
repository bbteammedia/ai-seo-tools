<?php
// Ensure theme classes autoload
require_once __DIR__ . '/vendor/autoload.php';

// Register REST routes
add_action('rest_api_init', [\AISEO\Rest\Routes::class, 'register']);

// Register custom post types
add_action('init', [\AISEO\PostTypes\Project::class, 'register']);

// Register front-end routes/templates
\AISEO\Template\Report::register();

add_action('after_switch_theme', function () {
    \AISEO\Template\Report::register();
    flush_rewrite_rules();
});
// Register admin dashboard
add_action('admin_menu', [\AISEO\Admin\Dashboard::class, 'register']);
add_action('admin_init', [\AISEO\Admin\Dashboard::class, 'registerActions']);
if (is_admin()) {
    \AISEO\Admin\Analytics::bootstrap();
}

// Register run history page
add_action('admin_menu', [\AISEO\Admin\RunHistoryPage::class, 'register_menu']);

// Cron scheduler
add_filter('cron_schedules', [\AISEO\Cron\Scheduler::class, 'registerSchedules']);
add_action('init', [\AISEO\Cron\Scheduler::class, 'init']);
add_action('switch_theme', [\AISEO\Cron\Scheduler::class, 'deactivate']);
add_action('init', [\AISEO\Cron\AnalyticsSync::class, 'init']);
add_action('switch_theme', [\AISEO\Cron\AnalyticsSync::class, 'deactivate']);


// Ensure storage base dir exists on theme load
add_action('after_setup_theme', function () {
    $dir = getenv('AISEO_STORAGE_DIR') ?: get_theme_file_path('storage/projects');
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
});
