<?php
namespace BBSEO\Admin;

class ChatbotSettings
{
    public const OPTION_KEY = 'bbseo_chatbot_context';

    private const DEFAULTS = [
        'context' => '',
        'max_context_chars' => 6000,
        'history_tail' => 12,
        'summary_enabled' => true,
        'handoff_enabled' => false,
        'handoff_email' => '',
        'handoff_subject' => 'New chatbot conversation ready for review',
    ];

    public static function registerPage(): void
    {
        add_submenu_page(
            'edit.php?post_type=bbseo_project',
            __('Chatbot Context', 'ai-seo-tool'),
            __('Chatbot Context', 'ai-seo-tool'),
            'manage_options',
            'bbseo-chatbot-context',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'ai-seo-tool'));
        }
        $settings = self::getSettings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Chatbot Context', 'ai-seo-tool'); ?></h1>
            <p><?php esc_html_e('Provide background information, tone, escalation rules, or data sources the chatbot should reference. The text is stored securely and used for every conversation.', 'ai-seo-tool'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bbseo-chatbot-settings">
                <?php wp_nonce_field('bbseo_save_chatbot_context'); ?>
                <input type="hidden" name="action" value="bbseo_save_chatbot_context" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="chatbot_context"><?php esc_html_e('Company & knowledge context', 'ai-seo-tool'); ?></label></th>
                        <td>
                            <textarea name="chatbot_context" id="chatbot_context" rows="12" class="large-text code" placeholder="<?php esc_attr_e('Example: We are Blackbird SEO, a technical SEO agency...', 'ai-seo-tool'); ?>"><?php echo esc_textarea($settings['context']); ?></textarea>
                            <p class="description"><?php esc_html_e('Include mission, services, escalation rules, data sources, and any reference docs. The text will be truncated to the limit below before hitting Gemini.', 'ai-seo-tool'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chatbot_max_context"><?php esc_html_e('Max context characters', 'ai-seo-tool'); ?></label></th>
                        <td>
                            <input type="number" id="chatbot_max_context" name="chatbot_max_context" min="500" step="100" value="<?php echo esc_attr($settings['max_context_chars']); ?>" />
                            <p class="description"><?php esc_html_e('Prevents giant prompts. Longer text is smart-trimmed to this size before each request.', 'ai-seo-tool'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chatbot_history_tail"><?php esc_html_e('History window (messages)', 'ai-seo-tool'); ?></label></th>
                        <td>
                            <input type="number" id="chatbot_history_tail" name="chatbot_history_tail" min="4" step="1" value="<?php echo esc_attr($settings['history_tail']); ?>" />
                            <p class="description"><?php esc_html_e('After this many combined user+assistant messages the oldest ones are summarized to keep token use low.', 'ai-seo-tool'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Summaries', 'ai-seo-tool'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="chatbot_summary_enabled" value="1" <?php checked($settings['summary_enabled']); ?> />
                                <?php esc_html_e('Keep a running summary of older messages instead of dropping them entirely.', 'ai-seo-tool'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Handoff email automation', 'ai-seo-tool'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="chatbot_handoff_enabled" value="1" <?php checked($settings['handoff_enabled']); ?> />
                                <?php esc_html_e('Offer to send a summary to your team when the bot believes the conversation is ready for human follow-up.', 'ai-seo-tool'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('The assistant will ask for permission and append a “Send summary” button in the chat UI.', 'ai-seo-tool'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chatbot_handoff_email"><?php esc_html_e('Handoff recipient(s)', 'ai-seo-tool'); ?></label></th>
                        <td>
                            <input type="text" id="chatbot_handoff_email" name="chatbot_handoff_email" class="regular-text" value="<?php echo esc_attr($settings['handoff_email']); ?>" placeholder="team@example.com, success@example.com" />
                            <p class="description"><?php esc_html_e('Comma separated list of addresses that should receive the summary email.', 'ai-seo-tool'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chatbot_handoff_subject"><?php esc_html_e('Handoff email subject', 'ai-seo-tool'); ?></label></th>
                        <td>
                            <input type="text" id="chatbot_handoff_subject" name="chatbot_handoff_subject" class="regular-text" value="<?php echo esc_attr($settings['handoff_subject']); ?>" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'ai-seo-tool')); ?>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'ai-seo-tool'));
        }
        check_admin_referer('bbseo_save_chatbot_context');
        $settings = [
            'context' => isset($_POST['chatbot_context']) ? wp_kses_post((string) $_POST['chatbot_context']) : '',
            'max_context_chars' => max(500, (int) ($_POST['chatbot_max_context'] ?? self::DEFAULTS['max_context_chars'])),
            'history_tail' => max(4, (int) ($_POST['chatbot_history_tail'] ?? self::DEFAULTS['history_tail'])),
            'summary_enabled' => !empty($_POST['chatbot_summary_enabled']),
            'handoff_enabled' => !empty($_POST['chatbot_handoff_enabled']),
            'handoff_email' => sanitize_text_field((string) ($_POST['chatbot_handoff_email'] ?? '')),
            'handoff_subject' => sanitize_text_field((string) ($_POST['chatbot_handoff_subject'] ?? self::DEFAULTS['handoff_subject'])),
        ];
        update_option(self::OPTION_KEY, $settings);
        wp_safe_redirect(add_query_arg('updated', 'true', wp_get_referer() ?: admin_url()));
        exit;
    }

    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION_KEY, self::DEFAULTS);
        if (is_string($stored)) {
            $stored = ['context' => $stored];
        }
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::DEFAULTS, $stored);
    }

    public static function getContext(): string
    {
        $settings = self::getSettings();
        return (string) $settings['context'];
    }

    public static function getContextForPrompt(): string
    {
        $settings = self::getSettings();
        return self::truncateText((string) $settings['context'], (int) $settings['max_context_chars']);
    }

    public static function truncateText(string $value, int $limit): string
    {
        if ($limit <= 0) {
            return $value;
        }
        if (function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            return mb_substr($value, 0, $limit);
        }
        if (strlen($value) > $limit) {
            return substr($value, 0, $limit);
        }
        return $value;
    }
}
