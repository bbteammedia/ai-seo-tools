<?php
use AISEO\PostTypes\Report as ReportPostType;
use AISEO\Helpers\Sections;
use AISEO\Helpers\DataLoader;

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
$summaryText = get_post_meta($report->ID, ReportPostType::META_SUMMARY, true) ?: '';
$actionsMeta = get_post_meta($report->ID, ReportPostType::META_ACTIONS, true) ?: '[]';
$actions = json_decode($actionsMeta, true);
$actions = is_array($actions) ? array_filter(array_map('trim', $actions)) : [];
$metaRecMeta = get_post_meta($report->ID, ReportPostType::META_META_RECO, true) ?: '[]';
$metaRec = json_decode($metaRecMeta, true);
$metaRec = is_array($metaRec) ? $metaRec : [];
$techNotes = get_post_meta($report->ID, ReportPostType::META_TECH, true) ?: '';

$sectionsRaw = get_post_meta($report->ID, Sections::META_SECTIONS, true) ?: '[]';
$sections = json_decode($sectionsRaw, true);
$sections = is_array($sections) ? $sections : [];
usort($sections, static fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

$snapshotMeta = get_post_meta($report->ID, ReportPostType::META_SNAPSHOT, true) ?: '';
$snapshot = json_decode($snapshotMeta, true);
if (!is_array($snapshot) || empty($snapshot['runs'])) {
    $snapshot = DataLoader::forReport($type, (string) $project, is_array($runs) ? $runs : [], (string) $pageUrl);
}
$snapshotRuns = $snapshot['runs'] ?? [];

$stats = [
    'pages' => 0,
    'issues' => 0,
    'status' => [
        '2xx' => 0,
        '3xx' => 0,
        '4xx' => 0,
        '5xx' => 0,
        'other' => 0,
    ],
];
$wordCountTotal = 0;
$wordCountPages = 0;
$brokenLinks = 0;
$missingTitle = 0;
$missingMeta = 0;

foreach ($snapshotRuns as $run) {
    $summary = $run['summary'] ?? [];
    $stats['pages'] += (int) ($summary['pages'] ?? count($run['pages'] ?? []));
    $stats['issues'] += (int) ($summary['issues']['total'] ?? 0);

    foreach (['2xx', '3xx', '4xx', '5xx', 'other'] as $bucket) {
        $stats['status'][$bucket] += (int) ($summary['status'][$bucket] ?? 0);
    }

    $pages = $run['pages'] ?? [];
    foreach ($pages as $page) {
        $wordCount = (int) ($page['word_count'] ?? 0);
        if ($wordCount > 0) {
            $wordCountTotal += $wordCount;
            $wordCountPages++;
        }

        $title = trim((string) ($page['title'] ?? ''));
        $metaDesc = trim((string) ($page['meta_description'] ?? ''));
        if ($title === '') {
            $missingTitle++;
        }
        if ($metaDesc === '') {
            $missingMeta++;
        }

        $statusCode = (int) ($page['status'] ?? 0);
        if ($statusCode >= 400 && $statusCode < 500) {
            $brokenLinks++;
        }
    }
}

$avgWordcount = $wordCountPages ? (int) floor($wordCountTotal / $wordCountPages) : null;
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
                <?php echo $project ? esc_html($project) : '—'; ?>
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
                <?php echo number_format_i18n($stats['pages']); ?>
            </li>
            <li>
                <strong>Total Issues</strong>
                <?php echo number_format_i18n($stats['issues']); ?>
            </li>
        </ul>
    </header>

    <?php if ($summaryText): ?>
        <section>
            <h2>Executive Summary</h2>
            <div class="summary-box">
                <?php echo wp_kses_post(wpautop($summaryText)); ?>
            </div>
        </section>
    <?php endif; ?>

    <section>
        <h2>Key Metrics Snapshot</h2>
        <table class="metrics">
            <tbody>
            <tr>
                <th scope="row">Total Pages Crawled</th>
                <td><?php echo number_format_i18n($stats['pages']); ?></td>
            </tr>
            <tr>
                <th scope="row">Indexed Pages (2xx)</th>
                <td><?php echo number_format_i18n($stats['status']['2xx']); ?></td>
            </tr>
            <tr>
                <th scope="row">Broken Links (4xx)</th>
                <td><?php echo number_format_i18n($stats['status']['4xx']); ?></td>
            </tr>
            <tr>
                <th scope="row">Server Errors (5xx)</th>
                <td><?php echo number_format_i18n($stats['status']['5xx']); ?></td>
            </tr>
            <tr>
                <th scope="row">Average Word Count</th>
                <td><?php echo $avgWordcount ? number_format_i18n($avgWordcount) : '—'; ?></td>
            </tr>
            <tr>
                <th scope="row">Pages Missing Title</th>
                <td><?php echo number_format_i18n($missingTitle); ?></td>
            </tr>
            <tr>
                <th scope="row">Pages Missing Meta Description</th>
                <td><?php echo number_format_i18n($missingMeta); ?></td>
            </tr>
            </tbody>
        </table>
    </section>

    <?php if ($actions): ?>
        <section>
            <h2>Top Actions</h2>
            <ol class="actions-list">
                <?php foreach ($actions as $action): ?>
                    <li><?php echo esc_html($action); ?></li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if ($metaRec): ?>
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
                                —
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

    <?php if ($techNotes): ?>
        <section>
            <h2>Technical Findings</h2>
            <div class="tech-box">
                <?php echo wp_kses_post(wpautop($techNotes)); ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($sections): ?>
        <section>
            <h2>Detailed Sections</h2>
            <?php foreach ($sections as $section): ?>
                <div class="section-card">
                    <h3><?php echo esc_html($section['title'] ?: ($section['type'] ?? 'Section')); ?></h3>
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
