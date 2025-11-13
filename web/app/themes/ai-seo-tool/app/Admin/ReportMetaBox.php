<?php
namespace BBSEO\Admin;

use BBSEO\PostTypes\Report;
use BBSEO\Helpers\Storage;

class ReportMetaBox
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('save_post_' . Report::POST_TYPE, [self::class, 'save'], 10, 3);
        add_action('wp_ajax_bbseo_project_runs', [self::class, 'ajaxProjectRuns']);
    }

    public static function add(): void
    {
        add_meta_box(
            'bbseo_report_settings',
            'Report Settings',
            [self::class, 'render'],
            Report::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render(\WP_Post $post): void
    {
        wp_nonce_field('bbseo_report_nonce', 'bbseo_report_nonce');

        $type = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($post->ID, Report::META_PROJECT, true) ?: '';
        $page = get_post_meta($post->ID, Report::META_PAGE, true) ?: '';
        $runs = json_decode(get_post_meta($post->ID, Report::META_RUNS, true) ?: '[]', true) ?: [];
        $isPrivate = get_post_meta($post->ID, Report::META_PRIVATE, true) === '1';

        $projects = [];
        $projectBase = Storage::baseDir();
        if (is_dir($projectBase)) {
            foreach (glob($projectBase . '/*', GLOB_ONLYDIR) as $dir) {
                $projects[] = basename($dir);
            }
        }
        ?>
        <div class="bbseo-grid">
            <div>
                <label><strong>Report Type</strong></label><br/>
                <select name="bbseo_report_type" id="bbseo_report_type">
                    <option value="general" <?php selected($type, 'general'); ?>>Website General SEO Audit</option>
                    <option value="per_page" <?php selected($type, 'per_page'); ?>>Website SEO Audit per Page</option>
                    <option value="technical" <?php selected($type, 'technical'); ?>>Technical SEO</option>
                </select>
            </div>
            <div>
                <label><strong>Project</strong></label><br/>
                <select name="bbseo_project_slug" id="bbseo_project_slug">
                    <option value="">- select -</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?php echo esc_attr($p); ?>" <?php selected($project, $p); ?>><?php echo esc_html($p); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

            <div class="full" id="bbseo_per_page_row" style="<?php echo $type === 'per_page' ? '' : 'display:none'; ?>">
                <label><strong>Page URL</strong></label><br/>
                <input type="url" class="widefat" name="bbseo_page" value="<?php echo esc_attr($page); ?>" placeholder="https://example.com/page" />
                <p class="description">For per-page reports, specify the target page URL.</p>
            </div>

            <div class="full">
                <label><strong>Runs (for compare)</strong></label>
                <select name="bbseo_runs[]" id="bbseo_runs" class="widefat" multiple size="5" data-initial-runs="<?php echo esc_attr(wp_json_encode($runs)); ?>">
                    <?php if (!$project): ?>
                        <option disabled value="">Select a project first…</option>
                    <?php else: ?>
                        <?php $projectRuns = self::projectRuns($project); ?>
                        <?php if (empty($projectRuns)): ?>
                            <option disabled value="">No runs found for this project</option>
                        <?php endif; ?>
                        <?php foreach ($projectRuns as $runInfo): ?>
                            <option
                                value="<?php echo esc_attr($runInfo['run']); ?>"
                                <?php echo in_array($runInfo['run'], $runs, true) ? 'selected' : ''; ?>
                            >
                                <?php echo esc_html($runInfo['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <p class="description">Leave empty to use the latest run; select one or more entries to compare. Hold Ctrl/Cmd to multiselect.</p>
                <button type="button" class="button button-secondary" id="bbseo-refresh-data">Refresh Data</button>
                <p class="description" id="bbseo-refresh-status">Pulls metrics from storage based on the selected project, runs, and report type.</p>
            </div>
            <div class="full">
                <label><strong>Make Report Private</strong></label><br/>
                <label class="description">
                    <input type="checkbox" name="bbseo_private" value="1" <?php checked($isPrivate); ?>>
                    Require a password to view the report
                </label>
                <input
                    type="password"
                    name="bbseo_report_password"
                    class="widefat"
                    placeholder="Enter password to protect this report"
                />
                <p class="description">Keep this empty to preserve the existing password when toggling privacy.</p>
            </div>
            <input type="hidden" id="bbseo_project_runs_nonce" value="<?php echo esc_attr(wp_create_nonce('bbseo_project_runs')); ?>" />
        </div>
        <input type="hidden" name="bbseo_refresh_sections_nonce" value="<?php echo esc_attr(wp_create_nonce('bbseo_refresh_sections_' . $post->ID)); ?>" />
        <input type="hidden" name="bbseo_post_id" value="<?php echo (int) $post->ID; ?>" />
        <?php
    }

    public static function save(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) return;
        if (!current_user_can('edit_post', $postId)) return;

        update_post_meta($postId, Report::META_TYPE, sanitize_text_field($_POST['bbseo_report_type'] ?? 'general'));
        update_post_meta($postId, Report::META_PROJECT, sanitize_text_field($_POST['bbseo_project_slug'] ?? ''));
        update_post_meta($postId, Report::META_PAGE, esc_url_raw($_POST['bbseo_page'] ?? ''));

        $runsRaw = $_POST['bbseo_runs'] ?? [];
        if (!is_array($runsRaw)) {
            $runsRaw = json_decode(stripslashes($runsRaw), true);
        }
        $runs = is_array($runsRaw)
            ? array_values(array_filter(array_map('sanitize_text_field', $runsRaw)))
            : [];
        update_post_meta($postId, Report::META_RUNS, wp_json_encode($runs));

        $isPrivate = !empty($_POST['bbseo_private']) ? '1' : '0';
        update_post_meta($postId, Report::META_PRIVATE, $isPrivate);

        $passwordInput = sanitize_text_field($_POST['bbseo_report_password'] ?? '');
        $passwordHashKey = Report::META_PASSWORD_HASH;
        $existingHash = get_post_meta($postId, $passwordHashKey, true) ?: '';

        if ($isPrivate === '1') {
            if ($passwordInput !== '') {
                update_post_meta($postId, $passwordHashKey, wp_hash_password($passwordInput));
            } elseif ($existingHash === '') {
                update_post_meta($postId, $passwordHashKey, wp_hash_password(wp_generate_password()));
            }
        } else {
            delete_post_meta($postId, $passwordHashKey);
        }

    }

    public static function ajaxProjectRuns(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['msg' => 'permission_denied']);
        }

        check_ajax_referer('bbseo_project_runs', '_wpnonce');

        $project = sanitize_text_field($_POST['project'] ?? '');
        if ($project === '') {
            wp_send_json_error(['msg' => 'Select a project before loading runs.']);
        }

        $runs = self::projectRuns($project);
        wp_send_json_success($runs);
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function projectRuns(string $project): array
    {
        $slug = sanitize_title($project);
        if ($slug === '') {
            return [];
        }

        $dir = Storage::runsDir($slug);
        if (!is_dir($dir)) {
            return [];
        }

        $list = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $runPath) {
            $runId = basename($runPath);
            $meta = self::readJson($runPath . '/meta.json');
            $started = $meta['started_at'] ?? gmdate('c', filemtime($runPath));
            $list[] = [
                'run' => $runId,
                'started' => (string) $started,
                'label' => sprintf('%s · %s', $runId, date('Y-m-d H:i', strtotime($started))),
            ];
        }

        usort($list, static fn ($a, $b) => strcmp($b['started'] ?? '', $a['started'] ?? ''));

        return $list;
    }

    private static function readJson(string $path)
    {
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }
}
