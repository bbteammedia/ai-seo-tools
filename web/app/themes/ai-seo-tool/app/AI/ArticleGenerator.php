<?php

namespace BBSEO\AI;

use BBSEO\Template\ArticleTemplates;
use WP_Error;

class ArticleGenerator
{
    public const OPTION_KEY = 'bbseo_article_generator';
    public const CRON_HOOK = 'bbseo_generate_article';
    public const WEEKLY_SCHEDULE = 'bbseo_articles_weekly';
    public const MONTHLY_SCHEDULE = 'bbseo_articles_monthly';

    public static function bootstrap(): void
    {
        add_filter('cron_schedules', [self::class, 'registerSchedules']);
        add_action('init', [self::class, 'syncSchedule'], 10, 0);
        add_action('switch_theme', [self::class, 'clearSchedule'], 10, 0);
        add_action(self::CRON_HOOK, [self::class, 'handleCron']);
    }

    public static function registerSchedules(array $schedules): array
    {
        $schedules[self::WEEKLY_SCHEDULE] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Once Weekly (BBSEO AI)', 'ai-seo-tool'),
        ];
        $schedules[self::MONTHLY_SCHEDULE] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Once Monthly (BBSEO AI)', 'ai-seo-tool'),
        ];
        return $schedules;
    }

    public static function defaults(): array
    {
        $defaultTemplate = ArticleTemplates::defaultFor('post');
        return [
            'enabled' => false,
            'schedule' => 'manual',
            'post_type' => 'post',
            'template' => $defaultTemplate,
            'tone' => __('Confident, encouraging, and data-backed.', 'ai-seo-tool'),
            'context' => __('You are writing for Blackbird SEO — a helpful strategist that mixes storytelling with tactical SEO advice.', 'ai-seo-tool'),
            'topics_pool' => '',
            'structures' => ['structured_headings', 'full_html'],
            'categories' => [],
            'tags' => [],
            'content_meta_key' => '',
            'seo' => [
                'enabled' => true,
                'title_key' => '_yoast_wpseo_title',
                'description_key' => '_yoast_wpseo_metadesc',
            ],
            'featured_image' => [
                'enabled' => true,
                'style_hint' => __('ultra realistic photo, cinematic lighting, 16:9 cover art', 'ai-seo-tool'),
            ],
            'image_provider' => 'gemini_imagen',
            'last_run' => null,
            'last_post_id' => null,
            'last_error' => null,
        ];
    }

    public static function getSettings(): array
    {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $defaults = self::defaults();
        $settings = wp_parse_args($saved, $defaults);
        $settings['structures'] = self::sanitizeStructures($settings['structures'] ?? []);
        $settings['categories'] = array_values(array_map('absint', (array) ($settings['categories'] ?? [])));
        $settings['tags'] = array_values(array_map('absint', (array) ($settings['tags'] ?? [])));
        if (!is_array($settings['seo'])) {
            $settings['seo'] = $defaults['seo'];
        }
        if (!is_array($settings['featured_image'])) {
            $settings['featured_image'] = $defaults['featured_image'];
        }
        $settings['schedule'] = in_array($settings['schedule'], ['manual', 'daily', 'weekly', 'monthly'], true)
            ? $settings['schedule']
            : 'manual';
        $settings['post_type'] = post_type_exists($settings['post_type']) ? $settings['post_type'] : 'post';
        $settings['template'] = $settings['template'] ?: ArticleTemplates::defaultFor($settings['post_type']);
        $settings['content_meta_key'] = self::sanitizeMetaKey($settings['content_meta_key'] ?? '');
        $settings['tone'] = sanitize_text_field($settings['tone'] ?? '');
        $settings['context'] = wp_kses_post($settings['context'] ?? '');
        $settings['topics_pool'] = sanitize_textarea_field($settings['topics_pool'] ?? '');

        $providers = array_keys(self::imageProviders());
        if (!in_array($settings['image_provider'], $providers, true)) {
            $settings['image_provider'] = $defaults['image_provider'];
        }

        return $settings;
    }

    public static function saveSettings(array $raw): array
    {
        $settings = self::sanitizeSettings($raw);
        update_option(self::OPTION_KEY, $settings);
        self::syncSchedule($settings);
        return $settings;
    }

    public static function syncSchedule(?array $settings = null): void
    {
        $settings = $settings ?: self::getSettings();
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        $enabled = !empty($settings['enabled']);
        $schedule = $settings['schedule'] ?? 'manual';

        if (!$enabled || $schedule === 'manual') {
            if ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }
            return;
        }

        $recurrence = self::mapScheduleToRecurrence($schedule);
        if (!$recurrence) {
            return;
        }

        $current = wp_get_schedule(self::CRON_HOOK);
        if ($timestamp && $current === $recurrence) {
            return;
        }

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, $recurrence, self::CRON_HOOK);
    }

    public static function clearSchedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function handleCron(): void
    {
        $result = self::generateArticle(false);
        if (is_wp_error($result)) {
            self::log('cron_failure', ['error' => $result->get_error_message()]);
        } else {
            self::log('cron_success', ['post_id' => $result['post_id'] ?? 0]);
        }
    }

    public static function generateArticle(bool $manual = true)
    {
        $settings = self::getSettings();
        if (empty($settings['enabled']) && !$manual) {
            return new WP_Error('bbseo_article_disabled', __('AI article automation is disabled.', 'ai-seo-tool'));
        }

        $template = ArticleTemplates::get($settings['template']);
        if (!$template) {
            $templateSlug = ArticleTemplates::defaultFor($settings['post_type']);
            $template = ArticleTemplates::get($templateSlug) ?: ArticleTemplates::get('post_simple');
            $settings['template'] = $templateSlug;
        }

        $prompt = self::buildPrompt($settings, $template);
        $schema = self::articleSchema();
        $article = Gemini::structuredContent($prompt, $schema, [
            'temperature' => 0.65,
            'maxOutputTokens' => 2048,
        ]);

        if (!$article) {
            $error = new WP_Error('bbseo_ai_empty', __('Gemini did not return an article payload.', 'ai-seo-tool'));
            self::updateLastRun([
                'last_error' => $error->get_error_message(),
            ]);
            return $error;
        }

        $saved = self::persistArticle($article, $settings);
        if (is_wp_error($saved)) {
            self::updateLastRun([
                'last_error' => $saved->get_error_message(),
            ]);
            return $saved;
        }

        self::updateLastRun([
            'last_post_id' => $saved['post_id'],
            'last_error' => null,
        ]);

        return $saved;
    }

    public static function availableStructures(): array
    {
        return [
            'structured_headings' => [
                'label' => __('Structured Headings', 'ai-seo-tool'),
                'prompt' => __('Use logical H2/H3 headings that mirror the outline.', 'ai-seo-tool'),
            ],
            'bullet_lists' => [
                'label' => __('Bullet Lists', 'ai-seo-tool'),
                'prompt' => __('When sharing steps or takeaways, format them as unordered bullet lists.', 'ai-seo-tool'),
            ],
            'numbered_lists' => [
                'label' => __('Numbered Steps', 'ai-seo-tool'),
                'prompt' => __('Use ordered lists for frameworks or chronological steps.', 'ai-seo-tool'),
            ],
            'full_html' => [
                'label' => __('Full HTML Body', 'ai-seo-tool'),
                'prompt' => __('Return well-formed HTML paragraphs, headings, and lists suitable for post_content.', 'ai-seo-tool'),
            ],
        ];
    }

    public static function imageProviders(): array
    {
        return [
            'gemini_imagen' => [
                'label' => __('Gemini Flash Image (API)', 'ai-seo-tool'),
                'description' => __('Calls gemini-2.5-flash-image via your GEMINI_API_KEY to return 16:9 hero art.', 'ai-seo-tool'),
            ],
            'pollinations' => [
                'label' => __('Pollinations (public endpoint)', 'ai-seo-tool'),
                'description' => __('Free community image API. Quality may vary and has no usage guarantees.', 'ai-seo-tool'),
            ],
            'picsum' => [
                'label' => __('Picsum placeholder', 'ai-seo-tool'),
                'description' => __('Random photographic placeholder based on the prompt hash.', 'ai-seo-tool'),
            ],
        ];
    }

    public static function mapScheduleToRecurrence(string $schedule): ?string
    {
        return match ($schedule) {
            'daily' => 'daily',
            'weekly' => self::WEEKLY_SCHEDULE,
            'monthly' => self::MONTHLY_SCHEDULE,
            default => null,
        };
    }

    private static function sanitizeSettings(array $raw): array
    {
        $defaults = self::defaults();
        $input = wp_parse_args($raw, $defaults);
        $input['enabled'] = !empty($raw['enabled']);
        $input['schedule'] = in_array($raw['schedule'] ?? '', ['manual', 'daily', 'weekly', 'monthly'], true)
            ? $raw['schedule']
            : 'manual';
        $input['post_type'] = post_type_exists($raw['post_type'] ?? '') ? $raw['post_type'] : 'post';
        $input['template'] = sanitize_key($raw['template'] ?? ArticleTemplates::defaultFor($input['post_type']));
        $input['tone'] = sanitize_text_field($raw['tone'] ?? $defaults['tone']);
        $input['context'] = wp_kses_post($raw['context'] ?? $defaults['context']);
        $input['topics_pool'] = sanitize_textarea_field($raw['topics_pool'] ?? '');
        $input['structures'] = self::sanitizeStructures($raw['structures'] ?? []);
        $input['categories'] = array_values(array_map('absint', (array) ($raw['categories'] ?? [])));
        $input['tags'] = array_values(array_map('absint', (array) ($raw['tags'] ?? [])));
        $input['content_meta_key'] = self::sanitizeMetaKey($raw['content_meta_key'] ?? '');

        $seo = is_array($raw['seo'] ?? null) ? $raw['seo'] : [];
        $input['seo'] = [
            'enabled' => !empty($seo['enabled']),
            'title_key' => self::sanitizeMetaKey($seo['title_key'] ?? $defaults['seo']['title_key']),
            'description_key' => self::sanitizeMetaKey($seo['description_key'] ?? $defaults['seo']['description_key']),
        ];

        $featured = is_array($raw['featured_image'] ?? null) ? $raw['featured_image'] : [];
        $input['featured_image'] = [
            'enabled' => !empty($featured['enabled']),
            'style_hint' => sanitize_text_field($featured['style_hint'] ?? $defaults['featured_image']['style_hint']),
        ];

        $provider = sanitize_key($raw['image_provider'] ?? $defaults['image_provider']);
        $allowedProviders = array_keys(self::imageProviders());
        if (!in_array($provider, $allowedProviders, true)) {
            $provider = $defaults['image_provider'];
        }
        $input['image_provider'] = $provider;

        // Keep last run metadata if it existed
        $current = self::getSettings();
        $input['last_run'] = $current['last_run'] ?? null;
        $input['last_post_id'] = $current['last_post_id'] ?? null;
        $input['last_error'] = $current['last_error'] ?? null;

        return $input;
    }

    private static function sanitizeStructures($value): array
    {
        $catalog = self::availableStructures();
        $allowed = array_keys($catalog);
        $selected = array_map('sanitize_key', (array) $value);
        $selected = array_values(array_intersect($selected, $allowed));
        if (!$selected) {
            $selected = ['structured_headings', 'full_html'];
        }
        return $selected;
    }

    private static function sanitizeMetaKey(?string $key): string
    {
        $key = (string) $key;
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        return preg_replace('/[^A-Za-z0-9_\-]/', '', $key);
    }

    private static function pickFocusTopic(array $settings, array $template): string
    {
        $custom = self::extractTopics($settings['topics_pool'] ?? '');
        if ($custom) {
            return (string) $custom[array_rand($custom)];
        }

        $defaults = array_filter(array_map('trim', (array) ($template['default_topics'] ?? [])));
        if ($defaults) {
            return (string) $defaults[array_rand($defaults)];
        }

        return __('Timely SEO insight tailored to the selected audience.', 'ai-seo-tool');
    }

    private static function extractTopics(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\r\n,]+/', $raw);
        $parts = array_filter(array_map('trim', (array) $parts));
        return array_values($parts);
    }

    private static function buildPrompt(array $settings, array $template): string
    {
        $outline = ArticleTemplates::outlineSummary($template);
        $structures = self::availableStructures();
        $structurePrompts = [];
        foreach ($settings['structures'] as $structureKey) {
            if (!empty($structures[$structureKey]['prompt'])) {
                $structurePrompts[] = '- ' . $structures[$structureKey]['prompt'];
            }
        }

        $categoryNames = self::termNames('category', $settings['categories'] ?? []);
        $tagNames = self::termNames('post_tag', $settings['tags'] ?? []);
        $context = trim(wp_strip_all_tags($settings['context']));
        $tone = $settings['tone'] ?: __('Confident, encouraging, data-backed.', 'ai-seo-tool');
        $writingNotes = implode("\n", array_map('wp_strip_all_tags', (array) ($template['writing_notes'] ?? [])));
        $focusTopic = self::pickFocusTopic($settings, $template);
        $defaultTopics = implode(', ', (array) ($template['default_topics'] ?? []));
        $cta = wp_strip_all_tags($template['cta'] ?? __('Invite the reader to continue the conversation.', 'ai-seo-tool'));
        $metaKey = $settings['content_meta_key'] ? sprintf(
            __('Store the final HTML inside the custom field "%s".', 'ai-seo-tool'),
            $settings['content_meta_key'],
        ) : __('Write semantic HTML ready for WordPress post_content.', 'ai-seo-tool');

        $structureText = $structurePrompts ? implode("\n", $structurePrompts) : '';
        $categoryLine = $categoryNames ? sprintf(__('Relevant categories: %s.', 'ai-seo-tool'), implode(', ', $categoryNames)) : '';
        $tagLine = $tagNames ? sprintf(__('Suggested tags: %s.', 'ai-seo-tool'), implode(', ', $tagNames)) : '';

        $ideaSeed = strtoupper(substr(md5(wp_generate_uuid4()), 0, 10));

        return <<<PROMPT
You are an editorial strategist ghost-writing for a premium SEO agency.
Goal: craft a publish-ready article for the "{$settings['post_type']}" post type by following the supplied template outline.

Tone to emulate: {$tone}
Brand context: {$context}
Recurring topics to weave in: {$defaultTopics}
Primary topic to explore in this draft: {$focusTopic}
Idea seed to keep this article unique: {$ideaSeed}
CTA requirement: {$cta}
{$categoryLine}
{$tagLine}

Template outline:
{$outline}

Formatting guidance:
{$structureText}

Additional writing notes:
{$writingNotes}

{$metaKey}

Hard requirements:
- Cite at least one statistic or insight.
- Keep paragraphs concise (max 4 sentences).
- Return JSON only.
- Keep seo_title under 65 chars and seo_description under 155 chars.
- Provide an "image_prompt" describing a cinematic hero image related to the article. Append the style hint "{$settings['featured_image']['style_hint']}".

Return JSON with keys:
- title
- excerpt (1-2 sentences)
- seo_title
- seo_description
- body_html (rich HTML body that already includes headings, lists, bold text as needed)
- section_summaries (array of {id, heading, summary})
- image_prompt
- keywords (array of strings)
PROMPT;
    }

    private static function articleSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'excerpt' => ['type' => 'string'],
                'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
                'body_html' => ['type' => 'string'],
                'section_summaries' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'heading' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['heading', 'summary'],
                    ],
                ],
                'image_prompt' => ['type' => 'string'],
                'keywords' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['title', 'body_html', 'seo_title', 'seo_description'],
        ];
    }

    private static function persistArticle(array $article, array $settings)
    {
        $content = wp_kses_post($article['body_html'] ?? '');
        if ($content === '') {
            return new WP_Error('bbseo_body_empty', __('AI response was missing body_html.', 'ai-seo-tool'));
        }

        $postData = [
            'post_type' => $settings['post_type'] ?? 'post',
            'post_status' => 'draft',
            'post_title' => sanitize_text_field($article['title'] ?? __('AI Article', 'ai-seo-tool')),
            'post_excerpt' => sanitize_textarea_field($article['excerpt'] ?? ''),
            'post_content' => $settings['content_meta_key'] ? '' : $content,
        ];

        $postId = wp_insert_post($postData, true);
        if (is_wp_error($postId)) {
            return $postId;
        }

        if ($settings['content_meta_key']) {
            update_post_meta($postId, $settings['content_meta_key'], $content);
        }

        if (!empty($article['keywords'])) {
            update_post_meta($postId, '_bbseo_ai_keywords', wp_json_encode(array_values((array) $article['keywords'])));
        }

        if (!empty($article['section_summaries'])) {
            update_post_meta($postId, '_bbseo_ai_outline', wp_json_encode(array_values((array) $article['section_summaries'])));
        }

        self::maybeAssignTerms($postId, $settings);
        self::maybeStoreSeoMeta($postId, $article, $settings);
        self::maybeGenerateFeaturedImage($postId, $article, $settings);

        do_action('bbseo_ai_article_created', $postId, $article, $settings);

        return [
            'post_id' => $postId,
            'article' => $article,
        ];
    }

    private static function maybeAssignTerms(int $postId, array $settings): void
    {
        $postType = $settings['post_type'] ?? 'post';
        if (!empty($settings['categories']) && taxonomy_exists('category') && is_object_in_taxonomy($postType, 'category')) {
            wp_set_post_terms($postId, $settings['categories'], 'category', false);
        }

        if (!empty($settings['tags']) && taxonomy_exists('post_tag') && is_object_in_taxonomy($postType, 'post_tag')) {
            wp_set_post_terms($postId, $settings['tags'], 'post_tag', false);
        }
    }

    private static function maybeStoreSeoMeta(int $postId, array $article, array $settings): void
    {
        if (empty($settings['seo']['enabled'])) {
            return;
        }
        $titleKey = $settings['seo']['title_key'] ?: '_yoast_wpseo_title';
        $descKey = $settings['seo']['description_key'] ?: '_yoast_wpseo_metadesc';

        if ($titleKey) {
            update_post_meta($postId, $titleKey, sanitize_text_field($article['seo_title'] ?? $article['title'] ?? ''));
        }
        if ($descKey) {
            update_post_meta($postId, $descKey, sanitize_text_field($article['seo_description'] ?? ''));
        }
    }

    private static function maybeGenerateFeaturedImage(int $postId, array $article, array $settings): void
    {
        if (empty($settings['featured_image']['enabled'])) {
            return;
        }

        $prompt = trim(($article['image_prompt'] ?? '') . ' ' . ($settings['featured_image']['style_hint'] ?? ''));
        if ($prompt === '') {
            return;
        }

        $defaultProvider = self::defaults()['image_provider'];
        $image = self::fetchImageFromPrompt($prompt, $settings['image_provider'] ?? $defaultProvider);
        if (!$image || is_wp_error($image)) {
            self::log('featured_image_failed', ['message' => $image instanceof WP_Error ? $image->get_error_message() : '']);
            return;
        }

        set_post_thumbnail($postId, $image);
    }

    private static function fetchImageFromPrompt(string $prompt, string $provider)
    {
        if ($provider === 'gemini_imagen') {
            $image = self::requestImagenBinary($prompt);
            if (is_wp_error($image)) {
                return $image;
            }

            return self::persistImageBinary($image['binary'], $image['mime'] ?? 'image/png');
        }

        $url = '';
        switch ($provider) {
            case 'picsum':
                $seed = rawurlencode(substr(md5($prompt), 0, 12));
                $url = "https://picsum.photos/seed/{$seed}/1200/628";
                break;
            default:
                $encoded = rawurlencode($prompt);
                $url = "https://image.pollinations.ai/prompt/{$encoded}?width=1200&height=628";
                break;
        }

        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            return new WP_Error('bbseo_image_empty', __('Image provider returned an empty body.', 'ai-seo-tool'));
        }

        $mime = wp_remote_retrieve_header($response, 'content-type') ?: 'image/jpeg';

        return self::persistImageBinary($body, $mime);
    }

    private static function termNames(string $taxonomy, array $ids): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }
        $names = [];
        foreach ($ids as $termId) {
            $term = get_term((int) $termId, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $names[] = $term->name;
            }
        }
        return $names;
    }

    private static function requestImagenBinary(string $prompt)
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: '';
        if (!$apiKey) {
            return new WP_Error('bbseo_imagen_key_missing', __('GEMINI_API_KEY is required to generate featured images.', 'ai-seo-tool'));
        }

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent';
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => $prompt . "\n\nImage requirements: cinematic 16:9 composition, ultra realistic, high detail.",
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
            ],
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('bbseo_imagen_invalid', __('Gemini Imagen returned an invalid response.', 'ai-seo-tool'));
        }

        if (!empty($body['error']['message'])) {
            return new WP_Error('bbseo_imagen_error', sprintf(__('Gemini Imagen error: %s', 'ai-seo-tool'), $body['error']['message']));
        }

        if ($code >= 400) {
            $message = $body['error']['message'] ?? sprintf(__('HTTP %d from Gemini Imagen.', 'ai-seo-tool'), $code);
            return new WP_Error('bbseo_imagen_http_error', $message);
        }

        $base64 = self::extractImagenBase64($body);
        if (!$base64) {
            return new WP_Error('bbseo_imagen_missing', __('Gemini Imagen response did not include image bytes.', 'ai-seo-tool'));
        }

        $binary = base64_decode($base64);
        if ($binary === false) {
            return new WP_Error('bbseo_imagen_decode', __('Unable to decode Gemini Imagen bytes.', 'ai-seo-tool'));
        }

        return [
            'binary' => $binary,
            'mime' => self::extractImagenMime($body) ?? 'image/png',
        ];
    }

    private static function extractImagenBase64(array $payload): ?string
    {
        if (!empty($payload['candidates']) && is_array($payload['candidates'])) {
            foreach ($payload['candidates'] as $candidate) {
                $parts = $candidate['content']['parts'] ?? [];
                if (!is_array($parts)) {
                    continue;
                }
                foreach ($parts as $part) {
                    $inline = $part['inline_data']['data'] ?? null;
                    if ($inline) {
                        return $inline;
                    }
                    $candidateData = self::firstFilledString([
                        $part['image']['imageBytes'] ?? null,
                        $part['image']['bytesBase64Encoded'] ?? null,
                    ]);
                    if ($candidateData) {
                        return $candidateData;
                    }
                }
            }
        }

        if (!empty($payload['generatedImages']) && is_array($payload['generatedImages'])) {
            foreach ($payload['generatedImages'] as $generated) {
                $candidate = self::firstFilledString([
                    $generated['image']['imageBytes'] ?? null,
                    $generated['image']['bytesBase64Encoded'] ?? null,
                    $generated['imageBytes'] ?? null,
                    $generated['bytesBase64Encoded'] ?? null,
                ]);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        if (!empty($payload['predictions']) && is_array($payload['predictions'])) {
            foreach ($payload['predictions'] as $prediction) {
                $candidate = self::firstFilledString([
                    $prediction['bytesBase64Encoded'] ?? null,
                    $prediction['imageBytes'] ?? null,
                    $prediction['image']['imageBytes'] ?? null,
                    $prediction['image']['bytesBase64Encoded'] ?? null,
                ]);
                if ($candidate) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function extractImagenMime(array $payload): ?string
    {
        if (!empty($payload['candidates']) && is_array($payload['candidates'])) {
            foreach ($payload['candidates'] as $candidate) {
                $parts = $candidate['content']['parts'] ?? [];
                if (!is_array($parts)) {
                    continue;
                }
                foreach ($parts as $part) {
                    $inlineType = $part['inline_data']['mime_type'] ?? null;
                    if (is_string($inlineType) && $inlineType !== '') {
                        return $inlineType;
                    }
                }
            }
        }

        $generated = $payload['generatedImages'][0]['image']['mimeType'] ?? $payload['generatedImages'][0]['mimeType'] ?? null;
        if (is_string($generated) && $generated !== '') {
            return $generated;
        }
        $prediction = $payload['predictions'][0]['mimeType'] ?? null;
        return is_string($prediction) && $prediction !== '' ? $prediction : null;
    }

    private static function firstFilledString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }
        return null;
    }

    private static function persistImageBinary(string $contents, string $mime = 'image/jpeg')
    {
        if ($contents === '') {
            return new WP_Error('bbseo_image_empty', __('Image provider returned an empty body.', 'ai-seo-tool'));
        }

        $temp = wp_tempnam('bbseo-ai');
        if (!$temp) {
            return new WP_Error('bbseo_temp_fail', __('Unable to write temporary image file.', 'ai-seo-tool'));
        }

        if (file_put_contents($temp, $contents) === false) {
            @unlink($temp);
            return new WP_Error('bbseo_temp_fail', __('Unable to write temporary image file.', 'ai-seo-tool'));
        }

        $extension = self::extensionFromMime($mime);
        $fileArray = [
            'name' => 'bbseo-ai-' . time() . '.' . $extension,
            'tmp_name' => $temp,
            'type' => $mime,
        ];

        self::ensureMediaIncludes();
        $attachmentId = media_handle_sideload($fileArray, 0);
        if (is_wp_error($attachmentId)) {
            @unlink($temp);
            return $attachmentId;
        }

        return $attachmentId;
    }

    private static function extensionFromMime(?string $mime): string
    {
        return match (strtolower((string) $mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    private static function ensureMediaIncludes(): void
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    private static function updateLastRun(array $overrides): void
    {
        $current = self::getSettings();
        $current = array_merge($current, $overrides);
        $current['last_run'] = time();
        update_option(self::OPTION_KEY, $current);
    }

    private static function log(string $message, array $context = []): void
    {
        $dir = wp_upload_dir();
        $logDir = trailingslashit($dir['basedir']) . 'ai-seo-tool';
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }
        $file = trailingslashit($logDir) . 'articles.log';
        $payload = $context ? wp_json_encode($context) : '';
        file_put_contents($file, sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $message, $payload), FILE_APPEND);
    }
}
