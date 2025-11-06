<?php
namespace AISEO\Analytics;

use AISEO\Analytics\Store as AnalyticsStore;
use AISEO\Helpers\Storage;

class GoogleAnalytics
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const REPORT_ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta';
    private const DEFAULT_RUN_ID = 'analytics';
    private const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
    ];

    public static function clientId(): string
    {
        return trim((string) getenv('AISEO_GA_CLIENT_ID'));
    }

    public static function clientSecret(): string
    {
        return trim((string) getenv('AISEO_GA_CLIENT_SECRET'));
    }

    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=aiseo_ga_callback');
    }

    public static function authorizationUrl(string $project, string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => self::clientId(),
            'redirect_uri' => self::redirectUri(),
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        return self::AUTH_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function exchangeAuthCode(string $code): array
    {
        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'code' => $code,
                'client_id' => self::clientId(),
                'client_secret' => self::clientSecret(),
                'redirect_uri' => self::redirectUri(),
                'grant_type' => 'authorization_code',
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body)) {
            $message = is_array($body) && isset($body['error']) ? $body['error'] : 'Token exchange failed';
            throw new \RuntimeException($message);
        }

        return $body;
    }

    public static function refreshAccessToken(string $project): array
    {
        $config = self::loadConfig($project);
        self::ensureGaConfig($config);
        $refreshToken = $config['analytics']['ga']['refresh_token'] ?? '';
        if (!$refreshToken) {
            throw new \RuntimeException(__('Refresh token missing. Please reconnect Google Analytics.', 'ai-seo-tool'));
        }

        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'client_id' => self::clientId(),
                'client_secret' => self::clientSecret(),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body) || empty($body['access_token'])) {
            $message = is_array($body) && isset($body['error']) ? $body['error'] : 'Unable to refresh Google Analytics token';
            throw new \RuntimeException($message);
        }

        $expiresIn = (int) ($body['expires_in'] ?? 3600);

        $config['analytics']['ga']['access_token'] = $body['access_token'];
        $config['analytics']['ga']['access_token_expires'] = time() + $expiresIn;
        $config['analytics']['ga']['last_token_refresh'] = gmdate('c');
        self::writeConfig($project, $config);

        return $body;
    }

    public static function storeTokens(string $project, array $tokens): void
    {
        Storage::ensureProject($project);
        $config = self::loadConfig($project);
        self::ensureGaConfig($config);
        $ga = $config['analytics']['ga'];

        if (!empty($tokens['refresh_token'])) {
            $ga['refresh_token'] = $tokens['refresh_token'];
        }
        if (!empty($tokens['access_token'])) {
            $ga['access_token'] = $tokens['access_token'];
        }
        if (!empty($tokens['expires_in'])) {
            $ga['access_token_expires'] = time() + (int) $tokens['expires_in'];
        }
        $ga['connected_at'] = gmdate('c');
        unset($ga['last_error']);

        $config['analytics']['ga'] = $ga;
        self::writeConfig($project, $config);
    }

    public static function disconnect(string $project): void
    {
        $config = self::loadConfig($project);
        self::ensureGaConfig($config);
        unset($config['analytics']['ga']['refresh_token'], $config['analytics']['ga']['access_token'], $config['analytics']['ga']['access_token_expires']);
        $config['analytics']['ga']['connected_at'] = null;
        $config['analytics']['ga']['last_sync'] = null;
        $config['analytics']['ga']['last_error'] = null;
        self::writeConfig($project, $config);
    }

    public static function hasRefreshToken(string $project): bool
    {
        $config = self::loadConfig($project);
        self::ensureGaConfig($config);
        return !empty($config['analytics']['ga']['refresh_token']);
    }

    public static function isConfigured(string $project): bool
    {
        $config = self::loadConfig($project);
        self::ensureGaConfig($config);
        $ga = $config['analytics']['ga'];
        return !empty($ga['refresh_token']) && !empty($ga['property_id']);
    }

    public static function sync(string $project, ?string $runId = null): array
    {
        $config = self::loadConfig($project);
        self::ensureGaConfig($config);
        $ga = $config['analytics']['ga'];
        $propertyId = $ga['property_id'] ?? '';
        if (!$propertyId) {
            throw new \RuntimeException(__('Google Analytics property ID is not configured.', 'ai-seo-tool'));
        }
        if (empty(self::clientId()) || empty(self::clientSecret())) {
            throw new \RuntimeException(__('Google Analytics client credentials are missing.', 'ai-seo-tool'));
        }
        if (empty($ga['refresh_token'])) {
            throw new \RuntimeException(__('Google Analytics is not connected for this project.', 'ai-seo-tool'));
        }

        $tokenData = self::refreshAccessToken($project);
        $accessToken = $tokenData['access_token'];

        $settings = self::parseSettings($ga);
        $timeseries = self::fetchTimeseries($project, $propertyId, $accessToken, $settings);

        $payload = [
            'project' => $project,
            'property_id' => $propertyId,
            'fetched_at' => gmdate('c'),
            'timeseries' => $timeseries['items'],
            'totals' => $timeseries['totals'],
            'range' => [
                'start' => $timeseries['start'],
                'end' => $timeseries['end'],
                'display' => $settings['label'],
            ],
            'metrics' => $settings['metrics'],
        ];

        $targetRun = $runId ?: (Storage::getLatestRun($project) ?: self::DEFAULT_RUN_ID);
        if ($targetRun === self::DEFAULT_RUN_ID) {
            Storage::ensureRun($project, $targetRun);
        }

        AnalyticsStore::save($project, $targetRun, 'ga', $payload);

        $config['analytics']['ga']['last_sync'] = gmdate('c');
        $config['analytics']['ga']['last_error'] = null;
        $config['analytics']['ga']['last_run'] = $targetRun;
        self::writeConfig($project, $config);

        return $payload;
    }

    public static function fetchTimeseries(string $project, string $propertyId, string $accessToken, array $settings): array
    {
        $endpoint = sprintf('%s/properties/%s:runReport', self::REPORT_ENDPOINT, rawurlencode($propertyId));
        $body = [
            'dateRanges' => [
                ['startDate' => $settings['range']['start'], 'endDate' => $settings['range']['end']],
            ],
            'metrics' => array_map(static fn ($metric) => ['name' => $metric], $settings['metrics']),
            'dimensions' => [
                ['name' => 'date'],
            ],
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            $message = $data['error']['message'] ?? 'Google Analytics API error';
            throw new \RuntimeException($message);
        }

        $rows = $data['rows'] ?? [];
        $items = [];
        $totals = array_fill_keys($settings['metrics'], 0);

        foreach ($rows as $row) {
            $dateDimension = $row['dimensionValues'][0]['value'] ?? null;
            $metrics = $row['metricValues'] ?? [];
            if (!$dateDimension || !$metrics) {
                continue;
            }
            $date = \DateTime::createFromFormat('Ymd', $dateDimension);
            $formattedDate = $date ? $date->format('Y-m-d') : $dateDimension;

            $entry = [
                'date' => $formattedDate,
            ];

            foreach ($settings['metrics'] as $index => $metricName) {
                $rawValue = $metrics[$index]['value'] ?? 0;
                $casted = self::castMetricValue($metricName, $rawValue);
                $entry[$metricName] = $casted;

                if (!self::isRatioMetric($metricName)) {
                    $totals[$metricName] += $casted;
                }
            }

            $items[] = $entry;
        }

        return [
            'items' => $items,
            'totals' => $totals,
            'start' => $settings['range']['start'],
            'end' => $settings['range']['end'],
        ];
    }

    public static function loadConfig(string $project): array
    {
        Storage::ensureProject($project);
        $path = Storage::projectDir($project) . '/config.json';
        if (!is_file($path)) {
            Storage::writeJson($path, []);
        }
        $config = json_decode(file_get_contents($path), true);
        return is_array($config) ? $config : [];
    }

    public static function writeConfig(string $project, array $config): void
    {
        Storage::writeJson(Storage::projectDir($project) . '/config.json', $config);
    }

    private static function ensureGaConfig(array &$config): void
    {
        if (!isset($config['analytics']) || !is_array($config['analytics'])) {
            $config['analytics'] = [];
        }
        if (!isset($config['analytics']['ga']) || !is_array($config['analytics']['ga'])) {
            $config['analytics']['ga'] = [];
        }
    }

    public static function defaultMetrics(): array
    {
        return ['sessions', 'totalUsers', 'screenPageViews'];
    }

    public static function metricGroups(): array
    {
        return [
            'traffic' => [
                'label' => __('Traffic', 'ai-seo-tool'),
                'metrics' => [
                    'sessions' => __('Sessions', 'ai-seo-tool'),
                    'totalUsers' => __('Total Users', 'ai-seo-tool'),
                    'newUsers' => __('New Users', 'ai-seo-tool'),
                    'screenPageViews' => __('Page Views', 'ai-seo-tool'),
                ],
            ],
            'engagement' => [
                'label' => __('Engagement', 'ai-seo-tool'),
                'metrics' => [
                    'engagedSessions' => __('Engaged Sessions', 'ai-seo-tool'),
                    'averageSessionDuration' => __('Avg Session Duration (s)', 'ai-seo-tool'),
                    'bounceRate' => __('Bounce Rate (%)', 'ai-seo-tool'),
                    'eventCount' => __('Event Count', 'ai-seo-tool'),
                ],
            ],
            'conversion' => [
                'label' => __('Conversions', 'ai-seo-tool'),
                'metrics' => [
                    'conversions' => __('Conversions', 'ai-seo-tool'),
                    'totalRevenue' => __('Total Revenue', 'ai-seo-tool'),
                ],
            ],
        ];
    }

    public static function rangeOptions(): array
    {
        return [
            'last_7_days' => __('Last 7 days', 'ai-seo-tool'),
            'last_30_days' => __('Last 30 days', 'ai-seo-tool'),
            'last_90_days' => __('Last 90 days', 'ai-seo-tool'),
            'custom' => __('Custom range', 'ai-seo-tool'),
        ];
    }

    private static function parseSettings(array $ga): array
    {
        $rangeKey = $ga['range'] ?? 'last_30_days';
        $metrics = $ga['metrics'] ?? self::defaultMetrics();
        if (!is_array($metrics) || empty($metrics)) {
            $metrics = self::defaultMetrics();
        }
        $metrics = array_values(array_unique(array_filter($metrics, static fn ($metric) => is_string($metric) && $metric !== '')));

        $range = self::resolveDateRange($rangeKey, $ga['custom_start'] ?? null, $ga['custom_end'] ?? null);
        return [
            'range' => $range,
            'metrics' => $metrics,
            'label' => self::rangeDisplayLabel($rangeKey, $range),
        ];
    }

    private static function resolveDateRange(string $rangeKey, ?string $customStart, ?string $customEnd): array
    {
        $today = gmdate('Y-m-d');
        switch ($rangeKey) {
            case 'last_7_days':
                return ['start' => gmdate('Y-m-d', strtotime('-6 days', strtotime($today))), 'end' => $today];
            case 'last_30_days':
                return ['start' => gmdate('Y-m-d', strtotime('-29 days', strtotime($today))), 'end' => $today];
            case 'last_90_days':
                return ['start' => gmdate('Y-m-d', strtotime('-89 days', strtotime($today))), 'end' => $today];
            case 'custom':
                $start = self::sanitizeDate($customStart) ?: $today;
                $end = self::sanitizeDate($customEnd) ?: $today;
                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }
                return ['start' => $start, 'end' => $end];
            default:
                return ['start' => gmdate('Y-m-d', strtotime('-29 days', strtotime($today))), 'end' => $today];
        }
    }

    private static function rangeDisplayLabel(string $key, array $range): string
    {
        $options = self::rangeOptions();
        if ($key !== 'custom' && isset($options[$key])) {
            return $options[$key];
        }
        return sprintf('%s → %s', $range['start'], $range['end']);
    }

    private static function sanitizeDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }
        $time = strtotime($date);
        if ($time === false) {
            return null;
        }
        return gmdate('Y-m-d', $time);
    }

    private static function castMetricValue(string $metric, $value)
    {
        if (self::isRatioMetric($metric)) {
            return (float) $value;
        }
        if (stripos($metric, 'duration') !== false) {
            return (float) $value;
        }
        if (stripos($metric, 'revenue') !== false) {
            return (float) $value;
        }
        return (int) $value;
    }

    private static function isRatioMetric(string $metric): bool
    {
        return in_array($metric, ['bounceRate'], true);
    }
}
