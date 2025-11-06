# AI SEO Tools — Modular Report Sections (Spec + Implementation Notes)

> Deliverable: **Use this doc as the single source of truth** for implementing modular, reorderable report sections **under the main editor**. No sidebar panels. Store everything as post meta in DB. AI (Gemini) can prefill each section.  
> Audience: your agent (Codex) + developers.

---

## 0) What we’re building (in plain English)

- A **modular section system** for the **Report** CPT (`aiseo_report`).  
- Admin can **add/remove/reorder** sections (drag-and-drop) in a **full-width panel under the main editor**.  
- Sections are **DB-persisted** as a single JSON meta field.  
- The section list is **pre-filled** based on selected **Report Type** (General / Per Page / Technical).  
- Each section may have:
  - `title` (editable),
  - `body` (editable rich text/plain),
  - `reco_list` (editable list of bullets),
  - optional `ai_notes` (internal, if you want to store extra AI outputs).  
- Admin can click **AI** on a section (or **Generate for All**) to prefill `body` + `reco_list` using **Gemini**.  

---

## 1) Data Model (DB fields)

- **Post type:** `aiseo_report` (already defined in your system or from prior step)
- **Meta keys used** (existing + new):
  - `_aiseo_report_type` — `general | per_page | technical`
  - `_aiseo_project_slug` — project slug
  - `_aiseo_page` — URL when `per_page` is selected
  - `_aiseo_runs` — JSON array of run IDs for comparison
  - `_aiseo_summary` — overall executive summary text (freeform)
  - `_aiseo_top_actions` — JSON array of strings (top 3–10 actions)
  - `_aiseo_meta_recos` — JSON array of objects (meta improvements)
  - `_aiseo_tech_findings` — freeform technical notes
  - `_aiseo_snapshot` — JSON of the data snapshot used for AI (optional)
  - **`_aiseo_sections`** — **JSON array of section objects** (NEW; the modular system lives here)

**Section object shape** (stored inside `_aiseo_sections`):
```json
{
  "id": "overview_ab12cd",
  "type": "overview",
  "title": "Overview",
  "body": "Editable narrative…",
  "reco_list": ["Bullet 1","Bullet 2"],
  "order": 10
}
```

---

## 2) Section Registry

A single helper returns the canonical **list of available section types** and the **default order** + **which report types** they apply to. Use this for prefill **and** UI dropdown options.

**File:** `app/Helpers/Sections.php`
```php
<?php
namespace AISEO\Helpers;

class Sections
{
    const META_SECTIONS = '_aiseo_sections';

    public static function registry(): array
    {
        return [
            'overview' => [
                'label' => 'Overview',
                'enabled_for' => ['general','per_page','technical'],
                'order' => 10,
            ],
            'performance_issues' => [
                'label' => 'Performance Issues',
                'enabled_for' => ['general','technical'],
                'order' => 20,
            ],
            'technical_seo' => [
                'label' => 'Technical SEO',
                'enabled_for' => ['general','technical'],
                'order' => 30,
            ],
            'onpage_meta_heading' => [
                'label' => 'On-Page SEO: Meta & Heading Optimization',
                'enabled_for' => ['general','per_page'],
                'order' => 40,
            ],
            'onpage_content_image' => [
                'label' => 'On-Page SEO: Content & Image Optimization',
                'enabled_for' => ['general','per_page'],
                'order' => 50,
            ],
        ];
    }

    public static function defaultsFor(string $type): array
    {
        $items = [];
        foreach (self::registry() as $key => $def) {
            if (in_array($type, $def['enabled_for'], true)) {
                $items[] = [
                    'id'        => uniqid($key.'_'),
                    'type'      => $key,
                    'title'     => $def['label'],
                    'body'      => '',
                    'ai_notes'  => '',
                    'reco_list' => [],
                    'order'     => $def['order'],
                ];
            }
        }
        usort($items, fn($a,$b)=>$a['order']<=>$b['order']);
        return $items;
    }
}
```

**Notes**
- Add new section types here later.  
- Titles can be overridden in the editor by the user.  
- `order` is informational for defaults; the UI uses list order on save.

---

## 3) Admin UI (under main editor)

A full-width panel appears **below** the main editor on a Report edit screen.  
It is **sortable** (drag handle), and includes **Add Section**, **Remove**, **AI** per-section, and **AI for All**.

**File:** `app/Admin/ReportSectionsUI.php`
```php
<?php
namespace AISEO\Admin;

use AISEO\PostTypes\Report;
use AISEO\Helpers\Sections;
use AISEO\Helpers\Storage;

class ReportSectionsUI
{
    public static function boot()
    {
        add_action('edit_form_after_editor', [self::class, 'render']);
        add_action('save_post_' . Report::POST_TYPE, [self::class, 'save'], 10, 3);
        add_action('wp_ajax_aiseo_sections_generate', [self::class,'generate_ai_for_section']);
        add_action('admin_enqueue_scripts', [self::class,'assets']);
    }

    public static function assets($hook)
    {
        // Needed for drag-and-drop
        wp_enqueue_script('jquery-ui-sortable');
    }

    public static function render(\WP_Post $post)
    {
        if ($post->post_type !== Report::POST_TYPE) return;
        wp_nonce_field('aiseo_sections_nonce','aiseo_sections_nonce');

        $type = get_post_meta($post->ID, Report::META_TYPE, true) ?: 'general';

        $json = get_post_meta($post->ID, Sections::META_SECTIONS, true) ?: '';
        $sections = json_decode($json, true);
        if (!is_array($sections)) {
            // First open → prefill from report type
            $sections = Sections::defaultsFor($type);
        }

        $registry = Sections::registry();
        ?>
        <style>
          .aiseo-sections { margin-top: 16px; }
          .aiseo-sections .section { border:1px solid #dcdcdc; border-radius:6px; padding:12px; margin-bottom:10px; background:#fff; }
          .aiseo-sections .section .head { display:flex; align-items:center; justify-content:space-between; }
          .aiseo-sections .section .type { font-weight:600; }
          .aiseo-sections .section .controls button { margin-left:6px; }
          .aiseo-sections .section textarea, .aiseo-sections .section input[type=text] { width:100%; }
          .aiseo-sections .add-row { margin: 12px 0; }
          .aiseo-sections .drag { cursor: move; opacity: .7; }
          .aiseo-sections .reco small { color:#666; }
        </style>

        <div class="postbox aiseo-sections">
          <h2 class="hndle"><span>Report Sections</span></h2>
          <div class="inside">

            <div id="aiseo-sections-list">
              <?php foreach ($sections as $idx => $sec): ?>
                <div class="section" data-id="<?php echo esc_attr($sec['id']);?>">
                  <div class="head">
                    <div class="type">
                      <span class="dashicons dashicons-move drag"></span>
                      <?php echo esc_html($registry[$sec['type']]['label'] ?? ucfirst($sec['type']));?>
                    </div>
                    <div class="controls">
                      <button type="button" class="button aiseo-ai-one" data-id="<?php echo esc_attr($sec['id']);?>">AI</button>
                      <button type="button" class="button button-link-delete aiseo-del" data-id="<?php echo esc_attr($sec['id']);?>">Remove</button>
                    </div>
                  </div>
                  <div class="body">
                    <label>Custom Title</label>
                    <input type="text" name="aiseo_sections[<?php echo $idx;?>][title]" value="<?php echo esc_attr($sec['title']);?>">
                    <input type="hidden" name="aiseo_sections[<?php echo $idx;?>][id]" value="<?php echo esc_attr($sec['id']);?>">
                    <input type="hidden" name="aiseo_sections[<?php echo $idx;?>][type]" value="<?php echo esc_attr($sec['type']);?>">
                    <textarea name="aiseo_sections[<?php echo $idx;?>][body]" rows="5" placeholder="Section narrative (editable)"><?php echo esc_textarea($sec['body']);?></textarea>
                    <div class="reco">
                      <label>Recommendations (one per line)</label>
                      <textarea name="aiseo_sections[<?php echo $idx;?>][reco_raw]" rows="3"><?php echo esc_textarea(implode("\n", $sec['reco_list'] ?? []));?></textarea>
                      <small>Suggestions can be prefilled by AI or edited manually.</small>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="add-row">
              <select id="aiseo-add-type">
                <option value="">Add Section…</option>
                <?php foreach ($registry as $k => $r): ?>
                  <option value="<?php echo esc_attr($k);?>"><?php echo esc_html($r['label']);?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="button" id="aiseo-add-btn">Add</button>
              <button type="button" class="button button-primary" id="aiseo-ai-all">Generate AI for All Sections</button>
            </div>

          </div>
        </div>

        <script>
        (function($){
          // sortable
          $('#aiseo-sections-list').sortable({
            handle: '.drag',
            stop: function() { renumber(); }
          });

          function renumber(){
            $('#aiseo-sections-list .section').each(function(i){
              $(this).find('input, textarea').each(function(){
                const name = $(this).attr('name');
                if(!name) return;
                const n = name.replace(/aiseo_sections\[\d+\]/, 'aiseo_sections['+i+']');
                $(this).attr('name', n);
              });
            });
          }

          $('#aiseo-add-btn').on('click', function(){
            const type = $('#aiseo-add-type').val();
            if(!type) return;
            const label = $('#aiseo-add-type option:selected').text();
            const id = type + '_' + Math.random().toString(36).slice(2,8);
            const idx = $('#aiseo-sections-list .section').length;
            const html = `
              <div class="section" data-id="${id}">
                <div class="head">
                  <div class="type"><span class="dashicons dashicons-move drag"></span>${label}</div>
                  <div class="controls">
                    <button type="button" class="button aiseo-ai-one" data-id="${id}">AI</button>
                    <button type="button" class="button button-link-delete aiseo-del" data-id="${id}">Remove</button>
                  </div>
                </div>
                <div class="body">
                  <label>Custom Title</label>
                  <input type="text" name="aiseo_sections[${idx}][title]" value="${label}">
                  <input type="hidden" name="aiseo_sections[${idx}][id]" value="${id}">
                  <input type="hidden" name="aiseo_sections[${idx}][type]" value="${type}">
                  <textarea name="aiseo_sections[${idx}][body]" rows="5" placeholder="Section narrative (editable)"></textarea>
                  <div class="reco">
                    <label>Recommendations (one per line)</label>
                    <textarea name="aiseo_sections[${idx}][reco_raw]" rows="3"></textarea>
                    <small>Suggestions can be prefilled by AI or edited manually.</small>
                  </div>
                </div>
              </div>`;
            $('#aiseo-sections-list').append(html);
          });

          $(document).on('click','.aiseo-del', function(){
            $(this).closest('.section').remove();
            renumber();
          });

          // AI: one section
          $(document).on('click','.aiseo-ai-one', function(){
            const secId = $(this).data('id');
            aiForSection(secId);
          });

          // AI: all sections
          $('#aiseo-ai-all').on('click', function(){
            $('#aiseo-sections-list .section').each(function(){
              const secId = $(this).data('id');
              aiForSection(secId);
            });
          });

          function aiForSection(secId){
            const form = $(document.forms['post']);
            const type = form.querySelector('select[name=aiseo_report_type]').value;
            const project = form.querySelector('select[name=aiseo_project_slug]').value;
            const page = form.querySelector('input[name=aiseo_page]')?.value || '';
            const runs = form.querySelector('input[name=aiseo_runs]')?.value || '[]';

            $.post(ajaxurl, {
              action: 'aiseo_sections_generate',
              post_id: <?php echo (int)$post->ID;?>,
              section_id: secId,
              type, project, page, runs,
              _wpnonce: '<?php echo wp_create_nonce('aiseo_ai_sections_'.$post->ID);?>'
            }, function(res){
              if(res && res.success){
                const $wrap = $('.section[data-id="'+secId+'"]');
                $wrap.find('textarea[name*="[body]"]').val(res.data.body||'');
                $wrap.find('textarea[name*="[reco_raw]"]').val((res.data.reco_list||[]).join("\n"));
              } else {
                console.warn('AI section failed', res);
              }
            });
          }
        })(jQuery);
        </script>
        <?php
    }

    public static function save($postId, $post, $update)
    {
        if (!isset($_POST['aiseo_sections_nonce']) || !wp_verify_nonce($_POST['aiseo_sections_nonce'],'aiseo_sections_nonce')) return;
        if (!current_user_can('edit_post',$postId)) return;

        $raw = $_POST['aiseo_sections'] ?? null;
        if (!is_array($raw)) return;

        $out = [];
        foreach ($raw as $i => $row) {
            $out[] = [
                'id'        => sanitize_text_field($row['id'] ?? ''),
                'type'      => sanitize_text_field($row['type'] ?? ''),
                'title'     => sanitize_text_field($row['title'] ?? ''),
                'body'      => wp_kses_post($row['body'] ?? ''),
                'reco_list' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($row['reco_raw'] ?? '' ))))),
                'order'     => $i * 10,
            ];
        }
        update_post_meta($postId, Sections::META_SECTIONS, json_encode($out));
    }

    public static function generate_ai_for_section()
    {
        $postId = intval($_POST['post_id'] ?? 0);
        if (!$postId || !current_user_can('edit_post',$postId)) wp_send_json_error(['msg'=>'perm']);
        check_ajax_referer('aiseo_ai_sections_'.$postId);

        const sectionId = sanitize_text_field($_POST['section_id'] ?? '');
        $type    = sanitize_text_field($_POST['type'] ?? 'general');
        $project = sanitize_text_field($_POST['project'] ?? '');
        $page    = esc_url_raw($_POST['page'] ?? '');
        $runsArr = json_decode(stripslashes($_POST['runs'] ?? '[]'), true) ?: [];

        $data = \AISEO\Helpers\DataLoader::forReport($type, $project, $runsArr, $page);
        $bodyReco = \AISEO\AI\Gemini::summarizeSection($type, $data, $sectionId);
        if (!is_array($bodyReco)) wp_send_json_error(['msg'=>'ai_failed']);

        wp_send_json_success($bodyReco);
    }
}
```
> **Note:** Ensure you already have `Report` CPT + base fields and `DataLoader::forReport()` in place (from previous steps).

---

## 4) AI hooks (Gemini stubs)

Extend your Gemini helper with a section-specific method. You will later replace logic with actual Gemini API calls + prompts.

**File:** `app/AI/Gemini.php` (add this method)
```php
<?php
namespace AISEO\AI;

class Gemini {
  public static function summarizeSection(string $type, array $data, string $sectionId): array {
    $label = '';
    if (strpos($sectionId,'overview_')===0) $label = 'Overview';
    elseif (strpos($sectionId,'performance_')===0) $label = 'Performance Issues';
    elseif (strpos($sectionId,'technical_')===0) $label = 'Technical SEO';
    elseif (strpos($sectionId,'onpage_meta_heading_')===0) $label = 'Meta & Heading Optimization';
    elseif (strpos($sectionId,'onpage_content_image_')===0) $label = 'Content & Image Optimization';
    else $label = 'Section';

    $runs = $data['runs'] ?? [];
    $issuesTotal = 0; $s4=0; $s5=0; $pages=0;
    foreach ($runs as $r) {
      $sum = $r['summary'] ?? [];
      $issuesTotal += intval($sum['issues']['total'] ?? 0);
      $s4 += intval($sum['status']['4xx'] ?? 0);
      $s5 += intval($sum['status']['5xx'] ?? 0);
      $pages += intval($sum['pages'] ?? 0);
    }

    $body = "{$label}: Analyzed {$pages} pages across ".count($runs)." run(s). Total issues: {$issuesTotal}. 4xx: {$s4}, 5xx: {$s5}. ";
    if ($label === 'Meta & Heading Optimization') {
      $body .= "Ensure titles ~55 chars, H1 present once, and unique meta descriptions per page.";
    } elseif ($label === 'Content & Image Optimization') {
      $body .= "Improve content depth, internal linking, and add descriptive ALT text for images.";
    } elseif ($label === 'Technical SEO') {
      $body .= "Validate canonicals, robots directives, sitemap coverage, and fix crawl errors.";
    }

    $reco = [
      "Prioritize fixing 4xx/5xx pages to restore crawl health",
      "Standardize title length and improve meta description quality",
      "Add ALT text and compress large images",
    ];

    return ['body'=>$body, 'reco_list'=>$reco];
  }
}
```
**Notes for real AI integration**
- Prompt should include: project name, selected runs’ summaries, and (for per_page) parsed page fields.
- Add guardrails: token limit, truncation of large datasets, top-N issues only.
- Consider caching AI outputs to avoid re-billing on every click.

---

## 5) Wiring Checklist

- [ ] Ensure `Report` CPT + base meta fields already exist (from your previous step).  
- [ ] Add files: `Sections.php`, `ReportSectionsUI.php`; extend `Gemini.php`.  
- [ ] Add bootstrap line:  
  ```php
  add_action('init', ['AISEO\Admin\ReportSectionsUI', 'boot']);
  ```
- [ ] Verify jQuery UI Sortable loads in admin (no CSP issues).  
- [ ] On **new Report**, sections auto-populate based on `type`.  
- [ ] **Save** preserves order and fields to `_aiseo_sections` as JSON.  
- [ ] **AI (per-section/All)** triggers AJAX → fills body + reco list.  
- [ ] Ensure `DataLoader::forReport(...)` exists and works.  

---

## 6) Rendering (front-end/PDF) — when you’re ready

- Read `_aiseo_sections` (JSON) → sort by `order` (or use array order).  
- Loop sections and render `title`, `body`, and `reco_list` as bullet points.  
- Respect user edits (do not recompute at render).  
- For PDF: pipe the rendered HTML to dompdf like your report template.

---

## 7) Security & Permissions

- All saves are behind `edit_post` checks + nonces for:
  - `aiseo_sections_nonce` (form submit)
  - `aiseo_ai_sections_{postId}` (AJAX)
- Sanitization:
  - `title`, `type`, `id` via `sanitize_text_field()`
  - `body` via `wp_kses_post()`
  - `reco_list` each line trimmed; you can also `wp_kses_post()` if you allow formatting

---

## 8) Performance Notes

- Sections are small JSON; post meta is OK.  
- For very large reports, you may split into multiple rows (e.g., `_aiseo_sections_1`, `_aiseo_sections_2`), but not needed now.  
- Debounce “AI for All” if your real Gemini calls are slow; show a progress toast per section.

---

## 9) Admin UX Tips

- Keep this panel **under** the main editor to maximize space and reduce sidebar clutter.  
- Consider a **compact mode** toggle if the list grows long.  
- Later: add “Duplicate section” to reuse a filled module.

---

## 10) Done-Definition / Acceptance Tests

- [ ] New Report (General type) → defaults show Overview, Performance, Technical, On-Page sections.  
- [ ] Drag sections to reorder → Save → order persists.  
- [ ] Add a new section from dropdown → Save and persist.  
- [ ] Remove a section → Save and confirm it disappears.  
- [ ] Click **AI** on one section → body + reco list fill sensibly.  
- [ ] Click **AI for All** → fills all visible sections.  
- [ ] Switching Report Type on an existing report does **not** overwrite sections automatically (no surprises). Admin can manually adjust via Add/Remove.  
- [ ] Front-end/PDF renderer respects section order and edited content.

---

## 11) What to tell Codex

> Read `docs/report-modular-sections-spec.md`.  
> Implement `app/Helpers/Sections.php`, `app/Admin/ReportSectionsUI.php`, and extend `app/AI/Gemini.php` with `summarizeSection()`.  
> Wire with `add_action('init', ['AISEO\Admin\ReportSectionsUI', 'boot']);`  
> Keep the fields under the main editor, not in a sidebar.  
> Use jQuery UI Sortable for ordering.  
> Save to `_aiseo_sections` exactly as specified.
