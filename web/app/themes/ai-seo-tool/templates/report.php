<?php
use BBSEO\PostTypes\Report as ReportPostType;
use BBSEO\Helpers\Sections;

/** @var \WP_Post|null $report */
$report = $GLOBALS['bbseo_report_post'] ?? null;
if (!$report instanceof \WP_Post) {
    status_header(404);
    echo '<h1>Report not found</h1>';
    return;
}
$isPdf = !empty($GLOBALS['bbseo_render_report_pdf']);
$publicCssPath = get_theme_file_path('assets/dist/css/public.css');
$publicCssContent = is_file($publicCssPath) ? file_get_contents($publicCssPath) : '';

$isPrivate = get_post_meta($report->ID, ReportPostType::META_PRIVATE, true) === '1';
$passwordHash = get_post_meta($report->ID, ReportPostType::META_PASSWORD_HASH, true) ?: '';
$passwordNonce = $_POST['bbseo_report_password_nonce'] ?? '';
$passwordAttempt = $_POST['bbseo_report_password'] ?? '';
$passwordAttempt = is_string($passwordAttempt) ? sanitize_text_field($passwordAttempt) : '';
$cookieKey = 'bbseo_report_access_' . $report->ID;
$cookieUnlocked = isset($_COOKIE[$cookieKey]) && $_COOKIE[$cookieKey] === '1';
$passwordGranted = !$isPrivate;
$passwordError = '';

if ($isPrivate && !$passwordGranted) {
    if ($cookieUnlocked && $passwordHash) {
        $passwordGranted = true;
    } elseif ($passwordAttempt !== '' && wp_verify_nonce($passwordNonce, 'bbseo_report_password') && $passwordHash !== '' && wp_check_password($passwordAttempt, $passwordHash)) {
        $passwordGranted = true;
        $cookiePath = defined('COOKIEPATH') ? COOKIEPATH : '/';
        setcookie($cookieKey, '1', time() + DAY_IN_SECONDS, $cookiePath);
    } elseif ($passwordAttempt !== '') {
        $passwordError = 'Incorrect password. Please try again.';
    }
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

$sectionsRaw = get_post_meta($report->ID, Sections::META_SECTIONS, true) ?: '[]';
$sections = maybe_unserialize($sectionsRaw);

if (!is_array($sections)) {
    $sections = [];
}

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

 $snapshotMeta = get_post_meta($report->ID, ReportPostType::META_SNAPSHOT, true) ?: '';
 $snapshot = json_decode($snapshotMeta, true);
 $snapshot = is_array($snapshot) ? $snapshot : [];


$generatedAt = get_the_modified_date('F j, Y', $report);
$sectionTitles = [
    'Executive Summary',
    'Top Actions',
    'Overview',
    'Performance Summary',
    'Technical SEO Issues',
    'On-Page SEO & Content',
    'Keyword Analysis',
    'Backlink Profile',
    'Crawl History',
    'Traffic Trends',
    'Search Visibility',
    'Meta Recommendations',
    'Technical Findings',
    'Recommendations',
];
$overviewMetrics = [
    ['label' => 'Pages Audited', 'value' => '128'],
    ['label' => 'Critical Issues', 'value' => '12'],
    ['label' => 'Average Lighthouse', 'value' => '85'],
    ['label' => 'Visibility Growth', 'value' => '+14%'],
];
$dummyText = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed non risus.';
 $sectionStyles = [
     'border border-slate-200 bg-white shadow-sm',
     'border border-slate-300 bg-slate-900 text-white shadow-xl',
     'border border-slate-100 bg-gradient-to-br from-slate-50 to-white shadow-lg',
     'border border-slate-200 bg-white/80 shadow',
 ];
$bodyParagraphs = [
    'This section highlights the most critical findings, offering a narrative that blends context with actionable insight as we prepare the narrative for stakeholders and clients.',
    'The content reflects both technical signals and strategic priorities so it can be mapped back to recommended next steps that the team can run with right away.',
];
$recommendations = [
    'Validate redirects and canonical tags at the network level.',
    'Prioritize opportunities with both SEO and CRO wins.',
    'Refresh the most important landing pages with richer content.',
    'Roll out structured data where schema is missing or malformed.',
    'Monitor Core Web Vitals weekly and flag regressions immediately.',
];
$metricsBySection = [
    'Performance Summary' => [
        'type' => 'table',
        'rows' => [
            ['Metric' => 'Mobile Lighthouse', 'Value' => '82'],
            ['Metric' => 'Desktop Lighthouse', 'Value' => '88'],
            ['Metric' => 'Server Response', 'Value' => '220ms'],
        ],
    ],
    'Traffic Trends' => [
        'type' => 'chart',
        'note' => 'Trailing 30 days · organic + paid',
    ],
    'Keyword Analysis' => [
        'type' => 'bar',
        'items' => [
            ['label' => 'Brand keywords', 'value' => 64],
            ['label' => 'High intent', 'value' => 49],
            ['label' => 'Informational', 'value' => 34],
        ],
    ],
    'Backlink Profile' => [
        'type' => 'table',
        'rows' => [
            ['Metric' => 'Referring Domains', 'Value' => '912'],
            ['Metric' => 'Authority Links', 'Value' => '74'],
        ],
    ],
];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($isPdf): ?>
        <?php if ($publicCssContent): ?>
            <style><?php echo $publicCssContent; ?></style>
        <?php endif; ?>
    <?php else: ?>
        <?php wp_head(); ?>
    <?php endif; ?>
</head>
<body class="bg-slate-50 text-slate-900 print:bg-white print:text-black">
<?php wp_body_open(); ?>
    <main class="min-h-screen">
        <div class="max-w-6xl mx-auto px-5 py-10 space-y-10">
            <header class="flex flex-wrap items-center justify-between gap-4 rounded-3xl bg-white/70 border border-slate-200 p-6 shadow-md print:bg-white print:shadow-none print:border-slate-200 print:text-black">
                <div class="flex items-center gap-3">
                    <div class="h-14 w-14 rounded-2xl border border-slate-200 text-white flex items-center justify-center font-semibold">
                        <img src="<?php echo esc_url(get_theme_file_uri('/assets/images/logo.svg')); ?>" alt="Blackbird Media Singapore" class="h-10 w-10">
                    </div>
                    <div>
                        <p class="text-xl font-semibold tracking-wide">Blackbird Media Singapore</p>
                        <p class="text-sm text-slate-500">Crafted for modern SEO teams</p>
                    </div>
                </div>
                <div class="text-right text-sm text-slate-500">
                    <p class="font-semibold">SEO Audit Report</p>
                    <p><?php echo esc_html($pageUrl ?: 'www.example.com'); ?></p>
                </div>
            </header>

            <section class="relative rounded-[36px] overflow-hidden bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 text-white flex flex-col md:flex-row justify-between p-10 print:bg-white print:text-black print:shadow-none print:border print:border-slate-200">
                <div class="space-y-4 max-w-3xl">
                    <p class="text-sm uppercase tracking-[0.3em] text-blue-200">Brand Snapshot</p>
                    <h1 class="text-4xl font-semibold"><?php echo esc_html($project ?: 'Brooklyn Concepts'); ?></h1>
                    <p class="text-xl font-light text-blue-100"><?php echo esc_html($typeLabel); ?></p>
                    <p class="text-base text-blue-200"><?php echo esc_html($project ?: 'Acme Inc.') . ' · ' . esc_html($typeLabel); ?></p>
                </div>
                <div class="flex flex-col gap-2 text-blue-200 text-sm">
                    <div class="h-32 w-32 self-end rounded-2xl bg-white/20 border border-white/30 shadow-inner flex items-center justify-center text-xs uppercase tracking-[0.4em]">
                        Logo / Screenshot
                    </div>
                    <div class="text-right">
                        <p class="font-semibold">Generated on <?php echo esc_html($generatedAt); ?></p>
                        <p class="text-xs uppercase tracking-[0.4em] text-white/60">Full Audit Overview</p>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-4">
                <?php foreach ($overviewMetrics as $metric): ?>
                    <div class="rounded-2xl bg-white border border-slate-200 p-4 shadow-sm print:bg-white print:shadow-none print:border-slate-200">
                        <p class="text-2xl font-semibold"><?php echo esc_html($metric['value']); ?></p>
                        <p class="text-xs uppercase tracking-[0.4em] text-slate-500"><?php echo esc_html($metric['label']); ?></p>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php if ($isPrivate && !$passwordGranted): ?>
                <section class="rounded-3xl border border-slate-200 bg-white/90 p-8 text-center shadow-md print:bg-white print:border-slate-200">
                    <p class="text-sm uppercase tracking-[0.4em] text-slate-500">Private report</p>
                    <h2 class="mt-4 text-2xl font-semibold text-slate-900">Enter password to continue</h2>
                    <?php if ($passwordError): ?>
                        <p class="mt-2 text-sm text-red-600"><?php echo esc_html($passwordError); ?></p>
                    <?php endif; ?>
                    <form method="post" class="mt-6 space-y-3 max-w-sm mx-auto">
                        <?php wp_nonce_field('bbseo_report_password', 'bbseo_report_password_nonce'); ?>
                        <input
                            type="password"
                            name="bbseo_report_password"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"
                            placeholder="Report password"
                            autocomplete="off"
                        />
                        <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-3 text-xs font-semibold uppercase tracking-[0.3em] text-white">Unlock report</button>
                    </form>
                    <p class="mt-4 text-xs text-slate-500">You must enter the password provided by the report owner to view the content.</p>
                </section>
                <?php return; ?>
            <?php endif; ?>

            <section class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-3xl bg-white p-6 border border-slate-200 shadow-lg flex flex-col gap-4 print:bg-white print:shadow-none print:border-slate-200">
                    <p class="text-xs uppercase tracking-[0.4em] text-slate-500">Chart</p>
                    <div class="h-48 rounded-2xl bg-gradient-to-br from-blue-500 to-sky-400 print:bg-slate-200"></div>
                    <p class="text-sm text-slate-600"><?php echo esc_html($dummyText); ?></p>
                </div>
                <div class="rounded-3xl bg-white p-6 border border-slate-200 shadow-lg flex flex-col gap-4 print:bg-white print:shadow-none print:border-slate-200">
                    <p class="text-xs uppercase tracking-[0.4em] text-slate-500">Bar chart</p>
                    <div class="h-48 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-400 print:bg-slate-200"></div>
                    <p class="text-sm text-slate-600"><?php echo esc_html($dummyText); ?></p>
                </div>
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white/70 p-6 shadow-inner space-y-3 print:bg-white print:border-slate-200 print:shadow-none">
                    <p class="text-xs uppercase tracking-[0.4em] text-slate-500">Quick Notes</p>
                    <p class="text-sm text-slate-600"><?php echo esc_html($dummyText); ?></p>
                    <p class="text-sm text-slate-600"><?php echo esc_html($dummyText); ?></p>
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-2 print:gap-4">
                <?php foreach ($sectionTitles as $index => $title): ?>
                    <?php
                        $style = $sectionStyles[$index % count($sectionStyles)];
                        $textColor = str_contains($style, 'text-white') ? 'text-white/90' : 'text-slate-700';
                        $metric = $metricsBySection[$title] ?? null;
                    ?>
                    <article class="rounded-3xl p-6 <?php echo esc_attr($style); ?> transition hover:-translate-y-1 print:bg-white print:text-black print:shadow-none print:border print:border-slate-200">
                        <header class="mb-3 flex items-center justify-between">
                            <h2 class="text-lg font-semibold tracking-tight <?php echo esc_attr($textColor); ?>"><?php echo esc_html($title); ?></h2>
                            <span class="text-xs uppercase tracking-[0.4em] <?php echo esc_attr($textColor); ?>">Draft</span>
                        </header>
                        <?php foreach ($bodyParagraphs as $paragraph): ?>
                            <p class="text-sm leading-relaxed <?php echo esc_attr($textColor); ?>"><?php echo esc_html($paragraph); ?></p>
                        <?php endforeach; ?>
                        <?php if ($metric): ?>
                            <div class="mt-4 space-y-3">
                                <?php if ($metric['type'] === 'table'): ?>
                                    <div class="overflow-x-auto rounded-2xl bg-white/80 p-3 shadow-inner print:bg-white">
                                        <table class="min-w-full text-xs uppercase tracking-[0.3em] text-slate-500 print:text-black">
                                            <tbody>
                                                <?php foreach ($metric['rows'] as $row): ?>
                                                    <tr>
                                                        <td class="py-1 pr-6 font-semibold <?php echo esc_attr($textColor); ?>"><?php echo esc_html($row['Metric']); ?></td>
                                                        <td class="py-1 text-right <?php echo esc_attr($textColor); ?>"><?php echo esc_html($row['Value']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php elseif ($metric['type'] === 'chart'): ?>
                                    <div class="space-y-2">
                                        <div class="h-32 rounded-2xl bg-gradient-to-r from-emerald-500 to-cyan-500 print:bg-slate-200"></div>
                                        <p class="text-xs uppercase tracking-[0.3em] text-slate-500 <?php echo esc_attr($textColor); ?>"><?php echo esc_html($metric['note'] ?? ''); ?></p>
                                    </div>
                                <?php elseif ($metric['type'] === 'bar'): ?>
                                    <div class="space-y-3">
                                        <?php foreach ($metric['items'] as $item): ?>
                                            <?php $percent = (int) ($item['value'] ?? 0); ?>
                                            <div class="text-xs flex items-center justify-between <?php echo esc_attr($textColor); ?>">
                                                <span><?php echo esc_html($item['label']); ?></span>
                                                <span><?php echo $percent; ?>%</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-slate-200">
                                                <div class="h-full rounded-full bg-gradient-to-r from-blue-600 to-sky-400" style="width: <?php echo $percent; ?>%;"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="mt-4">
                            <p class="text-xs uppercase tracking-[0.4em] <?php echo esc_attr($textColor); ?>">Recommendations</p>
                            <ul class="mt-2 space-y-2 text-sm <?php echo esc_attr($textColor); ?>">
                                <?php foreach ($recommendations as $recommendation): ?>
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1 h-1 w-1 rounded-full bg-slate-900 <?php echo str_contains($textColor, 'white') ? 'bg-white' : 'bg-slate-900'; ?>"></span>
                                        <span><?php echo esc_html($recommendation); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <footer class="rounded-3xl border border-slate-200 bg-white/80 p-6 text-sm text-slate-600 shadow-inner text-center">
                <p>© <?php echo date('Y'); ?> AI SEO Tool · Confidential report preview.</p>
                <p>Prepared for <?php echo esc_html($project ?: 'Demo Client'); ?>.</p>
            </footer>
        </div>
    </main>
<?php if (!$isPdf): ?>
    <?php wp_footer(); ?>
<?php endif; ?>
</body>
</html>
