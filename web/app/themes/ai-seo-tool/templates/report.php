<?php
use AISEO\Helpers\Storage;

/** Basic HTML report (will improve later / add PDF export) */
$projectParam = get_query_var('aiseo_report');
if (!$projectParam) {
    $projectParam = $_GET['project'] ?? '';
}
$project = sanitize_text_field($projectParam);
$projectSlug = sanitize_title($project);
$runParam = isset($_GET['run']) ? sanitize_key($_GET['run']) : '';
if (!$runParam && $projectSlug) {
    $runParam = Storage::getLatestRun($projectSlug) ?: '';
}

$base = getenv('AISEO_STORAGE_DIR') ?: get_theme_file_path('storage/projects');
$reportPath = $runParam ? Storage::runDir($projectSlug, $runParam) . '/report.json' : '';
$data = ($reportPath && file_exists($reportPath)) ? json_decode(file_get_contents($reportPath), true) : null;
$summaryPath = $runParam ? Storage::runDir($projectSlug, $runParam) . '/summary.json' : '';
$summary = ($summaryPath && file_exists($summaryPath)) ? json_decode(file_get_contents($summaryPath), true) : [];
if (!is_array($summary)) {
    $summary = [];
}
$timeseriesPath = $projectSlug ? Storage::projectDir($projectSlug) . '/timeseries.json' : '';
$timeseries = ($timeseriesPath && file_exists($timeseriesPath)) ? json_decode(file_get_contents($timeseriesPath), true) : ['items' => []];
if (!is_array($timeseries) || !isset($timeseries['items']) || !is_array($timeseries['items'])) {
    $timeseries = ['items' => []];
}
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>AI SEO Report – <?php echo esc_html($project); ?><?php if ($runParam): ?> (<?php echo esc_html($runParam); ?>)<?php endif; ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
  <div class="container">
    <h1 class="mb-3">AI SEO Report – <?php echo esc_html($project); ?><?php if ($runParam): ?> <small class="text-muted"><?php echo esc_html($runParam); ?></small><?php endif; ?></h1>
    <?php if (!$data): ?>
      <div class="alert alert-warning">No report found for this run.</div>
    <?php else: ?>
      <?php if (!empty($data['base_url'])): ?>
        <p><strong>Primary URL:</strong> <a href="<?php echo esc_url($data['base_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($data['base_url']); ?></a></p>
      <?php endif; ?>
      <p><strong>Generated:</strong> <?php echo esc_html($data['generated_at']); ?></p>
      <div class="row g-3">
        <div class="col-md-4">
          <div class="card"><div class="card-body">
            <h5 class="card-title">Pages</h5>
            <p class="display-6 mb-3"><?php echo (int) ($data['crawl']['pages_count'] ?? 0); ?></p>
            <?php if (!empty($data['crawl']['status_buckets'])): ?>
              <ul class="list-unstyled small mb-0">
                <?php foreach ($data['crawl']['status_buckets'] as $bucket => $count): ?>
                  <li><strong><?php echo esc_html($bucket); ?>:</strong> <?php echo (int) $count; ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div></div>
        </div>
        <div class="col-md-8">
          <div class="card"><div class="card-body">
            <h5 class="card-title">Top Issues</h5>
            <?php if (!empty($data['top_issues'])): ?>
              <ol class="mb-0 small">
                <?php foreach ($data['top_issues'] as $label => $count): ?>
                  <li><?php echo esc_html($label); ?> <span class="text-muted">(<?php echo (int) $count; ?>)</span></li>
                <?php endforeach; ?>
              </ol>
            <?php else: ?>
              <p class="mb-0">No issues detected.</p>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
      <div class="row g-3 mt-3">
        <div class="col-md-6">
          <div class="card"><div class="card-body">
            <h5 class="card-title">Status Breakdown (This Run)</h5>
            <canvas id="statusPie"></canvas>
          </div></div>
        </div>
        <div class="col-md-6">
          <div class="card"><div class="card-body">
            <h5 class="card-title">Issues (This Run)</h5>
            <div class="display-6" id="issuesThisRun"><?php echo (int) ($summary['issues']['total'] ?? 0); ?></div>
          </div></div>
        </div>
        <div class="col-12">
          <div class="card"><div class="card-body">
            <h5 class="card-title">Trends Over Time</h5>
            <div class="ratio ratio-21x9">
              <canvas id="trendLine"></canvas>
            </div>
          </div></div>
        </div>
      </div>
      <div class="row g-3 mt-1">
        <div class="col-12">
          <div class="card"><div class="card-body">
            <h5 class="card-title">Audit Items</h5>
            <?php $items = $data['audit']['items'] ?? []; ?>
            <?php if ($items): ?>
              <?php foreach (array_slice($items, 0, 100) as $it): ?>
                <div class="border-bottom py-2">
                  <div><strong>URL:</strong> <?php echo esc_html($it['url']); ?></div>
                  <?php if (!empty($it['status'])): ?>
                    <div class="small text-muted">Status: <?php echo (int) $it['status']; ?></div>
                  <?php endif; ?>
                  <?php if ($it['issues']): ?>
                  <ul class="mb-0 small">
                    <?php foreach ($it['issues'] as $iss): ?><li><?php echo esc_html($iss); ?></li><?php endforeach; ?>
                  </ul>
                  <?php else: ?>
                    <em class="small">No issues</em>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if (count($items) > 100): ?>
                <p class="mt-3 small text-muted">Showing first 100 pages. See JSON for full list.</p>
              <?php endif; ?>
            <?php else: ?>
              <p class="mb-0">No audit items found.</p>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <?php
  $summaryForJs = $summary ?: new stdClass();
  $seriesItemsForJs = isset($timeseries['items']) && is_array($timeseries['items']) ? $timeseries['items'] : [];
  ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  (function () {
    const summary = <?php echo json_encode($summaryForJs, JSON_UNESCAPED_SLASHES); ?>;
    const series = <?php echo json_encode($seriesItemsForJs, JSON_UNESCAPED_SLASHES); ?>;

    const issuesEl = document.getElementById('issuesThisRun');
    if (issuesEl) {
      issuesEl.textContent = summary?.issues?.total || 0;
    }

    const statusCanvas = document.getElementById('statusPie');
    if (statusCanvas && typeof Chart !== 'undefined') {
      new Chart(statusCanvas, {
        type: 'pie',
        data: {
          labels: ['2xx', '3xx', '4xx', '5xx', 'other'],
          datasets: [{
            data: [
              summary?.status?.['2xx'] || 0,
              summary?.status?.['3xx'] || 0,
              summary?.status?.['4xx'] || 0,
              summary?.status?.['5xx'] || 0,
              summary?.status?.other || 0
            ],
            backgroundColor: ['#36a2eb', '#4bc0c0', '#ffcd56', '#ff6384', '#a1a1a1']
          }]
        }
      });
    }

    const trendCanvas = document.getElementById('trendLine');
    if (trendCanvas && typeof Chart !== 'undefined') {
      const labels = series.map(item => item.date);
      new Chart(trendCanvas, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label: 'Pages', data: series.map(i => i.pages || 0), borderColor: '#36a2eb', backgroundColor: 'rgba(54,162,235,0.2)', fill: false },
            { label: '2xx', data: series.map(i => i['2xx'] || 0), borderColor: '#4bc0c0', backgroundColor: 'rgba(75,192,192,0.2)', fill: false },
            { label: '4xx', data: series.map(i => i['4xx'] || 0), borderColor: '#ffcd56', backgroundColor: 'rgba(255,205,86,0.2)', fill: false },
            { label: '5xx', data: series.map(i => i['5xx'] || 0), borderColor: '#ff6384', backgroundColor: 'rgba(255,99,132,0.2)', fill: false },
            { label: 'Issues', data: series.map(i => i.issues || 0), borderColor: '#a17de8', backgroundColor: 'rgba(161,125,232,0.2)', fill: false }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false
        }
      });
    }
  })();
  </script>
</body>
</html>
