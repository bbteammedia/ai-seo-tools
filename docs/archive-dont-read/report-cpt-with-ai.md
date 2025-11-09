# Blackbird SEO Tools — Reports as CPT (DB-Persisted) with AI Summary

This adds a **Report** custom post type that stores report data in the database. Reports support multiple **types**, selection of **project** (and optional page / runs), and an **admin button** to generate AI summaries that populate editable custom fields.

---

## 1) CPT Registration

**File:** `app/PostTypes/Report.php`
```php
<?php
namespace BBSEO\PostTypes;

class Report {
    const POST_TYPE = 'BBSEO_report';

    // Meta keys
    const META_TYPE      = '_BBSEO_report_type';      // general|per_page|technical
    const META_PROJECT   = '_BBSEO_project_slug';
    const META_PAGE      = '_BBSEO_page';             // URL or page id
    const META_RUNS      = '_BBSEO_runs';             // array of run_ids (for compare)
    const META_SUMMARY   = '_BBSEO_summary';          // main executive summary
    const META_ACTIONS   = '_BBSEO_top_actions';      // bullet list (JSON string)
    const META_META_RECO = '_BBSEO_meta_recos';       // meta updates (JSON string)
    const META_TECH      = '_BBSEO_tech_findings';    // notes
    const META_SNAPSHOT  = '_BBSEO_snapshot';         // serialized current data snapshot (JSON string)

    public static function register() {
        add_action('init', [self::class, 'cpt']);
        add_action('init', [self::class, 'register_meta']);
    }

    public static function cpt() {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'          => 'Reports',
                'singular_name' => 'Report',
                'add_new_item'  => 'Add New Report',
                'edit_item'     => 'Edit Report',
            ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-media-document',
            'supports' => ['title', 'editor'], // editor optional; we store fields in meta
            'show_in_rest' => true,
        ]);
    }

    public static function register_meta() {
        $metas = [
            self::META_TYPE, self::META_PROJECT, self::META_PAGE, self::META_RUNS,
            self::META_SUMMARY, self::META_ACTIONS, self::META_META_RECO,
            self::META_TECH, self::META_SNAPSHOT
        ];
        foreach ($metas as $m) {
            register_post_meta(self::POST_TYPE, $m, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => function() { return current_user_can('edit_posts'); }
            ]);
        }
    }
}
```
Add to theme bootstrap (e.g., `functions.php`):
```php
add_action('init', ['BBSEO\\PostTypes\\Report', 'register']);
```

---

## 2) Admin UI (Metabox + Controls)

**File:** `app/Admin/ReportMetaBox.php`
```php
<?php
namespace BBSEO\Admin;
use BBSEO\PostTypes\Report;
use BBSEO\Helpers\Storage;

class ReportMetaBox {
    public static function boot() {
        add_action('add_meta_boxes', [self::class,'add']);
        add_action('save_post_' . Report::POST_TYPE, [self::class,'save'], 10, 3);
        add_action('admin_enqueue_scripts', [self::class,'assets']);
        add_action('wp_ajax_BBSEO_generate_summary', [self::class,'generate_summary_ajax']);
    }

    public static function add() {
        add_meta_box('BBSEO_report_settings','Report Settings',[self::class,'render'], Report::POST_TYPE,'normal','high');
    }

    public static function render(\WP_Post $post) {
        wp_nonce_field('BBSEO_report_nonce','BBSEO_report_nonce');
        $type    = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($post->ID, Report::META_PROJECT, true) ?: '';
        $page    = get_post_meta($post->ID, Report::META_PAGE, true) ?: '';
        $runs    = json_decode(get_post_meta($post->ID, Report::META_RUNS, true) ?: '[]', true) ?: [];

        $summary = get_post_meta($post->ID, Report::META_SUMMARY, true) ?: '';
        $actions = json_decode(get_post_meta($post->ID, Report::META_ACTIONS, true) ?: '[]', true) ?: [];
        $metaRec = json_decode(get_post_meta($post->ID, Report::META_META_RECO, true) ?: '[]', true) ?: [];
        $tech    = get_post_meta($post->ID, Report::META_TECH, true) ?: '';

        // Projects from storage/projects/*
        $projects = [];
        foreach (glob(Storage::baseDir().'/*', GLOB_ONLYDIR) as $dir) $projects[] = basename($dir);

        ?>
        <style>.BBSEO-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.full{grid-column:1/-1}</style>
        <div class="BBSEO-grid">
          <div>
            <label><strong>Report Type</strong></label><br/>
            <select name="BBSEO_report_type">
              <option value="general"   <?php selected($type,'general');?>>Website General SEO Audit</option>
              <option value="per_page"  <?php selected($type,'per_page');?>>Website SEO Audit per Page</option>
              <option value="technical" <?php selected($type,'technical');?>>Technical SEO</option>
            </select>
          </div>
          <div>
            <label><strong>Project</strong></label><br/>
            <select name="BBSEO_project_slug" id="BBSEO_project_slug">
              <option value="">— select —</option>
              <?php foreach($projects as $p): ?>
                <option value="<?php echo esc_attr($p);?>" <?php selected($project,$p);?>><?php echo esc_html($p);?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="full" id="BBSEO_per_page_row" style="<?php echo $type==='per_page'?'':'display:none';?>">
            <label><strong>Page URL</strong></label><br/>
            <input type="url" class="widefat" name="BBSEO_page" value="<?php echo esc_attr($page);?>" placeholder="https://example.com/page" />
            <p class="description">For per-page reports, specify the target page URL.</p>
          </div>

          <div class="full">
            <label><strong>Runs (for compare)</strong></label>
            <input type="text" class="widefat" name="BBSEO_runs" value="<?php echo esc_attr(json_encode($runs));?>" placeholder='["RUN_ID_A","RUN_ID_B"]' />
            <p class="description">Leave empty to use latest run. For General/Technical you can add multiple run IDs to compare.</p>
          </div>

          <div class="full">
            <button type="button" class="button button-primary" id="BBSEO-generate-ai">Generate AI Summary</button>
            <span id="BBSEO-ai-status" style="margin-left:8px;"></span>
          </div>

          <div class="full">
            <label><strong>Executive Summary</strong></label>
            <textarea class="widefat" name="BBSEO_summary" rows="6"><?php echo esc_textarea($summary);?></textarea>
          </div>

          <div class="full">
            <label><strong>Top Actions (JSON array of strings)</strong></label>
            <textarea class="widefat" name="BBSEO_top_actions" rows="4"><?php echo esc_textarea(json_encode($actions, JSON_PRETTY_PRINT));?></textarea>
          </div>

          <div class="full">
            <label><strong>Meta Recommendations (JSON array of objects)</strong></label>
            <textarea class="widefat" name="BBSEO_meta_recos" rows="4"><?php echo esc_textarea(json_encode($metaRec, JSON_PRETTY_PRINT));?></textarea>
            <p class="description">Example: [{"url":"...","title":"...","meta_description":"..."}]</p>
          </div>

          <div class="full">
            <label><strong>Technical Findings</strong></label>
            <textarea class="widefat" name="BBSEO_tech_findings" rows="4"><?php echo esc_textarea($tech);?></textarea>
          </div>
        </div>

        <script>
        (function($){
          $('#BBSEO_project_slug, select[name=BBSEO_report_type]').on('change', function(){
            const type = $('select[name=BBSEO_report_type]').val();
            if(type==='per_page') $('#BBSEO_per_page_row').show(); else $('#BBSEO_per_page_row').hide();
          });
          $('#BBSEO-generate-ai').on('click', function(e){
            e.preventDefault();
            $('#BBSEO-ai-status').text('Generating...');
            $.post(ajaxurl, {
              action: 'BBSEO_generate_summary',
              post_id: <?php echo (int)$post->ID;?>,
              _wpnonce: '<?php echo wp_create_nonce('BBSEO_ai_nonce_'.$post->ID);?>'
            }, function(res){
              if(res && res.success){
                $('textarea[name=BBSEO_summary]').val(res.data.summary||'');
                $('textarea[name=BBSEO_top_actions]').val(JSON.stringify(res.data.actions||[], null, 2));
                $('textarea[name=BBSEO_meta_recos]').val(JSON.stringify(res.data.meta_rec||[], null, 2));
                $('textarea[name=BBSEO_tech_findings]').val(res.data.tech||'');
                $('#BBSEO-ai-status').text('Done.');
              } else {
                $('#BBSEO-ai-status').text('Failed.');
              }
            });
          });
        })(jQuery);
        </script>
        <?php
    }

    public static function assets($hook){
        // enqueue admin assets if needed later
    }

    public static function save($postId, $post, $update) {
        if (!isset($_POST['BBSEO_report_nonce']) || !wp_verify_nonce($_POST['BBSEO_report_nonce'],'BBSEO_report_nonce')) return;
        if (!current_user_can('edit_post',$postId)) return;

        update_post_meta($postId, Report::META_TYPE, sanitize_text_field($_POST['BBSEO_report_type'] ?? 'general'));
        update_post_meta($postId, Report::META_PROJECT, sanitize_text_field($_POST['BBSEO_project_slug'] ?? ''));
        update_post_meta($postId, Report::META_PAGE, esc_url_raw($_POST['BBSEO_page'] ?? ''));

        $runs = json_decode(stripslashes($_POST['BBSEO_runs'] ?? '[]'), true);
        update_post_meta($postId, Report::META_RUNS, json_encode(is_array($runs)?$runs:[]));

        update_post_meta($postId, Report::META_SUMMARY, wp_kses_post($_POST['BBSEO_summary'] ?? ''));

        $actions = json_decode(stripslashes($_POST['BBSEO_top_actions'] ?? '[]'), true);
        update_post_meta($postId, Report::META_ACTIONS, json_encode(is_array($actions)?$actions:[]));

        $metaRec = json_decode(stripslashes($_POST['BBSEO_meta_recos'] ?? '[]'), true);
        update_post_meta($postId, Report::META_META_RECO, json_encode(is_array($metaRec)?$metaRec:[]));

        update_post_meta($postId, Report::META_TECH, wp_kses_post($_POST['BBSEO_tech_findings'] ?? ''));
    }

    public static function generate_summary_ajax() {
        $postId = intval($_POST['post_id'] ?? 0);
        if (!$postId || !current_user_can('edit_post',$postId)) wp_send_json_error(['msg'=>'perm']);

        check_ajax_referer('BBSEO_ai_nonce_'.$postId);

        $type    = get_post_meta($postId, Report::META_TYPE, true) ?: 'general';
        $project = get_post_meta($postId, Report::META_PROJECT, true) ?: '';
        $page    = get_post_meta($postId, Report::META_PAGE, true) ?: '';
        $runs    = json_decode(get_post_meta($postId, Report::META_RUNS, true) ?: '[]', true) ?: [];

        // Load data from storage
        $data = \BBSEO\Helpers\DataLoader::forReport($type, $project, $runs, $page);

        // Call AI (stub now)
        $resp = \BBSEO\AI\Gemini::summarizeReport($type, $data);

        // Save to meta (editable)
        update_post_meta($postId, Report::META_SUMMARY, $resp['summary'] ?? '');
        update_post_meta($postId, Report::META_ACTIONS, json_encode($resp['actions'] ?? []));
        update_post_meta($postId, Report::META_META_RECO, json_encode($resp['meta_rec'] ?? []));
        update_post_meta($postId, Report::META_TECH, $resp['tech'] ?? '');
        update_post_meta($postId, Report::META_SNAPSHOT, json_encode($data));

        wp_send_json_success($resp);
    }
}
```
Add to bootstrap:
```php
add_action('init', ['BBSEO\\Admin\\ReportMetaBox', 'boot']);
```

---

## 3) Data Loader

**File:** `app/Helpers/DataLoader.php`
```php
<?php
namespace BBSEO\\Helpers;

class DataLoader {
    public static function forReport(string $type, string $project, array $runs, string $pageUrl=''): array
    {
        $base = Storage::projectDir($project);
        $result = ['type'=>$type,'project'=>$project,'runs'=>[]];

        $useRuns = $runs;
        if (!$useRuns) {
            $latest = Storage::getLatestRun($project);
            if ($latest) $useRuns = [$latest];
        }

        foreach ($useRuns as $run) {
            $dir = Storage::runDir($project, $run);
            $summary = self::read($dir.'/summary.json');
            $audit   = self::read($dir.'/audit.json');
            $report  = self::read($dir.'/report.json');
            $pages   = [];

            if ($type==='per_page' && $pageUrl) {
                $p = $dir.'/pages/'.md5($pageUrl).'.json';
                $pages = is_file($p) ? [self::read($p)] : [];
            } else {
                foreach (glob($dir.'/pages/*.json') as $f) $pages[] = self::read($f);
            }

            $result['runs'][] = [
                'run_id'  => $run,
                'summary' => $summary,
                'audit'   => $audit,
                'report'  => $report,
                'pages'   => $pages,
            ];
        }
        return $result;
    }

    private static function read($path){
        return is_file($path) ? json_decode(file_get_contents($path), true) : null;
    }
}
```

---

## 4) Gemini Stub

**File:** `app/AI/Gemini.php` (extend)
```php
<?php
namespace BBSEO\\AI;

class Gemini {
  public static function summarizeReport(string $type, array $data): array {
    // TODO: call Gemini API. For now, compose a simple stub.
    $runs = $data['runs'] ?? [];
    $pagesCount = 0; $issuesTotal = 0; $s4=0; $s5=0;

    foreach ($runs as $r) {
      $summary = $r['summary'] ?? [];
      $pagesCount += intval($summary['pages'] ?? count($r['pages'] ?? []));
      $issuesTotal += intval($summary['issues']['total'] ?? 0);
      $s4 += intval($summary['status']['4xx'] ?? 0);
      $s5 += intval($summary['status']['5xx'] ?? 0);
    }

    $summaryText = "Analyzed {$pagesCount} pages across ".count($runs)." run(s). Total issues: {$issuesTotal}. 4xx: {$s4}, 5xx: {$s5}.";
    if ($type==='per_page') $summaryText .= " Focus on the selected page’s title length, meta description quality, and internal links.";

    $actions = [
      "Fix 4xx/5xx pages and re-crawl until clean",
      "Ensure <title> is 50–60 chars and unique per page",
      "Write compelling meta descriptions (110–155 chars)",
      "Add descriptive ALT text on images",
    ];

    $metaRec = [];
    if ($type==='per_page' && !empty($runs[0]['pages'][0])) {
      $p = $runs[0]['pages'][0];
      $metaRec[] = [
        'url' => $p['url'] ?? '',
        'title' => $p['title'] ? substr($p['title'],0,60) : '',
        'meta_description' => $p['meta_description'] ? substr($p['meta_description'],0,150) : '',
      ];
    }

    $tech = "Check canonical tags, robots directives, and ensure JSON-LD is valid. Consider sitemaps and proper 301 redirects.";

    return [
      'summary' => $summaryText,
      'actions' => $actions,
      'meta_rec'=> $metaRec,
      'tech'    => $tech,
    ];
  }
}
```

---

## 5) Wire Up

Add to `functions.php` or theme bootstrap:
```php
// CPT + Meta
add_action('init', ['BBSEO\\PostTypes\\Report', 'register']);
// Metabox + Generate button
add_action('init', ['BBSEO\\Admin\\ReportMetaBox', 'boot']);
```

This gives you:
- **CPT “Reports”** in wp-admin
- **Metabox** to select type, project, (optional page), runs (for compare), and editable summary fields
- **Generate AI Summary** button that pulls data from storage (per run) and fills meta fields
- All stored in the **database** as post meta — users can edit freely

---

## 6) Notes / Next
- Convert the **admin table view** to show reports list per project and quick actions.
- Add a **Frontend/Export template** to render a report post (or PDF via dompdf).
- Replace the Gemini stub with a real API call using your `GEMINI_API_KEY`.
