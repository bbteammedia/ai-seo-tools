<?php
    $label = $registry[$typeKey]['label'] ?? ($section['title'] ?: ucfirst(str_replace('_', ' ', (string) $typeKey)));
    $editorId = 'bbseo_section_' . $index . '_body';
    $visible = (bool) ($section['visible'] ?? true);
    $metrics = is_array($section['metrics'] ?? null) ? $section['metrics'] : [];
    $hasMetricsContent = self::hasMetricContent($metrics);
    $recoList = is_array($section['reco_list'] ?? null) ? $section['reco_list'] : [];
    $metaList = is_array($section['meta_list'] ?? null) ? $section['meta_list'] : [];
    $metaJson = $metaList ? wp_json_encode($metaList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : "[]";
    $suppressMetrics = in_array($typeKey, ['executive_summary', 'top_actions', 'meta_recommendations', 'technical_findings'], true);
    $orderValue = self::sanitizeOrder((int) ($section['order'] ?? $index));
    $metricsJson = wp_json_encode($metrics);
    if (!is_string($metricsJson)) {
        $metricsJson = '[]';
    }
?>
<div class="section border border-slate-200 rounded-2xl bg-white p-4 shadow-sm transition-all duration-200" data-id="<?php echo esc_attr($section['id']); ?>" data-editor="<?php echo esc_attr($editorId); ?>">
    <div class="head flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div class="type flex items-center gap-2 text-lg font-semibold text-slate-900">
            <?php echo esc_html($label); ?>
        </div>
        <div class="controls flex flex-wrap items-center gap-3 text-sm text-slate-600">
            <label class="order flex items-center gap-2">
                <span class="text-[10px] uppercase tracking-[0.3em] text-slate-500">Order</span>
                <input
                    type="number"
                    min="0"
                    max="15"
                    step="1"
                    name="bbseo_sections[<?php echo esc_attr($index); ?>][order]"
                    value="<?php echo esc_attr($orderValue); ?>"
                    class="w-16 rounded-lg border border-slate-200 px-2 py-1 text-xs font-medium text-slate-700 focus:border-slate-400 focus:ring-2 focus:ring-slate-200 focus:outline-none"
                >
            </label>
            <label class="visibility flex items-center gap-2 text-xs font-medium text-slate-600">
                <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][visible]" value="0">
                <input type="checkbox" name="bbseo_sections[<?php echo esc_attr($index); ?>][visible]" value="1" <?php checked($visible); ?> class="h-4 w-4 rounded text-slate-900">
                Show section
            </label>
            <button
                type="button"
                class="button bbseo-ai-one inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.3em] text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200"
                data-id="<?php echo esc_attr($section['id']); ?>"
                title="Generate AI"
                aria-label="Generate AI"
            >
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-900 text-[11px] font-bold text-white">AI</span>
                <span class="screen-reader-text">Generate AI</span>
            </button>
        </div>
    </div>
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][metrics_json]" value="<?php echo esc_attr($metricsJson); ?>">

    <div class="editor mt-3">
        <?php
        wp_editor(
            $section['body'] ?? '',
            $editorId,
            [
                'textarea_name' => "bbseo_sections[{$index}][body]",
                'textarea_rows' => 8,
                'editor_height' => 180,
                'media_buttons' => false,
            ]
        );
        ?>
    </div>
    <div class="reco mt-3 flex flex-col gap-2 text-sm text-slate-600">
        <label class="text-sm font-medium text-slate-700"><strong>Additional Recommendations (one per line)</strong></label>
        <textarea name="bbseo_sections[<?php echo esc_attr($index); ?>][reco_raw]" rows="6" class="w-full min-h-[90px] rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:ring-2 focus:ring-slate-200 focus:outline-none"><?php echo esc_textarea(implode("\n", $recoList)); ?></textarea>
        <small class="text-xs italic text-slate-500">Additional Recommendations for AI context.</small>
    </div>
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($section['title']); ?>">
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($section['id']); ?>">
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][type]" value="<?php echo esc_attr($typeKey); ?>">
</div>
