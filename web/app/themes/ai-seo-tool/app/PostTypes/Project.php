<?php
namespace BBSEO\PostTypes;

use BBSEO\Analytics\GoogleAnalytics;
use BBSEO\Analytics\SearchConsole;
use BBSEO\Helpers\Storage;

class Project
{
    public const META_BASE_URL = '_bbseo_project_base_url';
    public const META_SCHEDULE = '_bbseo_project_schedule';
    public const META_LAST_RUN = '_bbseo_project_last_run';
    public const POST_TYPE = 'bbseo_project';

    public static function register(): void
    {
        $labels = [
            'name' => __('Projects', 'ai-seo-tool'),
            'singular_name' => __('Project', 'ai-seo-tool'),
            'add_new_item' => __('Add Project', 'ai-seo-tool'),
            'edit_item' => __('Edit Project', 'ai-seo-tool'),
            'new_item' => __('New Project', 'ai-seo-tool'),
            'view_item' => __('View Project', 'ai-seo-tool'),
            'search_items' => __('Search Projects', 'ai-seo-tool'),
            'not_found' => __('No projects found', 'ai-seo-tool'),
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
            'bbseo_project_details',
            __('Project Details', 'ai-seo-tool'),
            [self::class, 'renderMetaBox'],
            'bbseo_project',
            'normal',
            'default'
        );
    }

    public static function renderMetaBox($post): void
    {
        wp_nonce_field('bbseo_project_meta', 'bbseo_project_meta_nonce');
        $baseUrl = get_post_meta($post->ID, self::META_BASE_URL, true);
        $schedule = get_post_meta($post->ID, self::META_SCHEDULE, true) ?: 'manual';
        $lastRun = (int) get_post_meta($post->ID, self::META_LAST_RUN, true);
        $slug = $post->post_name ?: '';
        $config = [];
        if ($slug) {
            Storage::ensureProject($slug);
            $cfgPath = Storage::projectDir($slug) . '/config.json';
            if (is_file($cfgPath)) {
                $config = json_decode(file_get_contents($cfgPath), true) ?: [];
            }
        }
        $gaConfig = $config['analytics']['ga'] ?? [];
        $gaPropertyId = $gaConfig['property_id'] ?? '';
        $gaConnected = !empty($gaConfig['refresh_token']);
        $gaLastSync = $gaConfig['last_sync'] ?? null;
        $gaLastError = $gaConfig['last_error'] ?? null;
        $gaRange = $gaConfig['range'] ?? 'last_30_days';
        $gaCustomStart = $gaConfig['custom_start'] ?? '';
        $gaCustomEnd = $gaConfig['custom_end'] ?? '';
        $gaMetrics = $gaConfig['metrics'] ?? GoogleAnalytics::defaultMetrics();
        if (!is_array($gaMetrics)) {
            $gaMetrics = GoogleAnalytics::defaultMetrics();
        }
        $gscConfig = $config['analytics']['gsc'] ?? [];
        $gscProperty = $gscConfig['property'] ?? '';
        $gscRange = $gscConfig['range'] ?? 'last_30_days';
        $gscCustomStart = $gscConfig['custom_start'] ?? '';
        $gscCustomEnd = $gscConfig['custom_end'] ?? '';
        $gscMetrics = $gscConfig['metrics'] ?? SearchConsole::defaultMetrics();
        if (!is_array($gscMetrics)) {
            $gscMetrics = SearchConsole::defaultMetrics();
        }
        $gscLastSync = $gscConfig['last_sync'] ?? null;
        $gscLastError = $gscConfig['last_error'] ?? null;
        $gscConnected = $gaConnected && !empty($gscProperty);
        $clientConfigured = GoogleAnalytics::clientId() && GoogleAnalytics::clientSecret();
        $connectUrl = $slug ? wp_nonce_url(add_query_arg([
            'action' => 'bbseo_ga_connect',
            'project' => $slug,
        ], admin_url('admin-post.php')), 'bbseo_ga_connect_' . $slug) : '';
        $disconnectUrl = $slug ? wp_nonce_url(add_query_arg([
            'action' => 'bbseo_ga_disconnect',
            'project' => $slug,
        ], admin_url('admin-post.php')), 'bbseo_ga_disconnect_' . $slug) : '';
        $gaSyncUrl = $slug ? wp_nonce_url(add_query_arg([
            'action' => 'bbseo_ga_sync',
            'project' => $slug,
            'type' => 'ga',
        ], admin_url('admin-post.php')), 'bbseo_ga_sync_' . $slug . '_ga') : '';
        $gscSyncUrl = $slug ? wp_nonce_url(add_query_arg([
            'action' => 'bbseo_ga_sync',
            'project' => $slug,
            'type' => 'gsc',
        ], admin_url('admin-post.php')), 'bbseo_ga_sync_' . $slug . '_gsc') : '';
        ?>
        <?php if (isset($_GET['ga_notice'])): ?>
            <?php $notice = sanitize_text_field($_GET['ga_notice']); ?>
            <?php if ($notice === 'connected'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Google Analytics connected successfully.', 'ai-seo-tool'); ?></p></div>
            <?php elseif ($notice === 'disconnected'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Google Analytics disconnected.', 'ai-seo-tool'); ?></p></div>
            <?php elseif ($notice === 'synced'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Google Analytics data synced.', 'ai-seo-tool'); ?></p></div>
            <?php elseif ($notice === 'synced_gsc'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Search Console data synced.', 'ai-seo-tool'); ?></p></div>
            <?php elseif ($notice === 'error'): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($_GET['ga_message'] ?? __('Google Analytics error.', 'ai-seo-tool')); ?></p></div>
            <?php elseif ($notice === 'gsc_error'): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($_GET['ga_message'] ?? __('Search Console error.', 'ai-seo-tool')); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>
        <p>
            <label for="bbseo_project_base_url"><strong><?php esc_html_e('Primary Site URL', 'ai-seo-tool'); ?></strong></label>
            <input type="url" name="bbseo_project_base_url" id="bbseo_project_base_url" class="widefat" value="<?php echo esc_attr($baseUrl); ?>" placeholder="https://example.com" />
            <small class="description"><?php esc_html_e('Used as the starting point for crawls and reports.', 'ai-seo-tool'); ?></small>
        </p>
        <p>
            <label for="bbseo_project_schedule"><strong><?php esc_html_e('Crawl Schedule', 'ai-seo-tool'); ?></strong></label>
            <select name="bbseo_project_schedule" id="bbseo_project_schedule" class="widefat">
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
        <hr />
        <h2><?php esc_html_e('Google Analytics', 'ai-seo-tool'); ?></h2>
        <?php if (!$clientConfigured): ?>
            <p class="notice notice-warning"><?php esc_html_e('Set BBSEO_GA_CLIENT_ID and BBSEO_GA_CLIENT_SECRET in your environment to enable Google Analytics sync.', 'ai-seo-tool'); ?></p>
        <?php endif; ?>
        <p>
            <label for="bbseo_ga_property_id"><strong><?php esc_html_e('GA Property ID (GA4)', 'ai-seo-tool'); ?></strong></label>
            <input type="text" name="bbseo_ga_property_id" id="bbseo_ga_property_id" class="widefat" value="<?php echo esc_attr($gaPropertyId); ?>" placeholder="properties/123456789" />
            <small class="description"><?php esc_html_e('Use the full resource name (e.g. properties/123456789).', 'ai-seo-tool'); ?></small>
        </p>
        <?php if ($slug): ?>
            <p>
                <?php if ($gaConnected): ?>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <strong><?php esc_html_e('Connected', 'ai-seo-tool'); ?></strong>
                    <?php if ($gaLastSync): ?>
                        <span class="description"><?php printf(esc_html__('Last sync: %s', 'ai-seo-tool'), esc_html($gaLastSync)); ?></span>
                    <?php endif; ?>
                    <?php if ($gaLastError): ?>
                        <br><span class="description" style="color:#c92c2c;"><?php echo esc_html($gaLastError); ?></span>
                    <?php endif; ?>
                    <br>
                    <a class="button" href="<?php echo esc_url($gaSyncUrl); ?>"><?php esc_html_e('Sync GA Data Now', 'ai-seo-tool'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url($disconnectUrl); ?>"><?php esc_html_e('Disconnect', 'ai-seo-tool'); ?></a>
                <?php else: ?>
                    <span class="dashicons dashicons-no" aria-hidden="true"></span>
                    <strong><?php esc_html_e('Not connected to Google Analytics', 'ai-seo-tool'); ?></strong><br>
                    <a class="button button-primary<?php echo $clientConfigured ? '' : ' disabled'; ?>" href="<?php echo esc_url($clientConfigured ? $connectUrl : '#'); ?>" <?php if (!$clientConfigured) { echo 'aria-disabled="true"'; } ?>><?php esc_html_e('Connect Google Analytics', 'ai-seo-tool'); ?></a>
                <?php endif; ?>
            </p>
            <fieldset>
                <legend><?php esc_html_e('GA Sync Settings', 'ai-seo-tool'); ?></legend>
                <p>
                    <label for="bbseo_ga_range"><strong><?php esc_html_e('Date Range', 'ai-seo-tool'); ?></strong></label>
                    <select name="bbseo_ga_range" id="bbseo_ga_range" class="widefat">
                        <?php foreach (GoogleAnalytics::rangeOptions() as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($gaRange, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="description"><?php esc_html_e('Choose the range used when syncing Google Analytics data.', 'ai-seo-tool'); ?></small>
                </p>
                <div class="bbseo-ga-custom-range" style="<?php echo $gaRange === 'custom' ? '' : 'display:none;'; ?>">
                    <p>
                        <label for="bbseo_ga_custom_start"><strong><?php esc_html_e('Custom Start Date', 'ai-seo-tool'); ?></strong></label>
                        <input type="date" name="bbseo_ga_custom_start" id="bbseo_ga_custom_start" value="<?php echo esc_attr($gaCustomStart); ?>" class="regular-text" />
                    </p>
                    <p>
                        <label for="bbseo_ga_custom_end"><strong><?php esc_html_e('Custom End Date', 'ai-seo-tool'); ?></strong></label>
                        <input type="date" name="bbseo_ga_custom_end" id="bbseo_ga_custom_end" value="<?php echo esc_attr($gaCustomEnd); ?>" class="regular-text" />
                    </p>
                </div>
                <p>
                    <strong><?php esc_html_e('Metrics to Sync', 'ai-seo-tool'); ?></strong><br>
                    <small class="description"><?php esc_html_e('Select the metrics you want to collect for this project.', 'ai-seo-tool'); ?></small>
                </p>
                <div class="bbseo-ga-metric-groups">
                    <?php foreach (GoogleAnalytics::metricGroups() as $group => $items): ?>
                        <fieldset style="margin-bottom:10px;">
                            <legend><?php echo esc_html($items['label']); ?></legend>
                            <?php foreach ($items['metrics'] as $metric => $label): ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="bbseo_ga_metrics[]" value="<?php echo esc_attr($metric); ?>" <?php checked(in_array($metric, $gaMetrics, true)); ?> />
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <hr />
            <h2><?php esc_html_e('Google Search Console', 'ai-seo-tool'); ?></h2>
            <p>
                <label for="bbseo_gsc_property"><strong><?php esc_html_e('Search Console Property', 'ai-seo-tool'); ?></strong></label>
                <input type="text" name="bbseo_gsc_property" id="bbseo_gsc_property" class="widefat" value="<?php echo esc_attr($gscProperty); ?>" placeholder="https://example.com/ or sc-domain:example.com" />
                <small class="description"><?php esc_html_e('Enter the verified property URL (URL prefix) or domain property identifier (e.g. sc-domain:example.com).', 'ai-seo-tool'); ?></small>
            </p>
            <p>
                <?php if ($gscConnected): ?>
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <strong><?php esc_html_e('Ready to sync Search Console data.', 'ai-seo-tool'); ?></strong>
                    <?php if ($gscLastSync): ?>
                        <span class="description"><?php printf(esc_html__('Last sync: %s', 'ai-seo-tool'), esc_html($gscLastSync)); ?></span>
                    <?php endif; ?>
                    <?php if ($gscLastError): ?>
                        <br><span class="description" style="color:#c92c2c;"><?php echo esc_html($gscLastError); ?></span>
                    <?php endif; ?>
                    <br>
                    <a class="button" href="<?php echo esc_url($gscSyncUrl); ?>"><?php esc_html_e('Sync Search Console Data Now', 'ai-seo-tool'); ?></a>
                <?php else: ?>
                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                    <strong><?php esc_html_e('Connect Google Analytics and set a property to enable Search Console syncing.', 'ai-seo-tool'); ?></strong>
                <?php endif; ?>
            </p>
                    <fieldset>
                        <legend><?php esc_html_e('Search Console Sync Settings', 'ai-seo-tool'); ?></legend>
                        <p>
                            <label for="bbseo_gsc_range"><strong><?php esc_html_e('Date Range', 'ai-seo-tool'); ?></strong></label>
                            <select name="bbseo_gsc_range" id="bbseo_gsc_range" class="widefat">
                                <?php foreach (GoogleAnalytics::rangeOptions() as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($gscRange, $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="description"><?php esc_html_e('Choose the range used when syncing Search Console data.', 'ai-seo-tool'); ?></small>
                        </p>
                        <div class="bbseo-gsc-custom-range" style="<?php echo $gscRange === 'custom' ? '' : 'display:none;'; ?>">
                            <p>
                                <label for="bbseo_gsc_custom_start"><strong><?php esc_html_e('Custom Start Date', 'ai-seo-tool'); ?></strong></label>
                                <input type="date" name="bbseo_gsc_custom_start" id="bbseo_gsc_custom_start" value="<?php echo esc_attr($gscCustomStart); ?>" class="regular-text" />
                            </p>
                            <p>
                                <label for="bbseo_gsc_custom_end"><strong><?php esc_html_e('Custom End Date', 'ai-seo-tool'); ?></strong></label>
                                <input type="date" name="bbseo_gsc_custom_end" id="bbseo_gsc_custom_end" value="<?php echo esc_attr($gscCustomEnd); ?>" class="regular-text" />
                            </p>
                        </div>
                        <p>
                            <strong><?php esc_html_e('Metrics to Sync', 'ai-seo-tool'); ?></strong><br>
                            <small class="description"><?php esc_html_e('Select the metrics you want to collect from Search Console.', 'ai-seo-tool'); ?></small>
                        </p>
                        <div class="bbseo-gsc-metrics">
                            <?php foreach (SearchConsole::metricOptions() as $metric => $label): ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="bbseo_gsc_metrics[]" value="<?php echo esc_attr($metric); ?>" <?php checked(in_array($metric, $gscMetrics, true)); ?> />
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
        <?php else: ?>
            <p class="description"><?php esc_html_e('Save the project first to configure Google Analytics.', 'ai-seo-tool'); ?></p>
        <?php endif; ?>
        <script>
        (function($){
            function toggleRange($select, $target) {
                if ($select.val() === 'custom') {
                    $target.slideDown();
                } else {
                    $target.slideUp();
                }
            }
            $('#BBSEO_ga_range').on('change', function(){
                toggleRange($(this), $('.bbseo-ga-custom-range'));
            }).trigger('change');
            $('#BBSEO_gsc_range').on('change', function(){
                toggleRange($(this), $('.bbseo-gsc-custom-range'));
            }).trigger('change');
        })(jQuery);
        </script>
        <?php
    }

    public static function saveMeta(int $postId, $post): void
    {
        error_log("Saving meta for post ID: " . $postId);
        if (!isset($_POST['bbseo_project_meta_nonce']) || !wp_verify_nonce($_POST['bbseo_project_meta_nonce'], 'bbseo_project_meta')) {
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

        $value = isset($_POST['bbseo_project_base_url']) ? esc_url_raw($_POST['bbseo_project_base_url']) : '';
        error_log("Saving base URL: " . $value);
        if ($value) {
            update_post_meta($postId, self::META_BASE_URL, $value);
        } else {
            delete_post_meta($postId, self::META_BASE_URL);
        }

        $schedule = isset($_POST['bbseo_project_schedule']) ? self::sanitizeSchedule($_POST['bbseo_project_schedule']) : 'manual';
        update_post_meta($postId, self::META_SCHEDULE, $schedule);

        // After saving META_BASE_URL and META_SCHEDULE:
        $slug = $post->post_name;
        $baseUrl  = get_post_meta($postId, self::META_BASE_URL, true) ?: '';
        $schedule = self::sanitizeSchedule(get_post_meta($postId, self::META_SCHEDULE, true) ?: 'manual');

        // Ensure project folder & write config.json
        Storage::ensureProject($slug);
        

        $cfgPath = Storage::projectDir($slug) . '/config.json';
        $config = is_file($cfgPath) ? json_decode(file_get_contents($cfgPath), true) : [];

        $config['enabled']   = $config['enabled']   ?? true;
        $config['frequency'] = $schedule;                 // 'manual'|'weekly'|'monthly'
        // Keep any custom seed_urls, but always include baseUrl (if present)
        $seed = is_array($config['seed_urls'] ?? null) ? $config['seed_urls'] : [];
        if ($baseUrl) { $seed[] = $baseUrl; }
        $config['seed_urls'] = array_values(array_unique(array_filter($seed)));

        $gaPropertyId = isset($_POST['bbseo_ga_property_id']) ? sanitize_text_field($_POST['bbseo_ga_property_id']) : '';
        $gaRange = isset($_POST['bbseo_ga_range']) ? sanitize_text_field($_POST['bbseo_ga_range']) : 'last_30_days';
        $gaCustomStart = isset($_POST['bbseo_ga_custom_start']) ? sanitize_text_field($_POST['bbseo_ga_custom_start']) : '';
        $gaCustomEnd = isset($_POST['bbseo_ga_custom_end']) ? sanitize_text_field($_POST['bbseo_ga_custom_end']) : '';
        $gaMetrics = isset($_POST['bbseo_ga_metrics']) && is_array($_POST['bbseo_ga_metrics']) ? array_map('sanitize_text_field', $_POST['bbseo_ga_metrics']) : GoogleAnalytics::defaultMetrics();
        $gscProperty = isset($_POST['bbseo_gsc_property']) ? sanitize_text_field($_POST['bbseo_gsc_property']) : '';
        $gscRange = isset($_POST['bbseo_gsc_range']) ? sanitize_text_field($_POST['bbseo_gsc_range']) : 'last_30_days';
        $gscCustomStart = isset($_POST['bbseo_gsc_custom_start']) ? sanitize_text_field($_POST['bbseo_gsc_custom_start']) : '';
        $gscCustomEnd = isset($_POST['bbseo_gsc_custom_end']) ? sanitize_text_field($_POST['bbseo_gsc_custom_end']) : '';
        $gscMetrics = isset($_POST['bbseo_gsc_metrics']) && is_array($_POST['bbseo_gsc_metrics']) ? array_map('sanitize_text_field', $_POST['bbseo_gsc_metrics']) : SearchConsole::defaultMetrics();
        if (!$gaMetrics) {
            $gaMetrics = GoogleAnalytics::defaultMetrics();
        }
        if (!$gscMetrics) {
            $gscMetrics = SearchConsole::defaultMetrics();
        }

        if (!isset($config['analytics'])) {
            $config['analytics'] = [];
        }
        if (!isset($config['analytics']['ga'])) {
            $config['analytics']['ga'] = [];
        }
        $config['analytics']['ga']['property_id'] = $gaPropertyId;
        $config['analytics']['ga']['range'] = $gaRange ?: 'last_30_days';
        if ($gaRange === 'custom') {
            $config['analytics']['ga']['custom_start'] = $gaCustomStart ?: null;
            $config['analytics']['ga']['custom_end'] = $gaCustomEnd ?: null;
        } else {
            unset($config['analytics']['ga']['custom_start'], $config['analytics']['ga']['custom_end']);
        }
        $config['analytics']['ga']['metrics'] = array_values(array_unique($gaMetrics));
        if (!isset($config['analytics']['gsc'])) {
            $config['analytics']['gsc'] = [];
        }
        $config['analytics']['gsc']['property'] = $gscProperty;
        $config['analytics']['gsc']['range'] = $gscRange ?: 'last_30_days';
        if ($gscRange === 'custom') {
            $config['analytics']['gsc']['custom_start'] = $gscCustomStart ?: null;
            $config['analytics']['gsc']['custom_end'] = $gscCustomEnd ?: null;
        } else {
            unset($config['analytics']['gsc']['custom_start'], $config['analytics']['gsc']['custom_end']);
        }
        $config['analytics']['gsc']['metrics'] = array_values(array_unique($gscMetrics));

        Storage::writeJson($cfgPath, $config);
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
