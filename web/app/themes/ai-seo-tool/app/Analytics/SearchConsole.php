<?php
namespace BBSEO\Analytics;

use BBSEO\Analytics\Store as AnalyticsStore;
use BBSEO\Helpers\Storage;

class SearchConsole
{
    private const API_ENDPOINT = 'https://searchconsole.googleapis.com/webmasters/v3';
    private const DEFAULT_RUN_ID = 'analytics';

    public static function defaultMetrics(): array
    {
        return ['clicks', 'impressions', 'ctr', 'position'];
    }

    public static function metricOptions(): array
    {
        return [
            'clicks' => __('Clicks', 'ai-seo-tool'),
            'impressions' => __('Impressions', 'ai-seo-tool'),
            'ctr' => __('CTR (%)', 'ai-seo-tool'),
            'position' => __('Average Position', 'ai-seo-tool'),
        ];
    }

    public static function isConfigured(string $project): bool
    {
        if (!GoogleAnalytics::hasRefreshToken($project)) {
            return false;
        }
        $config = GoogleAnalytics::loadConfig($project);
        self::ensureConfig($config);
        $gsc = $config['analytics']['gsc'];
        return !empty(trim((string) ($gsc['property'] ?? '')));
    }

    public static function sync(string $project, ?string $runId = null): array
    {
        $config = GoogleAnalytics::loadConfig($project);
        self::ensureConfig($config);
        $gsc = $config['analytics']['gsc'];
        $property = isset($gsc['property']) ? trim((string) $gsc['property']) : '';
        if (!$property) {
            throw new \RuntimeException(__('Search Console property is not configured.', 'ai-seo-tool'));
        }

        $settings = self::parseSettings($gsc);
        $tokenData = GoogleAnalytics::refreshAccessToken($project);
        $accessToken = $tokenData['access_token'];

        $timeseries = self::fetchTimeseries($property, $accessToken, $settings);
        $details = self::buildDetailReports($property, $accessToken, $settings);

        $payload = [
            'project' => $project,
            'property' => $property,
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

        AnalyticsStore::save($project, $targetRun, 'gsc', $payload);

        if (!empty($details)) {
            AnalyticsStore::writeExtra($project, $targetRun, 'gsc-details.json', [
                'project' => $project,
                'property' => $property,
                'fetched_at' => $payload['fetched_at'],
                'range' => $payload['range'],
                'metrics' => $settings['metrics'],
                'details' => $details,
            ]);
        }

        $config['analytics']['gsc']['last_sync'] = gmdate('c');
        $config['analytics']['gsc']['last_error'] = null;
        $config['analytics']['gsc']['last_run'] = $targetRun;
        GoogleAnalytics::writeConfig($project, $config);

        return $payload;
    }

    private static function fetchTimeseries(string $property, string $accessToken, array $settings): array
    {
        $url = sprintf('%s/sites/%s/searchAnalytics/query', self::API_ENDPOINT, rawurlencode($property));
        $body = [
            'startDate' => $settings['range']['start'],
            'endDate' => $settings['range']['end'],
            'dimensions' => ['date'],
            'rowLimit' => 1000,
            'searchType' => 'web',
        ];

        $response = wp_remote_post($url, [
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
            $message = $data['error']['message'] ?? 'Search Console API error';
            throw new \RuntimeException($message);
        }

        $metrics = $settings['metrics'];
        $items = [];
        $totals = array_fill_keys($metrics, 0);
        $clickSum = 0.0;
        $impressionSum = 0.0;
        $positionWeight = 0.0;
        $positionWeightDenominator = 0.0;

        foreach ($data['rows'] ?? [] as $row) {
            $keys = $row['keys'] ?? [];
            $date = $keys[0] ?? null;
            if (!$date) {
                continue;
            }

            $clicks = (float) ($row['clicks'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $ctr = (float) ($row['ctr'] ?? 0) * 100;
            $position = (float) ($row['position'] ?? 0);

            $entry = ['date' => $date];

            foreach ($metrics as $metric) {
                switch ($metric) {
                    case 'clicks':
                        $entry['clicks'] = $clicks;
                        $totals['clicks'] += $clicks;
                        break;
                    case 'impressions':
                        $entry['impressions'] = $impressions;
                        $totals['impressions'] += $impressions;
                        break;
                    case 'ctr':
                        $entry['ctr'] = $ctr;
                        break;
                    case 'position':
                        $entry['position'] = $position;
                        break;
                }
            }

            $clickSum += $clicks;
            $impressionSum += $impressions;
            if (in_array('position', $metrics, true)) {
                $positionWeight += $position * $impressions;
                $positionWeightDenominator += $impressions;
            }

            $items[] = $entry;
        }

        if (in_array('ctr', $metrics, true)) {
            $totals['ctr'] = $impressionSum > 0 ? ($clickSum / $impressionSum) * 100 : 0;
        }
        if (in_array('position', $metrics, true)) {
            $totals['position'] = ($positionWeightDenominator > 0) ? ($positionWeight / $positionWeightDenominator) : 0;
        }
        if (isset($totals['clicks'])) {
            $totals['clicks'] = $clickSum;
        }
        if (isset($totals['impressions'])) {
            $totals['impressions'] = $impressionSum;
        }

        return [
            'items' => $items,
            'totals' => $totals,
            'start' => $settings['range']['start'],
            'end' => $settings['range']['end'],
        ];
    }

    private static function buildDetailReports(string $property, string $accessToken, array $settings): array
    {
        $reports = [];
        $metrics = $settings['metrics'];

        $reports['queries'] = self::fetchDimensionReport($property, $accessToken, $settings, 'query', 50, $metrics);
        $pageReport = self::fetchDimensionReport($property, $accessToken, $settings, 'page', 50, $metrics);
        $reports['pages'] = $pageReport;
        $reports['countries'] = self::fetchDimensionReport($property, $accessToken, $settings, 'country', 30, $metrics);
        $reports['devices'] = self::fetchDimensionReport($property, $accessToken, $settings, 'device', 10, $metrics);
        $reports['search_appearances'] = self::fetchDimensionReport($property, $accessToken, $settings, 'searchAppearance', 10, $metrics);

        $reports['index_issues'] = self::fetchIndexIssues($property, $accessToken, $pageReport);

        return $reports;
    }

    private static function fetchDimensionReport(string $property, string $accessToken, array $settings, string $dimension, int $limit, array $metrics): array
    {
        $url = sprintf('%s/sites/%s/searchAnalytics/query', self::API_ENDPOINT, rawurlencode($property));
        $orderField = in_array('clicks', $metrics, true) ? 'clicks' : (in_array('impressions', $metrics, true) ? 'impressions' : 'ctr');
        $body = [
            'startDate' => $settings['range']['start'],
            'endDate' => $settings['range']['end'],
            'dimensions' => [$dimension],
            'rowLimit' => $limit,
            'searchType' => 'web',
            'orderBy' => [
                [
                    'fieldName' => $orderField,
                    'descending' => true,
                ],
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data['rows'] ?? [] as $row) {
            $keys = $row['keys'] ?? [];
            $keyValue = $keys[0] ?? null;
            if ($keyValue === null || $keyValue === '') {
                continue;
            }

            $entry = ['key' => $keyValue];
            $clicks = (float) ($row['clicks'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $ctr = (float) ($row['ctr'] ?? 0) * 100;
            $position = (float) ($row['position'] ?? 0);

            foreach ($metrics as $metric) {
                switch ($metric) {
                    case 'clicks':
                        $entry['clicks'] = $clicks;
                        break;
                    case 'impressions':
                        $entry['impressions'] = $impressions;
                        break;
                    case 'ctr':
                        $entry['ctr'] = $ctr;
                        break;
                    case 'position':
                        $entry['position'] = $position;
                        break;
                }
            }

            $rows[] = $entry;
        }

        return $rows;
    }

    private static function fetchIndexIssues(string $property, string $accessToken, array $pages): array
    {
        if (empty($pages)) {
            return [];
        }

        $endpoint = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
        $issues = [];
        $processed = 0;
        foreach ($pages as $page) {
            if ($processed >= 20) {
                break;
            }
            $inspectionUrl = $page['key'] ?? '';
            if (!$inspectionUrl || stripos($inspectionUrl, 'http') !== 0) {
                continue;
            }
            $processed++;

            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'body' => wp_json_encode([
                    'inspectionUrl' => $inspectionUrl,
                    'siteUrl' => $property,
                ]),
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if ($code !== 200 || !is_array($data)) {
                continue;
            }

            $result = $data['inspectionResult']['indexStatusResult'] ?? [];
            if (!$result) {
                continue;
            }

            $verdict = $result['verdict'] ?? '';
            $coverageState = $result['coverageState'] ?? '';
            $issueDetected = $verdict !== 'PASS' || !in_array($coverageState, ['Submitted and indexed', 'Indexed, not submitted in sitemap'], true);

            if (!$issueDetected) {
                continue;
            }

            $issues[] = [
                'url' => $inspectionUrl,
                'verdict' => $verdict,
                'coverage_state' => $coverageState,
                'indexing_state' => $result['indexingState'] ?? null,
                'last_crawl_time' => $result['lastCrawlTime'] ?? null,
                'page_fetch_state' => $result['pageFetchState'] ?? null,
                'robots_txt_state' => $result['robotsTxtState'] ?? null,
                'user_canonical' => $result['userCanonical'] ?? null,
                'google_canonical' => $result['googleCanonical'] ?? null,
                'referring_urls' => $result['referringUrls'] ?? [],
                'sitemaps' => $result['sitemaps'] ?? [],
            ];
        }

        return $issues;
    }

    private static function parseSettings(array $gsc): array
    {
        $rangeKey = $gsc['range'] ?? 'last_30_days';
        $metrics = $gsc['metrics'] ?? self::defaultMetrics();
        if (!is_array($metrics) || empty($metrics)) {
            $metrics = self::defaultMetrics();
        }
        $metrics = array_values(array_unique(array_filter($metrics, static fn ($metric) => is_string($metric) && $metric !== '')));

        $range = self::resolveDateRange($rangeKey, $gsc['custom_start'] ?? null, $gsc['custom_end'] ?? null);

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
        $options = GoogleAnalytics::rangeOptions();
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

    private static function ensureConfig(array &$config): void
    {
        if (!isset($config['analytics']) || !is_array($config['analytics'])) {
            $config['analytics'] = [];
        }
        if (!isset($config['analytics']['gsc']) || !is_array($config['analytics']['gsc'])) {
            $config['analytics']['gsc'] = [];
        }
    }
}
