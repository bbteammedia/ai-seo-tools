<?php
namespace AISEO\Admin;

use AISEO\PostTypes\Report;
use AISEO\Helpers\Storage;

class ReportMetaBox
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('save_post_' . Report::POST_TYPE, [self::class, 'save'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function add(): void
    {
        add_meta_box(
            'aiseo_report_settings',
            'Report Settings',
            [self::class, 'render'],
            Report::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render(\WP_Post $post): void
    {
        wp_nonce_field('aiseo_report_nonce', 'aiseo_report_nonce');

        $type = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($post->ID, Report::META_PROJECT, true) ?: '';
        $page = get_post_meta($post->ID, Report::META_PAGE, true) ?: '';
        $runs = json_decode(get_post_meta($post->ID, Report::META_RUNS, true) ?: '[]', true) ?: [];

        $projects = [];
        $projectBase = Storage::baseDir();
        if (is_dir($projectBase)) {
            foreach (glob($projectBase . '/*', GLOB_ONLYDIR) as $dir) {
                $projects[] = basename($dir);
            }
        }
        ?>
        <style>
            .aiseo-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
            .aiseo-grid .full { grid-column:1 / -1; }
            .aiseo-grid textarea { min-height:120px; }
        </style>
        <div class="aiseo-grid">
            <div>
                <label><strong>Report Type</strong></label><br/>
                <select name="aiseo_report_type" id="aiseo_report_type">
                    <option value="general" <?php selected($type, 'general'); ?>>Website General SEO Audit</option>
                    <option value="per_page" <?php selected($type, 'per_page'); ?>>Website SEO Audit per Page</option>
                    <option value="technical" <?php selected($type, 'technical'); ?>>Technical SEO</option>
                </select>
            </div>
            <div>
                <label><strong>Project</strong></label><br/>
                <select name="aiseo_project_slug" id="aiseo_project_slug">
                    <option value="">— select —</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?php echo esc_attr($p); ?>" <?php selected($project, $p); ?>><?php echo esc_html($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="full" id="aiseo_per_page_row" style="<?php echo $type === 'per_page' ? '' : 'display:none'; ?>">
                <label><strong>Page URL</strong></label><br/>
                <input type="url" class="widefat" name="aiseo_page" value="<?php echo esc_attr($page); ?>" placeholder="https://example.com/page" />
                <p class="description">For per-page reports, specify the target page URL.</p>
            </div>

            <div class="full">
                <label><strong>Runs (for compare)</strong></label>
                <input type="text" class="widefat" name="aiseo_runs" value="<?php echo esc_attr(wp_json_encode($runs)); ?>" placeholder='["RUN_ID_A","RUN_ID_B"]' />
                <p class="description">Leave empty to use the latest run. For General/Technical you can add multiple run IDs to compare.</p>
            </div>
        </div>
        <script>
        (function($){
            function togglePage(){
                const type = $('#aiseo_report_type').val();
                if (type === 'per_page') {
                    $('#aiseo_per_page_row').show();
                } else {
                    $('#aiseo_per_page_row').hide();
                }
            }

            $('#aiseo_report_type').on('change', togglePage);
            togglePage();
        })(jQuery);
        </script>
        <?php
    }

    public static function assets(string $hook): void
    {
        // Placeholder for admin assets when needed.
    }

    public static function save(int $postId, \WP_Post $post, bool $update): void
    {
        if (!isset($_POST['aiseo_report_nonce']) || !wp_verify_nonce($_POST['aiseo_report_nonce'], 'aiseo_report_nonce')) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        update_post_meta($postId, Report::META_TYPE, sanitize_text_field($_POST['aiseo_report_type'] ?? 'general'));
        update_post_meta($postId, Report::META_PROJECT, sanitize_text_field($_POST['aiseo_project_slug'] ?? ''));
        update_post_meta($postId, Report::META_PAGE, esc_url_raw($_POST['aiseo_page'] ?? ''));

        $runs = json_decode(stripslashes($_POST['aiseo_runs'] ?? '[]'), true);
        $runs = is_array($runs) ? array_values(array_map('sanitize_text_field', $runs)) : [];
        update_post_meta($postId, Report::META_RUNS, wp_json_encode($runs));

    }
}
