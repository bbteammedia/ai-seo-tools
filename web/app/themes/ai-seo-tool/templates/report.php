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
</body>
</html>
