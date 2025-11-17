<?php
namespace BBSEO\Chatbot;

use BBSEO\AI\Gemini;

class GeminiClient
{
    private const MODEL = 'gemini-2.5-flash-lite';

    public static function reply(string $context, array $history, string $message, string $name, array $options = []): array
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: '';
        if (!$apiKey) {
            return [
                'text' => __('Gemini API key missing. Add GEMINI_API_KEY to .env to enable chatbot replies.', 'ai-seo-tool'),
                'source' => 'fallback',
            ];
        }

        $maxContextChars = (int) ($options['max_context_chars'] ?? 6000);
        $contextBlock = self::truncate($context, $maxContextChars);
        $historyBlock = self::buildHistoryBlock($history, (int) ($options['history_char_limit'] ?? 2400));
        $summary = isset($options['summary']) ? self::truncate((string) $options['summary'], 1200) : '';
        $prompt = self::buildPrompt($contextBlock, $summary, $historyBlock, $message, $name, [
            'handoff_enabled' => !empty($options['handoff_enabled']),
        ]);
        $endpoint = sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', self::MODEL);
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.45,
                'topP' => 0.9,
                'maxOutputTokens' => 768,
                'responseMimeType' => 'text/plain',
            ],
        ];

        Gemini::log('Chatbot prompt', [
            'prompt_chars' => strlen($prompt),
            'history_messages' => count($history),
        ]);

        $response = wp_remote_post($endpoint . '?key=' . rawurlencode($apiKey), [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            Gemini::log('Chatbot reply error', ['error' => $response->get_error_message()]);
            return [
                'text' => __('Sorry, I ran into a connection issue. Please try again.', 'ai-seo-tool'),
                'source' => 'error',
            ];
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            Gemini::log('Chatbot empty response');
            return [
                'text' => __('Hmm, I did not receive a response. Please ask again.', 'ai-seo-tool'),
                'source' => 'error',
            ];
        }

        $decoded = json_decode($body, true);
        $text = self::extractText($decoded);
        if (!$text) {
            Gemini::log('Chatbot malformed response', ['body' => substr($body, 0, 200)]);
            return [
                'text' => __('I could not understand the AI response. Could you rephrase?', 'ai-seo-tool'),
                'source' => 'error',
            ];
        }

        return [
            'text' => trim($text),
            'source' => 'gemini',
        ];
    }

    private static function buildHistoryBlock(array $messages, int $maxChars): string
    {
        $buffer = '';
        foreach ($messages as $entry) {
            if (empty($entry['role']) || empty($entry['text'])) {
                continue;
            }
            $speaker = $entry['role'] === 'assistant' ? 'Assistant' : 'User';
            $buffer .= sprintf("%s: %s\n", $speaker, self::cleanText($entry['text']));
        }
        $buffer = trim($buffer);
        return $buffer ? self::truncate($buffer, $maxChars) : '';
    }

    private static function buildPrompt(string $context, string $summary, string $history, string $message, string $name, array $directives = []): string
    {
        $cleanContext = wp_strip_all_tags($context);
        $safeContext = $cleanContext ?: __('You are a helpful assistant for the company. Keep answers concise and grounded in the provided context.', 'ai-seo-tool');
        $intro = "You are \"Blackbird Assistant\", an expert support bot. Always use the context to answer and be honest when information is missing. Keep responses under 6 sentences.";
        $userLine = sprintf('The guest is %s. Their latest question is: %s', $name ?: __('a guest', 'ai-seo-tool'), $message);
        $summarySection = $summary ? "Earlier conversation summary:\n{$summary}\n\n" : '';
        $historySection = $history ? "Recent conversation:\n{$history}\n\n" : '';
        $handoffInstruction = '';
        if (!empty($directives['handoff_enabled'])) {
            $handoffInstruction = "\n\nWhen you believe the conversation has collected everything needed for a teammate to follow up, do BOTH:\n1) Ask the guest for permission to send the summary to the human team.\n2) Append the token [[HANDOFF_READY]] at the very end of your reply.\nOnly append that token when you truly want a teammate to reach out. Otherwise, continue normally.";
        }

        return "{$intro}\n\nCompany context:\n{$safeContext}\n\n{$summarySection}{$historySection}{$userLine}{$handoffInstruction}\nAssistant:";
    }

    private static function extractText(?array $payload): string
    {
        if (!$payload) {
            return '';
        }
        $candidates = $payload['candidates'] ?? [];
        if (!$candidates) {
            return '';
        }
        $parts = $candidates[0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            if (!empty($part['text'])) {
                $text .= $part['text'];
            }
        }
        return $text;
    }

    private static function cleanText(string $text): string
    {
        $text = trim($text);
        return preg_replace('/\s+/', ' ', $text);
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
