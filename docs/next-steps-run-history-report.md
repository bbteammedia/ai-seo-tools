# AI SEO Tools – Next Implementation Steps (Run History, Reports, AI, Imports)

You’ve got **run-scoped crawling + scheduler** working – great! Here’s a focused, incremental plan with ready-to-implement snippets so your agent (Codex) can continue.

---

## 0) Quick QA Checklist (do this now)
- [ ] Manual Start (button) and REST `/start-crawl` both create **runs/{run_id}/queue|pages|meta.json**
- [ ] Minutely cron drains queue (env: `AISEO_DISABLE_CRON=false`, `AISEO_STEPS_PER_TICK=50`)
- [ ] When queue empties, **audit + report** are created inside the same run folder
- [ ] `latest_run.txt` updates to last started run
- [ ] `config.json` exists per project (saved on post save) and scheduler detects it

---

## 1) Run Summary & Project Timeseries
Create a normalized summary per run and append a project-level time series for charts and trend analysis.

### 1.1 `app/Report/Summary.php`
```php
<?php
namespace AISEO\Report;

use AISEO\Helpers\Storage;

class Summary
{
    public static function build(string $project, string $run): array
    {
        $dir = Storage::runDir($project, $run);
        $pages = glob($dir.'/pages/*.json');
        $count = count($pages);

        $statusBuckets = ['2xx'=>0,'3xx'=>0,'4xx'=>0,'5xx'=>0,'other'=>0];
        $issuesTotal = 0;

        foreach ($pages as $p) {
            $data = json_decode(file_get_contents($p), true) ?: [];
            $s = intval($data['status'] ?? 0);
            if ($s>=200 && $s<300) $statusBuckets['2xx']++;
            elseif ($s>=300 && $s<400) $statusBuckets['3xx']++;
            elseif ($s>=400 && $s<500) $statusBuckets['4xx']++;
            elseif ($s>=500 && $s<600) $statusBuckets['5xx']++;
            else $statusBuckets['other']++;

            // If you store issues on page level later, sum them here
            // $issuesTotal += count($data['issues'] ?? []);
        }

        $audit = json_decode(@file_get_contents($dir.'/audit.json'), true) ?: [];
        foreach (($audit['items'] ?? []) as $it) {
            $issuesTotal += count($it['issues'] ?? []);
        }

        $out = [
            'project'   => $project,
            'run_id'    => $run,
            'generated' => gmdate('c'),
            'pages'     => $count,
            'status'    => $statusBuckets,
            'issues'    => [
                'total' => $issuesTotal
            ]
        ];

        file_put_contents($dir.'/summary.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return $out;
    }

    public static function appendTimeseries(string $project, array $summary): array
    {
        $pdir = Storage::projectDir($project);
        $path = $pdir.'/timeseries.json';
        $ts = is_file($path) ? json_decode(file_get_contents($path), true) : ['items'=>[]];

        $ts['items'][] = [
            'run_id' => $summary['run_id'],
            'date'   => $summary['generated'],
            'pages'  => $summary['pages'],
            '2xx'    => $summary['status']['2xx'],
            '3xx'    => $summary['status']['3xx'],
            '4xx'    => $summary['status']['4xx'],
            '5xx'    => $summary['status']['5xx'],
            'issues' => $summary['issues']['total']
        ];

        file_put_contents($path, json_encode($ts, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return $ts;
    }
}
```

### 1.2 Call Summary after building report
In your finalize step (end of `/crawl-step` when queue is empty, and in cron finalize), add:
```php
$sum = \AISEO\Report\Summary::build($project, $run);
\AISEO\Report\Summary::appendTimeseries($project, $sum);
```

---

## 2) Report Template: Trend Charts (Chart.js)
Enhance `templates/report.php` to render charts from `summary.json` and `timeseries.json`.

### 2.1 Minimal HTML blocks
```php
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
      <div class="display-6" id="issuesThisRun"></div>
    </div></div>
  </div>
  <div class="col-12">
    <div class="card"><div class="card-body">
      <h5 class="card-title">Trends Over Time</h5>
      <canvas id="trendLine"></canvas>
    </div></div>
  </div>
</div>
```

### 2.2 Data injection & Chart.js
```php
<?php
$sumPath = $base.'/'.sanitize_title($project).'/runs/'.sanitize_text_field($_GET['run'] ?? '').'/summary.json';
$summary = is_file($sumPath) ? json_decode(file_get_contents($sumPath), true) : null;
$tsPath = $base.'/'.sanitize_title($project).'/timeseries.json';
$series = is_file($tsPath) ? json_decode(file_get_contents($tsPath), true) : ['items'=>[]];
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const summary = <?php echo json_encode($summary ?: [], JSON_UNESCAPED_SLASHES); ?>;
const series = <?php echo json_encode($series['items'] ?? [], JSON_UNESCAPED_SLASHES); ?>;

document.getElementById('issuesThisRun').textContent = (summary.issues?.total || 0);

new Chart(document.getElementById('statusPie'), {
  type: 'pie',
  data: {
    labels: ['2xx','3xx','4xx','5xx','other'],
    datasets: [{ data: [
      summary.status?.['2xx']||0,
      summary.status?.['3xx']||0,
      summary.status?.['4xx']||0,
      summary.status?.['5xx']||0,
      summary.status?.['other']||0
    ] }]
  }
});

const labels = series.map(i => i.date);
new Chart(document.getElementById('trendLine'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label: 'Pages', data: series.map(i => i.pages) },
      { label: '2xx', data: series.map(i => i['2xx']) },
      { label: '4xx', data: series.map(i => i['4xx']) },
      { label: '5xx', data: series.map(i => i['5xx']) },
      { label: 'Issues', data: series.map(i => i.issues) }
    ]
  }
});
</script>
```

---

## 3) GA/GSC CSV Import Endpoints (skeleton)

### 3.1 Routes
`/wp-json/ai-seo-tool/v1/upload-ga?project=...`  
`/wp-json/ai-seo-tool/v1/upload-gsc?project=...`
- Accept file (multipart) or JSON body
- Save under `runs/{run}/analytics/ga.json` and `gsc.json`

```php
register_rest_route('ai-seo-tool/v1','/upload-ga',[
  'methods'=>'POST',
  'callback'=>[self::class,'uploadGa'],
  'permission_callback'=>'__return_true',
]);
```

### 3.2 Handler (example: JSON body)
```php
public static function uploadGa(\WP_REST_Request $req){
  if(!\AISEO\Helpers\Http::validate_token($req)) return \AISEO\Helpers\Http::fail('invalid key',401);
  $project = sanitize_text_field($req->get_param('project'));
  $run = $req->get_param('run') ?: \AISEO\Helpers\Storage::getLatestRun($project);
  if(!$project || !$run) return \AISEO\Helpers\Http::fail('project/run required',422);
  $data = $req->get_json_params();
  $dir = \AISEO\Helpers\Storage::runDir($project,$run).'/analytics';
  if(!is_dir($dir)) wp_mkdir_p($dir);
  file_put_contents($dir.'/ga.json', json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  return \AISEO\Helpers\Http::ok(['saved'=>true]);
}
```

---

## 4) Gemini Executive Summary (stub)

### 4.1 `app/AI/Gemini.php`
```php
<?php
namespace AISEO\AI;

class Gemini {
  public static function summarize(array $summary): string {
    // TODO: call Gemini API with env key; for now return a stub
    $pages = $summary['pages'] ?? 0;
    $issues = $summary['issues']['total'] ?? 0;
    return "This run crawled {$pages} pages. Total issues: {$issues}. Focus on reducing 4xx/5xx and enriching meta descriptions.";
  }
}
```

### 4.2 Embed into report build
After you build `summary.json`:
```php
$exec = \AISEO\AI\Gemini::summarize($sum);
$data['ai_summary'] = $exec;
file_put_contents($dir.'/report.json', json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
```

---

## 5) PDF Export (dompdf)
Add a route `/export-pdf?project=&run=` that renders the existing HTML template and pipes to dompdf.

```php
$html = \AISEO\Templates\ReportRenderer::render($project,$run); // extract your current template logic to a class
$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4','portrait');
$dompdf->render();
$dompdf->stream("{$project}-{$run}.pdf", ['Attachment'=>true]);
exit;
```

---

## 6) Admin: Runs List + Actions
Create a small admin page listing recent runs for a project:

- Columns: Run ID, Started, Pages, 2xx/3xx/4xx/5xx, Issues, Actions (View Report, Export PDF)
- Read from `storage/projects/{project}/timeseries.json`
- Add a “Run Crawl Now” button at the top

---

## 7) Notifications (first pass)
If the latest summary shows an **increase in 4xx/5xx or issues**, write `notifications.json` at project root. Display a red badge in your admin menu when unread notifications exist.

---

## 8) Optional: Respect robots + politeness
In `Worker::process` add delays, domain lock, and robots.txt checks (Spatie Crawler supports robots by default once you integrate it).

---

## 9) Security Hardening
- Move from `key` param to WP Application Passwords or HMAC header (date + signature)
- Validate origin domain if you call from UptimeRobot only

---

## 10) Observability
- Append a small `run.log` inside each run to record steps (`seeded n urls`, `processed`, `finalized`)
- Add `/status?verbose=1` to return recent log tail

---

## Suggested Order of Work
1) Summary + timeseries (Sections 1–2) → immediate value & charts
2) Admin runs list (Section 6)
3) CSV/JSON imports (Section 3)
4) PDF export (Section 5)
5) Gemini stub (Section 4) → real API later
6) Notifications, security, observability (Sections 7–10)

---

**Tell Codex:** “Open `docs/next-steps-run-history-report.md` and implement step 1 now.”
