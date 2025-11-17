<?php

namespace BBSEO\Template;

class ArticleTemplates
{
    public static function all(): array
    {
        $templates = [
            'post_simple' => [
                'label' => __('Marketing Pulse Article', 'ai-seo-tool'),
                'post_type' => 'post',
                'description' => __('Balanced editorial article that blends SEO insights with marketing tips. Ideal for standard WordPress blog posts.', 'ai-seo-tool'),
                'outline' => [
                    [
                        'id' => 'hook',
                        'title' => __('Hook & Executive Summary', 'ai-seo-tool'),
                        'goal' => __('Deliver a two paragraph introduction that states the topic, previews outcomes, and sets the tone.', 'ai-seo-tool'),
                    ],
                    [
                        'id' => 'story',
                        'title' => __('Context & Story', 'ai-seo-tool'),
                        'goal' => __('Share background trends, cite at least one stat, and explain why the topic matters now.', 'ai-seo-tool'),
                    ],
                    [
                        'id' => 'framework',
                        'title' => __('Action Framework', 'ai-seo-tool'),
                        'goal' => __('Provide 3–5 actionable steps or pillars that marketers can follow.', 'ai-seo-tool'),
                    ],
                    [
                        'id' => 'case',
                        'title' => __('Example or Mini Case Study', 'ai-seo-tool'),
                        'goal' => __('Illustrate the framework or lesson with a concise story or hypothetical.', 'ai-seo-tool'),
                    ],
                    [
                        'id' => 'wrap',
                        'title' => __('Wrap-up & CTA', 'ai-seo-tool'),
                        'goal' => __('Summarize the transformation, reinforce the payoff, and invite the reader to take the next step.', 'ai-seo-tool'),
                    ],
                ],
                'writing_notes' => [
                    __('Keep paragraphs short (3-4 sentences).', 'ai-seo-tool'),
                    __('Blend narrative, data, and tactical guidance.', 'ai-seo-tool'),
                    __('Every section should contain an actionable learning.', 'ai-seo-tool'),
                ],
                'default_topics' => [
                    __('Modern SEO + content flywheel strategies', 'ai-seo-tool'),
                    __('AI-assisted marketing workflows', 'ai-seo-tool'),
                    __('Organic growth playbooks for SaaS or ecommerce', 'ai-seo-tool'),
                ],
                'cta' => __('Drive the reader toward joining the newsletter or booking a strategy call.', 'ai-seo-tool'),
            ],
        ];

        /**
         * Allow developers to register additional templates.
         *
         * @param array $templates
         * @return array
         */
        return apply_filters('bbseo_article_templates', $templates);
    }

    public static function forPostType(string $postType): array
    {
        $postType = $postType ?: 'post';
        $templates = self::all();

        return array_filter($templates, function ($template) use ($postType) {
            $supportedType = $template['post_type'] ?? 'post';
            return $supportedType === 'any' || $supportedType === $postType;
        });
    }

    public static function get(string $slug): ?array
    {
        $templates = self::all();
        return $templates[$slug] ?? null;
    }

    public static function defaultFor(string $postType): string
    {
        $templates = self::forPostType($postType);
        $keys = array_keys($templates);
        if (!empty($keys)) {
            return (string) $keys[0];
        }

        $all = array_keys(self::all());
        return (string) ($all[0] ?? 'post_simple');
    }

    public static function outlineSummary(array $template): string
    {
        $outline = (array) ($template['outline'] ?? []);
        if (!$outline) {
            return '';
        }

        $lines = [];
        foreach ($outline as $row) {
            $lines[] = sprintf(
                '- %s: %s',
                $row['title'] ?? 'Section',
                $row['goal'] ?? '',
            );
        }

        return implode("\n", $lines);
    }
}
