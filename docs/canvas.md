# AI SEO Tools – Kickoff Scaffold

A practical, copy‑pasteable starting point to spin up **WordPress Bedrock** + a custom theme **`ai-seo-tool`** with REST endpoints and a JSON storage queue.

---

## 0) Prerequisites

* PHP 8.1+
* Composer 2+
* Node 18+ (optional, for frontend bundling later)
* Web server vhost pointing to **`bedrock/web/`**

---

## 1) Create Bedrock project

```bash
composer create-project roots/bedrock ai-seo-tools
cd ai-seo-tools
```

> TLS verification defaults to WordPress’ bundled CA file. Set `AISEO_HTTP_VERIFY_TLS=false` in `.env` (or point it to a custom bundle path) for local dev environments with self-signed certs.

### Update `.env`

Duplicate `.env.example` → `.env`, then fill:

```env
# Basic WP
WP_ENV=development
WP_HOME=https://ai-seo-tools.local
WP_SITEURL=${WP_HOME}/wp

# DB (Bedrock still requires a DB for WP core; app stores data as JSON)
DB_NAME=ai_seo_tools
DB_USER=root
DB_PASSWORD=
DB_HOST=127.0.0.1

# Security (generate with `php -r 'echo bin2hex(random_bytes(32));'`)
AUTH_KEY=...
SECURE_AUTH_KEY=...
LOGGED_IN_KEY=...
NONCE_KEY=...
AUTH_SALT=...
SECURE_AUTH_SALT=...
LOGGED_IN_SALT=...
NONCE_SALT=...

# App‑specific
AISEO_SECURE_TOKEN=change-me
AISEO_STORAGE_DIR=${WP_CONTENT_DIR}/themes/ai-seo-tool/storage/projects
GEMINI_API_KEY=your-gemini-key
```

---

## 2) Install Composer packages (root)

```bash
composer require spatie/crawler symfony/dom-crawler guzzlehttp/guzzle dompdf/dompdf vlucas/phpdotenv
composer require symfony/css-selector
```

> Bedrock already uses Dotenv; we also keep `vlucas/phpdotenv` to allow theme-level `.env` usage if needed later.

---

## 3) Create theme scaffold

Create **`web/app/themes/ai-seo-tool/`** with the following structure:

```
ai-seo-tool/
├── style.css
├── index.php
├── functions.php
├── composer.json
├── templates/
│   └── report.php
├── app/
│   ├── Cron/
│   │   └── Scheduler.php
    ├── Admin/
	└── Dashboard.php
│   ├── PostTypes/Project.php
│   ├── Template/Report.php
│   ├── Rest/Routes.php
│   ├── Helpers/Storage.php
│   ├── Helpers/Http.php
│   ├── Helpers/RunId.php
│   ├── Crawl/Queue.php
│   ├── Crawl/Worker.php
│   ├── Crawl/Observer.php
│   ├── Crawl/Profile.php
│   ├── Audit/Runner.php
│   ├── Report/Builder.php
│   └── AI/Gemini.php
├── cron/
│   └── README.md
└── storage/
    └── projects/.gitignore
```

### `style.css`

```css
/*
Theme Name: AI SEO Tool
Author: Adi & Blackbird
Version: 0.1.0
*/
```

### `composer.json` (inside the theme)

```json
{
  "name": "aiseo/tool-theme",
  "type": "wordpress-theme",
  "autoload": {
    "psr-4": {
      "AISEO\\": "app/"
    }
  },
  "require": {
    "guzzlehttp/guzzle": "^7.10",
    "spatie/crawler": "^8.4",
    "symfony/dom-crawler": "^7.3",
    "symfony/css-selector": "^7.3"
  }
}
```

Run in **project root** to dump autoload for theme as well:

```bash
composer dump-autoload
```

Then install the theme-specific dependencies:

```bash
cd web/app/themes/ai-seo-tool && composer install
```

### `functions.php`

```php
<?php
// Ensure theme classes autoload
require_once __DIR__ . '/vendor/autoload.php';

// Register REST routes
add_action('rest_api_init', [\AISEO\Rest\Routes::class, 'register']);

// Custom post type for projects
add_action('init', [\AISEO\PostTypes\Project::class, 'register']);

// Front-end reporting route
\AISEO\Template\Report::register();

add_action('after_switch_theme', function () {
    \AISEO\Template\Report::register();
    flush_rewrite_rules();
});

// Ensure storage base dir exists on theme load
add_action('after_setup_theme', function () {
    $dir = getenv('AISEO_STORAGE_DIR') ?: get_theme_file_path('storage/projects');
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
});
```

### `index.php`

```php
<?php
/**
 * Minimal fallback index template for the AI SEO Tool theme.
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
  <main class="container py-5">
    <h1 class="mb-3"><?php bloginfo('name'); ?></h1>
    <p>This theme focuses on REST endpoints and the /ai-seo-report/{project} route. Add content here or create custom templates as needed.</p>
  </main>
  <?php wp_footer(); ?>
</body>
</html>
```

---

## 4) Implement helpers

### `app/Helpers/Http.php`

```php
<?php
namespace AISEO\Helpers;

class Http
{
    public static function ok($data = [], int $code = 200)
    {
        return new \WP_REST_Response([
            'status' => 'ok',
            'data' => $data,
        ], $code);
    }

    public static function fail(string $message, int $code = 400, array $extra = [])
    {
        return new \WP_REST_Response([
            'status' => 'error',
            'message' => $message,
            'extra' => $extra,
        ], $code);
    }

    public static function validate_token($request): bool
    {
        $token = $request->get_param('key');
        $expected = getenv('AISEO_SECURE_TOKEN') ?: '';
        return $expected && hash_equals($expected, (string)$token);
    }
}
```

### `app/Helpers/Storage.php`

```php
<?php
namespace AISEO\Helpers;

class Storage
{
    public static function baseDir(): string
    {
        $dir = getenv('AISEO_STORAGE_DIR');
        return $dir ?: get_theme_file_path('storage/projects');
    }

    public static function projectDir(string $slug): string
    {
        return self::baseDir() . '/' . sanitize_title($slug);
    }

    public static function ensureProject(string $slug): array
    {
        $base = self::projectDir($slug);
        $dirs = [
            $base,
            $base . '/queue',
            $base . '/pages',
        ];
        foreach ($dirs as $d) if (!is_dir($d)) wp_mkdir_p($d);
        return ['base' => $base, 'queue' => $base.'/queue', 'pages' => $base.'/pages'];
    }

    public static function writeJson(string $path, $data): bool
    {
        return (bool) file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }

    public static function readJson(string $path, $default = [])
    {
        return file_exists($path) ? json_decode(file_get_contents($path), true) : $default;
    }
}
```

---

## 5) Crawl modules

### `app/Crawl/Queue.php`

```php
<?php
namespace AISEO\Crawl;

use AISEO\Helpers\Storage;

class Queue
{
    public static function init(string $project, array $urls, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $qdir = $dirs['queue'];
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));

        foreach (glob($qdir . '/*.todo') as $f) {
            @unlink($f);
        }
        foreach (glob($qdir . '/*.done') as $f) {
            @unlink($f);
        }

        Storage::setLatestRun($project, $runId);

        $metaPath = $dirs['base'] . '/meta.json';
        $meta = [
            'run_id' => $runId,
            'project' => $project,
            'started_at' => gmdate('c'),
            'seed_urls' => $urls,
            'completed_at' => null,
        ];
        Storage::writeJson($metaPath, $meta);

        $added = self::enqueue($project, $urls, $runId);
        return ['queued' => $added, 'run_id' => $runId];
    }

    public static function next(string $project, string $runId): ?string
    {
        $qdir = Storage::runDir($project, $runId) . '/queue';
        $todos = glob($qdir . '/*.todo');
        return $todos ? $todos[0] : null;
    }

    public static function enqueue(string $project, array $urls, string $runId): int
    {
        $dirs = Storage::ensureRun($project, $runId);
        $qdir = $dirs['queue'];
        $pdir = $dirs['pages'];
        $added = 0;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $hash = md5($url);
            $todo = $qdir . '/' . $hash . '.todo';
            $done = $qdir . '/' . $hash . '.done';
            $page = $pdir . '/' . $hash . '.json';
            if (file_exists($todo) || file_exists($done) || file_exists($page)) {
                continue;
            }
            file_put_contents($todo, $url);
            $added++;
        }
        return $added;
    }
}
```


### `app/Admin/Dashboard.php`
### `app/Cron/Scheduler.php`

```php
<?php
namespace AISEO\Cron;

use AISEO\Helpers\RunId;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\Crawl\Worker;
use AISEO\Audit\Runner as AuditRunner;
use AISEO\Report\Builder as ReportBuilder;

class Scheduler
{
    public const EVENT = 'aiseo_minutely_drain';

    public static function registerSchedules($schedules)
    {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'ai-seo-tool'),
        ];
        return $schedules;
    }

    public static function init(): void
    {
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + 60, 'every_minute', self::EVENT);
        }

        add_action(self::EVENT, [self::class, 'drain']);
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::EVENT);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::EVENT);
        }
    }

    public static function drain(): void
    {
        $envDisabled = getenv('AISEO_DISABLE_CRON');
        if (is_string($envDisabled) && strtolower(trim($envDisabled)) === 'true') {
            return;
        }

        $projects = self::collectProjects();
        if (!$projects) {
            return;
        }

        $stepsPerTick = (int) (getenv('AISEO_STEPS_PER_TICK') ?: 50);
        if ($stepsPerTick <= 0) {
            $stepsPerTick = 50;
        }

        foreach ($projects as $project => $cfg) {
            Storage::ensureProject($project);

            $enabled = $cfg === null ? true : ($cfg['enabled'] ?? true);
            if (!$enabled) {
                continue;
            }

            $latestRun = Storage::getLatestRun($project);
            if ($cfg !== null && self::shouldStartNewRun($project, $cfg, $latestRun)) {
                $seed = $cfg['seed_urls'] ?? [];
                if (!empty($seed)) {
                    $runId = RunId::new();
                    Queue::init($project, $seed, $runId);
                    Storage::setLatestRun($project, $runId);
                    $latestRun = $runId;
                }
            }

            if (!$latestRun) {
                continue;
            }

            $processed = false;
            for ($i = 0; $i < $stepsPerTick; $i++) {
                $next = Queue::next($project, $latestRun);
                if (!$next) {
                    break;
                }
                $processed = true;
                Worker::process($project, $latestRun);
            }

            $runDir = Storage::runDir($project, $latestRun);
            $metaPath = $runDir . '/meta.json';
            $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];

            $queueEmpty = !Queue::next($project, $latestRun);
            if ($queueEmpty && empty($meta['completed_at'])) {
                $audit = AuditRunner::run($project, $latestRun);
                $report = ReportBuilder::build($project, $latestRun);
                $meta['completed_at'] = gmdate('c');
                $meta['summary'] = [
                    'pages' => $report['crawl']['pages_count'] ?? 0,
                    'issues' => $audit['summary']['issue_counts'] ?? [],
                ];
                Storage::writeJson($metaPath, $meta);
            } elseif ($processed) {
                $meta['last_tick_at'] = gmdate('c');
                Storage::writeJson($metaPath, $meta);
            }
        }
    }

    private static function collectProjects(): array
    {
        $baseDir = Storage::baseDir();
        $projects = [];

        foreach (glob($baseDir . '/*/config.json') as $configPath) {
            $project = basename(dirname($configPath));
            $projects[$project] = json_decode(file_get_contents($configPath), true) ?: [];
        }

        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) as $dir) {
            $project = basename($dir);
            if (!isset($projects[$project])) {
                $projects[$project] = null;
            }
        }

        return $projects;
    }

    private static function shouldStartNewRun(string $project, array $cfg, ?string $latestRun): bool
    {
        $frequency = $cfg['frequency'] ?? 'manual';
        if ($frequency === 'manual') {
            return false;
        }

        if (!$latestRun) {
            return true;
        }

        $metaPath = Storage::runDir($project, $latestRun) . '/meta.json';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        $started = isset($meta['started_at']) ? strtotime($meta['started_at']) : 0;
        if (!$started) {
            return true;
        }

        if ($frequency === 'weekly') {
            return (time() - $started) >= 7 * 86400;
        }

        if ($frequency === 'monthly') {
            return date('Ym', $started) !== date('Ym');
        }

        return false;
    }
}
```
`


```php
<?php
namespace AISEO\Admin;

use AISEO\Helpers\RunId;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\PostTypes\Project;

class Dashboard
{
    public static function register(): void
    {
        add_menu_page(
            __('AI SEO Dashboard', 'ai-seo-tool'),
            __('AI SEO', 'ai-seo-tool'),
            'manage_options',
            'ai-seo-dashboard',
            [self::class, 'render'],
            'dashicons-chart-area',
            56
        );
    }

    public static function registerActions(): void
    {
        add_action('admin_post_aiseo_run_crawl', [self::class, 'handleManualRun']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'ai-seo-tool'));
        }

        $projects = self::getProjects();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI SEO Projects', 'ai-seo-tool'); ?></h1>
            <?php if (isset($_GET['aiseo_notice'])): $notice = sanitize_text_field($_GET['aiseo_notice']); ?>
                <?php if ($notice === 'run'): ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Crawl queued. Cron will begin shortly.', 'ai-seo-tool'); ?></p></div>
                <?php elseif ($notice === 'run_fail'): ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Unable to queue crawl. Ensure a primary URL or seed_urls are configured.', 'ai-seo-tool'); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$projects): ?>
                <p><?php esc_html_e('No AI SEO projects found. Create one under AI SEO Projects → Add New.', 'ai-seo-tool'); ?></p>
                <?php return; ?>
            <?php endif; ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Project', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Primary URL', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Latest Run', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Queue', 'ai-seo-tool'); ?></th>
                        <th><?php esc_html_e('Actions', 'ai-seo-tool'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $project): ?>
                        <?php $summary = self::loadSummary($project['slug']); ?>
                        <tr>
                            <td><?php echo esc_html($project['title']); ?></td>
                            <td>
                                <?php if ($project['base_url']): ?>
                                    <a href="<?php echo esc_url($project['base_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($project['base_url']); ?></a>
                                <?php else: ?>
                                    <em><?php esc_html_e('Not set', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($summary['run_id']): ?>
                                    <strong><?php echo esc_html($summary['run_id']); ?></strong><br />
                                    <span class="description"><?php echo esc_html($summary['status']); ?></span>
                                <?php else: ?>
                                    <em><?php esc_html_e('No runs yet', 'ai-seo-tool'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($summary['run_id']): ?>
                                    <?php printf(__('Todo: %d, Done: %d, Pages: %d', 'ai-seo-tool'), $summary['queue_remaining'], $summary['queue_done'], $summary['pages']); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url(self::reportLink($project['slug'], $summary['run_id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('View Report', 'ai-seo-tool'); ?></a>
                                <a class="button" href="<?php echo esc_url(self::manualCrawlUrl($project['slug'])); ?>"><?php esc_html_e('Run Crawl Now', 'ai-seo-tool'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function getProjects(): array
    {
        $posts = get_posts([
            'post_type' => Project::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);

        return array_map(function ($post) {
            return [
                'ID' => $post->ID,
                'slug' => $post->post_name,
                'title' => $post->post_title,
                'base_url' => Project::getBaseUrl($post->post_name),
            ];
        }, $posts);
    }

    private static function loadSummary(string $project): array
    {
        $runId = Storage::getLatestRun($project);
        if (!$runId) {
            return [
                'run_id' => null,
                'status' => __('Pending', 'ai-seo-tool'),
                'queue_remaining' => 0,
                'queue_done' => 0,
                'pages' => 0,
            ];
        }
        $runDir = Storage::runDir($project, $runId);
        $queueDir = $runDir . '/queue';
        $pagesDir = $runDir . '/pages';
        $todos = glob($queueDir . '/*.todo');
        $done = glob($queueDir . '/*.done');
        $pages = glob($pagesDir . '/*.json');
        $status = __('Processing', 'ai-seo-tool');
        if (empty($todos)) {
            $status = __('Completed', 'ai-seo-tool');
        }
        return [
            'run_id' => $runId,
            'status' => $status,
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
        ];
    }

    private static function reportLink(string $slug, ?string $runId): string
    {
        $home = trailingslashit(home_url());
        $url = $home . 'ai-seo-report/' . $slug;
        if ($runId) {
            $url = add_query_arg('run', $runId, $url);
        }
        return $url;
    }

    private static function manualCrawlUrl(string $slug): string
    {
        $url = admin_url('admin-post.php');
        $url = add_query_arg([
            'action' => 'aiseo_run_crawl',
            'project' => sanitize_title($slug),
        ], $url);
        return wp_nonce_url($url, 'aiseo_run_crawl_' . $slug);
    }

    public static function handleManualRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }

        $slug = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        check_admin_referer('aiseo_run_crawl_' . $slug);

        $urls = [];
        $config = self::readConfig($slug);
        if (!empty($config['seed_urls']) && is_array($config['seed_urls'])) {
            $urls = array_merge($urls, $config['seed_urls']);
        }
        $base = Project::getBaseUrl($slug);
        if ($base) {
            $urls[] = $base;
        }
        $urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));

        if (empty($urls)) {
            wp_safe_redirect(add_query_arg('aiseo_notice', 'run_fail', admin_url('admin.php?page=ai-seo-dashboard')));
            exit;
        }

        $runId = RunId::new();
        Queue::init($slug, $urls, $runId);
        Storage::setLatestRun($slug, $runId);

        wp_safe_redirect(add_query_arg('aiseo_notice', 'run', admin_url('admin.php?page=ai-seo-dashboard')));
        exit;
    }

    private static function readConfig(string $project): array
    {
        $path = Storage::projectDir($project) . '/config.json';
        return file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }
}
``````

### `app/Crawl/Observer.php`

```php
<?php
namespace AISEO\Crawl;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class Observer extends CrawlObserver
{
    private string $project;
    private ?string $originUrl;
    private ?array $lastResult = null;

    public function __construct(string $project, ?string $originUrl = null)
    {
        $this->project = $project;
        $this->originUrl = $originUrl;
    }

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $this->lastResult = Worker::handleResponse(
            $this->project,
            $url,
            $response,
            $foundOnUrl,
            $linkText
        );
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $response = $requestException->getResponse();
        $this->lastResult = Worker::handleFailure(
            $this->project,
            $url,
            $response,
            $requestException,
            $foundOnUrl,
            $linkText
        );
    }

    public function recordException(string $url, \Throwable $exception): void
    {
        $uri = new \GuzzleHttp\Psr7\Uri($url);
        $this->lastResult = Worker::handleFailure(
            $this->project,
            $uri,
            null,
            $exception,
            null,
            null
        );
    }

    public function getLastResult(): ?array
    {
        return $this->lastResult;
    }
}
```

### `app/Crawl/Profile.php`

```php
<?php
namespace AISEO\Crawl;

use AISEO\PostTypes\Project;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlProfile;

class Profile extends CrawlProfile
{
    private string $host;

    public function __construct(string $project, string $referenceUrl)
    {
        $base = Project::getBaseUrl($project) ?: $referenceUrl;
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $this->host = $this->normalizeHost($host);
    }

    public function shouldCrawl(UriInterface $url): bool
    {
        $host = $this->normalizeHost($url->getHost() ?? '');
        return $host !== '' && $host === $this->host;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }
}
```

### `app/Crawl/Worker.php`

```php
<?php
namespace AISEO\Crawl;

use AISEO\Helpers\Storage;
use AISEO\PostTypes\Project;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\Crawler;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Worker
{
    public static function process(string $project, string $runId): array
    {
        $qfile = Queue::next($project, $runId);
        if (!$qfile) {
            return ['message' => 'queue-empty'];
        }

        $url = trim(file_get_contents($qfile));
        $observer = new Observer($project, $runId, $url);

        $crawler = Crawler::create(self::clientOptions())
            ->setCrawlObserver($observer)
            ->setCrawlProfile(new Profile($project, $url))
            ->setConcurrency(1)
            ->setMaximumDepth(0)
            ->setTotalCrawlLimit(1)
            ->setCurrentCrawlLimit(1)
            ->setParseableMimeTypes(['text/html', 'application/xhtml+xml'])
            ->setUserAgent('AISEO-Bot/0.1');

        try {
            $crawler->startCrawling($url);
        } catch (\Throwable $exception) {
            $observer->recordException($url, $exception);
        } finally {
            $done = substr($qfile, 0, -5) . '.done';
            @rename($qfile, $done);
        }

        $result = $observer->getLastResult();
        return $result ?: ['message' => 'queued', 'url' => $url];
    }

    public static function handleResponse(
        string $project,
        string $runId,
        UriInterface $uri,
        ResponseInterface $response,
        ?UriInterface $foundOn = null,
        ?string $linkText = null
    ): array {
        $dirs = Storage::ensureRun($project, $runId);
        $url = (string) $uri;
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
        $contentType = $headers['content-type'][0] ?? '';
        $contentLength = self::resolveContentLength($headers, $body);

        $page = [
            'run_id' => $runId,
            'project' => $project,
            'url' => $url,
            'status' => $status,
            'found_on' => $foundOn ? (string) $foundOn : null,
            'link_text' => $linkText,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'headers' => self::simplifyHeaders($headers),
            'fetched_at' => gmdate('c'),
        ];

        $discovered = [];

        if (self::isHtml($contentType)) {
            $parsed = self::parseHtml($project, $url, $body);
            $page = array_merge($page, $parsed['meta'], [
                'links' => $parsed['links'],
                'images' => $parsed['images'],
                'headings' => $parsed['headings'],
                'structured_data' => $parsed['structured_data'],
                'open_graph' => $parsed['open_graph'],
            ]);
            $discovered = $parsed['internal_urls'];
        }

        $pfile = $dirs['pages'] . '/' . md5($url) . '.json';
        Storage::writeJson($pfile, $page);

        if (!empty($discovered)) {
            Queue::enqueue($project, $discovered, $runId);
        }

        self::touchMeta($dirs['base'], ['last_processed_at' => gmdate('c')]);

        return $page;
    }

    public static function handleFailure(
        string $project,
        string $runId,
        UriInterface $uri,
        ?ResponseInterface $response = null,
        ?\Throwable $exception = null,
        ?UriInterface $foundOn = null,
        ?string $linkText = null
    ): array {
        $dirs = Storage::ensureRun($project, $runId);
        $status = $response ? $response->getStatusCode() : 0;
        $body = $response ? (string) $response->getBody() : '';
        $headers = $response ? array_change_key_case($response->getHeaders(), CASE_LOWER) : [];

        $page = [
            'run_id' => $runId,
            'project' => $project,
            'url' => (string) $uri,
            'status' => $status,
            'found_on' => $foundOn ? (string) $foundOn : null,
            'link_text' => $linkText,
            'content_type' => $headers['content-type'][0] ?? '',
            'content_length' => self::resolveContentLength($headers, $body),
            'headers' => self::simplifyHeaders($headers),
            'fetched_at' => gmdate('c'),
            'error' => $exception ? $exception->getMessage() : 'request failed',
        ];

        $pfile = $dirs['pages'] . '/' . md5($page['url']) . '.json';
        Storage::writeJson($pfile, $page);

        self::touchMeta($dirs['base'], ['last_processed_at' => gmdate('c')]);

        return $page;
    }

    private static function clientOptions(): array
    {
        $verify = self::resolveVerifyOption();

        return [
            'verify' => $verify,
            'timeout' => 20,
            'allow_redirects' => false,
            'headers' => [
                'User-Agent' => 'AISEO-Bot/0.1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
    }

    private static function resolveVerifyOption()
    {
        $env = getenv('AISEO_HTTP_VERIFY_TLS');
        if (is_string($env) && $env !== '') {
            $value = strtolower(trim($env));
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (file_exists($env)) {
                return $env;
            }
        }

        if (defined('ABSPATH')) {
            $bundle = rtrim(ABSPATH, '/') . '/wp-includes/certificates/ca-bundle.crt';
            if (file_exists($bundle)) {
                return $bundle;
            }
        }

        return true;
    }

    private static function resolveContentLength(array $headers, string $body): int
    {
        if (isset($headers['content-length'][0])) {
            return (int) $headers['content-length'][0];
        }

        return strlen($body);
    }

    private static function simplifyHeaders(array $headers): array
    {
        $keep = ['content-type', 'content-length', 'cache-control', 'expires', 'last-modified', 'etag'];
        $out = [];
        foreach ($keep as $header) {
            if (isset($headers[$header])) {
                $out[$header] = implode(', ', $headers[$header]);
            }
        }
        return $out;
    }

    private static function isHtml(string $contentType): bool
    {
        return stripos($contentType, 'text/html') !== false
            || stripos($contentType, 'application/xhtml') !== false;
    }

    private static function parseHtml(string $project, string $url, string $html): array
    {
        $crawler = new DomCrawler($html, $url);

        $title = $crawler->filter('title')->count() ? trim($crawler->filter('title')->text()) : '';
        $metaDescription = $crawler->filter('meta[name="description"]')->count()
            ? trim((string) $crawler->filter('meta[name="description"]')->attr('content'))
            : '';
        $metaRobots = $crawler->filter('meta[name="robots"]')->count()
            ? trim((string) $crawler->filter('meta[name="robots"]')->attr('content'))
            : '';
        $canonical = $crawler->filter('link[rel="canonical"]')->count()
            ? trim((string) $crawler->filter('link[rel="canonical"]')->attr('href'))
            : '';

        $headings = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $headings[$tag] = $crawler->filter($tag)->each(fn (DomCrawler $node) => trim($node->text()));
        }

        $images = $crawler->filter('img')->each(function (DomCrawler $node) use ($url) {
            $src = $node->attr('src') ?? '';
            $alt = trim($node->attr('alt') ?? '');
            $src = self::absolutizeUrl($src, $url);
            return [
                'src' => $src,
                'alt' => $alt,
            ];
        });

        $internalLinks = [];
        $externalLinks = [];

        $baseUrl = Project::getBaseUrl($project) ?: $url;
        $targetHost = self::normalizeHost(parse_url($baseUrl, PHP_URL_HOST) ?: '');

        $crawler->filter('a[href]')->each(function (DomCrawler $node) use (&$internalLinks, &$externalLinks, $url, $targetHost) {
            $href = $node->attr('href') ?? '';
            $href = self::absolutizeUrl($href, $url);
            if (!$href) {
                return;
            }

            $parts = parse_url($href);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return;
            }

            $entry = [
                'url' => self::buildUrlWithoutFragment($parts),
                'anchor' => trim($node->text() ?? ''),
            ];

            $host = self::normalizeHost($parts['host'] ?? '');
            if ($host && $host === $targetHost) {
                $internalLinks[] = $entry;
            } else {
                $externalLinks[] = $entry;
            }
        });

        $uniqueInternal = array_values(array_unique(array_map(fn ($link) => $link['url'], $internalLinks)));

        return [
            'meta' => [
                'title' => $title,
                'meta_description' => $metaDescription,
                'meta_robots' => $metaRobots,
                'canonical' => $canonical,
            ],
            'links' => [
                'internal' => $internalLinks,
                'external' => $externalLinks,
            ],
            'images' => $images,
            'headings' => $headings,
            'internal_urls' => $uniqueInternal,
            'structured_data' => self::extractStructuredData($crawler),
            'open_graph' => self::extractOpenGraph($crawler),
        ];
    }

    private static function absolutizeUrl(string $href, string $base): string
    {
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
            return '';
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }

        return (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($base), new \GuzzleHttp\Psr7\Uri($href));
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    private static function buildUrlWithoutFragment(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $path, $query);
    }

    private static function extractStructuredData(DomCrawler $crawler): array
    {
        $schemas = [];
        $nodes = $crawler->filter('script[type="application/ld+json"]');
        foreach ($nodes as $node) {
            $raw = trim($node->textContent ?? '');
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                $schemas[] = $decoded;
            } else {
                $schemas[] = $raw;
            }
        }
        return $schemas;
    }

    private static function extractOpenGraph(DomCrawler $crawler): array
    {
        $og = [];
        $nodes = $crawler->filter('meta[property^="og:"], meta[property^="OG:"], meta[name^="og:"], meta[name^="OG:"]');
        foreach ($nodes as $node) {
            $property = $node->getAttribute('property') ?: $node->getAttribute('name');
            $content = $node->getAttribute('content');
            if (!$property || $content === null) {
                continue;
            }
            $property = strtolower(trim($property));
            if ($property === '') {
                continue;
            }
            $og[$property] = trim($content);
        }
        return $og;
    }

    private static function touchMeta(string $runBase, array $extra): void
    {
        $metaPath = $runBase . '/meta.json';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta = array_merge($meta, $extra);
        Storage::writeJson($metaPath, $meta);
    }
}
```

---

## 6) Audit and Report stubs

### `app/Audit/Runner.php`

```php
<?php
namespace AISEO\Audit;

use AISEO\Helpers\Storage;

class Runner
{
    public static function run(string $project, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $pdir = $dirs['pages'];
        $audits = [];
        $issueCounts = [];
        $statusBuckets = [
            '2xx' => 0,
            '3xx' => 0,
            '4xx' => 0,
            '5xx' => 0,
            'other' => 0,
        ];

        foreach (glob($pdir . '/*.json') as $f) {
            $page = Storage::readJson($f, []);
            if (!$page) {
                continue;
            }

            [$issues, $bucket] = self::assessPage($page);

            if (isset($statusBuckets[$bucket])) {
                $statusBuckets[$bucket]++;
            } else {
                $statusBuckets['other']++;
            }

            foreach ($issues as $issue) {
                $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
            }

            $audits[] = [
                'url' => $page['url'] ?? '',
                'status' => $page['status'] ?? null,
                'issues' => $issues,
            ];
        }

        $out = [
            'run_id' => $runId,
            'project' => $project,
            'generated_at' => gmdate('c'),
            'summary' => [
                'total_pages' => count($audits),
                'status_buckets' => $statusBuckets,
                'issue_counts' => $issueCounts,
            ],
            'items' => $audits,
        ];
        Storage::writeJson($dirs['base'] . '/audit.json', $out);
        return $out;
    }

    private static function assessPage(array $page): array
    {
        $issues = [];
        $status = (int)($page['status'] ?? 0);
        $bucket = self::bucketStatus($status);

        if ($status >= 500) {
            $issues[] = 'Server error (5xx)';
        } elseif ($status >= 400) {
            $issues[] = 'Client error (4xx)';
        } elseif ($status >= 300) {
            $issues[] = 'Redirect (3xx)';
        } elseif ($status === 0) {
            $issues[] = 'Missing status code';
        }

        $title = trim((string)($page['title'] ?? ''));
        $titleLen = mb_strlen($title);
        if ($title === '') {
            $issues[] = 'Missing title tag';
        } elseif ($titleLen > 70) {
            $issues[] = 'Title longer than 70 characters';
        } elseif ($titleLen < 30) {
            $issues[] = 'Title shorter than 30 characters';
        }

        $meta = trim((string)($page['meta_description'] ?? ''));
        $metaLen = mb_strlen($meta);
        if ($meta === '') {
            $issues[] = 'Missing meta description';
        } elseif ($metaLen > 160) {
            $issues[] = 'Meta description longer than 160 characters';
        } elseif ($metaLen < 50) {
            $issues[] = 'Meta description shorter than 50 characters';
        }

        if (empty($page['canonical'])) {
            $issues[] = 'Missing canonical URL';
        }

        $headings = $page['headings'] ?? [];
        $h1s = is_array($headings['h1'] ?? null) ? $headings['h1'] : [];
        if (count($h1s) === 0) {
            $issues[] = 'Missing H1 heading';
        } elseif (count($h1s) > 1) {
            $issues[] = 'Multiple H1 headings';
        }

        $contentLength = (int)($page['content_length'] ?? 0);
        if ($contentLength > 1024 * 1024) {
            $issues[] = 'Content size greater than 1MB';
        }

        $images = $page['images'] ?? [];
        if (is_array($images)) {
            $missingAlt = array_filter($images, fn($img) => trim((string)($img['alt'] ?? '')) === '');
            if (count($missingAlt) > 0) {
                $issues[] = 'Images without ALT text';
            }
        }

        $og = $page['open_graph'] ?? [];
        if (!self::hasKeys($og, ['og:title', 'og:description'])) {
            $issues[] = 'Missing OG title or description';
        }
        if (!self::hasKeys($og, ['og:image'])) {
            $issues[] = 'Missing OG image';
        }

        $schemas = $page['structured_data'] ?? [];
        if (is_array($schemas) && count($schemas) === 0) {
            $issues[] = 'No structured data';
        }

        return [$issues, $bucket];
    }

    private static function bucketStatus(int $status): string
    {
        if ($status >= 500) {
            return '5xx';
        }
        if ($status >= 400) {
            return '4xx';
        }
        if ($status >= 300) {
            return '3xx';
        }
        if ($status >= 200 && $status < 300) {
            return '2xx';
        }
        return 'other';
    }

    private static function hasKeys(array $haystack, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($haystack[$key]) || trim((string)$haystack[$key]) === '') {
                return false;
            }
        }
        return true;
    }
}
```




### `app/Report/Builder.php`

```php
<?php
namespace AISEO\Report;

use AISEO\Helpers\Storage;
use AISEO\PostTypes\Project;

class Builder
{
    public static function build(string $project, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $audit = Storage::readJson($dirs['base'] . '/audit.json', []);
        $crawl = [
            'pages_count' => count(glob($dirs['pages'] . '/*.json')),
            'status_buckets' => $audit['summary']['status_buckets'] ?? [],
        ];
        $topIssues = [];
        if (!empty($audit['summary']['issue_counts'])) {
            $counts = $audit['summary']['issue_counts'];
            arsort($counts);
            $topIssues = array_slice($counts, 0, 10, true);
        }
        $data = [
            'run_id' => $runId,
            'project' => $project,
            'base_url' => Project::getBaseUrl($project),
            'generated_at' => gmdate('c'),
            'crawl' => $crawl,
            'audit' => $audit,
            'top_issues' => $topIssues,
        ];
        Storage::writeJson($dirs['base'] . '/report.json', $data);
        return $data;
    }
}
```




### `templates/report.php`

```php
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
```




---

## 7) REST API routes

### `app/Rest/Routes.php`

```php
<?php
namespace AISEO\Rest;

use WP_REST_Request;
use AISEO\Helpers\Http;
use AISEO\Helpers\RunId;
use AISEO\Helpers\Storage;
use AISEO\Crawl\Queue;
use AISEO\Crawl\Worker;
use AISEO\Audit\Runner as AuditRunner;
use AISEO\Report\Builder as ReportBuilder;
use AISEO\PostTypes\Project;

class Routes
{
    public static function register()
    {
        register_rest_route('ai-seo-tool/v1', '/start-crawl', [
            'methods' => 'POST',
            'callback' => [self::class, 'startCrawl'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/crawl-step', [
            'methods' => 'GET',
            'callback' => [self::class, 'crawlStep'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/audit', [
            'methods' => 'POST',
            'callback' => [self::class, 'audit'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/report', [
            'methods' => 'POST',
            'callback' => [self::class, 'report'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('ai-seo-tool/v1', '/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function startCrawl(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        $urlsParam = $req->get_param('urls');
        $urls = [];
        if (is_array($urlsParam)) {
            foreach ($urlsParam as $u) {
                $clean = esc_url_raw((string) $u);
                if ($clean) {
                    $urls[] = $clean;
                }
            }
        }

        $baseUrl = Project::getBaseUrl($project);
        if ($baseUrl) {
            $urls[] = $baseUrl;
        }

        $urls = array_values(array_unique(array_filter($urls)));
        if (!$project || empty($urls)) {
            return Http::fail('project requires at least one valid URL', 422);
        }

        $runId = $req->get_param('run_id');
        $runId = $runId ? self::normalizeRunId($runId) : RunId::new();

        $result = Queue::init($project, $urls, $runId);
        Storage::setLatestRun($project, $runId);

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'queued' => $result['queued'] ?? 0,
        ]);
    }

    public static function crawlStep(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : (Storage::getLatestRun($project) ?? '');
        if (!$runId) {
            return Http::fail('run not found', 404);
        }

        $result = Worker::process($project, $runId);

        if (!Queue::next($project, $runId)) {
            $audit = AuditRunner::run($project, $runId);
            $report = ReportBuilder::build($project, $runId);
            $result['audit'] = $audit['summary'] ?? [];
            $result['report'] = $report['crawl'] ?? [];
        }

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'processed' => $result,
        ]);
    }

    public static function audit(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : (Storage::getLatestRun($project) ?? '');
        if (!$runId) {
            return Http::fail('run not found', 404);
        }

        return Http::ok(AuditRunner::run($project, $runId));
    }

    public static function report(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : (Storage::getLatestRun($project) ?? '');
        if (!$runId) {
            return Http::fail('run not found', 404);
        }

        return Http::ok(ReportBuilder::build($project, $runId));
    }

    public static function status(WP_REST_Request $req)
    {
        if (!Http::validate_token($req)) {
            return Http::fail('invalid key', 401);
        }
        $project = sanitize_text_field($req->get_param('project'));
        if (!$project) {
            return Http::fail('project required', 422);
        }
        $runId = $req->get_param('run');
        $runId = $runId ? self::normalizeRunId($runId) : Storage::getLatestRun($project);
        if (!$runId) {
            return Http::ok([
                'project' => $project,
                'message' => 'no runs yet',
            ]);
        }

        $runDir = Storage::runDir($project, $runId);
        $queueDir = $runDir . '/queue';
        $pagesDir = $runDir . '/pages';
        $todos = glob($queueDir . '/*.todo');
        $done = glob($queueDir . '/*.done');
        $pages = glob($pagesDir . '/*.json');

        return Http::ok([
            'project' => $project,
            'run_id' => $runId,
            'queue_remaining' => count($todos),
            'queue_done' => count($done),
            'pages' => count($pages),
            'base_url' => Project::getBaseUrl($project),
        ]);
    }
}


    private static function normalizeRunId(?string $runId): string
    {
        $runId = (string) $runId;
        return preg_replace('/[^A-Za-z0-9_\-]/', '', $runId);
    }
```


---

## 8) Frontend hookup (quick test route)

Add content and test the front-end route:

* In **AI SEO Projects → Add New**, create a project (title becomes the slug) and fill in the **Primary Site URL** field.
* Visit: `https://ai-seo-tools.local/ai-seo-report/{project-slug}` to render `templates/report.php`.
* If you get a 404 the first time, visit **Settings → Permalinks** and click Save to flush rewrite rules.

*(Later we’ll add a proper WP template and nice UI with Bootstrap + Alpine.)*

---

## 9) Example API calls

Use the CPT slug (e.g. `client-slug`) in the `project` query parameter below. The secure token comes from `.env` (`AISEO_SECURE_TOKEN`).

### Seed queue

```bash
curl -X POST \
  "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/start-crawl?project=client-slug&key=AISEO_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "urls": [
      "https://example.com/",
      "https://example.com/about",
      "https://example.com/contact"
    ]
  }'
```
> `urls` is optional when the **Primary Site URL** meta is filled; the endpoint will seed the queue with that value automatically.

### Process one URL (simulate uptime monitor)

```bash
curl "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/crawl-step?project=client-slug&key=AISEO_TOKEN"
```

### Audit & report manually

```bash
curl -X POST "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/audit?project=client-slug&key=AISEO_TOKEN"
curl -X POST "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/report?project=client-slug&key=AISEO_TOKEN"
```

### Status

```bash
curl "https://ai-seo-tools.local/wp-json/ai-seo-tool/v1/status?project=client-slug&key=AISEO_TOKEN"
```
> Response includes `base_url` so you can confirm which site the project targets.

---

## 10) Next increments (after booting)

* Replace minimal HTML parsing with **Spatie Crawler** + `symfony/dom-crawler` for robust extraction (H1/H2, canonical, robots, images/ALT, links, etc.)
* Add GA/GSC CSV import endpoints and parsers
* Add Gemini summary generator in `app/AI/Gemini.php`
* Build HTML→PDF export via `dompdf`
* Dashboard page in WP Admin for progress monitor + report download
* Rate limiting & polite crawling (robots.txt, delay, domain lock)
* Authentication upgrade (signed HMAC or app password)

---

## 12) Scheduled crawls

* Use the toggle on wp-admin → AI SEO to enable/disable the hourly scheduler globally.
* Set per-project cadence (Manual, Weekly, Monthly) in the project meta box. Manual only seeds on demand; auto cadences seed when due.
* Manual "Run Crawl Now" behaves like `/start-crawl`: it seeds the queue immediately, and the hourly worker handles `/crawl-step` processing on the next tick.
* The scheduler can also be disabled via `.env` by setting `AISEO_DISABLE_CRON=true`.
* Manual "Run Crawl Now" only seeds the queue; the hourly worker will process the queue and generate reports during its next tick.
* Set the crawl cadence per project under **AI SEO Projects → Project Details** (Manual, Weekly, Monthly).
* The `AI SEO` dashboard (wp-admin) shows last run time, top issues, and links to trigger manual crawls.
* A WP-Cron job (`aiseo_cron_tick`) runs hourly, seeds the queue, processes up to 200 URLs, and stores snapshots under `storage/projects/<slug>/history/<timestamp>/`.


## 11) Git ignores

Add to project root `.gitignore`:

```
/web/app/themes/ai-seo-tool/storage/projects/
/web/app/themes/ai-seo-tool/vendor/
/.env
```

And to theme `storage/projects/.gitignore`:

```
*
!.gitignore
```

---

You can now paste these files into VS Code and run the API smoke test. In the next step, we’ll wire **Spatie Crawler** and the **Gemini** summarizer.
### `app/PostTypes/Project.php`

```php
<?php
namespace AISEO\PostTypes;

class Project
{
    public const META_BASE_URL = '_aiseo_project_base_url';
    public const META_SCHEDULE = '_aiseo_project_schedule';
    public const META_LAST_RUN = '_aiseo_project_last_run';
    public const POST_TYPE = 'aiseo_project';

    public static function register(): void
    {
        $labels = [
            'name' => __('AI SEO Projects', 'ai-seo-tool'),
            'singular_name' => __('AI SEO Project', 'ai-seo-tool'),
            'add_new_item' => __('Add New SEO Project', 'ai-seo-tool'),
            'edit_item' => __('Edit SEO Project', 'ai-seo-tool'),
            'new_item' => __('New SEO Project', 'ai-seo-tool'),
            'view_item' => __('View SEO Project', 'ai-seo-tool'),
            'search_items' => __('Search SEO Projects', 'ai-seo-tool'),
            'not_found' => __('No SEO projects found', 'ai-seo-tool'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels' => $labels,
            'public' => true,
            'show_in_rest' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-chart-line',
            'supports' => ['title', 'editor'],
            'rewrite' => ['slug' => 'seo-project'],
        ]);

        register_post_meta(self::POST_TYPE, self::META_BASE_URL, [
            'type' => 'string',
            'show_in_rest' => true,
            'single' => true,
            'sanitize_callback' => 'esc_url_raw',
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);

        register_post_meta(self::POST_TYPE, self::META_SCHEDULE, [
            'type' => 'string',
            'show_in_rest' => true,
            'single' => true,
            'sanitize_callback' => [self::class, 'sanitizeSchedule'],
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);

        register_post_meta(self::POST_TYPE, self::META_LAST_RUN, [
            'type' => 'integer',
            'show_in_rest' => true,
            'single' => true,
            'sanitize_callback' => 'absint',
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);

        add_action('add_meta_boxes', [self::class, 'addMetaBoxes']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'saveMeta'], 10, 2);
    }

    public static function addMetaBoxes(): void
    {
        add_meta_box(
            'aiseo_project_details',
            __('Project Details', 'ai-seo-tool'),
            [self::class, 'renderMetaBox'],
            'aiseo_project',
            'normal',
            'default'
        );
    }

    public static function renderMetaBox($post): void
    {
        wp_nonce_field('aiseo_project_meta', 'aiseo_project_meta_nonce');
        $baseUrl = get_post_meta($post->ID, self::META_BASE_URL, true);
        $schedule = get_post_meta($post->ID, self::META_SCHEDULE, true) ?: 'manual';
        $lastRun = (int) get_post_meta($post->ID, self::META_LAST_RUN, true);
        ?>
        <p>
            <label for="aiseo_project_base_url"><strong><?php esc_html_e('Primary Site URL', 'ai-seo-tool'); ?></strong></label>
            <input type="url" name="aiseo_project_base_url" id="aiseo_project_base_url" class="widefat" value="<?php echo esc_attr($baseUrl); ?>" placeholder="https://example.com" />
            <small class="description"><?php esc_html_e('Used as the starting point for crawls and reports.', 'ai-seo-tool'); ?></small>
        </p>
        <p>
            <label for="aiseo_project_schedule"><strong><?php esc_html_e('Crawl Schedule', 'ai-seo-tool'); ?></strong></label>
            <select name="aiseo_project_schedule" id="aiseo_project_schedule" class="widefat">
                <?php foreach (self::scheduleOptions() as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($schedule, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="description"><?php esc_html_e('Choose how often the crawler should refresh data automatically.', 'ai-seo-tool'); ?></small>
        </p>
        <p>
            <strong><?php esc_html_e('Last Crawl:', 'ai-seo-tool'); ?></strong>
            <?php
            if ($lastRun) {
                $relative = human_time_diff($lastRun, current_time('timestamp'));
                printf('%s (%s %s)', esc_html(gmdate('Y-m-d H:i', $lastRun)), esc_html($relative), esc_html__('ago', 'ai-seo-tool'));
            } else {
                esc_html_e('Never', 'ai-seo-tool');
            }
            ?>
        </p>
        <?php
    }

    public static function saveMeta(int $postId, $post): void
    {
        if (!isset($_POST['aiseo_project_meta_nonce']) || !wp_verify_nonce($_POST['aiseo_project_meta_nonce'], 'aiseo_project_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        $value = isset($_POST['aiseo_project_base_url']) ? esc_url_raw($_POST['aiseo_project_base_url']) : '';
        if ($value) {
            update_post_meta($postId, self::META_BASE_URL, $value);
        } else {
            delete_post_meta($postId, self::META_BASE_URL);
        }

        $schedule = isset($_POST['aiseo_project_schedule']) ? self::sanitizeSchedule($_POST['aiseo_project_schedule']) : 'manual';
        update_post_meta($postId, self::META_SCHEDULE, $schedule);
    }

    public static function getBySlug(string $slug): ?\WP_Post
    {
        $slug = sanitize_title($slug);
        if (!$slug) {
            return null;
        }
        $post = get_page_by_path($slug, OBJECT, self::POST_TYPE);
        return ($post instanceof \WP_Post) ? $post : null;
    }

    public static function getBaseUrl(string $slug): string
    {
        $post = self::getBySlug($slug);
        if (!$post) {
            return '';
        }
        return (string) get_post_meta($post->ID, self::META_BASE_URL, true);
    }

    public static function getSchedule(string $slug): string
    {
        $post = self::getBySlug($slug);
        if (!$post) {
            return 'manual';
        }
        $value = get_post_meta($post->ID, self::META_SCHEDULE, true);
        return self::sanitizeSchedule($value) ?: 'manual';
    }

    public static function getLastRun(string $slug): int
    {
        $post = self::getBySlug($slug);
        if (!$post) {
            return 0;
        }
        return (int) get_post_meta($post->ID, self::META_LAST_RUN, true);
    }

    public static function updateLastRun(string $slug, int $timestamp): void
    {
        $post = self::getBySlug($slug);
        if ($post) {
            update_post_meta($post->ID, self::META_LAST_RUN, (int) $timestamp);
        }
    }

    public static function scheduleOptions(): array
    {
        return [
            'manual' => __('Manual', 'ai-seo-tool'),
            'weekly' => __('Weekly', 'ai-seo-tool'),
            'monthly' => __('Monthly', 'ai-seo-tool'),
        ];
    }

    public static function sanitizeSchedule($value): string
    {
        $value = is_string($value) ? strtolower($value) : 'manual';
        return in_array($value, ['manual', 'weekly', 'monthly'], true) ? $value : 'manual';
    }
}
```



### `app/Template/Report.php`

```php
<?php
namespace AISEO\Template;

class Report
{
    private static bool $booted = false;

    public static function register(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('init', [self::class, 'addRewriteRule']);
        add_filter('query_vars', [self::class, 'registerQueryVar']);
        add_filter('template_include', [self::class, 'loadTemplate']);
    }

    public static function addRewriteRule(): void
    {
        add_rewrite_rule('^ai-seo-report/([^/]+)/?$', 'index.php?aiseo_report=$matches[1]', 'top');
    }

    public static function registerQueryVar(array $vars): array
    {
        $vars[] = 'aiseo_report';
        return $vars;
    }

    public static function loadTemplate(string $template): string
    {
        $project = get_query_var('aiseo_report');
        if (!$project) {
            return $template;
        }

        $file = get_theme_file_path('templates/report.php');
        return file_exists($file) ? $file : $template;
    }
}
```
