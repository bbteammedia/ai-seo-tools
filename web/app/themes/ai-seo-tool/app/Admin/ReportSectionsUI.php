<?php
namespace AISEO\Admin;

use AISEO\PostTypes\Report;
use AISEO\Helpers\Sections;
use AISEO\Helpers\DataLoader;
use AISEO\Helpers\ReportMetrics;
use AISEO\AI\Gemini;

class ReportSectionsUI
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'registerMetaBox']);
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

        if (function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }
    }

    public static function registerMetaBox(): void
    {
        add_meta_box(
            'aiseo_report_sections',
            'Report Sections',
            [self::class, 'render'],
            Report::POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render(\WP_Post $post): void
    {
        if ($post->post_type !== Report::POST_TYPE) {
            return;
        }

        wp_nonce_field('aiseo_sections_nonce', 'aiseo_sections_nonce');

        $type = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($post->ID, Report::META_PROJECT, true) ?: '';
        $pageUrl = get_post_meta($post->ID, Report::META_PAGE, true) ?: '';
        $runsMeta = get_post_meta($post->ID, Report::META_RUNS, true) ?: '[]';
        $runs = json_decode($runsMeta, true);
        $runs = is_array($runs) ? $runs : [];

        $stored = get_post_meta($post->ID, Sections::META_SECTIONS, true) ?: '';
        $sectionsRaw = json_decode($stored, true);
        $sectionsRaw = is_array($sectionsRaw) ? $sectionsRaw : [];

        $sections = self::prepareSections($sectionsRaw, $type);
        $registry = Sections::registry();
        $nonce = wp_create_nonce('aiseo_ai_sections_' . $post->ID);

        $snapshot = DataLoader::forReport($type, (string) $project, $runs, (string) $pageUrl);
        $metricsBySection = ReportMetrics::build($type, $snapshot);
        $hasData = !empty($snapshot['runs']);

        ?>
        <style>
            .aiseo-sections { margin-top: 16px; }
            .aiseo-sections .section { border:1px solid #dcdcdc; border-radius:6px; padding:16px; margin-bottom:12px; background:#fff; transition:opacity 0.2s; }
            .aiseo-sections .section.loading { opacity:0.65; }
            .aiseo-sections .section .head { display:flex; align-items:center; justify-content:space-between; gap:12px; }
            .aiseo-sections .section .type { font-weight:600; display:flex; align-items:center; gap:8px; }
            .aiseo-sections .section .controls { display:flex; align-items:center; gap:12px; }
            .aiseo-sections .section .controls label { display:flex; align-items:center; gap:6px; font-weight:500; }
            .aiseo-sections .section .metrics { margin-top:12px; }
            .aiseo-sections .section .metrics-table { width:100%; border-collapse:collapse; margin:0; font-size:13px; }
            .aiseo-sections .section .metrics-table th,
            .aiseo-sections .section .metrics-table td { padding:8px 10px; border-bottom:1px solid #e5e7eb; text-align:left; }
            .aiseo-sections .section .metrics-table th { background:#f8fafc; text-transform:uppercase; font-size:12px; letter-spacing:0.04em; color:#475569; }
            .aiseo-sections .section .metrics-empty { font-style:italic; color:#64748b; margin:8px 0 0; }
            .aiseo-sections .section .metrics-note { font-size:12px; color:#64748b; margin-top:6px; }
            .aiseo-sections .section .editor { margin-top:12px; }
            .aiseo-sections .section .reco { margin-top:12px; }
            .aiseo-sections .section .reco textarea { width:100%; min-height:90px; }
            .aiseo-sections .toolbar { display:flex; justify-content:flex-end; margin-bottom:12px; }
            .aiseo-sections .no-data { margin-bottom:12px; font-style:italic; color:#64748b; }
        </style>
        <div class="aiseo-sections">
            <?php if (!$hasData): ?>
                <p class="no-data">Connect a project and run a crawl to populate the metric tables. Sections remain editable while data is loading.</p>
            <?php endif; ?>
            <div class="toolbar">
                <button type="button" class="button button-secondary" id="aiseo-ai-all">Generate AI for All Sections</button>
            </div>
            <div id="aiseo-sections-list">
                <?php foreach ($sections as $idx => $sec): ?>
                    <?php
                        $label = $registry[$sec['type']]['label'] ?? ($sec['title'] ?: ucfirst(str_replace('_', ' ', (string) $sec['type'])));
                        $editorId = 'aiseo_section_' . $idx . '_body';
                        $visible = $sec['visible'] ?? true;
                        $metrics = $metricsBySection[$sec['type']] ?? [];
                        $hasMetricsContent = !empty($metrics['rows']) || !empty($metrics['empty']) || !empty($metrics['note']);
                        $recoList = is_array($sec['reco_list'] ?? null) ? $sec['reco_list'] : [];
                    ?>
                    <div class="section" data-id="<?php echo esc_attr($sec['id']); ?>" data-editor="<?php echo esc_attr($editorId); ?>">
                        <div class="head">
                            <div class="type">
                                <?php echo esc_html($label); ?>
                            </div>
                            <div class="controls">
                                <label>
                                    <input type="hidden" name="aiseo_sections[<?php echo esc_attr($idx); ?>][visible]" value="0">
                                    <input type="checkbox" name="aiseo_sections[<?php echo esc_attr($idx); ?>][visible]" value="1" <?php checked($visible); ?>>
                                    Show section
                                </label>
                                <button type="button" class="button aiseo-ai-one" data-id="<?php echo esc_attr($sec['id']); ?>">AI</button>
                            </div>
                        </div>
                        <?php if ($hasMetricsContent): ?>
                            <div class="metrics">
                                <?php self::renderMetricsTable($metrics); ?>
                            </div>
                        <?php endif; ?>
                        <div class="editor">
                            <?php
                            wp_editor(
                                $sec['body'] ?? '',
                                $editorId,
                                [
                                    'textarea_name' => "aiseo_sections[{$idx}][body]",
                                    'textarea_rows' => 8,
                                    'editor_height' => 180,
                                    'media_buttons' => false,
                                ]
                            );
                            ?>
                        </div>
                        <?php if ($sec['type'] === 'recommendations'): ?>
                            <div class="reco">
                                <label><strong>Recommended Actions (one per line)</strong></label>
                                <textarea name="aiseo_sections[<?php echo esc_attr($idx); ?>][reco_raw]" rows="4"><?php echo esc_textarea(implode("\n", $recoList)); ?></textarea>
                            </div>
                        <?php endif; ?>
                        <input type="hidden" name="aiseo_sections[<?php echo esc_attr($idx); ?>][title]" value="<?php echo esc_attr($sec['title']); ?>">
                        <input type="hidden" name="aiseo_sections[<?php echo esc_attr($idx); ?>][id]" value="<?php echo esc_attr($sec['id']); ?>">
                        <input type="hidden" name="aiseo_sections[<?php echo esc_attr($idx); ?>][type]" value="<?php echo esc_attr($sec['type']); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function($){
            const ajaxNonce = '<?php echo esc_js($nonce); ?>';
            const postId = <?php echo (int) $post->ID; ?>;

            function setEditorContent(editorId, content) {
                if (window.tinymce) {
                    const editor = window.tinymce.get(editorId);
                    if (editor) {
                        editor.setContent(content || '');
                    }
                }
                const textarea = document.getElementById(editorId);
                if (textarea) {
                    textarea.value = content || '';
                }
            }

            function aiForSection(sectionId) {
                const form = document.forms['post'];
                if (!form) {
                    return;
                }

                const $section = $('.aiseo-sections .section[data-id="' + sectionId + '"]');
                const editorId = $section.data('editor');

                const type = form.querySelector('[name="aiseo_report_type"]')?.value || 'general';
                const project = form.querySelector('[name="aiseo_project_slug"]')?.value || '';
                const page = form.querySelector('[name="aiseo_page"]')?.value || '';
                const runs = form.querySelector('[name="aiseo_runs"]')?.value || '[]';

                $section.addClass('loading');

                $.post(ajaxurl, {
                    action: 'aiseo_sections_generate',
                    post_id: postId,
                    section_id: sectionId,
                    type: type,
                    project: project,
                    page: page,
                    runs: runs,
                    _wpnonce: ajaxNonce
                }).done(function(res){
                    if (res && res.success) {
                        setEditorContent(editorId, res.data.body || '');
                        const reco = res.data.reco_list || [];
                        const $reco = $section.find('textarea[name*="[reco_raw]"]');
                        if ($reco.length) {
                            $reco.val(reco.join("\n"));
                        }
                    }
                }).always(function(){
                    $section.removeClass('loading');
                });
            }

            $(document).on('click', '.aiseo-ai-one', function(){
                aiForSection($(this).data('id'));
            });

            $('#aiseo-ai-all').on('click', function(){
                $('.aiseo-sections .section').each(function(){
                    aiForSection($(this).data('id'));
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    private static function prepareSections(array $sections, string $type): array
    {
        $registry = Sections::registry();
        $existingByType = [];

        foreach ($sections as $section) {
            if (!is_array($section) || empty($section['type'])) {
                continue;
            }
            $existingByType[$section['type']] = $section;
        }

        $prepared = [];

        foreach ($registry as $key => $def) {
            if (($def['legacy'] ?? false) || !in_array($type, $def['enabled_for'], true)) {
                continue;
            }

            $section = $existingByType[$key] ?? [
                'id' => uniqid($key . '_'),
                'type' => $key,
                'title' => $def['label'],
                'body' => '',
                'reco_list' => [],
                'order' => $def['order'] ?? 0,
                'visible' => true,
            ];

            $prepared[] = self::normalizeSection($section, $def['label'], (int) ($def['order'] ?? 0));
            unset($existingByType[$key]);
        }

        foreach ($existingByType as $key => $section) {
            $label = $registry[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
            $prepared[] = self::normalizeSection($section, $label, (int) ($section['order'] ?? 900));
        }

        usort($prepared, static function (array $a, array $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        return $prepared;
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private static function normalizeSection(array $section, string $label, int $fallbackOrder): array
    {
        $type = isset($section['type']) ? (string) $section['type'] : 'section';
        $section['id'] = isset($section['id']) && $section['id'] !== '' ? (string) $section['id'] : uniqid($type . '_');
        $section['title'] = isset($section['title']) && $section['title'] !== '' ? (string) $section['title'] : $label;
        $section['order'] = isset($section['order']) ? (int) $section['order'] : $fallbackOrder;
        $section['body'] = isset($section['body']) ? (string) $section['body'] : '';
        $section['reco_list'] = is_array($section['reco_list'] ?? null) ? $section['reco_list'] : [];
        $section['visible'] = array_key_exists('visible', $section) ? (bool) $section['visible'] : true;

        return $section;
    }

    /**
     * @param array<string,mixed> $table
     */
    private static function renderMetricsTable(array $table): void
    {
        $rows = $table['rows'] ?? [];
        $headers = $table['headers'] ?? [];

        if (!$headers && $rows) {
            $headers = array_keys(reset($rows));
        }

        if ($rows) {
            echo '<table class="metrics-table"><thead><tr>';
            foreach ($headers as $header) {
                echo '<th>' . esc_html((string) $header) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($headers as $header) {
                    $cell = $row[$header] ?? '';
                    echo '<td>' . esc_html((string) $cell) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!$rows && !empty($table['empty'])) {
            echo '<p class="metrics-empty">' . esc_html((string) $table['empty']) . '</p>';
        }

        if (!empty($table['note'])) {
            echo '<p class="metrics-note">' . esc_html((string) $table['note']) . '</p>';
        }
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
            $visible = !empty($row['visible']);
            $recoRaw = $row['reco_raw'] ?? '';
            if (is_array($recoRaw)) {
                $recoRaw = implode("\n", $recoRaw);
            }
            $out[] = [
                'id' => sanitize_text_field($row['id'] ?? ''),
                'type' => sanitize_text_field($row['type'] ?? ''),
                'title' => sanitize_text_field($row['title'] ?? ''),
                'body' => wp_kses_post($row['body'] ?? ''),
                'visible' => $visible ? 1 : 0,
                'reco_list' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $recoRaw)))),
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
