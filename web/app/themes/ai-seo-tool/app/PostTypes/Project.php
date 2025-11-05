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
