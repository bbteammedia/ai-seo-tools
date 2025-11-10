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
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
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

        $projects = [];
        $projectBase = Storage::baseDir();
        if (is_dir($projectBase)) {
            foreach (glob($projectBase . '/*', GLOB_ONLYDIR) as $dir) {
                $projects[] = basename($dir);
            }
        }
        ?>
        <style>
            .bbseo-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
            .bbseo-grid .full { grid-column:1 / -1; }
            .bbseo-grid textarea { min-height:120px; }
        </style>
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
                <input type="text" class="widefat" name="bbseo_runs" value="<?php echo esc_attr(wp_json_encode($runs)); ?>" placeholder='["RUN_ID_A","RUN_ID_B"]' />
                <p class="description">Leave empty to use the latest run. For General/Technical you can add multiple run IDs to compare.</p>
                <button type="button" class="button button-secondary" id="bbseo-refresh-data">Refresh Data</button>
                <p class="description" id="bbseo-refresh-status">Pulls metrics from storage based on the selected project, runs, and report type.</p>
            </div>
        </div>
        <script>
        (function($){
            const refreshState = {
                nonce: '<?php echo esc_js(wp_create_nonce('bbseo_refresh_sections_' . $post->ID)); ?>',
                postId: <?php echo (int) $post->ID; ?>,
            };

            function togglePage(){
                const type = $('#BBSEO_report_type').val();
                if (type === 'per_page') {
                    $('#BBSEO_per_page_row').show();
                } else {
                    $('#BBSEO_per_page_row').hide();
                }
            }

            $('#BBSEO_report_type').on('change', togglePage);
            togglePage();

            const $refreshButton = $('#bbseo-refresh-data');
            const $status = $('#bbseo-refresh-status');

            function setStatus(message, isError = false){
                if (!$status.length) {
                    return;
                }
                $status.text(message);
                $status.css('color', isError ? '#d63638' : '#646970');
            }

            function resolveEditorForm() {
                return (
                    document.getElementById('post') ||
                    document.querySelector('form#post') ||
                    document.querySelector('form[name="post"]') ||
                    document.querySelector('form.editor-post-form')
                );
            }

            if ($refreshButton.length) {
                $refreshButton.on('click', function(e){
                    e.preventDefault();
                    const form = resolveEditorForm();
                    if (!form) {
                        setStatus('Editor form missing. Reload the page and try again.', true);
                        return;
                    }
                    const type = form.querySelector('[name="bbseo_report_type"]')?.value || 'general';
                    const project = form.querySelector('[name="bbseo_project_slug"]')?.value || '';
                    const page = form.querySelector('[name="bbseo_page"]')?.value || '';
                    const runs = form.querySelector('[name="bbseo_runs"]')?.value || '[]';

                    if (!project) {
                        setStatus('Select a project before refreshing data.', true);
                        return;
                    }

                    setStatus('Refreshing data… this may take a moment.');
                    $refreshButton.prop('disabled', true).addClass('updating-message');

                    $.post(ajaxurl, {
                        action: 'bbseo_refresh_sections',
                        post_id: refreshState.postId,
                        _wpnonce: refreshState.nonce,
                        type: type,
                        project: project,
                        page: page,
                        runs: runs
                    }).done(function(res){
                        if (res && res.success) {
                            setStatus('Data refreshed. Reloading…');
                            setTimeout(function(){ window.location.reload(); }, 600);
                        } else if (res && res.data && res.data.msg) {
                            setStatus(res.data.msg, true);
                        } else {
                            setStatus('Refresh failed. Please try again.', true);
                        }
                    }).fail(function(xhr){
                        const message = xhr?.responseJSON?.data?.msg || ('AJAX ' + xhr.status);
                        setStatus(message, true);
                    }).always(function(){
                        $refreshButton.prop('disabled', false).removeClass('updating-message');
                    });
                });
            }
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
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) return;
        if (!current_user_can('edit_post', $postId)) return;

        update_post_meta($postId, Report::META_TYPE, sanitize_text_field($_POST['bbseo_report_type'] ?? 'general'));
        update_post_meta($postId, Report::META_PROJECT, sanitize_text_field($_POST['bbseo_project_slug'] ?? ''));
        update_post_meta($postId, Report::META_PAGE, esc_url_raw($_POST['bbseo_page'] ?? ''));

        $runs = json_decode(stripslashes($_POST['bbseo_runs'] ?? '[]'), true);
        $runs = is_array($runs) ? array_values(array_map('sanitize_text_field', $runs)) : [];
        update_post_meta($postId, Report::META_RUNS, wp_json_encode($runs));

    }
}
