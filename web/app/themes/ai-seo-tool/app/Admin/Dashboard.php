<?php
namespace AISEO\Admin;

use AISEO\Helpers\RunId;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\PostTypes\Project;

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
            <?php if (isset($_GET['aiseo_notice'])): $notice = sanitize_text_field($_GET['aiseo_notice']); ?>
                <?php if ($notice === 'run'): ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Crawl queued. Cron will begin shortly.', 'ai-seo-tool'); ?></p></div>
                <?php elseif ($notice === 'run_fail'): ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Unable to queue crawl. Ensure a primary URL or seed_urls are configured.', 'ai-seo-tool'); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$projects): ?>
                <p><?php esc_html_e('No AI SEO projects found. Create one under AI SEO Projects → Add New.', 'ai-seo-tool'); ?></p>
                <?php return; ?>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Project', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Primary URL', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Latest Run', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Queue', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Actions', 'ai-seo-tool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <?php $summary = self::loadSummary($project['slug']); ?>
                        <tr>
                            <td><?php echo esc_html($project['title']); ?></td>
                            <td>
                                <?php if ($project['base_url']): ?>
                                    <a href="<?php echo esc_url($project['base_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($project['base_url']); ?></a>
                                <?php else: ?>
                                    <em><?php esc_html_e('Not set', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($summary['run_id']): ?>
                                    <strong><?php echo esc_html($summary['run_id']); ?></strong><br />
                                    <span class="description"><?php echo esc_html($summary['status']); ?></span>
                                <?php else: ?>
                                    <em><?php esc_html_e('No runs yet', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($summary['run_id']): ?>
                                    <?php printf(__('Todo: %d, Done: %d, Pages: %d', 'ai-seo-tool'), $summary['queue_remaining'], $summary['queue_done'], $summary['pages']); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url(self::reportLink($project['slug'], $summary['run_id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('View Report', 'ai-seo-tool'); ?></a>
                                <a class="button" href="<?php echo esc_url(self::manualCrawlUrl($project['slug'])); ?>"><?php esc_html_e('Run Crawl Now', 'ai-seo-tool'); ?></a>
                                <a class="button" href="<?php echo esc_url(self::historyLink($project['slug'])); ?>"><?php esc_html_e('Run History', 'ai-seo-tool'); ?></a>
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

        return array_map(function ($post) {
            return [
                'ID' => $post->ID,
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'base_url' => Project::getBaseUrl($post->post_name),
            ];
        }, $posts);
    }

    private static function loadSummary(string $project): array
    {
        $runId = Storage::getLatestRun($project);
        if (!$runId) {
            return [
                'run_id' => null,
                'status' => __('Pending', 'ai-seo-tool'),
                'queue_remaining' => 0,
                'queue_done' => 0,
                'pages' => 0,
            ];
        }
        $runDir = Storage::runDir($project, $runId);
        $queueDir = $runDir . '/queue';
        $pagesDir = $runDir . '/pages';
        $todos = glob($queueDir . '/*.todo');
        $done = glob($queueDir . '/*.done');
        $pages = glob($pagesDir . '/*.json');
        $status = __('Processing', 'ai-seo-tool');
        if (empty($todos)) {
            $status = __('Completed', 'ai-seo-tool');
        }
        return [
            'run_id' => $runId,
            'status' => $status,
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
        ];
    }

    private static function reportLink(string $slug, ?string $runId): string
    {
        $home = trailingslashit(home_url());
        $url = $home . 'ai-seo-report/' . $slug;
        if ($runId) {
            $url = add_query_arg('run', $runId, $url);
        }
        return $url;
    }

    private static function historyLink(string $slug): string
    {
        return add_query_arg([
            'page' => 'ai-seo-run-history',
            'project' => sanitize_title($slug),
        ], admin_url('admin.php'));
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

    public static function handleManualRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }

        $slug = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        check_admin_referer('aiseo_run_crawl_' . $slug);

        // Ensure project folder & config
        Storage::ensureProject($slug);
        $cfgPath = Storage::projectDir($slug) . '/config.json';
        if (!is_file($cfgPath)) {
            // Minimal fallback config when user never saved post meta
            $config = ['enabled' => true, 'frequency' => 'manual', 'seed_urls' => []];
            Storage::writeJson($cfgPath, $config);
        }
        $config = json_decode(file_get_contents($cfgPath), true) ?: [];

        // Build seed list: config seed_urls + Base URL meta
        $urls = is_array($config['seed_urls'] ?? null) ? $config['seed_urls'] : [];
        $base = \AISEO\PostTypes\Project::getBaseUrl($slug);
        if ($base) { $urls[] = $base; }
        $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));

        if (empty($urls)) {
            wp_safe_redirect(add_query_arg('aiseo_notice', 'run_fail', admin_url('admin.php?page=ai-seo-dashboard')));
            exit;
        }

        $runId = RunId::new();
        Queue::init($slug, $urls, $runId);          // creates runs/{runId}/queue + meta.json
        Storage::setLatestRun($slug, $runId);

        wp_safe_redirect(add_query_arg('aiseo_notice', 'run', admin_url('admin.php?page=ai-seo-dashboard')));
        exit;
    }

    private static function readConfig(string $project): array
    {
        $path = Storage::projectDir($project) . '/config.json';
        return file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
}
