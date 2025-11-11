<?php
    $label = $registry[$typeKey]['label'] ?? ($section['title'] ?: ucfirst(str_replace('_', ' ', (string) $typeKey)));
    $editorId = 'bbseo_section_' . $index . '_body';
    $visible = (bool) ($section['visible'] ?? true);
    $metrics = is_array($section['metrics'] ?? null) ? $section['metrics'] : [];
    $hasMetricsContent = self::hasMetricContent($metrics);
    $recoList = is_array($section['reco_list'] ?? null) ? $section['reco_list'] : [];
    $metaList = is_array($section['meta_list'] ?? null) ? $section['meta_list'] : [];
    $metaJson = $metaList ? wp_json_encode($metaList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : "[]";
    $suppressMetrics = false;
    $orderValue = self::sanitizeOrder((int) ($section['order'] ?? $index));
    $metricsJson = wp_json_encode($metrics);
    if (!is_string($metricsJson)) {
        $metricsJson = '[]';
    }
?>
<div class="section" data-id="<?php echo esc_attr($section['id']); ?>" data-editor="<?php echo esc_attr($editorId); ?>">
    <div class="head">
        <div class="type">
            <?php echo esc_html($label); ?>
        </div>
        <div class="controls">
            <label class="order">
                <span>Order</span>
                <input type="number" min="0" max="15" step="1" name="bbseo_sections[<?php echo esc_attr($index); ?>][order]" value="<?php echo esc_attr($orderValue); ?>">
            </label>
            <label class="visibility">
                <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][visible]" value="0">
                <input type="checkbox" name="bbseo_sections[<?php echo esc_attr($index); ?>][visible]" value="1" <?php checked($visible); ?>>
                Show section
            </label>
            <button type="button" class="button bbseo-ai-one" data-id="<?php echo esc_attr($section['id']); ?>">AI</button>
        </div>
    </div>
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][metrics_json]" value="<?php echo esc_attr($metricsJson); ?>">
    <?php if ($hasMetricsContent): ?>
        <div class="metrics">
            <?php self::renderMetricsTable($metrics); ?>
        </div>
    <?php endif; ?>
    <div class="editor">
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
    <div class="reco">
        <label><strong>Additional Recommendations (one per line)</strong></label>
        <textarea name="bbseo_sections[<?php echo esc_attr($index); ?>][reco_raw]" rows="6"><?php echo esc_textarea(implode("\n", $recoList)); ?></textarea>
        <small>Additional Recommendations for AI context.</small>
    </div>
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($section['title']); ?>">
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($section['id']); ?>">
    <input type="hidden" name="bbseo_sections[<?php echo esc_attr($index); ?>][type]" value="<?php echo esc_attr($typeKey); ?>">
</div>
