<?php
namespace BBSEO\Admin;

use BBSEO\Chatbot\SessionStore;

class ChatbotHistoryPage
{
    public static function register(): void
    {
        add_submenu_page(
            'edit.php?post_type=bbseo_project',
            __('Chatbot History', 'ai-seo-tool'),
            __('Chatbot History', 'ai-seo-tool'),
            'manage_options',
            'bbseo-chatbot-history',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'ai-seo-tool'));
        }

        $email = isset($_GET['chatbot_email']) ? sanitize_email((string) $_GET['chatbot_email']) : '';
        $sessionId = isset($_GET['chatbot_session']) ? sanitize_text_field((string) $_GET['chatbot_session']) : '';
        $sessions = $email ? SessionStore::listSessions($email) : [];
        $activeSession = ($email && $sessionId) ? SessionStore::loadSession($email, $sessionId) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Chatbot History', 'ai-seo-tool'); ?></h1>

            <form method="get" class="bbseo-chatbot-history-search">
                <input type="hidden" name="post_type" value="bbseo_project" />
                <input type="hidden" name="page" value="bbseo-chatbot-history" />
                <label for="chatbot_email" class="screen-reader-text"><?php esc_html_e('Email address', 'ai-seo-tool'); ?></label>
                <input type="email" id="chatbot_email" name="chatbot_email" placeholder="guest@example.com" value="<?php echo esc_attr($email); ?>" />
                <?php submit_button(__('Load history', 'ai-seo-tool'), 'secondary', '', false); ?>
            </form>

            <?php if ($email && !$sessions): ?>
                <p><?php esc_html_e('No sessions found for this email.', 'ai-seo-tool'); ?></p>
            <?php endif; ?>

            <div class="bbseo-chatbot-history-grid">
                <div class="bbseo-chatbot-history-list">
                    <h2><?php esc_html_e('Sessions', 'ai-seo-tool'); ?></h2>
                    <?php if (!$sessions): ?>
                        <p><?php esc_html_e('Use the search form to load a guest’s sessions.', 'ai-seo-tool'); ?></p>
                    <?php else: ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Title', 'ai-seo-tool'); ?></th>
                                    <th><?php esc_html_e('Updated', 'ai-seo-tool'); ?></th>
                                    <th><?php esc_html_e('Preview', 'ai-seo-tool'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $session): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url(add_query_arg([
                                                'post_type' => 'bbseo_project',
                                                'page' => 'bbseo-chatbot-history',
                                                'chatbot_email' => rawurlencode($email),
                                                'chatbot_session' => rawurlencode($session['id']),
                                            ], admin_url('edit.php'))); ?>">
                                                <?php echo esc_html($session['title'] ?: $session['id']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html($session['updated_at'] ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($session['updated_at'])) : '—'); ?></td>
                                        <td><?php echo esc_html($session['preview']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="bbseo-chatbot-history-transcript">
                    <h2><?php esc_html_e('Transcript', 'ai-seo-tool'); ?></h2>
                    <?php if ($activeSession): ?>
                        <p><strong><?php echo esc_html($activeSession['title'] ?? $activeSession['id']); ?></strong></p>
                        <?php if (!empty($activeSession['handoff_sent'])): ?>
                            <div class="notice notice-success inline">
                                <p><?php printf(esc_html__('Summary emailed on %s', 'ai-seo-tool'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($activeSession['handoff_sent_at'] ?? 'now')))); ?></p>
                            </div>
                        <?php elseif (!empty($activeSession['handoff_requested'])): ?>
                            <div class="notice notice-warning inline">
                                <p><?php esc_html_e('Assistant requested a handoff, awaiting guest confirmation.', 'ai-seo-tool'); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($activeSession['summary'])): ?>
                            <div class="notice notice-info inline">
                                <p><strong><?php esc_html_e('Summary of earlier exchanges:', 'ai-seo-tool'); ?></strong> <?php echo esc_html($activeSession['summary']); ?></p>
                            </div>
                        <?php endif; ?>
                        <ol class="bbseo-chatbot-messages">
                            <?php foreach ($activeSession['messages'] ?? [] as $message): ?>
                                <li class="<?php echo esc_attr($message['role'] === 'assistant' ? 'assistant' : 'user'); ?>">
                                    <div class="bbseo-chatbot-meta">
                                        <strong><?php echo esc_html($message['role'] === 'assistant' ? __('Assistant', 'ai-seo-tool') : __('Guest', 'ai-seo-tool')); ?></strong>
                                        <span><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($message['timestamp'] ?? 'now'))); ?></span>
                                    </div>
                                    <div class="bbseo-chatbot-body"><?php echo wpautop(esc_html($message['text'] ?? '')); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php elseif ($email): ?>
                        <p><?php esc_html_e('Select a session to inspect the transcript.', 'ai-seo-tool'); ?></p>
                    <?php else: ?>
                        <p><?php esc_html_e('Enter an email above to inspect a guest conversation.', 'ai-seo-tool'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <style>
            .bbseo-chatbot-history-grid {
                display: grid;
                grid-template-columns: 1fr 1.2fr;
                gap: 24px;
                margin-top: 24px;
            }
            .bbseo-chatbot-history-search input[type="email"] {
                width: 280px;
                max-width: 100%;
            }
            .bbseo-chatbot-history-transcript {
                max-height: 70vh;
                overflow-y: auto;
                padding: 16px;
                border: 1px solid #dfe3e8;
                background: #fff;
            }
            .bbseo-chatbot-messages {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .bbseo-chatbot-messages li {
                border-bottom: 1px solid #eff1f3;
                padding: 12px 0;
            }
            .bbseo-chatbot-messages li:last-child {
                border-bottom: none;
            }
            .bbseo-chatbot-meta {
                display: flex;
                justify-content: space-between;
                font-size: 13px;
                color: #4b5563;
                margin-bottom: 6px;
            }
            .bbseo-chatbot-body p {
                margin: 0 0 8px;
            }
            @media screen and (max-width: 960px) {
                .bbseo-chatbot-history-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
}
