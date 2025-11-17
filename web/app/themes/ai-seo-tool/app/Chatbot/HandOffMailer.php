<?php
namespace BBSEO\Chatbot;

use BBSEO\AI\Gemini;

class HandOffMailer
{
    public static function send(array $session, array $settings): array
    {
        $recipients = self::parseRecipients($settings['handoff_email'] ?? '');
        if (!$recipients) {
            return ['sent' => false, 'message' => __('No handoff recipients configured.', 'ai-seo-tool')];
        }
        $subject = $settings['handoff_subject'] ?: __('New chatbot conversation ready for review', 'ai-seo-tool');
        $summary = self::generateSummary($session);
        $transcript = self::buildTranscript($session);

        $body = self::renderEmail($session, $summary, $transcript);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($recipients, $subject, $body, $headers);
        if (!$sent) {
            return ['sent' => false, 'message' => __('Unable to send the email. Check mail configuration.', 'ai-seo-tool')];
        }

        return ['sent' => true, 'message' => __('Summary sent to your team. Someone will follow up shortly.', 'ai-seo-tool')];
    }

    private static function parseRecipients(string $raw): array
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $valid = [];
        foreach ($parts as $email) {
            $clean = sanitize_email($email);
            if ($clean) {
                $valid[] = $clean;
            }
        }
        return $valid;
    }

    private static function generateSummary(array $session): string
    {
        $prompt = "Summarize the following chatbot conversation for a teammate. "
            . "Highlight who the guest is, what they need, key details provided, and recommended next steps. "
            . "Keep it under 180 words.\n\n"
            . "Existing running summary (optional):\n"
            . ($session['summary'] ?? '(none)') . "\n\n"
            . "Recent transcript:\n"
            . self::buildTranscript($session, 2500);

        $result = Gemini::summarize($prompt);
        $summary = trim((string) ($result['summary'] ?? ''));

        if ($summary) {
            return $summary;
        }

        return self::fallbackSummary($session);
    }

    private static function fallbackSummary(array $session): string
    {
        $messages = $session['messages'] ?? [];
        $userLines = [];
        foreach ($messages as $entry) {
            if (($entry['role'] ?? '') === 'user' && !empty($entry['text'])) {
                $userLines[] = sanitize_textarea_field($entry['text']);
            }
        }
        $userLines = array_slice($userLines, -5);
        if (!$userLines) {
            return __('Guest interacted with the assistant but no additional details were captured.', 'ai-seo-tool');
        }
        return __('Guest shared the following:', 'ai-seo-tool') . "\n- " . implode("\n- ", $userLines);
    }

    private static function buildTranscript(array $session, int $limit = 4000): string
    {
        $messages = $session['messages'] ?? [];
        return HistoryManager::formatMessages($messages, $limit);
    }

    private static function renderEmail(array $session, string $summary, string $transcript): string
    {
        $name = esc_html($session['name'] ?? '');
        $email = esc_html($session['email'] ?? '');
        $sessionId = esc_html($session['id'] ?? '');
        $company = esc_html(get_bloginfo('name'));
        ob_start();
        ?>
        <div style="font-family:Arial,Helvetica,sans-serif; color:#0f172a;">
            <p><?php printf(__('New chatbot summary from %s:', 'ai-seo-tool'), $company); ?></p>
            <ul>
                <li><strong><?php esc_html_e('Guest', 'ai-seo-tool'); ?>:</strong> <?php echo $name ?: __('Anonymous', 'ai-seo-tool'); ?></li>
                <li><strong><?php esc_html_e('Email', 'ai-seo-tool'); ?>:</strong> <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo $email ?: __('Unknown', 'ai-seo-tool'); ?></a></li>
                <li><strong><?php esc_html_e('Session ID', 'ai-seo-tool'); ?>:</strong> <?php echo $sessionId; ?></li>
            </ul>
            <h3><?php esc_html_e('Summary', 'ai-seo-tool'); ?></h3>
            <p><?php echo nl2br(esc_html($summary)); ?></p>
            <h3><?php esc_html_e('Recent transcript', 'ai-seo-tool'); ?></h3>
            <pre style="background:#f5f5f5;padding:12px;border-radius:6px;font-family:Menlo,Consolas,monospace;font-size:13px;white-space:pre-wrap;"><?php echo esc_html($transcript); ?></pre>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
