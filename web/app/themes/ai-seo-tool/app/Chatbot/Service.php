<?php
namespace BBSEO\Chatbot;

use BBSEO\Admin\ChatbotSettings;

class Service
{
    public static function identify(string $name, string $email): array
    {
        $profile = SessionStore::saveProfile($name, $email);
        $sessions = SessionStore::listSessions($email);
        return [
            'profile' => $profile,
            'sessions' => $sessions,
        ];
    }

    public static function createSession(string $name, string $email): ?array
    {
        $context = ChatbotSettings::getContextForPrompt();
        SessionStore::saveProfile($name, $email);
        $session = SessionStore::createSession($name, $email, $context);
        return $session ?: null;
    }

    public static function getSession(string $email, string $sessionId): ?array
    {
        return SessionStore::loadSession($email, $sessionId);
    }

    public static function listSessions(string $email): array
    {
        return SessionStore::listSessions($email);
    }

    public static function sendMessage(string $name, string $email, string $sessionId, string $message): ?array
    {
        $settings = ChatbotSettings::getSettings();
        $session = SessionStore::appendMessage($email, $sessionId, [
            'role' => 'user',
            'text' => $message,
            'timestamp' => gmdate('c'),
        ]);

        if (!$session) {
            return null;
        }

        $session = HistoryManager::enforceLimits($session, $settings);
        SessionStore::saveSession($email, $session);

        if (self::shouldAutoSendHandoff($message, $settings)) {
            return self::handleAutoHandoff($session, $settings, $email, $sessionId);
        }

        $reply = GeminiClient::reply(
            (string) ($session['context_snapshot'] ?? ChatbotSettings::getContextForPrompt()),
            $session['messages'] ?? [],
            $message,
            $name,
            [
                'summary' => $session['summary'] ?? '',
                'max_context_chars' => $settings['max_context_chars'] ?? 6000,
                'handoff_enabled' => !empty($settings['handoff_enabled']) && !empty($settings['handoff_email']),
            ]
        );

        $assistantText = $reply['text'];
        $handoffReady = false;
        if (!empty($settings['handoff_enabled']) && !empty($settings['handoff_email'])) {
            if (strpos($assistantText, '[[HANDOFF_READY]]') !== false) {
                $assistantText = trim(str_replace('[[HANDOFF_READY]]', '', $assistantText));
                $handoffReady = true;
            }
        }

        $session = SessionStore::appendMessage($email, $sessionId, [
            'role' => 'assistant',
            'text' => $assistantText,
            'timestamp' => gmdate('c'),
        ]);

        if ($session) {
            $session = HistoryManager::enforceLimits($session, $settings);
            if ($handoffReady && empty($session['handoff_sent'])) {
                $session['handoff_requested'] = true;
                $session['handoff_requested_at'] = gmdate('c');
            }
            SessionStore::saveSession($email, $session);
        }

        if (!$session) {
            return null;
        }

        return [
            'session' => $session,
            'actions' => self::availableActions($session, $settings),
        ];
    }

    public static function performAction(string $action, string $name, string $email, string $sessionId): ?array
    {
        $settings = ChatbotSettings::getSettings();
        $session = SessionStore::loadSession($email, $sessionId);
        if (!$session) {
            return null;
        }

        $message = '';
        if ($action === 'handoff_send') {
            $result = self::sendHandoffEmail($session, $settings, $email);
            $session = $result['session'];
            $message = $result['message'];
            $session = self::appendAssistantNote($email, $sessionId, $message, $settings)
                ?: (SessionStore::loadSession($email, $sessionId) ?: $session);
        } elseif ($action === 'handoff_dismiss') {
            $session['handoff_requested'] = false;
            SessionStore::saveSession($email, $session);
            $message = __('Got it — we will keep the conversation going.', 'ai-seo-tool');
            $session = self::appendAssistantNote($email, $sessionId, $message, $settings)
                ?: (SessionStore::loadSession($email, $sessionId) ?: $session);
        } else {
            $message = __('Unknown action.', 'ai-seo-tool');
        }

        return [
            'session' => $session,
            'actions' => self::availableActions($session, $settings),
            'message' => $message,
        ];
    }

    public static function formatSessionResponse(array $session): array
    {
        $settings = ChatbotSettings::getSettings();
        return [
            'session' => $session,
            'actions' => self::availableActions($session, $settings),
        ];
    }

    private static function availableActions(array $session, array $settings): array
    {
        $actions = [];
        $handoffReady = !empty($settings['handoff_enabled']) && !empty($settings['handoff_email']) && !empty($session['handoff_requested']) && empty($session['handoff_sent']);
        if ($handoffReady) {
            $actions[] = [
                'type' => 'handoff_send',
                'label' => __('Yes, send this summary to the team', 'ai-seo-tool'),
            ];
            $actions[] = [
                'type' => 'handoff_dismiss',
                'label' => __('No thanks, keep chatting', 'ai-seo-tool'),
            ];
        }

        return $actions;
    }

    private static function shouldAutoSendHandoff(string $message, array $settings): bool
    {
        if (empty($settings['handoff_enabled']) || empty($settings['handoff_email'])) {
            return false;
        }
        $message = trim($message);
        if ($message === '') {
            return false;
        }
        $patterns = [
            '/\b(send|email|forward|share)\b.*\b(summary|details|information|conversation|chat|this|it)\b/i',
            '/\b(summary|details|information)\b.*\b(send|email|forward)\b/i',
            '/\bsend\b.*\b(team|staff|human|agent)\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        return false;
    }

    private static function handleAutoHandoff(array $session, array $settings, string $email, string $sessionId): array
    {
        $result = self::sendHandoffEmail($session, $settings, $email);
        $notice = $result['message'] ?: __('I just sent the summary to our team for follow-up.', 'ai-seo-tool');
        $session = $result['session'];
        $session = self::appendAssistantNote($email, $sessionId, $notice, $settings)
            ?: (SessionStore::loadSession($email, $sessionId) ?: $session);

        return [
            'session' => $session,
            'actions' => self::availableActions($session, $settings),
            'message' => $notice,
        ];
    }

    private static function sendHandoffEmail(array $session, array $settings, string $email): array
    {
        if (empty($settings['handoff_enabled']) || empty($settings['handoff_email'])) {
            return [
                'session' => $session,
                'message' => __('Handoff is disabled in settings.', 'ai-seo-tool'),
            ];
        }
        if (!empty($session['handoff_sent'])) {
            return [
                'session' => $session,
                'message' => __('Summary already sent.', 'ai-seo-tool'),
            ];
        }

        $result = HandOffMailer::send($session, $settings);
        $message = $result['message'] ?? __('I shared the transcript with our team.', 'ai-seo-tool');

        if ($result['sent']) {
            $session['handoff_sent'] = true;
            $session['handoff_sent_at'] = gmdate('c');
            $session['handoff_requested'] = false;
        } else {
            $session['handoff_requested'] = true;
            $session['handoff_requested_at'] = gmdate('c');
        }

        SessionStore::saveSession($email, $session);

        return [
            'session' => $session,
            'message' => $message,
        ];
    }

    private static function appendAssistantNote(string $email, string $sessionId, string $text, array $settings): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return SessionStore::loadSession($email, $sessionId);
        }
        $session = SessionStore::appendMessage($email, $sessionId, [
            'role' => 'assistant',
            'text' => $text,
            'timestamp' => gmdate('c'),
        ]);
        if ($session) {
            $session = HistoryManager::enforceLimits($session, $settings);
            SessionStore::saveSession($email, $session);
        }
        return $session;
    }
}
