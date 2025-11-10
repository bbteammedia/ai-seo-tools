<?php
namespace BBSEO\Admin;

use BBSEO\Analytics\GoogleAnalytics;
use BBSEO\Analytics\SearchConsole;
use BBSEO\PostTypes\Project;

class Analytics
{
    public static function bootstrap(): void
    {
        add_action('admin_post_bbseo_ga_connect', [self::class, 'startConnect']);
        add_action('admin_post_bbseo_ga_disconnect', [self::class, 'disconnect']);
        add_action('admin_post_bbseo_ga_sync', [self::class, 'manualSync']);
        add_action('admin_post_bbseo_ga_callback', [self::class, 'handleCallback']);
    }

    public static function startConnect(): void
    {
        self::assertManageCapabilities();

        $project = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!$project || !wp_verify_nonce($nonce, 'bbseo_ga_connect_' . $project)) {
            wp_die(__('Invalid request.', 'ai-seo-tool'));
        }

        if (!GoogleAnalytics::clientId() || !GoogleAnalytics::clientSecret()) {
            wp_die(__('Google Analytics client credentials are not configured.', 'ai-seo-tool'));
        }

        $state = wp_generate_uuid4();
        set_transient('bbseo_ga_state_' . $state, [
            'project' => $project,
            'created' => time(),
        ], 15 * MINUTE_IN_SECONDS);

        $authUrl = GoogleAnalytics::authorizationUrl($project, $state);
        add_filter('allowed_redirect_hosts', [self::class, 'allowGoogleHosts']);
        wp_safe_redirect($authUrl);
        exit;
    }

    public static function handleCallback(): void
    {
        self::assertManageCapabilities();

        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

        $payload = $state ? get_transient('bbseo_ga_state_' . $state) : null;
        if (!$payload || !is_array($payload)) {
            wp_die(__('OAuth state mismatch. Please try connecting again.', 'ai-seo-tool'));
        }
        delete_transient('bbseo_ga_state_' . $state);

        $project = $payload['project'] ?? '';
        $redirect = self::projectEditLink($project);

        if ($error) {
            wp_safe_redirect(add_query_arg('ga_notice', 'error', $redirect));
            exit;
        }

        try {
            $tokens = GoogleAnalytics::exchangeAuthCode($code);
            GoogleAnalytics::storeTokens($project, $tokens);
            wp_safe_redirect(add_query_arg('ga_notice', 'connected', $redirect));
        } catch (\Throwable $exception) {
            GoogleAnalytics::disconnect($project);
            wp_safe_redirect(add_query_arg([
                'ga_notice' => 'error',
                'ga_message' => rawurlencode($exception->getMessage()),
            ], $redirect));
        }
        exit;
    }

    public static function disconnect(): void
    {
        self::assertManageCapabilities();
        $project = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!$project || !wp_verify_nonce($nonce, 'bbseo_ga_disconnect_' . $project)) {
            wp_die(__('Invalid request.', 'ai-seo-tool'));
        }

        GoogleAnalytics::disconnect($project);
        wp_safe_redirect(add_query_arg('ga_notice', 'disconnected', self::projectEditLink($project)));
        exit;
    }

    public static function manualSync(): void
    {
        self::assertManageCapabilities();
        $project = isset($_GET['project']) ? sanitize_title($_GET['project']) : '';
        $nonce = $_GET['_wpnonce'] ?? '';
        $type = isset($_GET['type']) ? sanitize_key($_GET['type']) : 'ga';
        $expectedNonce = $type === 'gsc' ? 'bbseo_ga_sync_' . $project . '_gsc' : 'bbseo_ga_sync_' . $project . '_ga';
        if (!$project || (!wp_verify_nonce($nonce, $expectedNonce) && !wp_verify_nonce($nonce, 'bbseo_ga_sync_' . $project))) {
            wp_die(__('Invalid request.', 'ai-seo-tool'));
        }

        $redirect = self::projectEditLink($project);
        try {
            if ($type === 'gsc') {
                SearchConsole::sync($project);
                wp_safe_redirect(add_query_arg('ga_notice', 'synced_gsc', $redirect));
            } else {
                GoogleAnalytics::sync($project);
                wp_safe_redirect(add_query_arg('ga_notice', 'synced', $redirect));
            }
        } catch (\Throwable $exception) {
            $config = GoogleAnalytics::loadConfig($project);
            if (!isset($config['analytics']) || !is_array($config['analytics'])) {
                $config['analytics'] = [];
            }
            if ($type === 'gsc') {
                if (!isset($config['analytics']['gsc']) || !is_array($config['analytics']['gsc'])) {
                    $config['analytics']['gsc'] = [];
                }
                $config['analytics']['gsc']['last_error'] = $exception->getMessage();
                GoogleAnalytics::writeConfig($project, $config);
                wp_safe_redirect(add_query_arg([
                    'ga_notice' => 'gsc_error',
                    'ga_message' => rawurlencode($exception->getMessage()),
                ], $redirect));
            } else {
                if (!isset($config['analytics']['ga']) || !is_array($config['analytics']['ga'])) {
                    $config['analytics']['ga'] = [];
                }
                $config['analytics']['ga']['last_error'] = $exception->getMessage();
                GoogleAnalytics::writeConfig($project, $config);
                wp_safe_redirect(add_query_arg([
                    'ga_notice' => 'error',
                    'ga_message' => rawurlencode($exception->getMessage()),
                ], $redirect));
            }
        }
        exit;
    }

    private static function projectEditLink(string $project): string
    {
        $post = Project::getBySlug($project);
        if ($post) {
            return get_edit_post_link($post->ID, 'redirect');
        }
        return admin_url('edit.php?post_type=' . Project::POST_TYPE);
    }

    private static function assertManageCapabilities(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }
    }

    public static function allowGoogleHosts(array $hosts): array
    {
        $hosts[] = 'accounts.google.com';
        $hosts[] = 'www.accounts.google.com';
        return array_unique($hosts);
    }
}
