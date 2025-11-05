# AI SEO Tools – Run-based Scheduler & Storage Refactor

## Goal
Refactor storage and scheduler so every crawl **run** is isolated in its own folder (created when user presses Start or when auto-start triggers).  
Each run contains its own `queue/` (todo/done) and `pages/`.  
A **1-minute cron** drains the queue quickly.  
Manual and scheduled runs share the same logic.

---

## Folder Structure
```
/web/app/themes/ai-seo-tool/storage/projects/{project}/
  runs/
    {run_id}/
      queue/
        <md5(url)>.todo|.done
      pages/
        <md5(url)>.json
      audit.json
      report.json
  config.json
  latest_run.txt
```
Each `run_id` folder is created when `start-crawl` is called manually or by scheduler.

---

## Helper: RunId.php
```php
namespace AISEO\Helpers;

class RunId {
  public static function new(): string {
    $ts = gmdate('Y-m-d_His');
    $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    return "{$ts}_{$rand}";
  }
}
```

---

## Storage Helpers Update
Add to `AISEO\Helpers\Storage`:
```php
public static function runsDir(string $project): string
public static function runDir(string $project, string $runId): string
public static function ensureRun(string $project, string $runId): array
public static function setLatestRun(string $project, string $runId): void
public static function getLatestRun(string $project): ?string
```
These ensure directories exist and track latest run ID.

---

## REST API Changes

### `/start-crawl` (POST)
- Input: `project`, `urls[]`, optional `run_id`
- Behavior:
  - Create new `run_id` if not given
  - Create `runs/{run_id}/queue` and seed `.todo` files
  - Save `latest_run.txt`
  - Return `{ run_id, queued }`

### `/crawl-step` (GET)
- Input: `project`, optional `run`
- Process 1 `.todo` file inside `runs/{run}/queue`
- When queue empty → auto-run `audit` and `report` for that run

### `/status` (GET)
- Input: `project`, optional `run`
- Output counts for the given run

### `/audit` + `/report` (POST)
- Input: `project`, optional `run`
- Runs audit or report generation in that run

---

## Queue and Worker Update
### Queue.php
```php
Queue::init($project, $urls, $runId);
Queue::next($project, $runId);
```
### Worker.php
```php
Worker::process($project, $runId);
```
Store results under `runs/{runId}/pages`.

---

## Audit & Report Update
Operate under `runs/{runId}/` and output results in same folder.

---

## Scheduler (1-minute Cron)

### Add custom schedule
```php
add_filter('cron_schedules', function($s){
  $s['every_minute'] = ['interval'=>60,'display'=>'Every Minute'];
  return $s;
});

add_action('init', function(){
  if(!wp_next_scheduled('aiseo_minutely_drain')){
    wp_schedule_event(time()+60,'every_minute','aiseo_minutely_drain');
  }
});
```

### Drain handler
```php
add_action('aiseo_minutely_drain', function(){
  if(getenv('AISEO_DISABLE_CRON')==='true') return;

  $base = \AISEO\Helpers\Storage::baseDir();
  foreach(glob($base.'/*/config.json') as $cfgPath){
    $project = basename(dirname($cfgPath));
    $cfg = json_decode(file_get_contents($cfgPath), true) ?: [];
    if(!($cfg['enabled'] ?? true)) continue;

    $run = \AISEO\Helpers\Storage::getLatestRun($project);
    if(!$run || aiseo_should_start_new_run($project,$cfg)){
      $urls = $cfg['seed_urls'] ?? [];
      if($urls){
        $run = \AISEO\Helpers\RunId::new();
        \AISEO\Crawl\Queue::init($project,$urls,$run);
        \AISEO\Helpers\Storage::setLatestRun($project,$run);
      }
    }

    $steps = intval(getenv('AISEO_STEPS_PER_TICK') ?: 50);
    for($i=0;$i<$steps;$i++){
      $next = \AISEO\Crawl\Queue::next($project,$run);
      if(!$next) break;
      \AISEO\Crawl\Worker::process($project,$run);
    }

    if(!glob(\AISEO\Helpers\Storage::runDir($project,$run).'/queue/*.todo')){
      \AISEO\Audit\Runner::run($project,$run);
      \AISEO\Report\Builder::build($project,$run);
    }
  }
});
```

---

## Frequency Helper
```php
function aiseo_should_start_new_run(string $project, array $cfg): bool {
  $freq = $cfg['frequency'] ?? 'manual';
  if($freq==='manual') return false;

  $latest = \AISEO\Helpers\Storage::getLatestRun($project);
  if(!$latest) return true;

  $metaPath = \AISEO\Helpers\Storage::runDir($project,$latest).'/meta.json';
  $meta = file_exists($metaPath)?json_decode(file_get_contents($metaPath),true):[];
  $started = isset($meta['started_at'])?strtotime($meta['started_at']):0;
  if(!$started) return true;

  if($freq==='weekly')  return (time()-$started)>=7*86400;
  if($freq==='monthly') return (date('Ym',$started)!==date('Ym'));
  return false;
}
```

---

## Config Example
`config.json`
```json
{
  "enabled": true,
  "frequency": "weekly",
  "seed_urls": [
    "https://example.com/",
    "https://example.com/about"
  ]
}
```

---

## Testing Commands
```bash
# Start manual run
curl -X POST "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/start-crawl?project=demo&key=YOURTOKEN"   -H 'Content-Type: application/json'   -d '{"urls":["https://example.com/","https://example.com/about"]}'

# Process step
curl "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/crawl-step?project=demo&run=RUN_ID&key=YOURTOKEN"

# Status
curl "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/status?project=demo&run=RUN_ID&key=YOURTOKEN"
```

---

## Notes
- Each run has isolated queue and result files.
- `latest_run.txt` tracks the active run.
- Scheduler runs every minute to process quickly.
- Manual start or weekly/monthly auto-start both create new run folders.
