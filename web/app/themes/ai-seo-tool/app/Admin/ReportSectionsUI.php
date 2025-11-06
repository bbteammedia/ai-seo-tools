<?php
namespace AISEO\Admin;

use AISEO\PostTypes\Report;
use AISEO\Helpers\Sections;
use AISEO\Helpers\DataLoader;
use AISEO\AI\Gemini;

class ReportSectionsUI
{
    public static function boot(): void
    {
        add_action('edit_form_after_editor', [self::class, 'render']);
        add_action('save_post_' . Report::POST_TYPE, [self::class, 'save'], 10, 3);
        add_action('wp_ajax_aiseo_sections_generate', [self::class, 'generateAiForSection']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }

    public static function assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== Report::POST_TYPE) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
    }

    public static function render(\WP_Post $post): void
    {
        if ($post->post_type !== Report::POST_TYPE) {
            return;
        }

        wp_nonce_field('aiseo_sections_nonce', 'aiseo_sections_nonce');

        $type = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';
        $stored = get_post_meta($post->ID, Sections::META_SECTIONS, true) ?: '';
        $sections = json_decode($stored, true);

        if (!is_array($sections) || empty($sections)) {
            $sections = Sections::defaultsFor($type);
        }

        $registry = Sections::registry();
        $nonce = wp_create_nonce('aiseo_ai_sections_' . $post->ID);
        ?>
        <style>
            .aiseo-sections { margin-top: 16px; }
            .aiseo-sections .section { border:1px solid #dcdcdc; border-radius:6px; padding:12px; margin-bottom:10px; background:#fff; }
            .aiseo-sections .section .head { display:flex; align-items:center; justify-content:space-between; }
            .aiseo-sections .section .type { font-weight:600; display:flex; align-items:center; gap:6px; }
            .aiseo-sections .section .controls button { margin-left:6px; }
            .aiseo-sections .section textarea,
            .aiseo-sections .section input[type=text] { width:100%; }
            .aiseo-sections .add-row { margin:12px 0; display:flex; gap:8px; align-items:center; }
            .aiseo-sections .drag { cursor:move; color:#666; }
            .aiseo-sections .reco small { color:#666; display:block; margin-top:4px; }
        </style>
        <div class="postbox aiseo-sections">
            <h2 class="hndle"><span>Report Sections</span></h2>
            <div class="inside">
                <div id="aiseo-sections-list">
                    <?php foreach ($sections as $idx => $sec): ?>
                        <div class="section" data-id="<?php echo esc_attr($sec['id']); ?>">
                            <div class="head">
                                <div class="type">
                                    <span class="dashicons dashicons-move drag"></span>
                                    <?php echo esc_html($registry[$sec['type']]['label'] ?? ucfirst(str_replace('_', ' ', $sec['type']))); ?>
                                </div>
                                <div class="controls">
                                    <button type="button" class="button aiseo-ai-one" data-id="<?php echo esc_attr($sec['id']); ?>">AI</button>
                                    <button type="button" class="button button-link-delete aiseo-del" data-id="<?php echo esc_attr($sec['id']); ?>">Remove</button>
                                </div>
                            </div>
                            <div class="body">
                                <label>Custom Title</label>
                                <input type="text" name="aiseo_sections[<?php echo esc_attr($idx); ?>][title]" value="<?php echo esc_attr($sec['title']); ?>">
                                <input type="hidden" name="aiseo_sections[<?php echo esc_attr($idx); ?>][id]" value="<?php echo esc_attr($sec['id']); ?>">
                                <input type="hidden" name="aiseo_sections[<?php echo esc_attr($idx); ?>][type]" value="<?php echo esc_attr($sec['type']); ?>">
                                <textarea name="aiseo_sections[<?php echo esc_attr($idx); ?>][body]" rows="5" placeholder="Section narrative (editable)"><?php echo esc_textarea($sec['body'] ?? ''); ?></textarea>
                                <div class="reco">
                                    <label>Recommendations (one per line)</label>
                                    <textarea name="aiseo_sections[<?php echo esc_attr($idx); ?>][reco_raw]" rows="3"><?php echo esc_textarea(implode("\n", $sec['reco_list'] ?? [])); ?></textarea>
                                    <small>Suggestions can be prefilled by AI or edited manually.</small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="add-row">
                    <select id="aiseo-add-type">
                        <option value="">Add Section…</option>
                        <?php foreach ($registry as $k => $r): ?>
                            <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($r['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="aiseo-add-btn">Add</button>
                    <button type="button" class="button button-primary" id="aiseo-ai-all">Generate AI for All Sections</button>
                </div>
            </div>
        </div>
        <script>
        (function($){
            const $list = $('#aiseo-sections-list');

            function renumber(){
                $list.find('.section').each(function(idx){
                    $(this).find('input, textarea').each(function(){
                        const name = $(this).attr('name');
                        if (!name) return;
                        $(this).attr('name', name.replace(/aiseo_sections\[\d+]/, 'aiseo_sections[' + idx + ']'));
                    });
                });
            }

            $list.sortable({
                handle: '.drag',
                stop: renumber
            });

            $('#aiseo-add-btn').on('click', function(){
                const type = $('#aiseo-add-type').val();
                if (!type) {
                    return;
                }
                const label = $('#aiseo-add-type option:selected').text();
                const id = type + '_' + Math.random().toString(36).slice(2, 8);
                const idx = $list.find('.section').length;
                const template = `
                    <div class="section" data-id="${id}">
                        <div class="head">
                            <div class="type">
                                <span class="dashicons dashicons-move drag"></span>${label}
                            </div>
                            <div class="controls">
                                <button type="button" class="button aiseo-ai-one" data-id="${id}">AI</button>
                                <button type="button" class="button button-link-delete aiseo-del" data-id="${id}">Remove</button>
                            </div>
                        </div>
                        <div class="body">
                            <label>Custom Title</label>
                            <input type="text" name="aiseo_sections[${idx}][title]" value="${label}">
                            <input type="hidden" name="aiseo_sections[${idx}][id]" value="${id}">
                            <input type="hidden" name="aiseo_sections[${idx}][type]" value="${type}">
                            <textarea name="aiseo_sections[${idx}][body]" rows="5" placeholder="Section narrative (editable)"></textarea>
                            <div class="reco">
                                <label>Recommendations (one per line)</label>
                                <textarea name="aiseo_sections[${idx}][reco_raw]" rows="3"></textarea>
                                <small>Suggestions can be prefilled by AI or edited manually.</small>
                            </div>
                        </div>
                    </div>
                `;
                $list.append(template);
            });

            $(document).on('click', '.aiseo-del', function(){
                $(this).closest('.section').remove();
                renumber();
            });

            function aiForSection(sectionId){
                const form = document.forms['post'];
                if (!form) {
                    return;
                }
                const type = form.querySelector('[name="aiseo_report_type"]')?.value || 'general';
                const project = form.querySelector('[name="aiseo_project_slug"]')?.value || '';
                const page = form.querySelector('[name="aiseo_page"]')?.value || '';
                const runs = form.querySelector('[name="aiseo_runs"]')?.value || '[]';

                $.post(ajaxurl, {
                    action: 'aiseo_sections_generate',
                    post_id: <?php echo (int) $post->ID; ?>,
                    section_id: sectionId,
                    type: type,
                    project: project,
                    page: page,
                    runs: runs,
                    _wpnonce: '<?php echo esc_js($nonce); ?>'
                }).done(function(res){
                    if (!res || !res.success) {
                        return;
                    }
                    const wrap = $list.find('.section[data-id="' + sectionId + '"]');
                    wrap.find('textarea[name*="[body]"]').val(res.data.body || '');
                    wrap.find('textarea[name*="[reco_raw]"]').val((res.data.reco_list || []).join("\n"));
                });
            }

            $(document).on('click', '.aiseo-ai-one', function(){
                aiForSection($(this).data('id'));
            });

            $('#aiseo-ai-all').on('click', function(){
                $list.find('.section').each(function(){
                    aiForSection($(this).data('id'));
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function save(int $postId, \WP_Post $post, bool $update): void
    {
        if (!isset($_POST['aiseo_sections_nonce']) || !wp_verify_nonce($_POST['aiseo_sections_nonce'], 'aiseo_sections_nonce')) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!isset($_POST['aiseo_sections']) || !is_array($_POST['aiseo_sections'])) {
            update_post_meta($postId, Sections::META_SECTIONS, wp_json_encode([]));
            return;
        }

        $out = [];

        foreach ($_POST['aiseo_sections'] as $idx => $row) {
            $out[] = [
                'id' => sanitize_text_field($row['id'] ?? ''),
                'type' => sanitize_text_field($row['type'] ?? ''),
                'title' => sanitize_text_field($row['title'] ?? ''),
                'body' => wp_kses_post($row['body'] ?? ''),
                'reco_list' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($row['reco_raw'] ?? ''))))),
                'order' => (int) $idx * 10,
            ];
        }

        update_post_meta($postId, Sections::META_SECTIONS, wp_json_encode($out));
    }

    public static function generateAiForSection(): void
    {
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['msg' => 'permission_denied']);
        }

        check_ajax_referer('aiseo_ai_sections_' . $postId);

        $sectionId = sanitize_text_field($_POST['section_id'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'general');
        $project = sanitize_text_field($_POST['project'] ?? '');
        $page = esc_url_raw($_POST['page'] ?? '');
        $runs = json_decode(stripslashes($_POST['runs'] ?? '[]'), true);
        $runs = is_array($runs) ? array_map('sanitize_text_field', $runs) : [];

        $data = DataLoader::forReport($type, $project, $runs, $page);
        $result = Gemini::summarizeSection($type, $data, $sectionId);

        if (!is_array($result)) {
            wp_send_json_error(['msg' => 'ai_failed']);
        }

        wp_send_json_success($result);
    }
}
