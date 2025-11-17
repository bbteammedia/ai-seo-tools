<?php

namespace BBSEO\Admin;

use BBSEO\AI\ArticleGenerator;
use BBSEO\Template\ArticleTemplates;

class ArticleGeneratorPage
{
    public static function bootstrap(): void
    {
        add_action('admin_menu', [self::class, 'register']);
        add_action('admin_post_bbseo_save_article_generator', [self::class, 'handleSave']);
        add_action('admin_post_bbseo_run_article_generator', [self::class, 'handleManualRun']);
    }

    public static function register(): void
    {
        add_submenu_page(
            'edit.php?post_type=bbseo_project',
            __('AI Article Generator', 'ai-seo-tool'),
            __('AI Articles', 'ai-seo-tool'),
            'manage_options',
            'bbseo-ai-articles',
            [self::class, 'render'],
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'ai-seo-tool'));
        }

        $settings = ArticleGenerator::getSettings();
        $structures = ArticleGenerator::availableStructures();
        $postTypes = self::getPostTypes();
        $templates = ArticleTemplates::all();
        $imageProviders = ArticleGenerator::imageProviders();
        $nextRun = wp_next_scheduled(ArticleGenerator::CRON_HOOK);
        $notice = isset($_GET['bbseo_ai_status']) ? sanitize_text_field(wp_unslash($_GET['bbseo_ai_status'])) : '';
        $message = isset($_GET['bbseo_ai_message']) ? sanitize_text_field(wp_unslash($_GET['bbseo_ai_message'])) : '';
        $recentPost = !empty($settings['last_post_id']) ? get_post((int) $settings['last_post_id']) : null;
        ?>
        <div class="wrap bbseo-ai-generator">
            <h1><?php esc_html_e('AI Article Generator', 'ai-seo-tool'); ?></h1>

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings updated.', 'ai-seo-tool'); ?></p></div>
            <?php elseif ($notice === 'manual_success'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Article drafted successfully. Review it below.', 'ai-seo-tool'); ?>
                    <?php if (!empty($_GET['bbseo_ai_post'])): ?>
                        <?php $postId = (int) $_GET['bbseo_ai_post']; ?>
                        <a href="<?php echo esc_url(get_edit_post_link($postId)); ?>" class="button button-small" target="_blank" rel="noopener"><?php esc_html_e('Open Draft', 'ai-seo-tool'); ?></a>
                    <?php endif; ?>
                </p></div>
            <?php elseif ($notice === 'manual_error'): ?>
                <div class="notice notice-error"><p><?php echo esc_html($message ?: __('Unable to generate the article. Check the logs for details.', 'ai-seo-tool')); ?></p></div>
            <?php endif; ?>

            <div class="bbseo-ai-panels">
                <div class="bbseo-ai-panel bbseo-ai-panel--settings">
                    <h2><?php esc_html_e('Generation Settings', 'ai-seo-tool'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('bbseo_save_article_generator'); ?>
                        <input type="hidden" name="action" value="bbseo_save_article_generator" />
                        <table class="form-table" role="presentation">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Enable automation', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="bbseo_article[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> /> <?php esc_html_e('Produce articles on a schedule', 'ai-seo-tool'); ?></label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Schedule', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <select name="bbseo_article[schedule]">
                                            <?php
                                            $scheduleOptions = [
                                                'manual' => __('Manual only', 'ai-seo-tool'),
                                                'daily' => __('Daily', 'ai-seo-tool'),
                                                'weekly' => __('Weekly', 'ai-seo-tool'),
                                                'monthly' => __('Monthly', 'ai-seo-tool'),
                                            ];
        foreach ($scheduleOptions as $value => $label) :
            ?>
                                                <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['schedule'], $value); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($nextRun): ?>
                                            <p class="description"><?php printf(
                                                esc_html__('Next run: %s', 'ai-seo-tool'),
                                                esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $nextRun), 'M j, Y g:i a')),
                                            ); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Post type', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <select name="bbseo_article[post_type]">
                                            <?php foreach ($postTypes as $postType => $label): ?>
                                                <option value="<?php echo esc_attr($postType); ?>" <?php selected($settings['post_type'], $postType); ?>><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('The article template is tailored for this post type.', 'ai-seo-tool'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Template', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <select name="bbseo_article[template]">
                                            <?php foreach ($templates as $slug => $template): ?>
                                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($settings['template'], $slug); ?>>
                                                    <?php echo esc_html(sprintf('%s (%s)', $template['label'], $template['post_type'])); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php echo esc_html($templates[$settings['template']]['description'] ?? ''); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Tone', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <input type="text" class="regular-text" name="bbseo_article[tone]" value="<?php echo esc_attr($settings['tone']); ?>" />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Context & brand voice', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <textarea name="bbseo_article[context]" rows="4" class="large-text"><?php echo esc_textarea($settings['context']); ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Topic ideas', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <textarea name="bbseo_article[topics_pool]" rows="3" class="large-text" placeholder="<?php esc_attr_e('e.g. Local SEO for restaurants, Seasonal ecommerce SEO, Technical fixes for publishers', 'ai-seo-tool'); ?>"><?php echo esc_textarea($settings['topics_pool']); ?></textarea>
                                        <p class="description"><?php esc_html_e('Provide comma or newline separated prompts. The generator randomly picks one per article to avoid repetitive SaaS drafts.', 'ai-seo-tool'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Content structure', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <?php foreach ($structures as $key => $info): ?>
                                            <label style="display:block;margin-bottom:6px;">
                                                <input type="checkbox" name="bbseo_article[structures][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $settings['structures'], true)); ?> />
                                                <strong><?php echo esc_html($info['label']); ?></strong><br />
                                                <span class="description"><?php echo esc_html($info['prompt']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Categories', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <?php echo self::renderTaxonomyChecklist('category', $settings['categories'], $settings['post_type']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Tags', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <?php echo self::renderTaxonomyChecklist('post_tag', $settings['tags'], $settings['post_type']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Store body in meta key', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <input type="text" class="regular-text" name="bbseo_article[content_meta_key]" value="<?php echo esc_attr($settings['content_meta_key']); ?>" placeholder="_custom_content_field" />
                                        <p class="description"><?php esc_html_e('Leave blank to write the HTML into post_content. Provide a meta key if your theme pulls content from a custom field.', 'ai-seo-tool'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('SEO title/meta', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="bbseo_article[seo][enabled]" value="1" <?php checked(!empty($settings['seo']['enabled'])); ?> /> <?php esc_html_e('Write SEO meta fields', 'ai-seo-tool'); ?></label>
                                        <div style="margin-top:8px;">
                                            <label><?php esc_html_e('Title meta key', 'ai-seo-tool'); ?><br />
                                                <input type="text" name="bbseo_article[seo][title_key]" value="<?php echo esc_attr($settings['seo']['title_key']); ?>" class="regular-text" />
                                            </label>
                                        </div>
                                        <div style="margin-top:8px;">
                                            <label><?php esc_html_e('Description meta key', 'ai-seo-tool'); ?><br />
                                                <input type="text" name="bbseo_article[seo][description_key]" value="<?php echo esc_attr($settings['seo']['description_key']); ?>" class="regular-text" />
                                            </label>
                                        </div>
                                        <p class="description"><?php esc_html_e('Defaults match Yoast SEO. Adjust if you use a different plugin.', 'ai-seo-tool'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Featured image', 'ai-seo-tool'); ?></th>
                                    <td>
                                        <label><input type="checkbox" name="bbseo_article[featured_image][enabled]" value="1" <?php checked(!empty($settings['featured_image']['enabled'])); ?> /> <?php esc_html_e('Generate hero image automatically', 'ai-seo-tool'); ?></label>
                                        <p><label><?php esc_html_e('Style hint', 'ai-seo-tool'); ?><br />
                                            <input type="text" class="regular-text" name="bbseo_article[featured_image][style_hint]" value="<?php echo esc_attr($settings['featured_image']['style_hint']); ?>" />
                                        </label></p>
                                        <p>
                                            <label><?php esc_html_e('Image provider', 'ai-seo-tool'); ?>
                                                <select name="bbseo_article[image_provider]">
                                                    <?php foreach ($imageProviders as $key => $provider): ?>
                                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['image_provider'], $key); ?>>
                                                            <?php echo esc_html($provider['label']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                        </p>
                                        <?php if (!empty($imageProviders[$settings['image_provider']]['description'])): ?>
                                            <p class="description"><?php echo esc_html($imageProviders[$settings['image_provider']]['description']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="submit">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Save Settings', 'ai-seo-tool'); ?></button>
                        </p>
                    </form>
                </div>

                <div class="bbseo-ai-panel bbseo-ai-panel--actions">
                    <h2><?php esc_html_e('Manual Generation', 'ai-seo-tool'); ?></h2>
                    <p><?php esc_html_e('Need an article right now? Run the generator immediately and it will save a draft using the settings above.', 'ai-seo-tool'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('bbseo_run_article_generator'); ?>
                        <input type="hidden" name="action" value="bbseo_run_article_generator" />
                        <button type="submit" class="button button-secondary"><?php esc_html_e('Generate article now', 'ai-seo-tool'); ?></button>
                    </form>

                    <hr />
                    <h3><?php esc_html_e('Latest status', 'ai-seo-tool'); ?></h3>
                    <ul>
                        <li><strong><?php esc_html_e('Last run', 'ai-seo-tool'); ?>:</strong>
                            <?php echo $settings['last_run'] ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $settings['last_run']), 'M j, Y g:i a')) : esc_html__('Never', 'ai-seo-tool'); ?>
                        </li>
                        <li><strong><?php esc_html_e('Last draft', 'ai-seo-tool'); ?>:</strong>
                            <?php if ($recentPost): ?>
                                <a href="<?php echo esc_url(get_edit_post_link($recentPost->ID)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_the_title($recentPost)); ?></a>
                            <?php else: ?>
                                <em><?php esc_html_e('No draft yet', 'ai-seo-tool'); ?></em>
                            <?php endif; ?>
                        </li>
                        <li><strong><?php esc_html_e('Last error', 'ai-seo-tool'); ?>:</strong>
                            <?php echo $settings['last_error'] ? esc_html($settings['last_error']) : esc_html__('None', 'ai-seo-tool'); ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'ai-seo-tool'));
        }
        check_admin_referer('bbseo_save_article_generator');
        $input = isset($_POST['bbseo_article']) ? wp_unslash($_POST['bbseo_article']) : [];
        $input = is_array($input) ? $input : [];
        ArticleGenerator::saveSettings($input);
        $target = add_query_arg(['bbseo_ai_status' => 'saved'], self::pageUrl());
        if (!wp_safe_redirect($target)) {
            wp_redirect($target);
        }
        exit;
    }

    public static function handleManualRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'ai-seo-tool'));
        }
        check_admin_referer('bbseo_run_article_generator');
        $result = ArticleGenerator::generateArticle(true);
        if (is_wp_error($result)) {
            $target = add_query_arg([
                'bbseo_ai_status' => 'manual_error',
                'bbseo_ai_message' => $result->get_error_message(),
            ], self::pageUrl());
            if (!wp_safe_redirect($target)) {
                wp_redirect($target);
            }
            exit;
        }

        $target = add_query_arg([
            'bbseo_ai_status' => 'manual_success',
            'bbseo_ai_post' => $result['post_id'],
        ], self::pageUrl());
        if (!wp_safe_redirect($target)) {
            wp_redirect($target);
        }
        exit;
    }

    private static function pageUrl(): string
    {
        return add_query_arg(
            [
                'post_type' => 'bbseo_project',
                'page' => 'bbseo-ai-articles',
            ],
            admin_url('edit.php'),
        );
    }

    private static function getPostTypes(): array
    {
        $objects = get_post_types(['public' => true], 'objects');
        $list = [];
        foreach ($objects as $postType => $obj) {
            $list[$postType] = $obj->labels->singular_name ?? $postType;
        }
        return $list;
    }

    private static function renderTaxonomyChecklist(string $taxonomy, array $selected, string $postType): string
    {
        if (!taxonomy_exists($taxonomy) || !is_object_in_taxonomy($postType, $taxonomy)) {
            return '<p class="description">' . esc_html__('This post type does not use this taxonomy.', 'ai-seo-tool') . '</p>';
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || !$terms) {
            return '<p class="description">' . esc_html__('No terms available yet.', 'ai-seo-tool') . '</p>';
        }

        ob_start();
        echo '<div class="bbseo-term-checklist">';
        foreach ($terms as $term) {
            printf(
                '<label><input type="checkbox" name="bbseo_article[%1$s][]" value="%2$d" %3$s /> %4$s</label><br />',
                esc_attr($taxonomy === 'category' ? 'categories' : 'tags'),
                (int) $term->term_id,
                checked(in_array((int) $term->term_id, $selected, true), true, false),
                esc_html($term->name),
            );
        }
        echo '</div>';
        return ob_get_clean();
    }
}
