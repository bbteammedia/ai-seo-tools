<?php
namespace BBSEO\PostTypes;

class Report
{
    public const POST_TYPE = 'BBSEO_report';

    public const META_TYPE = '_BBSEO_report_type';
    public const META_PROJECT = '_BBSEO_project_slug';
    public const META_PAGE = '_BBSEO_page';
    public const META_RUNS = '_BBSEO_runs';
    public const META_SUMMARY = '_BBSEO_summary';
    public const META_ACTIONS = '_BBSEO_top_actions';
    public const META_META_RECO = '_BBSEO_meta_recos';
    public const META_TECH = '_BBSEO_tech_findings';
    public const META_SNAPSHOT = '_BBSEO_snapshot';

    public static function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Reports',
                'singular_name' => 'Report',
                'add_new_item' => 'Add New Report',
                'edit_item' => 'Edit Report',
            ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-media-document',
            'supports' => ['title', 'editor'],
            'show_in_rest' => true,
        ]);
    }

    public static function registerMeta(): void
    {
        $metas = [
            self::META_TYPE,
            self::META_PROJECT,
            self::META_PAGE,
            self::META_RUNS,
            self::META_SUMMARY,
            self::META_ACTIONS,
            self::META_META_RECO,
            self::META_TECH,
            self::META_SNAPSHOT,
        ];

        foreach ($metas as $metaKey) {
            register_post_meta(self::POST_TYPE, $metaKey, [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => static function () {
                    return current_user_can('edit_posts');
                },
            ]);
        }
    }

    public static function register(): void
    {
        self::registerPostType();
        self::registerMeta();
    }
}
