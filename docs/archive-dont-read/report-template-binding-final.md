# Blackbird SEO Tools — Report Template Binding & Data Sources (Final Spec)

**This spec matches your current setup exactly.**  
- **Frontend template:** `web/app/themes/ai-seo-tool/templates/report.php`  
- **Public URL:** `https://ai-seo-tools.box/report/{slug}` (your router should load `report.php` for this path)  
- **Admin editing:** Report CPT + modular sections under the main editor

It solves two issues you reported:
1) **Sections not connected to JSON data** — provides a single DataLoader that reads all required files and a reference usage inside `templates/report.php`.
2) **AI button not working** — hardened JS + PHP AJAX handler that works in Gutenberg and Classic.

---

## 1) Data files to read (per your list)

For project `{project}` and a selected `{run}` (or latest when none is set), load these:

**Run-scoped**
- `storage/projects/{project}/runs/{run}/summary.json`
- `storage/projects/{project}/runs/{run}/report.json`
- `storage/projects/{project}/runs/{run}/audit.json`
- `storage/projects/{project}/runs/{run}/analytics/ga.json` (optional)
- `storage/projects/{project}/runs/{run}/analytics/gsc.json` (optional)
- `storage/projects/{project}/runs/{run}/analytics/gsc-details.json` (optional)
- `storage/projects/{project}/runs/{run}/backlinks/provider.json` (optional, future)

**Project-scoped**
- `storage/projects/{project}/timeseries.json`
- `storage/projects/{project}/analytics/ga-timeseries.json` (optional)
- `storage/projects/{project}/analytics/gsc-timeseries.json` (optional)

---

## 2) Unified DataLoader (drop-in)

**File:** `app/Helpers/DataLoader.php`

```php
<?php
namespace BBSEO\Helpers;

class DataLoader {
  public static function forReport(string $type, string $project, array $runs = [], string $pageUrl=''): array
  {
    $out = [
      'type'    => $type,
      'project' => $project,
      'runs'    => [],
      'project_scope' => [
        'timeseries'       => self::read(Storage::projectDir($project) . '/timeseries.json'),
        'ga_timeseries'    => self::read(Storage::projectDir($project) . '/analytics/ga-timeseries.json'),
        'gsc_timeseries'   => self::read(Storage::projectDir($project) . '/analytics/gsc-timeseries.json'),
      ],
    ];
    if (!$project) return $out;

    // pick latest if empty
    if (!$runs) {
      $latest = Storage::getLatestRun($project);
      if ($latest) $runs = [$latest];
    }

    foreach ($runs as $run) {
      $dir = Storage::runDir($project, $run);
      $runPack = [
        'run_id'   => $run,
        'summary'  => self::read($dir . '/summary.json') ?: [],
        'report'   => self::read($dir . '/report.json')  ?: [],
        'audit'    => self::read($dir . '/audit.json')   ?: [],
        'analytics'=> [
          'ga'           => self::read($dir . '/analytics/ga.json'),
          'gsc'          => self::read($dir . '/analytics/gsc.json'),
          'gsc_details'  => self::read($dir . '/analytics/gsc-details.json'),
        ],
        'backlinks'=> [
          'provider'     => self::read($dir . '/backlinks/provider.json'),
        ],
        'pages'    => [],
      ];

      // Optional: hydrate a specific page (per_page report)
      if ($type === 'per_page' && $pageUrl) {
        $pf = $dir . '/pages/' . md5($pageUrl) . '.json';
        $runPack['pages'] = is_file($pf) ? [ self::read($pf) ?: [] ] : [];
      }

      $out['runs'][] = $runPack;
    }

    return $out;
  }

  private static function read(string $path){
    if (!is_file($path)) return null;
    $json = file_get_contents($path);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
  }
}
```

> This loader returns **everything you listed**, grouped per run and at project scope. Use only what you need in the template.

---

## 3) Binding inside `templates/report.php`

**File:** `web/app/themes/ai-seo-tool/templates/report.php` (snippet to glue data → UI)

```php
<?php
use BBSEO\Helpers\DataLoader;
use BBSEO\Helpers\Sections;

// Resolve the report post by slug from router
$report = get_queried_object(); // or your router sets $report_id
$post_id = is_object($report) ? $report->ID : get_the_ID();

$project = get_post_meta($post_id, '_bbseo_project_slug', true);
$type    = get_post_meta($post_id, '_bbseo_report_type', true) ?: 'general';
$runs    = json_decode(get_post_meta($post_id, '_bbseo_runs', true) ?: '[]', true) ?: [];
$pageUrl = get_post_meta($post_id, '_bbseo_page', true) ?: '';

// Load unified data set
$data = DataLoader::forReport($type, $project, $runs, $pageUrl);

// Aggregates for header cards
$totalPages = 0; $totalIssues = 0;
foreach ($data['runs'] as $r) {
  $totalPages += intval($r['summary']['pages'] ?? 0);
  $totalIssues += intval($r['summary']['issues']['total'] ?? 0);
}

// Editor fields
$execSummary = get_post_meta($post_id, '_bbseo_summary', true);
$topActions  = json_decode(get_post_meta($post_id, '_bbseo_top_actions', true) ?: '[]', true);

// Sections from DB (order + show respected)
$sections = json_decode(get_post_meta($post_id, Sections::META_SECTIONS, true) ?: '[]', true);
usort($sections, fn($a,$b)=>intval($a['order']??0)<=>intval($b['order']??0));

// Helper for table dashes
$dash = function($v){ return ($v === 0 || $v === '0') ? '0' : ($v ? esc_html($v) : '—'); };
?>
<!-- Your existing HTML here: header, pills, cards, etc. -->
<!-- Then for Key Metrics Snapshot, bind from the *latest run*: -->
<?php $latest = end($data['runs']) ?: []; $s = $latest['summary'] ?? []; ?>
<tr><td>Total Pages Crawled</td><td><?= $dash($s['pages'] ?? null) ?></td></tr>
<tr><td>Indexed Pages (2xx)</td><td><?= $dash($s['status']['2xx'] ?? null) ?></td></tr>
<tr><td>Broken Links (4xx)</td><td><?= $dash($s['status']['4xx'] ?? null) ?></td></tr>
<tr><td>Server Errors (5xx)</td><td><?= $dash($s['status']['5xx'] ?? null) ?></td></tr>
<!-- ...other rows as available in your summary/audit/report payloads -->
```

### Example bindings by section type
- **Performance Summary**  
  - GA Top Pageviews → `$latest['analytics']['ga']['top_pages'][0] ?? null`
  - GSC Best Performing Keyword → `$latest['analytics']['gsc']['top_keywords'][0] ?? null`
  - Crawler Largest Page → compute from `$latest['report']['pages']` (or `pages/*.json` if you store sizes there).

- **Technical SEO Issues**  
  Use `$latest['audit']` if it lists issue buckets; fallback to `summary.status` for 4xx/5xx counts.

- **On-Page SEO & Content**  
  Map issues from `$latest['report']` (e.g., missing titles, metas, h1s); show your default recommendations when values are `null`.

> The template should **not** recompute AI; it **renders what’s saved** in CPT meta and reads metrics from the loader for contextual numbers.

---

## 4) AI button fix (admin) — JS + PHP that match

In the **admin sections UI** (the panel under the editor), include the globals and the working JS.  
Add right after your sections markup:

```php
<script>
window.BBSEOReportPostId = <?php echo (int)$post->ID;?>;
window.BBSEOSectionsNonce = '<?php echo esc_js( wp_create_nonce('bbseo_ai_sections_'.$post->ID) );?>';
</script>
<script>
(function($){
  const $list = $('#bbseo-sections-list');
  if (!$list.length) return;

  function getSettings(){
    let runsVal = $('input[name="bbseo_runs"]').val() || '[]';
    try { JSON.parse(runsVal); } catch(e){ runsVal = '[]'; }
    return {
      type: $('select[name="bbseo_report_type"]').val() || 'general',
      project: $('select[name="bbseo_project_slug"]').val() || '',
      page: $('input[name="bbseo_page"]').val() || '',
      runs: runsVal
    };
  }

  function aiForSection(id){
    const s = getSettings();
    $.post(ajaxurl, {
      action: 'bbseo_sections_generate',
      post_id: window.BBSEOReportPostId,
      section_id: id,
      type: s.type, project: s.project, page: s.page, runs: s.runs,
      _wpnonce: window.BBSEOSectionsNonce
    }).done(function(res){
      if(res && res.success){
        const $wrap = $('.section[data-id="'+id+'"]');
        $wrap.find('textarea[name*="[body]"]').val(res.data.body||'');
        $wrap.find('textarea[name*="[reco_raw]"]').val((res.data.reco_list||[]).join("\n"));
      } else {
        alert('AI failed'); console.warn(res);
      }
    }).fail(function(xhr){ alert('AJAX '+xhr.status); console.error(xhr.responseText); });
  }

  $(document).on('click','.bbseo-ai-one', function(){ aiForSection($(this).data('id')); });
  $('#bbseo-ai-all').on('click', function(){
    $list.find('.section').each(function(){ aiForSection($(this).data('id')); });
  });
})(jQuery);
</script>
```

**Server handler (must match the JS):**

```php
<?php
add_action('wp_ajax_bbseo_sections_generate', function(){
  $postId = intval($_POST['post_id'] ?? 0);
  if (!$postId || !current_user_can('edit_post',$postId)) wp_send_json_error(['msg'=>'perm']);
  check_ajax_referer('bbseo_ai_sections_'.$postId);

  $sectionId = sanitize_text_field($_POST['section_id'] ?? '');
  $type    = sanitize_text_field($_POST['type'] ?? 'general');
  $project = sanitize_text_field($_POST['project'] ?? '');
  $page    = esc_url_raw($_POST['page'] ?? '');
  $runsArr = json_decode(stripslashes($_POST['runs'] ?? '[]'), true) ?: [];

  $data = \BBSEO\Helpers\DataLoader::forReport($type, $project, $runsArr, $page);

  // Call your AI; provide a deterministic fallback so UI shows something
  if (method_exists('\BBSEO\AI\Gemini','summarizeSection')) {
    $resp = \BBSEO\AI\Gemini::summarizeSection($type, $data, $sectionId);
  } else {
    $sum = $data['runs'][0]['summary'] ?? [];
    $resp = [
      'body' => sprintf('Analyzed %d pages with %d total issues.', intval($sum['pages'] ?? 0), intval($sum['issues']['total'] ?? 0)),
      'reco_list' => ['Fix 4xx/5xx', 'Improve titles & metas', 'Add ALT text']
    ];
  }

  if (!is_array($resp)) wp_send_json_error(['msg'=>'ai_failed']);
  wp_send_json_success($resp);
});
```

**Common pitfalls fixed here**
- Nonce creation `wp_create_nonce('bbseo_ai_sections_'.$post->ID)` matches `check_ajax_referer('bbseo_ai_sections_'.$postId)`
- Uses `ajaxurl` in admin; adds console logging on failure.

---
