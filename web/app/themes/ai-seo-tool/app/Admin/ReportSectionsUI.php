<?php
namespace BBSEO\Admin;

use BBSEO\PostTypes\Report;
use BBSEO\Helpers\Sections;
use BBSEO\Helpers\DataLoader;
use BBSEO\Helpers\ReportMetrics;
use BBSEO\AI\Gemini;

class ReportSectionsUI
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'registerMetaBox']);
        add_action('save_post_' . Report::POST_TYPE, [self::class, 'save'], 10, 3);
        add_action('wp_ajax_bbseo_sections_generate', [self::class, 'generateAiForSection']);
        add_action('wp_ajax_bbseo_refresh_sections', [self::class, 'refreshSectionsData']);
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
            'bbseo_report_sections',
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

        wp_nonce_field('bbseo_sections_nonce', 'bbseo_sections_nonce');

        $type = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($post->ID, Report::META_PROJECT, true) ?: '';
        $pageUrl = get_post_meta($post->ID, Report::META_PAGE, true) ?: '';
        $runsMeta = get_post_meta($post->ID, Report::META_RUNS, true) ?: '[]';
        $runs = json_decode($runsMeta, true);
        $runs = is_array($runs) ? $runs : [];

        $stored = get_post_meta($post->ID, Sections::META_SECTIONS, true);
        $sectionsRaw = maybe_unserialize($stored);
        if (!is_array($sectionsRaw)) {
            $sectionsRaw = [];
        }
        
        $sections = self::prepareSections($sectionsRaw, $type, $post);
        $registry = Sections::registry();
        $nonce = wp_create_nonce('bbseo_ai_sections_' . $post->ID);

        $hasData = false;
        foreach ($sections as $sectionCheck) {
            $metricsCheck = is_array($sectionCheck['metrics'] ?? null) ? $sectionCheck['metrics'] : [];
            if (!empty($metricsCheck['rows'])) {
                $hasData = true;
                break;
            }
        }

        ?>
        <div class="bbseo-sections mt-5 space-y-4">
            <?php if (!$hasData): ?>
                <p class="no-data italic text-sm text-slate-500">Connect a project and run a crawl to populate the metric tables. Sections remain editable while data is loading.</p>
            <?php endif; ?>
            <div class="toolbar flex justify-end">
                <button
                    type="button"
                    class=" inline-flex items-center gap-2 rounded-full border border-transparent bg-slate-900 px-4 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-white shadow-lg transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    id="bbseo-ai-all"
                    title="Generate AI for All Sections"
                    aria-label="Generate AI for All Sections"
                >
                    <span class="flex items-center justify-center rounded-full bg-white p-1 text-[10px] font-bold text-slate-900">
                        <svg viewBox="0 0 16 16" class="h-4 w-4 stroke-current" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 2l4 4-4 4h6l-4 4"/>
                        </svg>
                    </span>
                    <span class="screen-reader-text">Generate AI for All Sections</span>
                </button>
            </div>
            <div id="bbseo-sections-list" class="space-y-4">
                <?php foreach ($sections as $idx => $sec): ?>
                    <?php self::renderSectionMetaTemplate($sec['type'], $idx, $sec); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <input type="hidden" name="bbseo_sections_post_id" value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="bbseo_post_id" value="<?php echo (int) $post->ID; ?>" />
        <?php
    }

    private static function renderSectionMetaTemplate(string $typeKey, int $index, array $section): void
    {
        $template = locate_template("templates/sections-meta/{$typeKey}.php");
        if (!$template) {
            $template = locate_template('templates/sections-meta/default.php');
        }
        if (!$template) {
            return;
        }

        $sectionIndex = $index;
        $sectionData  = $section;
        $metaList     = is_array($section['meta_list'] ?? null) ? $section['meta_list'] : [];

        include $template;
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    private static function prepareSections(array $sections, string $type, \WP_Post $post): array
    {
        $registry = Sections::registry();
        $existingByType = [];
        foreach ($sections as $section) {

            if (!is_array($section) || empty($section['type'])) {
                continue;
            }
            $existingByType[$section['type']] = $section;
        }

        $legacy = self::legacyPayload($post);
        $prepared = [];
        $positionCounter = 0;

        foreach ($registry as $key => $def) {
            if (($def['legacy'] ?? false) || !in_array($type, $def['enabled_for'], true)) {
                continue;
            }

            $fallbackOrder = self::sanitizeOrder($positionCounter);
            ++$positionCounter;

            $section = $existingByType[$key] ?? [
                'id' => uniqid($key . '_'),
                'type' => $key,
                'title' => $def['label'],
                'body' => '',
                'reco_list' => [],
                'meta_list' => [],
                'order' => $fallbackOrder,
                'visible' => true,
            ];

            $prepared[] = self::hydrateLegacySection(
                self::normalizeSection($section, $def['label'], $fallbackOrder),
                $legacy
            );
            unset($existingByType[$key]);
        }

        foreach ($existingByType as $key => $section) {
            $label = $registry[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
            $fallbackOrder = self::sanitizeOrder((int) ($section['order'] ?? $positionCounter));
            ++$positionCounter;
            $prepared[] = self::hydrateLegacySection(
                self::normalizeSection($section, $label, $fallbackOrder),
                $legacy
            );
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
        $section['order'] = isset($section['order']) ? self::sanitizeOrder((int) $section['order']) : self::sanitizeOrder($fallbackOrder);
        $section['body'] = isset($section['body']) ? (string) $section['body'] : '';
        $section['reco_list'] = is_array($section['reco_list'] ?? null) ? $section['reco_list'] : [];
        $section['visible'] = array_key_exists('visible', $section) ? (bool) $section['visible'] : true;
        $section['meta_list'] = self::normalizeMetaList($section['meta_list'] ?? []);
        $section['metrics'] = is_array($section['metrics'] ?? null) ? $section['metrics'] : [];

        return $section;
    }

    /**
     * @return array{summary:string,actions:array<int,string>,meta:array<int,array<string,string>>,tech:string}
     */
    private static function legacyPayload(\WP_Post $post): array
    {
        $summary = get_post_meta($post->ID, Report::META_SUMMARY, true) ?: '';
        $actionsMeta = get_post_meta($post->ID, Report::META_ACTIONS, true) ?: '[]';
        $actions = json_decode($actionsMeta, true);
        $actions = is_array($actions) ? array_values(array_filter(array_map('trim', $actions))) : [];

        $metaMeta = get_post_meta($post->ID, Report::META_META_RECO, true) ?: '[]';
        $meta = json_decode($metaMeta, true);
        $meta = is_array($meta) ? self::normalizeMetaList($meta) : [];

        $tech = get_post_meta($post->ID, Report::META_TECH, true) ?: '';

        return [
            'summary' => (string) $summary,
            'actions' => $actions,
            'meta' => $meta,
            'tech' => (string) $tech,
        ];
    }

    /**
     * @param array<string,mixed> $section
     */
    private static function hydrateLegacySection(array $section, array $legacy): array
    {
        $type = $section['type'] ?? '';
        switch ($type) {
            case 'executive_summary':
                if (trim((string) ($section['body'] ?? '')) === '' && $legacy['summary'] !== '') {
                    $section['body'] = $legacy['summary'];
                }
                break;
            case 'top_actions':
                if (empty($section['reco_list']) && !empty($legacy['actions'])) {
                    $section['reco_list'] = $legacy['actions'];
                }
                break;
            case 'meta_recommendations':
                if (empty($section['meta_list']) && !empty($legacy['meta'])) {
                    $section['meta_list'] = $legacy['meta'];
                }
                break;
            case 'technical_findings':
                if (trim((string) ($section['body'] ?? '')) === '' && $legacy['tech'] !== '') {
                    $section['body'] = $legacy['tech'];
                }
                break;
        }

        return $section;
    }

    /**
     * @param mixed $items
     * @return array<int,array<string,string>>
     */
    private static function normalizeMetaList($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'url' => (string) ($item['url'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'meta_description' => (string) ($item['meta_description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function parseMetaJson(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::sanitizeMetaList($decoded);
    }

    /**
     * @param mixed $items
     * @return array<int,array<string,string>>
     */
    private static function sanitizeMetaList($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'url' => esc_url_raw($item['url'] ?? ''),
                'title' => sanitize_text_field($item['title'] ?? ''),
                'meta_description' => sanitize_text_field($item['meta_description'] ?? ''),
            ];
        }

        return $out;
    }

    private static function sanitizeOrder(int $order): int
    {
        if ($order < 0) {
            $order = 0;
        }
        if ($order > 15) {
            $order = 15;
        }
        return $order;
    }

    private static function hasMetricContent(array $metrics): bool
    {
        return !empty($metrics['rows']) || !empty($metrics['empty']) || !empty($metrics['note']);
    }

    /**
     * @param mixed $metrics
     * @return array<string,mixed>
     */
    private static function sanitizeMetrics($metrics): array
    {
        if (!is_array($metrics)) {
            return [
                'headers' => [],
                'rows' => [],
                'empty' => '',
                'note' => '',
            ];
        }

        $headers = [];
        if (isset($metrics['headers']) && is_array($metrics['headers'])) {
            foreach ($metrics['headers'] as $header) {
                if (is_scalar($header)) {
                    $headers[] = sanitize_text_field((string) $header);
                }
            }
        }

        $rows = [];
        if (isset($metrics['rows']) && is_array($metrics['rows'])) {
            foreach ($metrics['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cleanRow = [];
                foreach ($row as $key => $value) {
                    $cleanKey = is_scalar($key) ? sanitize_text_field((string) $key) : '';
                    if ($cleanKey === '') {
                        continue;
                    }
                    $cleanRow[$cleanKey] = is_scalar($value) ? sanitize_text_field((string) $value) : '';
                }
                if ($cleanRow) {
                    $rows[] = $cleanRow;
                }
            }
        }

        $emptyMessage = '';
        if (!empty($metrics['empty'])) {
            $emptyMessage = sanitize_text_field((string) $metrics['empty']);
        }

        $noteMessage = '';
        if (!empty($metrics['note'])) {
            $noteMessage = sanitize_text_field((string) $metrics['note']);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'empty' => $emptyMessage,
            'note' => $noteMessage,
        ];
    }

    /**
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    private static function sanitizeSectionForStorage(array $section): array
    {
        $order = self::sanitizeOrder((int) ($section['order'] ?? 0));
        $visible = !empty($section['visible']) ? 1 : 0;
        $recoList = is_array($section['reco_list'] ?? null) ? array_values(array_map('sanitize_text_field', $section['reco_list'])) : [];

        return [
            'id' => sanitize_text_field($section['id'] ?? ''),
            'type' => sanitize_text_field($section['type'] ?? ''),
            'title' => sanitize_text_field($section['title'] ?? ''),
            'body' => wp_kses_post($section['body'] ?? ''),
            'visible' => $visible,
            'reco_list' => $recoList,
            'meta_list' => self::sanitizeMetaList($section['meta_list'] ?? []),
            'metrics' => self::sanitizeMetrics($section['metrics'] ?? []),
            'order' => $order,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     */
    private static function storeSections(int $postId, array $sections): void
    {
        $sanitized = [];
        $summaryBody = '';
        $topActions = [];
        $metaRecommendations = [];
        $techBody = '';

        foreach ($sections as $section) {
            $clean = self::sanitizeSectionForStorage($section);
            $sanitized[] = $clean;
        }

        usort($sanitized, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        


        update_post_meta($postId, Sections::META_SECTIONS, $sanitized);

        $storedRaw      = get_post_meta($postId, Sections::META_SECTIONS, true) ?: '';
    }

    /**
     * @param array<string,mixed> $table
     */
    private static function renderMetricsTable(array $table): void
    {
        $headers = is_array($table['headers'] ?? null) ? array_values($table['headers']) : [];
        $rows = is_array($table['rows'] ?? null) ? $table['rows'] : [];
        $emptyMessage = trim((string) ($table['empty'] ?? ''));
        $note = trim((string) ($table['note'] ?? ''));

        if (!$headers && $rows) {
            $firstRow = reset($rows);
            if (is_array($firstRow)) {
                $headers = array_values(array_keys($firstRow));
            }
        }

        if (!$headers) {
            $headers = ['Metric', 'Value'];
        }

        $rowsForDisplay = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $rowsForDisplay[] = $row;
            }
        }

        ?>
        <div class="metrics-editor border border-dashed border-slate-300 rounded-2xl p-3 bg-slate-50 flex flex-col gap-3">
            <div class="metrics-editor__headers flex flex-col gap-2">
                <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">
                    <span class="metrics-editor__label">Columns</span>
                    <button
                        type="button"
                        class="metrics-editor__add-header inline-flex items-center justify-center rounded-full border border-slate-200 bg-white p-2 text-[11px] font-medium text-slate-700 shadow-sm no-underline focus:outline-none focus:ring-2 focus:ring-slate-200"
                        title="Add column"
                        aria-label="Add column"
                    >
                        <span class="dashicons dashicons-plus text-slate-700 text-base" aria-hidden="true"></span>
                        <span class="screen-reader-text">Add column</span>
                    </button>
                </div>
                <div class="metrics-editor__header-list flex flex-wrap gap-2">
                    <?php foreach ($headers as $header): ?>
                        <div class="metrics-editor__header flex items-center gap-1 border border-slate-200 rounded-full px-2 py-1 bg-white shadow-sm">
                            <input type="text" class="metrics-editor__header-input bg-transparent text-[12px] placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300" value="<?php echo esc_attr($header); ?>" placeholder="Column label">
                            <button type="button" class="metrics-editor__remove-header inline-flex items-center justify-center text-slate-400 hover:text-slate-600 focus:outline-non no-underline" aria-label="Remove column">
                                <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                <span class="sr-only">Remove column</span>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="metrics-editor__rows flex flex-col gap-2">
                <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">
                    <span class="metrics-editor__label">Rows</span>
                    <button
                        type="button"
                        class="metrics-editor__add-row inline-flex items-center justify-center rounded-full border border-slate-200 bg-white p-2 text-[11px] font-medium text-slate-700 shadow-sm no-underline focus:outline-none focus:ring-2 focus:ring-slate-200"
                        title="Add row"
                        aria-label="Add row"
                    >
                        <span class="dashicons dashicons-plus text-slate-700 text-base" aria-hidden="true"></span>
                        <span class="screen-reader-text">Add row</span>
                    </button>
                </div>
                <p class="metrics-editor__empty-state text-xs text-slate-500 italic" data-metrics-empty-state <?php if (!empty($rowsForDisplay)): ?>hidden<?php endif; ?>>
                    No rows yet. Add a row to record metrics.
                </p>
                <?php foreach ($rowsForDisplay as $row): ?>
                    <div class="metrics-editor__row flex flex-wrap items-start gap-2 border border-slate-200 rounded-2xl bg-white px-3 py-2 shadow-sm shadow-slate-50">
                        <?php foreach ($headers as $header): ?>
                            <div class="metrics-editor__cell flex-1 min-w-[150px]">
                                <input type="text" class="metrics-editor__cell-input block w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:ring-2 focus:ring-slate-200 focus:outline-none" value="<?php echo esc_attr($row[$header] ?? ''); ?>" placeholder="<?php echo esc_attr($header ?: 'Value'); ?>">
                            </div>
                        <?php endforeach; ?>
                        <button type="button" class="metrics-editor__remove-row inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 focus:outline-none">
                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            <span class="screen-reader-text">Remove row</span>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="metrics-editor__notes flex flex-col gap-3 text-sm text-slate-600">
                <label class="flex flex-col gap-1 text-[13px] font-medium text-slate-600">
                    <span class="font-semibold text-xs text-slate-500 uppercase tracking-[0.1em]">Empty state message</span>
                    <input type="text" class="metrics-empty-input border border-slate-200 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-slate-200 focus:outline-none" value="<?php echo esc_attr($emptyMessage); ?>" placeholder="Shown when rows are empty">
                </label>
                <label class="flex flex-col gap-1 text-[13px] font-medium text-slate-600">
                    <span class="font-semibold text-xs text-slate-500 uppercase tracking-[0.1em]">Note</span>
                    <textarea class="metrics-note-input border border-slate-200 rounded-md px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-slate-200 focus:outline-none" rows="2" placeholder="Optional context for this table"><?php echo esc_textarea($note); ?></textarea>
                </label>
            </div>
        </div>
        <?php
    }

    public static function save(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) return;
        if (!current_user_can('edit_post', $postId)) return;

        if (!isset($_POST['bbseo_sections']) || !is_array($_POST['bbseo_sections'])) {
            return;
        }

        $storedRaw = get_post_meta($postId, Sections::META_SECTIONS, true) ?: '';
        $sections = maybe_unserialize($storedRaw);
        if (!is_array($sections)) {
            $sections = [];
        }


        foreach ($_POST['bbseo_sections'] as $idx => $row) {

            $recoRaw = $row['reco_raw'] ?? '';
            if (is_array($recoRaw)) {
                $recoRaw = implode("\n", $recoRaw);
            }
            $recoList = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $recoRaw))));

            $metricsJsonRaw = isset($row['metrics_json']) ? (string) $row['metrics_json'] : '';
            $metricsDecoded = $metricsJsonRaw !== '' ? json_decode(stripslashes($metricsJsonRaw), true) : [];
            if (!is_array($metricsDecoded)) {
                $metricsDecoded = [];
            }

            $sectionId = $row['id'] ?? '';
            $foundIndex = null;
            foreach ($sections as $sIdx => $existingSection) {
                if (isset($existingSection['id']) && $existingSection['id'] === $sectionId) {
                    $foundIndex = $sIdx;
                    break;
                }
            }

            $sections[$foundIndex ?? $idx] = [
                'id' => $row['id'] ?? '',
                'type' => $row['type'] ?? '',
                'title' => $row['title'] ?? '',
                'body' => $row['body'] ?? '',
                'visible' => !empty($row['visible']),
                'reco_list' => $recoList,
                'meta_list' => [],
                'metrics' => $metricsDecoded,
                'order' => $row['order'] ?? $idx,
            ];

        }

        self::storeSections($postId, $sections);
    }

    /**
     * Refresh the sections data for a given report post.
     */
    public static function refreshSectionsData(): void
    {
        // --- Validate and authorize request ---
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;

        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['msg' => 'permission_denied']);
        }

        check_ajax_referer('bbseo_refresh_sections_' . $postId);

        // --- Sanitize input ---
        $type     = sanitize_text_field($_POST['type'] ?? 'general');
        $project  = sanitize_text_field($_POST['project'] ?? '');
        $page     = esc_url_raw($_POST['page'] ?? '');
        $runsRaw  = stripslashes($_POST['runs'] ?? '[]');
        $runsJson = json_decode($runsRaw, true);

        $runs = is_array($runsJson)
            ? array_values(array_map('sanitize_text_field', $runsJson))
            : [];

        if ($project === '') {
            wp_send_json_error(['msg' => 'Select a project before refreshing.']);
        }

        // --- Load data ---
        $data = DataLoader::forReport($type, $project, $runs, $page);

        if (!$data) {
            wp_send_json_error(['msg' => 'Data loader failed.']);
        }

        // --- Validate post existence ---
        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            wp_send_json_error(['msg' => 'Report not found.']);
        }

        // --- Retrieve and prepare sections ---
        $storedRaw      = get_post_meta($postId, Sections::META_SECTIONS, true) ?: '';
        $storedSections = maybe_unserialize($storedRaw);
        if (!is_array($storedSections)) {
            $storedSections = [];
        }

        $sections         = self::prepareSections($storedSections, $type, $post);
        $metricsBySection = ReportMetrics::build($type, $data);

        // --- Attach metrics to sections ---
        foreach ($sections as &$section) {
            $typeKey = $section['type'] ?? '';
            $section['metrics'] = $metricsBySection[$typeKey] ?? [];
        }
        unset($section); // Prevent reference leaks

        // --- Store updated sections and snapshot ---
        self::storeSections($postId, $sections);
        update_post_meta($postId, Report::META_SNAPSHOT, wp_json_encode($data));

        // --- Return success ---
        wp_send_json_success(['msg' => 'Sections refreshed.']);
    }

    public static function generateAiForSection(): void
    {
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['msg' => 'permission_denied']);
        }

        //check_ajax_referer('bbseo_ai_sections_' . $postId);

        $sectionId = sanitize_text_field($_POST['section_id'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'general');
        $project = sanitize_text_field($_POST['project'] ?? '');
        $page = esc_url_raw($_POST['page'] ?? '');
        $runs = json_decode(stripslashes($_POST['runs'] ?? '[]'), true);
        $runs = is_array($runs) ? array_map('sanitize_text_field', $runs) : [];

        $data = DataLoader::forReport($type, $project, $runs, $page);
        $metricsBySection = ReportMetrics::build($type, $data);
        $data['section_metrics'] = $metricsBySection;
        Gemini::log('Gemini section_metrics', [
            'section_metrics' => $metricsBySection,
        ]);
        $result = Gemini::summarizeSection($type, $data, $sectionId);

        if (!is_array($result)) {
            wp_send_json_error(['msg' => 'ai_failed']);
        }

        wp_send_json_success($result);
    }
}
