<?php
namespace BBSEO\Chatbot;

use BBSEO\AI\Gemini;

class HistoryManager
{
    public static function enforceLimits(array $session, array $settings): array
    {
        $limit = max(4, (int) ($settings['history_tail'] ?? 12));
        $messages = $session['messages'] ?? [];
        if (!$messages || count($messages) <= $limit) {
            return $session;
        }

        $summaryEnabled = (bool) ($settings['summary_enabled'] ?? true);
        if ($summaryEnabled) {
            $excess = array_slice($messages, 0, count($messages) - $limit);
            $session['summary'] = self::buildSummary((string) ($session['summary'] ?? ''), $excess);
        }

        $session['messages'] = array_slice($messages, -$limit);
        return $session;
    }

    private static function buildSummary(string $existing, array $messages): string
    {
        $chunk = self::formatMessages($messages);
        if (!$chunk) {
            return $existing;
        }

        $prompt = "You maintain a condensed running summary for a support chatbot.\n"
            . "Existing summary (if any):\n{$existing}\n\n"
            . "Here are the latest user/assistant exchanges:\n{$chunk}\n\n"
            . "Write an updated summary (max 120 words) that captures critical questions, answers, and promises. "
            . "Do not include greetings or fluff.";

        $result = Gemini::summarize($prompt);
        $text = trim((string) ($result['summary'] ?? ''));
        if (!$text) {
            $text = trim($existing . "\n" . $chunk);
        }

        return self::truncate($text, 1200);
    }

    public static function formatMessages(array $messages, int $limit = 2000): string
    {
        $buffer = '';
        foreach ($messages as $entry) {
            if (empty($entry['role']) || empty($entry['text'])) {
                continue;
            }
            $speaker = $entry['role'] === 'assistant' ? 'Assistant' : 'User';
            $line = sanitize_textarea_field((string) $entry['text']);
            $buffer .= "{$speaker}: {$line}\n";
        }
        return self::truncate($buffer, $limit);
    }

    private static function truncate(string $value, int $limit): string
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
