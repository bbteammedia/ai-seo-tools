<?php
namespace AISEO\Admin;

use AISEO\PostTypes\Project;
use AISEO\Helpers\Storage;
use AISEO\Cron\Scheduler;

class Dashboard
{
    public static function register(): void
    {
        add_menu_page(
            __('AI SEO Dashboard', 'ai-seo-tool'),
            __('AI SEO', 'ai-seo-tool'),
            'manage_options',
            'ai-seo-dashboard',
            [self::class, 'render'],
            'dashicons-chart-area',
            56
        );
    }

    public static function registerActions(): void
    {
        add_action('admin_post_aiseo_run_crawl', [self::class, 'handleManualRun']);
        add_action('admin_post_aiseo_toggle_scheduler', [self::class, 'handleToggleScheduler']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'ai-seo-tool'));
        }

        $projects = self::getProjects();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI SEO Projects', 'ai-seo-tool'); ?></h1>
            <?php if (isset($_GET['aiseo_notice'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Crawl has been queued. Check back soon for updated results.', 'ai-seo-tool'); ?></p>
                </div>
            <?php endif; ?>
            <div class="notice-inline">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:20px;">
                    <?php wp_nonce_field('aiseo_toggle_scheduler'); ?>
                    <input type="hidden" name="action" value="aiseo_toggle_scheduler" />
                    <p>
                        <strong><?php esc_html_e('Scheduler status:', 'ai-seo-tool'); ?></strong>
                        <?php if (Scheduler::isEnabled()): ?>
                            <span class="status-enabled"><?php esc_html_e('Active', 'ai-seo-tool'); ?></span>
                            <button type="submit" name="enabled" value="0" class="button"><?php esc_html_e('Disable Scheduler', 'ai-seo-tool'); ?></button>
                        <?php else: ?>
                            <span class="status-disabled"><?php esc_html_e('Disabled', 'ai-seo-tool'); ?></span>
                            <button type="submit" name="enabled" value="1" class="button button-primary"><?php esc_html_e('Enable Scheduler', 'ai-seo-tool'); ?></button>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            <?php if (!$projects): ?>
                <p><?php esc_html_e('No AI SEO projects found. Create one under AI SEO Projects → Add New.', 'ai-seo-tool'); ?></p>
                <?php return; ?>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Project', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Primary URL', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Schedule', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Pages', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Last Crawl', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Top Issues', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Actions', 'ai-seo-tool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <?php $summary = self::loadSummary($project); ?>
                        <tr>
                            <td><?php echo esc_html($project['title']); ?></td>
                            <td>
                                <?php if ($project['base_url']): ?>
                                    <a href="<?php echo esc_url($project['base_url']); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html($project['base_url']); ?>
                                    </a>
                                <?php else: ?>
                                    <em><?php esc_html_e('Not set', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($project['schedule_label']); ?></td>
                            <td><?php echo (int) ($summary['pages'] ?? 0); ?></td>
                            <td>
                                <?php
                                if (!empty($summary['last_run'])) {
                                    $relative = human_time_diff($summary['last_run'], current_time('timestamp'));
                                    printf('%s<br /><span class="description">%s %s</span>', esc_html(gmdate('Y-m-d H:i', $summary['last_run'])), esc_html($relative), esc_html__('ago', 'ai-seo-tool'));
                                } else {
                                    echo '<em>' . esc_html__('Never', 'ai-seo-tool') . '</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($summary['top_issues'])): ?>
                                    <ul class="small">
                                        <?php foreach ($summary['top_issues'] as $issue => $count): ?>
                                            <li><?php echo esc_html($issue); ?> <span class="count">(<?php echo (int) $count; ?>)</span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <em><?php esc_html_e('No issues', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url(self::reportLink($project['slug'])); ?>" target="_blank">
                                    <?php esc_html_e('View Report', 'ai-seo-tool'); ?>
                                </a>
                                <a class="button" href="<?php echo esc_url(self::manualCrawlUrl($project['slug'])); ?>">
                                    <?php esc_html_e('Run Crawl Now', 'ai-seo-tool'); ?>
                                </a>
                                <a class="button" href="<?php echo esc_url(self::apiStatusLink($project['slug'])); ?>" target="_blank" rel="noopener">
                                    <?php esc_html_e('REST Status', 'ai-seo-tool'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function getProjects(): array
    {
        $posts = get_posts([
            'post_type' => Project::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);

        $options = Project::scheduleOptions();

        return array_map(function ($post) use ($options) {
            $base = Project::getBaseUrl($post->post_name);
            $schedule = Project::getSchedule($post->post_name);
            return [
                'ID' => $post->ID,
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'base_url' => $base,
                'schedule' => $schedule,
                'schedule_label' => $options[$schedule] ?? ucfirst($schedule),
            ];
        }, $posts);
    }

    private static function loadSummary(array $project): array
    {
        $reportPath = Storage::projectDir($project['slug']) . '/report.json';
        $report = file_exists($reportPath) ? json_decode(file_get_contents($reportPath), true) : [];

        $audit = $report['audit'] ?? [];
        $summary = [
            'generated_at' => $report['generated_at'] ?? null,
            'pages' => $report['crawl']['pages_count'] ?? null,
            'status_buckets' => $report['crawl']['status_buckets'] ?? [],
            'top_issues' => $report['top_issues'] ?? [],
            'last_run' => Project::getLastRun($project['slug']),
        ];

        if (empty($summary['top_issues']) && !empty($audit['summary']['issue_counts'])) {
            $counts = $audit['summary']['issue_counts'];
            arsort($counts);
            $summary['top_issues'] = array_slice($counts, 0, 5, true);
        }

        return $summary;
    }

    private static function reportLink(string $slug): string
    {
        $home = trailingslashit(home_url());
        return $home . 'ai-seo-report/' . $slug;
    }

    private static function manualCrawlUrl(string $slug): string
    {
        $url = admin_url('admin-post.php');
        $url = add_query_arg([
            'action' => 'aiseo_run_crawl',
            'project' => sanitize_title($slug),
        ], $url);
        return wp_nonce_url($url, 'aiseo_run_crawl_' . $slug);
    }

    private static function apiStatusLink(string $slug): string
    {
        $key = getenv('AISEO_SECURE_TOKEN') ?: 'AISEO_TOKEN';
        return add_query_arg([
            'project' => $slug,
            'key' => $key,
        ], rest_url('ai-seo-tool/v1/status'));
    }

    public static function handleManualRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }

        $slug = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        check_admin_referer('aiseo_run_crawl_' . $slug);

        if ($slug) {
            Scheduler::runProject($slug, true, 200);
        }

        wp_safe_redirect(add_query_arg('aiseo_notice', 'run', admin_url('admin.php?page=ai-seo-dashboard')));
        exit;
    }

    public static function handleToggleScheduler(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }

        check_admin_referer('aiseo_toggle_scheduler');

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        Scheduler::setEnabled($enabled);
        if ($enabled) {
            Scheduler::init();
        } else {
            Scheduler::deactivate();
        }

        wp_safe_redirect(add_query_arg('aiseo_notice', $enabled ? 'scheduler_on' : 'scheduler_off', admin_url('admin.php?page=ai-seo-dashboard')));
        exit;
    }
}
