<?php
namespace AISEO\Helpers;

class Sections
{
    public const META_SECTIONS = '_aiseo_sections';

    /**
     * Canonical registry of supported section types.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            'overview' => [
                'label' => 'Overview',
                'enabled_for' => ['general', 'per_page', 'technical'],
                'order' => 10,
            ],
            'performance_issues' => [
                'label' => 'Performance Issues',
                'enabled_for' => ['general', 'technical'],
                'order' => 20,
            ],
            'technical_seo' => [
                'label' => 'Technical SEO',
                'enabled_for' => ['general', 'technical'],
                'order' => 30,
            ],
            'onpage_meta_heading' => [
                'label' => 'On-Page SEO: Meta & Heading Optimization',
                'enabled_for' => ['general', 'per_page'],
                'order' => 40,
            ],
            'onpage_content_image' => [
                'label' => 'On-Page SEO: Content & Image Optimization',
                'enabled_for' => ['general', 'per_page'],
                'order' => 50,
            ],
        ];
    }

    /**
     * Default sections for a report type.
     */
    public static function defaultsFor(string $type): array
    {
        $items = [];

        foreach (self::registry() as $key => $def) {
            if (!in_array($type, $def['enabled_for'], true)) {
                continue;
            }

            $items[] = [
                'id' => uniqid($key . '_'),
                'type' => $key,
                'title' => $def['label'],
                'body' => '',
                'ai_notes' => '',
                'reco_list' => [],
                'order' => $def['order'],
            ];
        }

        usort($items, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $items;
    }
}
