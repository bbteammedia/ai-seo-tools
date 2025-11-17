<?php
namespace BBSEO\AI;

use BBSEO\Helpers\LLMContext;

class Gemini
{
    private const MODEL = 'gemini-2.5-flash-lite';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    protected $dataSection = [
            'executive_summary_' => [
                'label' => 'Executive Summary',
                'id' => 'executive_summary',
                'data_key' => 'executiveSummary'
            ],
            'top_actions_' => [
                'label' => 'Top Actions',
                'id' => 'top_actions',
                'data_key' => 'topActions'
            ],
            'overview_' => [
                'label' => 'Overview',
                'id' => 'overview',
                'data_key' => 'overview'
            ],
            'performance_summary_' => [
                'label' => 'Performance Summary',
                'id' => 'performance_summary',
                'data_key' => 'performance'
            ],
            'performance_' => [
                'label' => 'Performance Summary',
                'id' => 'performance',
                'data_key' => 'performance'
            ],
            'technical_seo_issues_' => [
                'label' => 'Technical SEO Issues',
                'id' => 'technical_seo_issues',
                'data_key' => 'technicalSEO'
            ],
            'technical_' => [
                'label' => 'Technical SEO Issues',
                'id' => 'technical',
                'data_key' => 'technicalSEO'
            ],
            'onpage_seo_content_' => [
                'label' => 'On-Page SEO & Content',
                'id' => 'onpage_seo_content',
                'data_key' => 'onpageContent'
            ],
            'onpage_meta_heading_' => [
                'label' => 'Meta & Heading Optimization',
                'id' => 'onpage_meta_heading',
                'data_key' => 'metaHeading'
            ],
            'keyword_analysis_' => [
                'label' => 'Keyword Analysis',
                'id' => 'keyword_analysis',
                'data_key' => 'keywordAnalysis'
            ],
            'backlink_profile_' => [
                'label' => 'Backlink Profile',
                'id' => 'backlink_profile',
                'data_key' => 'backlinkProfile'
            ],
            'meta_recommendations_' => [
                'label' => 'Meta Recommendations',
                'id' => 'meta_recommendations',
                'data_key' => 'metaRecommendations'
            ],
            'onpage_content_image_' => [
                'label' => 'Content & Image Optimization',
                'id' => 'onpage_content_image',
                'data_key' => 'onpageContentImage'
            ],
            'technical_findings_' => [
                'label' => 'Technical Findings',
                'id' => 'technical_findings',
                'data_key' => 'technicalFindings'
            ],
            'recommendations_' => [
                'label' => 'Recommendations',
                'id' => 'recommendations',
                'data_key' => 'recommendations'
            ]
        ];

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
        $label = self::getSectionString($sectionId, 'label') ?: 'General';
        $prompt = self::sectionPromptJson(self::getSectionString($sectionId, 'id') ?: 'general');

        $schema = [
            'type'       => 'object',
            'properties' => [
                'body'      => ['type' => 'string', 'description' => '1–2 short paragraphs.'],
                'reco_list' => [
                'type'  => 'array',
                'items' => ['type' => 'string', 'description' => 'Concise, actionable bullet.']
                ],
            ],
            'required'            => ['body','reco_list']
        ];

        $response = self::callGemini(
            self::buildSectionPrompt($type, $label, $sectionId, $data),
            [
                'temperature' => $prompt['temperature'] ?? 0.4,
                'maxOutputTokens' => $prompt['max_tokens'] ?? 1024,
                'temperature' => $prompt['temperature'] ?? 0.4,
                'topP' => $prompt['top_p'] ?? 0.95,
                'responseSchema' => $schema,
            ]
        );

        self::log('Gemini summarizeSection: response received', ['section' => $sectionId, 'response_snippet' => $response]);

        if ($response) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $return = [
                    'body'      => sanitize_textarea_field($decoded['body'] ?? ''),
                    'reco_list' => self::sanitizeStringsArray($decoded['reco_list'] ?? [], 8),
                ];
                self::log('Gemini summarizeSection: response', $return);
                return $return;
            }
            self::log('Gemini summarizeSection: JSON decode failed', ['section' => $sectionId, 'response' => self::shorten($response)]);
        }

        self::log('Gemini summarizeSection: using fallback', ['section' => $sectionId]);
        return self::fallbackSection($type, $data, $sectionId);
    }

    public static function structuredContent(string $prompt, array $schema, array $config = []): ?array
    {
        $config['responseSchema'] = $schema;
        $response = self::callGemini($prompt, $config);
        if (!$response) {
            return null;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function callGemini(string $prompt, array $generationConfig = []): ?string
    {
        $apiKey = getenv('GEMINI_API_KEY') ?: '';
        if (!$apiKey) {
            self::log('Gemini call skipped: missing API key');
            return null;
        }

        $endpoint = sprintf(self::ENDPOINT, self::MODEL) . '?key=' . rawurlencode($apiKey);

        $baseConfig = [
            'temperature'      => 0.4,
            'topP'             => 0.95,
            'maxOutputTokens'  => 1024,
            'responseMimeType' => 'application/json',
        ];
        $payload = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => array_merge($baseConfig, $generationConfig),
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
High level stats: pages={$stats['pages']} images={$stats['images']} errors={$stats['errors']} issues={$stats['issues']} 4xx={$stats['status4xx']} 5xx={$stats['status5xx']}
TXT;

        return $instructions . "\n\nDATA (JSON):\n" . $context;
    }

    private static function buildSectionPrompt(string $type, string $label, string $sectionId, array $data): string
    {
        $promptJsonFile = self::getSectionString($sectionId, 'id') ?: 'general';
        $ctxKey         = self::getSectionString($sectionId, 'data_key') ?: null;

        $ctx = [];
        if ($ctxKey && method_exists(\BBSEO\Helpers\LLMContext::class, $ctxKey)) {
            $ctx = \BBSEO\Helpers\LLMContext::$ctxKey($data);
        }
        
        $metricsKey = self::sectionMetricsKey($sectionId);
        $sectionMetrics = $data['section_metrics'][$sectionId] ?? $data['section_metrics'][$metricsKey] ?? [];
        if (!empty($sectionMetrics)) {
            $ctx['metrics'] = $sectionMetrics;
        }
        $contextJson = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $prompt = self::sectionPromptJson($promptJsonFile) ?: [];
        $base   = (string)($prompt['prompt'] ?? "You are an SEO analyst writing the '{$label}' section.");
        $expected = (string)($prompt['expected_result'] ?? 'Return ONLY valid JSON as {"body":string,"reco_list":string[]}.');

        $project = (string)($data['project'] ?? '');
        $base = strtr($base, [
            '{{type}}'    => $type,
            '{{project}}' => $project,
        ]);

        $guidelinesList = '';
        if (!empty($prompt['guidelines']) && is_array($prompt['guidelines'])) {
            $lines = array_map(static fn($s) => '- ' . trim((string)$s), $prompt['guidelines']);
            $guidelinesList = implode("\n", $lines);
        }

        // 4) Build strong, compact instruction
        $instructions = <<<TXT
    {$base}

    Context (JSON):
    {$contextJson}

    {$expected}

    Guidelines:
    {$guidelinesList}

    Hard rules:
    - Output JSON only (no markdown, code fences, or commentary).
    - Use double quotes for all keys/strings (valid JSON, UTF-8).
    - Allowed keys: "body", "reco_list" only.
    - If a metric is unavailable, acknowledge it gracefully.
    - Use basic english and can understand for non-technical readers.

    Metadata:
    - section_id: {$sectionId}
    - report_type: {$type}
    - project: {$project}
    TXT;

        // 5) If no context AND prompt specifies a fallback_response, append it (the caller may choose to use it)
        if (empty($ctx) && !empty($prompt['fallback_response'])) {
            $instructions .= "\n\nFALLBACK_JSON:\n" . (string)$prompt['fallback_response'];
        }

        self::log('Gemini buildSectionPrompt', [
            'instructions' => $instructions,
        ]);

        return $instructions;
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

    private static function sectionMetricsKey(string $sectionId): string
    {
        if (strpos($sectionId, '_') === false) {
            return $sectionId;
        }
        
        // Split by underscore and take first two parts if available
        $parts = explode('_', $sectionId);
        
        // If we have at least 2 parts, return first two joined with underscore
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }
        
        // Otherwise return the first part (fallback for single underscore)
        return $parts[0];
    }

    private static function compileStats(array $data): array
    {
        $runs = $data['runs'] ?? [];
        $pages = 0;
        $images = 0;
        $errors = 0;
        $issues = 0;
        $status4xx = 0;
        $status5xx = 0;

        foreach ($runs as $run) {
            $summary = $run['summary'] ?? [];
            $pages += (int) ($summary['pages'] ?? count($run['pages'] ?? []));
            $images += (int) ($summary['images'] ?? count($run['images'] ?? []));
            $errors += (int) ($summary['errors'] ?? count($run['errors'] ?? []));
            $issues += (int) ($summary['issues']['total'] ?? 0);
            $status4xx += (int) ($summary['status']['4xx'] ?? 0);
            $status5xx += (int) ($summary['status']['5xx'] ?? 0);
        }

        return [
            'pages' => $pages,
            'images' => $images,
            'errors' => $errors,
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
        $key = self::getSectionString($sectionId, 'id') ?: 'general';
        $def = self::sectionPromptJson($key); 
        if (is_array($def) && !empty($def['fallback_response'])) {
            $decoded = json_decode($def['fallback_response'], true);

            if (is_array($decoded)) {
                return [
                    'body'      => (string)($decoded['body'] ?? ''),
                    'reco_list' => array_values(array_filter((array)($decoded['reco_list'] ?? []))),
                ];
            }
            return [
                'body'      => (string)$def['fallback_response'],
                'reco_list' => [],
            ];
        }
        $project = (string)($data['project'] ?? 'unknown');
        return [
            'body'      => sprintf("No AI output available. Fallback generated for '%s' (%s report) in project '%s'.", $key, $type ?: 'general', $project),
            'reco_list' => [],
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

    private static function sectionPromptJson(string $promptJsonFile, string $type = '', string $project = '', string $label = ''): array
    {
        // 0) Default fallback (short, token-efficient)
        $default = [
            'prompt' => "You are an SEO analyst creating a fallback summary for the '{{label}}' section in a {{type}} SEO report for {{project}}. Provide a concise overview of likely SEO improvements based on best practices for this report type.",
            'expected_result' => "Return ONLY valid JSON (no markdown) as { \"body\": string, \"reco_list\": string[] } where `body` is a short paragraph and `reco_list` includes 5 high-value recommendations.",
            'guidelines' => [
                "Adapt to report type (general, per_page, technical).",
                "Do not mention missing data or unavailable files.",
                "Keep tone confident, concise, and business-friendly.",
                "Recommendations must be practical and prioritized.",
                "Output clean JSON only (no markdown or extra text)."
            ],
            'fallback_response' => '{ "body": "Fallback summary generated.", "reco_list": [] }',
            'temperature' => 0.6,
            'max_tokens' => 1500,
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ];

        // 1) Handle missing filename
        $key = trim((string)$promptJsonFile);
        if ($key === '') {
            return $default;
        }

        // 2) Cache to prevent repeated disk reads
        static $cache = [];
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // 3) Resolve prompts directory
        $dir = rtrim(\get_stylesheet_directory(), '/') . '/templates/prompt';
        $path = $dir . '/' . $key . '.json';

        // 4) Load + decode file (safe JSON)
        $fileCfg = [];
        if (is_file($path) && is_readable($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $fileCfg = $decoded;
                }
            }
        }

        // 5) Merge file config into defaults
        $cfg = array_replace_recursive($default, $fileCfg);

        // 6) Normalize types and fallback
        $cfg['prompt']          = (string)($cfg['prompt'] ?? $default['prompt']);
        $cfg['expected_result'] = (string)($cfg['expected_result'] ?? $default['expected_result']);
        $cfg['guidelines']      = array_values(array_filter(array_map(
            static fn($g) => is_string($g) ? trim($g) : '',
            (array)($cfg['guidelines'] ?? $default['guidelines'])
        ))) ?: $default['guidelines'];

        // Ensure fallback_response valid JSON
        $fallback = (string)($cfg['fallback_response'] ?? $default['fallback_response']);
        $test = json_decode($fallback, true);
        if (!is_array($test) || !array_key_exists('body', $test)) {
            $fallback = $default['fallback_response'];
        }
        $cfg['fallback_response'] = $fallback;

        // Clamp numeric params
        $cfg['temperature']       = max(0.0, min(1.0, (float)($cfg['temperature'] ?? $default['temperature'])));
        $cfg['top_p']             = max(0.0, min(1.0, (float)($cfg['top_p'] ?? $default['top_p'])));
        $cfg['max_tokens']        = max(256, min(4000, (int)($cfg['max_tokens'] ?? $default['max_tokens'])));
        $cfg['frequency_penalty'] = (float)($cfg['frequency_penalty'] ?? 0);
        $cfg['presence_penalty']  = (float)($cfg['presence_penalty'] ?? 0);

        // 7) Interpolate variables {{type}}, {{project}}, {{label}}
        $replacements = [
            '{{type}}'    => $type,
            '{{project}}' => $project,
            '{{label}}'   => $label,
        ];
        $cfg['prompt']          = strtr($cfg['prompt'], $replacements);
        $cfg['expected_result'] = strtr($cfg['expected_result'], $replacements);
        $cfg['guidelines']      = array_map(fn($g) => strtr($g, $replacements), $cfg['guidelines']);

        // 8) Cache and return
        $cache[$key] = $cfg;
        return $cfg;
    }

    /**
     * Map $sectionId prefixes to canonical prompt keys used by sectionPromptJson.
     * Keep this in sync with your prompts directory / registry.
     */
    private static function getSectionString(string $sectionId, string $dataKey = 'label'): string
    {
        // get $dataSection property
        $sectionData = (new self())->dataSection;

        foreach ($sectionData as $prefix => $data) {
            if (strpos($sectionId, $prefix) === 0) {
                return $data[$dataKey] ?? false;
            }
        }

        return false;
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

    public static function log(string $message, array $context = []): void
    {
        $payload = $context ? ' ' . wp_json_encode($context) : '';
        // save log file to wp-content/uploads/ai-seo-tool/gemini.log
        $uploadDir = wp_upload_dir();
        $logDir = trailingslashit($uploadDir['basedir']) . 'ai-seo-tool';
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }
        $logFile = trailingslashit($logDir) . 'gemini.log';
        $date = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$date}] {$message}{$payload}\n", FILE_APPEND);

        
    }

    private static function shorten(string $value, int $max = 300): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '…';
    }
}
