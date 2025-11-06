<?php
namespace AISEO\AI;

class Gemini
{
    private const MODEL = 'gemini-2.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public static function summarize(string $prompt, array $context = []): array
    {
        $text = self::generateContent($prompt);

        return [
            'summary' => $text,
            'context' => $context,
        ];
    }

    public static function summarizeReport(string $type, array $data): array
    {
        $response = self::callGemini(
            self::buildReportPrompt($type, $data),
            [
                'temperature' => 0.4,
                'maxOutputTokens' => 2048,
            ]
        );

        if ($response) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                return [
                    'summary' => sanitize_textarea_field($decoded['summary'] ?? ''),
                    'actions' => self::sanitizeStringsArray($decoded['actions'] ?? [], 8),
                    'meta_rec' => self::sanitizeMetaRecommendations($decoded['meta_rec'] ?? []),
                    'tech' => sanitize_textarea_field($decoded['tech'] ?? ''),
                ];
            }
            self::log('Gemini summarizeReport: JSON decode failed', ['response' => self::shorten($response)]);
        }

        self::log('Gemini summarizeReport: using fallback', ['type' => $type]);
        return self::fallbackReport($type, $data);
    }

    public static function summarizeSection(string $type, array $data, string $sectionId): array
    {
        $label = self::labelForSection($sectionId);

        $response = self::callGemini(
            self::buildSectionPrompt($type, $label, $sectionId, $data),
            [
                'temperature' => 0.35,
                'maxOutputTokens' => 768,
            ]
        );

        if ($response) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                return [
                    'body' => sanitize_textarea_field($decoded['body'] ?? ''),
                    'reco_list' => self::sanitizeStringsArray($decoded['reco_list'] ?? [], 8),
                ];
            }
            self::log('Gemini summarizeSection: JSON decode failed', ['section' => $sectionId, 'response' => self::shorten($response)]);
        }

        self::log('Gemini summarizeSection: using fallback', ['section' => $sectionId]);
        return self::fallbackSection($type, $data, $sectionId);
    }

    private static function callGemini(string $prompt, array $generationConfig = []): ?string
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: '';
        if (!$apiKey) {
            self::log('Gemini call skipped: missing API key');
            return null;
        }

        $endpoint = sprintf(self::ENDPOINT, self::MODEL) . '?key=' . rawurlencode($apiKey);
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => array_merge([
                'temperature' => 0.4,
                'maxOutputTokens' => 1024,
                'topP' => 0.95,
                'responseMimeType' => 'application/json',
            ], $generationConfig),
        ];

        self::log('Gemini request', [
            'endpoint' => self::ENDPOINT,
            'prompt_chars' => strlen($prompt),
            'config' => $payload['generationConfig'],
        ]);

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            self::log('Gemini request error', ['error' => $response->get_error_message()]);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            self::log('Gemini request returned empty body');
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::log('Gemini response JSON parse failed', ['body' => self::shorten($body)]);
            return null;
        }

        $text = self::extractTextFromResponse($data);
        if (!$text) {
            self::log('Gemini response missing text content', ['body' => self::shorten($body)]);
            return null;
        }

        $json = self::extractJsonBlock($text);

        if (!$json) {
            self::log('Gemini response missing JSON block', ['text' => self::shorten($text)]);
        }

        return $json ?: null;
    }

    private static function extractTextFromResponse(array $response): string
    {
        $candidates = $response['candidates'] ?? [];
        if (!is_array($candidates) || empty($candidates)) {
            return '';
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return '';
        }

        $buffer = '';
        foreach ($parts as $part) {
            if (!empty($part['text'])) {
                $buffer .= (string) $part['text'];
            }
        }

        return trim($buffer);
    }

    private static function extractJsonBlock(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // If the text already looks like JSON, try it as-is first.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $text;
        }
        
        // FIX 2a: Strip common markdown code fences (```json or ```) that models sometimes add.
        $text = preg_replace('/^```json\s*|```\s*$/s', '', $text);
        $text = trim($text);

        // FIX 2b: Find the text between the first '{' and the last '}' to capture the full object.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $candidate;
            }
        }
        
        // Fallback: Attempt to pull the first JSON object from the text using the original greedy regex.
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $candidate = $matches[0];
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function buildReportPrompt(string $type, array $data): string
    {
        $stats = self::compileStats($data);
        $context = self::prepareContext($data);

        $instructions = <<<TXT
You are an SEO analyst generating an executive summary for an audit report.
Use the provided crawl/audit data to create concise insights.
Return ONLY valid JSON (no markdown) matching this exact schema:
{
  "summary": string,
  "actions": string[] (3-6 concise action items),
  "meta_rec": [{"url": string, "title": string, "meta_description": string}],
  "tech": string
}
Rules:
- Keep sentences short and actionable.
- Reference page or issue counts when meaningful.
- Limit meta_rec to at most 3 entries; omit if you lack data.
- Never invent URLs or stats that are not in the data.
- Use plain text (no markdown, bullet prefixes, or HTML).
Report type: {$type}
High level stats: pages={$stats['pages']} issues={$stats['issues']} 4xx={$stats['status4xx']} 5xx={$stats['status5xx']}
TXT;

        return $instructions . "\n\nDATA (JSON):\n" . $context;
    }

    private static function buildSectionPrompt(string $type, string $label, string $sectionId, array $data): string
    {
        $stats = self::compileStats($data);
        $context = self::prepareContext($data);

        $instructions = <<<TXT
You are drafting the "{$label}" section of an SEO report ({$type}).
Focus on trends and issues relevant to this section.
Output ONLY JSON (no prose around it) with this schema:
{
  "body": string,
  "reco_list": string[] (2-5 concise recommendations)
}
Guidelines:
- Keep body under 120 words; use sentences not bullets.
- Recommendations must be actionable and specific to this section.
- If data is missing, mention the gap instead of guessing.
- Use plain text (no markdown or numbering).
Section id: {$sectionId}
General stats: pages={$stats['pages']} issues={$stats['issues']} 4xx={$stats['status4xx']} 5xx={$stats['status5xx']}
TXT;

        return $instructions . "\n\nDATA (JSON):\n" . $context;
    }

    private static function prepareContext(array $data, int $limit = 16000): string
    {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!$json) {
            return '{}';
        }

        if (strlen($json) > $limit) {
            $json = substr($json, 0, $limit) . '...';
        }

        return $json;
    }

    private static function compileStats(array $data): array
    {
        $runs = $data['runs'] ?? [];
        $pages = 0;
        $issues = 0;
        $status4xx = 0;
        $status5xx = 0;

        foreach ($runs as $run) {
            $summary = $run['summary'] ?? [];
            $pages += (int) ($summary['pages'] ?? count($run['pages'] ?? []));
            $issues += (int) ($summary['issues']['total'] ?? 0);
            $status4xx += (int) ($summary['status']['4xx'] ?? 0);
            $status5xx += (int) ($summary['status']['5xx'] ?? 0);
        }

        return [
            'pages' => $pages,
            'issues' => $issues,
            'status4xx' => $status4xx,
            'status5xx' => $status5xx,
        ];
    }

    private static function fallbackReport(string $type, array $data): array
    {
        $stats = self::compileStats($data);
        $runs = $data['runs'] ?? [];

        $summaryText = sprintf(
            'Analyzed %d pages across %d run(s). Total issues: %d. 4xx: %d, 5xx: %d.',
            $stats['pages'],
            count($runs),
            $stats['issues'],
            $stats['status4xx'],
            $stats['status5xx']
        );

        if ($type === 'per_page') {
            $summaryText .= ' Focus on the selected page’s title length, meta description quality, and internal links.';
        }

        $actions = [
            'Fix 4xx/5xx pages and re-crawl until clean',
            'Ensure <title> is 50–60 chars and unique per page',
            'Write compelling meta descriptions (110–155 chars)',
            'Add descriptive ALT text on images',
        ];

        $metaRec = [];
        if ($type === 'per_page' && !empty($runs[0]['pages'][0])) {
            $page = $runs[0]['pages'][0];
            $metaRec[] = [
                'url' => sanitize_text_field($page['url'] ?? ''),
                'title' => isset($page['title']) ? self::truncate((string) $page['title'], 60) : '',
                'meta_description' => isset($page['meta_description']) ? self::truncate((string) $page['meta_description'], 150) : '',
            ];
        }

        $tech = 'Check canonical tags, robots directives, and ensure JSON-LD is valid. Consider sitemaps and proper 301 redirects.';

        return [
            'summary' => $summaryText,
            'actions' => $actions,
            'meta_rec' => $metaRec,
            'tech' => $tech,
        ];
    }

    private static function fallbackSection(string $type, array $data, string $sectionId): array
    {
        $label = self::labelForSection($sectionId);
        $stats = self::compileStats($data);
        $runs = $data['runs'] ?? [];

        $body = sprintf(
            '%s: Analyzed %d pages across %d run(s). Total issues: %d. 4xx: %d, 5xx: %d.',
            $label,
            $stats['pages'],
            count($runs),
            $stats['issues'],
            $stats['status4xx'],
            $stats['status5xx']
        );

        if ($label === 'Meta & Heading Optimization') {
            $body .= ' Ensure titles ~55 chars, H1 present once, and unique meta descriptions per page.';
        } elseif ($label === 'Content & Image Optimization') {
            $body .= ' Improve content depth, internal linking, and add descriptive ALT text for images.';
        } elseif ($label === 'Technical SEO') {
            $body .= ' Validate canonicals, robots directives, sitemap coverage, and fix crawl errors.';
        }

        $reco = [
            'Prioritize fixing 4xx/5xx pages to restore crawl health',
            'Standardize title length and improve meta description quality',
            'Add ALT text and compress large images',
        ];

        return [
            'body' => $body,
            'reco_list' => $reco,
        ];
    }

    private static function sanitizeStringsArray($items, int $max = 8): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            $sanitized[] = sanitize_text_field($item);
            if (count($sanitized) >= $max) {
                break;
            }
        }

        return $sanitized;
    }

    private static function sanitizeMetaRecommendations($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'url' => esc_url_raw($item['url'] ?? ''),
                'title' => sanitize_text_field($item['title'] ?? ''),
                'meta_description' => sanitize_text_field($item['meta_description'] ?? ''),
            ];
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    private static function labelForSection(string $sectionId): string
    {
        if (strpos($sectionId, 'overview_') === 0) {
            return 'Overview';
        }
        if (strpos($sectionId, 'performance_summary_') === 0 || strpos($sectionId, 'performance_') === 0) {
            return 'Performance Summary';
        }
        if (strpos($sectionId, 'technical_seo_issues_') === 0 || strpos($sectionId, 'technical_') === 0) {
            return 'Technical SEO Issues';
        }
        if (strpos($sectionId, 'onpage_seo_content_') === 0) {
            return 'On-Page SEO & Content';
        }
        if (strpos($sectionId, 'onpage_meta_heading_') === 0) {
            return 'Meta & Heading Optimization';
        }
        if (strpos($sectionId, 'keyword_analysis_') === 0) {
            return 'Keyword Analysis';
        }
        if (strpos($sectionId, 'backlink_profile_') === 0) {
            return 'Backlink Profile';
        }
        if (strpos($sectionId, 'onpage_content_image_') === 0) {
            return 'Content & Image Optimization';
        }
        if (strpos($sectionId, 'recommendations_') === 0) {
            return 'Recommendations';
        }

        return 'Section';
    }

    private static function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }

    private static function generateContent(string $prompt): string
    {
        $response = self::callGemini($prompt);
        return $response ?: '';
    }

    private static function log(string $message, array $context = []): void
    {
        $payload = $context ? ' ' . wp_json_encode($context) : '';
        error_log('[AISEO Gemini] ' . $message . $payload);
    }

    private static function shorten(string $value, int $max = 300): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '…';
    }
}
