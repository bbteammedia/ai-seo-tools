<?php
namespace BBSEO\Admin;

use BBSEO\PostTypes\Report as ReportPostType;

class ReportAdminList
{
    public static function boot(): void
    {
        add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
        add_filter('manage_' . ReportPostType::POST_TYPE . '_posts_columns', [self::class, 'addColumns']);
        add_action('manage_' . ReportPostType::POST_TYPE . '_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
    }

    public static function rowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== ReportPostType::POST_TYPE) {
            return $actions;
        }

        $viewUrl = self::reportUrl($post);
        $pdfUrl = self::reportUrl($post, true);

        $actions['bbseo_view'] = sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url($viewUrl),
            esc_html__('View Report', 'ai-seo-tool')
        );

        $actions['bbseo_pdf'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($pdfUrl),
            esc_html__('Download PDF', 'ai-seo-tool')
        );

        return $actions;
    }

    public static function addColumns(array $columns): array
    {
        $columns['bbseo_report_links'] = __('Report Links', 'ai-seo-tool');
        return $columns;
    }

    public static function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'bbseo_report_links') {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            echo '-';
            return;
        }

        $viewUrl = self::reportUrl($post);
        $pdfUrl = self::reportUrl($post, true);

        printf(
            '<a class="button button-small" href="%s" target="_blank" rel="noopener">%s</a> ',
            esc_url($viewUrl),
            esc_html__('View', 'ai-seo-tool')
        );
        printf(
            '<a class="button button-small" href="%s">%s</a>',
            esc_url($pdfUrl),
            esc_html__('PDF', 'ai-seo-tool')
        );
    }

    private static function reportUrl(\WP_Post $post, bool $pdf = false): string
    {
        $url = home_url('report/' . $post->post_name);
        if ($pdf) {
            $url = add_query_arg('format', 'pdf', $url);
        }
        return $url;
    }
}
