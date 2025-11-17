<?php
namespace BBSEO\Chatbot;

class SessionStore
{
    private const MAX_MESSAGES = 40;

    public static function ensureBaseDir(): void
    {
        self::baseDir();
    }

    private static function baseDir(): string
    {
        $dir = getenv('BBSEO_CHATBOT_DIR');
        if (!$dir) {
            $dir = get_theme_file_path('storage/chatbot');
        }
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return rtrim($dir, '/');
    }

    private static function emailKey(string $email): string
    {
        $normalized = strtolower(trim($email));
        return $normalized ? hash('sha256', $normalized) : '';
    }

    private static function userDir(string $email): ?string
    {
        $key = self::emailKey($email);
        if (!$key) {
            return null;
        }
        $dir = self::baseDir() . '/' . $key;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir . '/sessions')) {
            wp_mkdir_p($dir . '/sessions');
        }
        return $dir;
    }

    public static function saveProfile(string $name, string $email): array
    {
        $name = sanitize_text_field($name);
        $email = sanitize_email($email);
        $dir = self::userDir($email);
        if (!$dir) {
            return [];
        }
        $profilePath = $dir . '/profile.json';
        $existing = self::readJson($profilePath, []);
        $profile = [
            'name' => $name ?: ($existing['name'] ?? ''),
            'email' => $email,
            'created_at' => $existing['created_at'] ?? gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
        file_put_contents($profilePath, wp_json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $profile;
    }

    public static function getProfile(string $email): array
    {
        $dir = self::userDir($email);
        if (!$dir) {
            return [];
        }
        $profilePath = $dir . '/profile.json';
        return self::readJson($profilePath, []);
    }

    public static function createSession(string $name, string $email, string $contextSnapshot): array
    {
        $name = sanitize_text_field($name);
        $email = sanitize_email($email);
        $dir = self::userDir($email);
        if (!$dir) {
            return [];
        }
        $sessionId = self::generateSessionId();
        $session = [
            'id' => $sessionId,
            'title' => sprintf(__('Session started %s', 'ai-seo-tool'), date_i18n('M j, Y g:i a')),
            'email' => $email,
            'name' => $name,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'messages' => [],
            'context_snapshot' => $contextSnapshot,
            'summary' => '',
            'handoff_requested' => false,
            'handoff_sent' => false,
            'handoff_sent_at' => '',
            'handoff_notes' => '',
            'handoff_requested_at' => '',
        ];
        self::saveSession($email, $session);
        return $session;
    }

    public static function listSessions(string $email): array
    {
        $dir = self::userDir($email);
        if (!$dir) {
            return [];
        }
        $files = glob($dir . '/sessions/*.json') ?: [];
        $sessions = [];
        foreach ($files as $file) {
            $session = self::readJson($file, []);
            if (!$session) {
                continue;
            }
            $sessions[] = [
                'id' => $session['id'] ?? '',
                'title' => $session['title'] ?? '',
                'updated_at' => $session['updated_at'] ?? '',
                'preview' => self::previewFromSession($session),
            ];
        }
        usort($sessions, function ($a, $b) {
            return strcmp($b['updated_at'], $a['updated_at']);
        });
        return $sessions;
    }

    public static function loadSession(string $email, string $sessionId): ?array
    {
        $dir = self::userDir($email);
        if (!$dir) {
            return null;
        }
        $file = $dir . '/sessions/' . sanitize_file_name($sessionId) . '.json';
        $session = self::readJson($file, []);
        if (!$session) {
            return null;
        }
        return $session;
    }

    public static function appendMessage(string $email, string $sessionId, array $message): ?array
    {
        $dir = self::userDir($email);
        if (!$dir) {
            return null;
        }
        $file = $dir . '/sessions/' . sanitize_file_name($sessionId) . '.json';
        $session = self::readJson($file, []);
        if (!$session) {
            return null;
        }
        $session['messages'][] = [
            'role' => $message['role'],
            'text' => self::sanitizeMessage($message['text'] ?? ''),
            'timestamp' => $message['timestamp'] ?? gmdate('c'),
        ];
        $session['messages'] = self::trimMessages($session['messages']);
        $session['updated_at'] = gmdate('c');
        self::persistSession($dir, $session);
        return $session;
    }

    public static function saveSession(string $email, array $session): void
    {
        $dir = self::userDir($email);
        if (!$dir) {
            return;
        }
        self::persistSession($dir, $session);
    }

    public static function updateSession(string $email, string $sessionId, array $updates): ?array
    {
        $session = self::loadSession($email, $sessionId);
        if (!$session) {
            return null;
        }
        foreach ($updates as $key => $value) {
            $session[$key] = $value;
        }
        self::saveSession($email, $session);
        return $session;
    }

    private static function persistSession(string $dir, array $session): void
    {
        if (isset($session['summary'])) {
            $session['summary'] = self::sanitizeMessage((string) $session['summary']);
        }
        $session['handoff_requested'] = !empty($session['handoff_requested']);
        $session['handoff_sent'] = !empty($session['handoff_sent']);
        $file = $dir . '/sessions/' . sanitize_file_name($session['id']) . '.json';
        file_put_contents($file, wp_json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function generateSessionId(): string
    {
        try {
            $bytes = random_bytes(8);
            $id = substr(bin2hex($bytes), 0, 12);
        } catch (\Exception $e) {
            $id = strtolower(wp_generate_password(12, false));
        }
        return 'chat_' . $id;
    }

    private static function previewFromSession(array $session): string
    {
        $messages = $session['messages'] ?? [];
        if (!$messages) {
            $summary = (string) ($session['summary'] ?? '');
            return $summary ? self::truncateText($summary, 120) : '';
        }
        $last = end($messages);
        return is_array($last) ? self::truncateText((string) ($last['text'] ?? ''), 120) : '';
    }

    private static function truncateText(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
            return mb_substr($text, 0, $limit) . '…';
        }
        if (strlen($text) > $limit) {
            return substr($text, 0, $limit) . '…';
        }
        return $text;
    }

    private static function trimMessages(array $messages): array
    {
        $count = count($messages);
        if ($count <= self::MAX_MESSAGES) {
            return $messages;
        }
        return array_slice($messages, $count - self::MAX_MESSAGES);
    }

    private static function sanitizeMessage(string $text): string
    {
        return sanitize_textarea_field($text);
    }

    private static function readJson(string $path, $default = [])
    {
        if (!file_exists($path)) {
            return $default;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return $default;
        }
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : $default;
    }
}
