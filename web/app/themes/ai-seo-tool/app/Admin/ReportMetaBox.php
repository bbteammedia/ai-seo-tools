<?php
namespace AISEO\Admin;

use AISEO\PostTypes\Report;
use AISEO\Helpers\Storage;
use AISEO\Helpers\DataLoader;
use AISEO\AI\Gemini;

class ReportMetaBox
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('save_post_' . Report::POST_TYPE, [self::class, 'save'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_aiseo_generate_summary', [self::class, 'generateSummaryAjax']);
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

        $summaryVisibleValue = get_post_meta($post->ID, Report::META_SUMMARY_VISIBLE, true);
        $summaryVisible = $summaryVisibleValue === '' ? '1' : $summaryVisibleValue;
        $summary = get_post_meta($post->ID, Report::META_SUMMARY, true) ?: '';
        $actions = json_decode(get_post_meta($post->ID, Report::META_ACTIONS, true) ?: '[]', true) ?: [];
        $metaRec = json_decode(get_post_meta($post->ID, Report::META_META_RECO, true) ?: '[]', true) ?: [];
        $tech = get_post_meta($post->ID, Report::META_TECH, true) ?: '';
        $actionsJson = wp_json_encode($actions, JSON_PRETTY_PRINT) ?: '[]';
        $metaRecJson = wp_json_encode($metaRec, JSON_PRETTY_PRINT) ?: '[]';

        $projects = [];
        $projectBase = Storage::baseDir();
        if (is_dir($projectBase)) {
            foreach (glob($projectBase . '/*', GLOB_ONLYDIR) as $dir) {
                $projects[] = basename($dir);
            }
        }

        $nonce = wp_create_nonce('aiseo_ai_nonce_' . $post->ID);
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

            <div class="full">
                <button type="button" class="button button-primary" id="aiseo-generate-ai">Generate AI Summary</button>
                <span id="aiseo-ai-status" style="margin-left:8px;"></span>
            </div>

            <div class="full">
                <label>
                    <input type="checkbox" name="aiseo_summary_visible" value="1" <?php checked($summaryVisible, '1'); ?>>
                    <strong>Show Executive Summary in report</strong>
                </label>
            </div>

            <div class="full">
                <label><strong>Executive Summary</strong></label>
                <textarea class="widefat" name="aiseo_summary" rows="6"><?php echo esc_textarea($summary); ?></textarea>
            </div>

            <div class="full">
                <label><strong>Top Actions (JSON array of strings)</strong></label>
                <textarea class="widefat" name="aiseo_top_actions" rows="4"><?php echo esc_textarea($actionsJson); ?></textarea>
            </div>

            <div class="full">
                <label><strong>Meta Recommendations (JSON array of objects)</strong></label>
                <textarea class="widefat" name="aiseo_meta_recos" rows="4"><?php echo esc_textarea($metaRecJson); ?></textarea>
                <p class="description">Example: [{"url":"...","title":"...","meta_description":"..."}]</p>
            </div>

            <div class="full">
                <label><strong>Technical Findings</strong></label>
                <textarea class="widefat" name="aiseo_tech_findings" rows="4"><?php echo esc_textarea($tech); ?></textarea>
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

            $('#aiseo-generate-ai').on('click', function(e){
                e.preventDefault();
                const $status = $('#aiseo-ai-status');
                $status.text('Generating…');
                $.post(ajaxurl, {
                    action: 'aiseo_generate_summary',
                    post_id: <?php echo (int) $post->ID; ?>,
                    _wpnonce: '<?php echo esc_js($nonce); ?>'
                }).done(function(res){
                    if (!res || !res.success) {
                        $status.text('Failed.');
                        return;
                    }
                    $('textarea[name="aiseo_summary"]').val(res.data.summary || '');
                    $('textarea[name="aiseo_top_actions"]').val(JSON.stringify(res.data.actions || [], null, 2));
                    $('textarea[name="aiseo_meta_recos"]').val(JSON.stringify(res.data.meta_rec || [], null, 2));
                    $('textarea[name="aiseo_tech_findings"]').val(res.data.tech || '');
                    $status.text('Done.');
                }).fail(function(){
                    $status.text('Failed.');
                });
            });
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

        update_post_meta($postId, Report::META_SUMMARY_VISIBLE, isset($_POST['aiseo_summary_visible']) ? '1' : '0');
        update_post_meta($postId, Report::META_SUMMARY, wp_kses_post($_POST['aiseo_summary'] ?? ''));

        $actions = json_decode(stripslashes($_POST['aiseo_top_actions'] ?? '[]'), true);
        $actions = is_array($actions) ? array_values(array_map('sanitize_text_field', $actions)) : [];
        update_post_meta($postId, Report::META_ACTIONS, wp_json_encode($actions));

        $metaRec = json_decode(stripslashes($_POST['aiseo_meta_recos'] ?? '[]'), true);
        if (!is_array($metaRec)) {
            $metaRec = [];
        } else {
            $metaRec = array_map(static function ($item) {
                if (!is_array($item)) {
                    return [];
                }
                return [
                    'url' => esc_url_raw($item['url'] ?? ''),
                    'title' => sanitize_text_field($item['title'] ?? ''),
                    'meta_description' => sanitize_text_field($item['meta_description'] ?? ''),
                ];
            }, $metaRec);
        }
        update_post_meta($postId, Report::META_META_RECO, wp_json_encode($metaRec));

        update_post_meta($postId, Report::META_TECH, wp_kses_post($_POST['aiseo_tech_findings'] ?? ''));
    }

    public static function generateSummaryAjax(): void
    {
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['msg' => 'permission_denied']);
        }

        check_ajax_referer('aiseo_ai_nonce_' . $postId);

        $type = get_post_meta($postId, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($postId, Report::META_PROJECT, true) ?: '';
        $page = get_post_meta($postId, Report::META_PAGE, true) ?: '';
        $runs = json_decode(get_post_meta($postId, Report::META_RUNS, true) ?: '[]', true) ?: [];

        $data = DataLoader::forReport($type, $project, $runs, $page);
        $resp = Gemini::summarizeReport($type, $data);

        update_post_meta($postId, Report::META_SUMMARY, $resp['summary'] ?? '');
        update_post_meta($postId, Report::META_ACTIONS, wp_json_encode($resp['actions'] ?? []));
        update_post_meta($postId, Report::META_META_RECO, wp_json_encode($resp['meta_rec'] ?? []));
        update_post_meta($postId, Report::META_TECH, $resp['tech'] ?? '');
        update_post_meta($postId, Report::META_SNAPSHOT, wp_json_encode($data));

        wp_send_json_success($resp);
    }
}
