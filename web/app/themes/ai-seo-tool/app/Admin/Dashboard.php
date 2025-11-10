<?php
namespace BBSEO\Admin;

use BBSEO\Helpers\RunId;
use BBSEO\Helpers\Storage;
use BBSEO\Crawl\Queue;
use BBSEO\PostTypes\Project;

class Dashboard
{
    public static function register(): void
    {
        add_menu_page(
            __('Blackbird SEO Dashboard', 'ai-seo-tool'),
            __('Blackbird SEO', 'ai-seo-tool'),
            'manage_options',
            'ai-seo-dashboard',
            [self::class, 'render'],
            'dashicons-chart-area',
            56
        );
    }

    public static function registerActions(): void
    {
        add_action('admin_post_bbseo_run_crawl', [self::class, 'handleManualRun']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'ai-seo-tool'));
        }

        $projects = self::getProjects();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Blackbird SEO Projects', 'ai-seo-tool'); ?></h1>
            <?php if (isset($_GET['bbseo_notice'])): $notice = sanitize_text_field($_GET['bbseo_notice']); ?>
                <?php if ($notice === 'run'): ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Crawl queued. Cron will begin shortly.', 'ai-seo-tool'); ?></p></div>
                <?php elseif ($notice === 'run_fail'): ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Unable to queue crawl. Ensure a primary URL or seed_urls are configured.', 'ai-seo-tool'); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$projects): ?>
                <p><?php esc_html_e('No Blackbird SEO projects found. Create one under Blackbird SEO Projects → Add New.', 'ai-seo-tool'); ?></p>
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
                                    <span class="description"><?php echo esc_html($summary['status']); ?></span><br />
                                    <span class="description">
                                        <?php
                                        $remaining = self::calculateRemainingRunTime($summary);
                                        if ($remaining !== null) {
                                            printf(
                                                _n('Approximately %d minute remaining', 'Approximately %d minutes remaining', $remaining, 'ai-seo-tool'),
                                                $remaining
                                            );
                                        } else {
                                            esc_html_e('No remaining items', 'ai-seo-tool');
                                        }
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <em><?php esc_html_e('No runs yet', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($summary['run_id']): ?>
                                    <?php printf(__('Todo: %d, Done: %d, Pages: %d, Images: %d, Errors: %d', 'ai-seo-tool'), $summary['queue_remaining'], $summary['queue_done'], $summary['pages'], $summary['images'], $summary['errors']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url(self::manualCrawlUrl($project['slug'])); ?>"><?php esc_html_e('Run Crawl Now', 'ai-seo-tool'); ?></a>
                                <a class="button" href="<?php echo esc_url(self::historyLink($project['slug'])); ?>"><?php esc_html_e('Crawl History', 'ai-seo-tool'); ?></a>
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

    // calculate remaining "run" time based on queue status, cron frequency, hits, etc.
    private static function calculateRemainingRunTime(array $summary): ?int
    {
        if ($summary['queue_remaining'] === 0) {
            return null;
        }

        $averagePerMinute = 30; // assume 30 pages per minute as a baseline
        $estimation = 0;
        // check the runs folder on the project to see if we can get a better estimate based on past runs
        $runs = new \DirectoryIterator(Storage::runDir($summary['project'], '*'));
        if ($runs) {
            foreach ($runs as $run) {
                if (!$run->isDir() || $run->isDot()) {
                    continue;
                }

                $meta_file = Storage::runDir($summary['project'], $run->getFilename()) . '/meta.json';

                if (!file_exists($meta_file)) {
                    continue;
                }

                $meta = json_decode(file_get_contents($meta_file), true);
                if (!isset($meta['summary'])) {
                    continue;
                }

                $pagesDone = $meta['summary']['pages'] ?? 0;
                $imagesDone = $meta['summary']['images'] ?? 0;
                $errorsDone = $meta['summary']['errors'] ?? 0;
                $totalItems = $pagesDone + $imagesDone + $errorsDone;
                $startedAt = isset($meta['started_at']) ? strtotime($meta['started_at']) : null;
                $completedAt = isset($meta['completed_at']) ? strtotime($meta['completed_at']) : null;

                if ($startedAt && $completedAt) {
                    $estimation = $completedAt - $startedAt;
                    break;
                } elseif ($totalItems > 0) {
                    $estimation = ($totalItems / $averagePerMinute) * 60;
                    break;
                } else {
                    continue;
                }
            }
        }

        if ($estimation === 0) {
            $estimation = ($summary['queue_done'] + $summary['queue_remaining']) / $averagePerMinute * 60;
        }

        $remainingMinutes = (int) ceil(($estimation / ($summary['queue_done'] + $summary['queue_remaining'])) * $summary['queue_remaining'] / 60);
        return $remainingMinutes;
    }

    private static function loadSummary(string $project): array
    {
        $runId = Storage::getLatestRun($project);
        if (!$runId) {
            return [
                'project' => $project,
                'run_id' => null,
                'status' => __('Pending', 'ai-seo-tool'),
                'queue_remaining' => 0,
                'queue_done' => 0,
                'pages' => 0,
                'images' => 0,
                'errors' => 0,
            ];
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
        $status = __('Processing', 'ai-seo-tool');
        if (empty($todos)) {
            $status = __('Completed', 'ai-seo-tool');
        }
        return [
            'project' => $project,
            'run_id' => $runId,
            'status' => $status,
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
            'images' => count($images),
            'errors' => count($errors),
        ];
    }

    private static function reportLink(string $slug, ?string $runId): string
    {
        $home = trailingslashit(home_url());
        $url = $home . 'report/' . $slug;
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
            'action' => 'bbseo_run_crawl',
            'project' => sanitize_title($slug),
        ], $url);
        return wp_nonce_url($url, 'bbseo_run_crawl_' . $slug);
    }

    public static function handleManualRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }

        $slug = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        check_admin_referer('bbseo_run_crawl_' . $slug);

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
        $base = \BBSEO\PostTypes\Project::getBaseUrl($slug);
        if ($base) { $urls[] = $base; }
        $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));

        if (empty($urls)) {
            wp_safe_redirect(add_query_arg('bbseo_notice', 'run_fail', admin_url('admin.php?page=ai-seo-dashboard')));
            exit;
        }

        $runId = RunId::new();
        Queue::init($slug, $urls, $runId);          // creates runs/{runId}/queue + meta.json
        Storage::setLatestRun($slug, $runId);

        wp_safe_redirect(add_query_arg('bbseo_notice', 'run', admin_url('admin.php?page=ai-seo-dashboard')));
        exit;
    }

    private static function readConfig(string $project): array
    {
        $path = Storage::projectDir($project) . '/config.json';
        return file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
}
