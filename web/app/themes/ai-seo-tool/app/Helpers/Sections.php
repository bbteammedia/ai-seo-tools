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
            'executive_summary' => [
                'label' => 'Executive Summary',
                'enabled_for' => ['general', 'per_page', 'technical'],
                'order' => 5,
            ],
            'top_actions' => [
                'label' => 'Top Actions',
                'enabled_for' => ['general', 'per_page', 'technical'],
                'order' => 8,
            ],
            'overview' => [
                'label' => 'Overview',
                'enabled_for' => ['general', 'per_page', 'technical'],
                'order' => 10,
            ],
            'performance_summary' => [
                'label' => 'Performance Summary',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 20,
            ],
            'technical_seo_issues' => [
                'label' => 'Technical SEO Issues',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 30,
            ],
            'onpage_seo_content' => [
                'label' => 'On-Page SEO & Content',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 40,
            ],
            'keyword_analysis' => [
                'label' => 'Keyword Analysis',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 50,
            ],
            'backlink_profile' => [
                'label' => 'Backlink Profile',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 60,
            ],
            'meta_recommendations' => [
                'label' => 'Meta Recommendations',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 80,
            ],
            'technical_findings' => [
                'label' => 'Technical Findings',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 90,
            ],
            'recommendations' => [
                'label' => 'Recommendations',
                'enabled_for' => ['general', 'technical', 'per_page'],
                'order' => 100,
            ],
            // Legacy definitions kept for backward compatibility with saved data.
            'performance_issues' => [
                'label' => 'Performance Issues (legacy)',
                'enabled_for' => [],
                'order' => 820,
                'legacy' => true,
            ],
            'technical_seo' => [
                'label' => 'Technical SEO (legacy)',
                'enabled_for' => [],
                'order' => 830,
                'legacy' => true,
            ],
            'onpage_meta_heading' => [
                'label' => 'Meta & Heading Optimization (legacy)',
                'enabled_for' => [],
                'order' => 840,
                'legacy' => true,
            ],
            'onpage_content_image' => [
                'label' => 'Content & Image Optimization (legacy)',
                'enabled_for' => [],
                'order' => 850,
                'legacy' => true,
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
            if (($def['legacy'] ?? false) || !in_array($type, $def['enabled_for'], true)) {
                continue;
            }

            $position = count($items);
            $items[] = [
                'id' => uniqid($key . '_'),
                'type' => $key,
                'title' => $def['label'],
                'body' => '',
                'ai_notes' => '',
                'reco_list' => [],
                'meta_list' => [],
                'order' => min($position, 15),
                'visible' => true,
            ];
        }

        usort($items, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $items;
    }
}
