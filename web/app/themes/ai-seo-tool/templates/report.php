<?php
use AISEO\PostTypes\Report as ReportPostType;
use AISEO\Helpers\Sections;

/** @var \WP_Post|null $report */
$report = $GLOBALS['aiseo_report_post'] ?? null;
if (!$report instanceof \WP_Post) {
    status_header(404);
    echo '<h1>Report not found</h1>';
    return;
}

$type = get_post_meta($report->ID, ReportPostType::META_TYPE, true) ?: 'general';
$typeLabel = [
    'general' => 'Website General SEO Audit',
    'per_page' => 'Website SEO Audit per Page',
    'technical' => 'Technical SEO',
][$type] ?? ucfirst($type);

$project = get_post_meta($report->ID, ReportPostType::META_PROJECT, true) ?: '';
$pageUrl = get_post_meta($report->ID, ReportPostType::META_PAGE, true) ?: '';
$runsMeta = get_post_meta($report->ID, ReportPostType::META_RUNS, true) ?: '[]';
$runs = json_decode($runsMeta, true) ?: [];
$legacySummary = get_post_meta($report->ID, ReportPostType::META_SUMMARY, true) ?: '';
$legacyActionsMeta = get_post_meta($report->ID, ReportPostType::META_ACTIONS, true) ?: '[]';
$legacyActions = json_decode($legacyActionsMeta, true);
$legacyActions = is_array($legacyActions) ? array_filter(array_map('trim', $legacyActions)) : [];
$legacyMetaRecMeta = get_post_meta($report->ID, ReportPostType::META_META_RECO, true) ?: '[]';
$legacyMetaRec = json_decode($legacyMetaRecMeta, true);
$legacyMetaRec = is_array($legacyMetaRec) ? $legacyMetaRec : [];
$legacyTechNotes = get_post_meta($report->ID, ReportPostType::META_TECH, true) ?: '';

$sectionsRaw = get_post_meta($report->ID, Sections::META_SECTIONS, true) ?: '[]';
$sections = json_decode($sectionsRaw, true);
$sections = is_array($sections) ? array_values(array_filter(array_map(static function ($section) {
    if (!is_array($section)) {
        return null;
    }
    if (!array_key_exists('visible', $section)) {
        $section['visible'] = 1;
    } else {
        $section['visible'] = (int) $section['visible'];
    }
    return $section;
}, $sections))) : [];
usort($sections, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

$sectionsByType = [];
foreach ($sections as $sectionItem) {
    if (!is_array($sectionItem)) {
        continue;
    }
    $sectionType = $sectionItem['type'] ?? '';
    if ($sectionType === '') {
        continue;
    }
    $sectionsByType[$sectionType] = $sectionItem;
}

$execSection = $sectionsByType['executive_summary'] ?? null;
$summaryBody = is_array($execSection) ? (string) ($execSection['body'] ?? '') : '';
if (trim($summaryBody) === '' && $legacySummary !== '') {
    $summaryBody = $legacySummary;
}
$summaryVisibleFlag = is_array($execSection) ? ((int) ($execSection['visible'] ?? 1) === 1) : (trim($summaryBody) !== '');
$showSummary = $summaryVisibleFlag && trim($summaryBody) !== '';

$actionsSection = $sectionsByType['top_actions'] ?? null;
$actions = is_array($actionsSection)
    ? array_values(array_filter(array_map('trim', (array) ($actionsSection['reco_list'] ?? []))))
    : [];
if (!$actions && $legacyActions) {
    $actions = $legacyActions;
}
$actionsVisibleFlag = is_array($actionsSection) ? ((int) ($actionsSection['visible'] ?? 1) === 1) : (!empty($actions));
$showActions = $actionsVisibleFlag && !empty($actions);

$metaSection = $sectionsByType['meta_recommendations'] ?? null;
$metaRec = is_array($metaSection) ? (array) ($metaSection['meta_list'] ?? []) : [];
if (!$metaRec && $legacyMetaRec) {
    $metaRec = $legacyMetaRec;
}
$metaVisibleFlag = is_array($metaSection) ? ((int) ($metaSection['visible'] ?? 1) === 1) : (!empty($metaRec));
$metaRec = array_map(static function ($row) {
    if (!is_array($row)) {
        return [
            'url' => '',
            'title' => (string) $row,
            'meta_description' => '',
        ];
    }
    return [
        'url' => (string) ($row['url'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'meta_description' => (string) ($row['meta_description'] ?? ''),
    ];
}, $metaRec);
$showMetaRec = $metaVisibleFlag && !empty($metaRec);

$techSection = $sectionsByType['technical_findings'] ?? null;
$techNotes = is_array($techSection) ? (string) ($techSection['body'] ?? '') : '';
if (trim($techNotes) === '' && $legacyTechNotes !== '') {
    $techNotes = $legacyTechNotes;
}
$techVisibleFlag = is_array($techSection) ? ((int) ($techSection['visible'] ?? 1) === 1) : (trim($techNotes) !== '');
$showTech = $techVisibleFlag && trim($techNotes) !== '';

$orderedVisibleSections = array_values(array_filter($sections, static function ($section) {
    if (!is_array($section)) {
        return false;
    }
    return ((int) ($section['visible'] ?? 1)) === 1;
}));
usort($orderedVisibleSections, static function ($a, $b) {
    return (($a['order'] ?? 0) <=> ($b['order'] ?? 0));
});
$specialSectionTypes = ['executive_summary', 'top_actions', 'meta_recommendations', 'technical_findings'];
$visibleDetailSections = array_values(array_filter($orderedVisibleSections, static function ($section) use ($specialSectionTypes) {
    if (!is_array($section)) {
        return false;
    }
    $typeKey = $section['type'] ?? '';
    if (in_array($typeKey, $specialSectionTypes, true)) {
        return false;
    }
    return true;
}));

$snapshotMeta = get_post_meta($report->ID, ReportPostType::META_SNAPSHOT, true) ?: '';
$snapshot = json_decode($snapshotMeta, true);
$snapshot = is_array($snapshot) ? $snapshot : [];
$runsData = is_array($snapshot['runs'] ?? null) ? $snapshot['runs'] : [];
$sectionRegistry = Sections::registry();

$totalPages = 0;
$totalIssues = 0;
$statusTotals = [
    '2xx' => 0,
    '3xx' => 0,
    '4xx' => 0,
    '5xx' => 0,
    'other' => 0,
];

foreach ($runsData as $runData) {
    if (!is_array($runData)) {
        continue;
    }
    $summary = is_array($runData['summary'] ?? null) ? $runData['summary'] : [];
    $issuesSummary = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];
    $statusSummary = is_array($summary['status'] ?? null)
        ? $summary['status']
        : (is_array($summary['status_buckets'] ?? null) ? $summary['status_buckets'] : []);

    $totalPages += (int) ($summary['pages'] ?? $summary['total_pages'] ?? 0);

    if (isset($issuesSummary['total'])) {
        $totalIssues += (int) $issuesSummary['total'];
    } else {
        $issueSum = 0;
        foreach ($issuesSummary as $count) {
            if (is_numeric($count)) {
                $issueSum += (int) $count;
            }
        }
        $totalIssues += $issueSum;
    }

    foreach ($statusTotals as $bucket => $value) {
        $statusTotals[$bucket] += (int) ($statusSummary[$bucket] ?? 0);
    }
}

$latestRun = $runsData ? $runsData[array_key_last($runsData)] : null;
$latestSummary = is_array($latestRun['summary'] ?? null) ? $latestRun['summary'] : [];
$latestStatus = is_array($latestSummary['status'] ?? null)
    ? $latestSummary['status']
    : (is_array($latestSummary['status_buckets'] ?? null) ? $latestSummary['status_buckets'] : []);
$latestIssues = is_array($latestSummary['issues'] ?? null) ? $latestSummary['issues'] : [];

$dash = static function ($value): string {
    if ($value === null || $value === '' || $value === []) {
        return '-';
    }
    if ($value === 0 || $value === '0') {
        return '0';
    }
    if (is_numeric($value)) {
        return number_format_i18n((float) $value);
    }
    return (string) $value;
};

$generatedAt = get_the_modified_date('F j, Y', $report);
$runsList = is_array($runs) && $runs ? implode(', ', array_map('sanitize_text_field', $runs)) : 'Latest run';

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo esc_html(get_the_title($report)); ?> – SEO Audit Report</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: "Inter", "Segoe UI", sans-serif; background:#f6f7fb; margin:0; color:#1d1f24; }
        .report-wrap { max-width:900px; margin:32px auto; background:#fff; padding:40px 48px; border-radius:16px; box-shadow:0 20px 45px rgba(15,23,42,0.12); }
        header h1 { margin:0 0 8px; font-size:32px; }
        header .meta { color:#51617a; font-size:15px; margin-bottom:24px; }
        .meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin:0 0 24px; padding:0; list-style:none; }
        .meta-grid li { background:#f1f4fa; border-radius:10px; padding:12px 14px; font-size:14px; }
        .meta-grid strong { display:block; font-size:13px; text-transform:uppercase; letter-spacing:0.08em; color:#6d7b90; margin-bottom:6px; }
        h2 { margin-top:36px; font-size:24px; border-bottom:2px solid #e2e8f0; padding-bottom:8px; }
        h3 { font-size:20px; margin-top:24px; }
        table.metrics { width:100%; border-collapse:collapse; margin:16px 0; font-size:15px; }
        table.metrics th, table.metrics td { padding:10px 12px; border-bottom:1px solid #e1e6ef; text-align:left; }
        table.metrics th { background:#f9fafc; width:45%; font-weight:600; color:#334155; }
        .pill-list { list-style:none; margin:12px 0; padding:0; display:flex; flex-wrap:wrap; gap:8px; }
        .pill-list li { background:#e6f0ff; color:#1b4ed8; padding:8px 12px; border-radius:999px; font-size:14px; }
        .metrics-block { margin-top:12px; }
        .metrics-empty { font-style:italic; color:#64748b; margin:8px 0 0; }
        .metrics-note { font-size:13px; color:#64748b; margin-top:6px; }
        .section-card { background:#f9fbff; border-radius:14px; padding:20px 24px; margin-top:20px; border:1px solid #dfe7f6; }
        .section-card h3 { margin-top:0; }
        .section-card .body { font-size:15px; line-height:1.6; color:#1f2933; }
        .section-card ul { margin:12px 0 0; padding-left:20px; }
        .section-card li { margin-bottom:6px; }
        .meta-table { width:100%; border-collapse:collapse; margin:16px 0; font-size:15px; }
        .meta-table th, .meta-table td { padding:10px 12px; border-bottom:1px solid #e1e6ef; text-align:left; }
        .meta-table th { background:#f9fafc; text-transform:uppercase; font-size:13px; letter-spacing:0.08em; color:#64748b; }
        .tech-box { background:#fff5e6; border:1px solid #f0c88c; padding:18px 20px; border-radius:12px; font-size:15px; color:#7c4a03; }
        .summary-box { background:#eef2ff; border:1px solid #c7d2fe; padding:18px 20px; border-radius:12px; font-size:16px; line-height:1.6; color:#312e81; }
        .actions-list { margin:16px 0; padding-left:20px; }
        .actions-list li { margin-bottom:8px; font-size:15px; }
        footer { margin-top:48px; text-align:center; font-size:13px; color:#6c7a93; }
        @media print {
            body { background:#fff; }
            .report-wrap { box-shadow:none; margin:0; border-radius:0; }
            header h1 { font-size:28px; }
        }
    </style>
</head>
<body>
<div class="report-wrap">
    <header>
        <h1><?php echo esc_html(get_the_title($report)); ?></h1>
        <div class="meta"><?php echo esc_html($typeLabel); ?> · Generated <?php echo esc_html($generatedAt); ?></div>
        <ul class="meta-grid">
            <li>
                <strong>Project</strong>
                <?php echo $project ? esc_html($project) : '-'; ?>
            </li>
            <?php if ($pageUrl): ?>
            <li>
                <strong>Page URL</strong>
                <a href="<?php echo esc_url($pageUrl); ?>" target="_blank" rel="noopener"><?php echo esc_html($pageUrl); ?></a>
            </li>
            <?php endif; ?>
            <li>
                <strong>Runs</strong>
                <?php echo $runsList ? esc_html($runsList) : 'Latest run'; ?>
            </li>
            <li>
                <strong>Total Pages</strong>
                <?php echo esc_html($dash($totalPages)); ?>
            </li>
            <li>
                <strong>Total Issues</strong>
                <?php echo esc_html($dash($totalIssues)); ?>
            </li>
        </ul>
    </header>

<?php if ($showSummary): ?>
    <section>
        <h2>Executive Summary</h2>
        <div class="summary-box">
            <?php echo wp_kses_post(wpautop($summaryBody)); ?>
        </div>
    </section>
<?php endif; ?>

<section>
    <h2>Key Metrics Snapshot</h2>
    <table class="metrics">
        <tbody>
        <tr>
            <th scope="row">Total Pages Crawled</th>
            <td><?php echo esc_html($dash($latestSummary['pages'] ?? null)); ?></td>
        </tr>
        <tr>
            <th scope="row">Indexed Pages (2xx)</th>
            <td><?php echo esc_html($dash($latestStatus['2xx'] ?? ($statusTotals['2xx'] ?? null))); ?></td>
        </tr>
        <tr>
            <th scope="row">Broken Links (4xx)</th>
            <td><?php echo esc_html($dash($latestStatus['4xx'] ?? ($statusTotals['4xx'] ?? null))); ?></td>
        </tr>
        <tr>
            <th scope="row">Server Errors (5xx)</th>
            <td><?php echo esc_html($dash($latestStatus['5xx'] ?? ($statusTotals['5xx'] ?? null))); ?></td>
        </tr>
        <tr>
            <th scope="row">Issues Found (Total)</th>
            <td><?php echo esc_html($dash($latestIssues['total'] ?? $totalIssues)); ?></td>
        </tr>
            </tbody>
        </table>
</section>

<?php if ($showActions): ?>
    <section>
        <h2>Top Actions</h2>
        <ol class="actions-list">
            <?php foreach ($actions as $action): ?>
                <li><?php echo esc_html($action); ?></li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php if ($showMetaRec): ?>
    <section>
        <h2>Meta Recommendations</h2>
        <table class="meta-table">
            <thead>
            <tr>
                <th scope="col">URL</th>
                <th scope="col">Suggested Title</th>
                <th scope="col">Suggested Description</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($metaRec as $row): ?>
                <tr>
                    <td>
                        <?php if (!empty($row['url'])): ?>
                            <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['url']); ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($row['title'] ?? ''); ?></td>
                    <td><?php echo esc_html($row['meta_description'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php if ($showTech): ?>
    <section>
        <h2>Technical Findings</h2>
        <div class="tech-box">
            <?php echo wp_kses_post(wpautop($techNotes)); ?>
        </div>
    </section>
<?php endif; ?>

<?php
$renderSectionMetrics = static function (array $table): void {
    $rows = $table['rows'] ?? [];
    $headers = $table['headers'] ?? [];
    if (!$headers && $rows) {
        $headers = array_keys(reset($rows));
    }
    if ($rows) {
        echo '<table class="metrics"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . esc_html((string) $header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                echo '<td>' . esc_html((string) $value) . '</td>';
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
};
?>
<?php if ($visibleDetailSections): ?>
    <section>
        <h2>Detailed Sections</h2>
        <?php foreach ($visibleDetailSections as $section): ?>
            <?php
                $typeKey = (string) ($section['type'] ?? '');
                $label = $section['title'] ?: ($sectionRegistry[$typeKey]['label'] ?? ucfirst(str_replace('_', ' ', $typeKey)));
                $metrics = is_array($section['metrics'] ?? null) ? $section['metrics'] : [];
                $hasMetrics = !empty($metrics['rows']) || !empty($metrics['empty']) || !empty($metrics['note']);
            ?>
            <div class="section-card">
                <h3><?php echo esc_html($label); ?></h3>
                <?php if ($hasMetrics): ?>
                    <div class="metrics-block">
                        <?php $renderSectionMetrics($metrics); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($section['body'])): ?>
                    <div class="body"><?php echo wp_kses_post(wpautop($section['body'])); ?></div>
                <?php endif; ?>
                <?php if (!empty($section['reco_list']) && is_array($section['reco_list'])): ?>
                    <ul>
                        <?php foreach ($section['reco_list'] as $reco): ?>
                            <li><?php echo esc_html((string) $reco); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

    <footer>
        Prepared with AI SEO Tools · <?php echo esc_html(date_i18n(get_option('date_format'))); ?>
    </footer>
</div>
<?php wp_reset_postdata(); ?>
</body>
</html>
