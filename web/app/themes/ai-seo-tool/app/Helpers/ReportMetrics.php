<?php
namespace AISEO\Helpers;

class ReportMetrics
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,array<string,mixed>>
     */
    public static function build(string $type, array $snapshot): array
    {
        $base = self::compileBase($snapshot);

        return [
            'executive_summary' => ['rows' => [], 'headers' => []],
            'top_actions' => ['rows' => [], 'headers' => []],
            'overview' => self::overviewTable($type, $base),
            'performance_summary' => self::performanceSummaryTable($type, $base),
            'technical_seo_issues' => self::technicalIssuesTable($type, $base),
            'onpage_seo_content' => self::onpageTable($base),
            'keyword_analysis' => self::keywordTable($base),
            'backlink_profile' => self::backlinkTable($base),
            'meta_recommendations' => ['rows' => [], 'headers' => []],
            'technical_findings' => ['rows' => [], 'headers' => []],
            'recommendations' => ['rows' => [], 'headers' => []],
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function compileBase(array $snapshot): array
    {
        $runs = $snapshot['runs'] ?? [];

        $base = [
            'total_pages' => 0,
            'indexed_pages' => 0,
            'status' => [
                '2xx' => 0,
                '3xx' => 0,
                '4xx' => 0,
                '5xx' => 0,
            ],
            'issues' => [
                'broken_links' => 0,
                'missing_titles' => 0,
                'missing_meta' => 0,
                'long_titles' => 0,
                'low_content' => 0,
                'redirect_chains' => 0,
                'mixed_content' => 0,
                'duplicate_content' => 0,
                'missing_h1' => 0,
                'missing_canonical' => 0,
                'alt_missing' => 0,
            ],
            'samples' => [
                'broken_links' => null,
                'missing_titles' => null,
                'missing_meta' => null,
                'long_titles' => null,
                'low_content' => null,
                'redirect_chains' => null,
                'mixed_content' => null,
                'duplicate_content' => null,
                'missing_h1' => null,
                'missing_canonical' => null,
                'alt_missing' => null,
            ],
            'word_count_total' => 0,
            'word_count_pages' => 0,
            'avg_word_count' => 0,
            'pages' => [],
            'analytics' => [
                'ga_top_pageviews' => [],
                'ga_top_exit_pages' => [],
                'gsc_best_keywords' => [],
                'gsc_low_ctr' => [],
            ],
            'crawler' => [
                'largest_pages' => [],
                'slowest_pages' => [],
                'redirect_heavy' => [],
                'short_content_pages' => [],
            ],
            'backlinks' => [
                'referring_domains' => null,
                'total_backlinks' => null,
                'new_links' => null,
                'lost_links' => null,
                'toxic_score' => null,
                'anchor_distribution' => null,
                'last_synced' => null,
            ],
        ];

        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $summary = $run['summary'] ?? [];
            $issuesSummary = $summary['issues'] ?? [];
            $statusSummary = $summary['status'] ?? [];

            $base['total_pages'] += (int) ($summary['pages'] ?? count($run['pages'] ?? []));
            foreach (['2xx', '3xx', '4xx', '5xx'] as $bucket) {
                if (isset($statusSummary[$bucket])) {
                    $base['status'][$bucket] += (int) $statusSummary[$bucket];
                }
            }

            $base['issues']['redirect_chains'] += (int) ($issuesSummary['redirect_chains'] ?? 0);
            $base['issues']['mixed_content'] += (int) ($issuesSummary['mixed_content'] ?? 0);
            $base['issues']['duplicate_content'] += (int) ($issuesSummary['duplicate_content'] ?? 0);

            $report = $run['report'] ?? [];
            if (is_array($report)) {
                self::mergeLists($base['analytics']['ga_top_pageviews'], self::extractList($report, [
                    'ga4.top_pageviews',
                    'ga.top_pageviews',
                    'analytics.top_pageviews',
                ]));
                self::mergeLists($base['analytics']['ga_top_exit_pages'], self::extractList($report, [
                    'ga4.top_exit_pages',
                    'ga.top_exit_pages',
                ]));
                self::mergeLists($base['analytics']['gsc_best_keywords'], self::extractList($report, [
                    'gsc.best_keywords',
                    'gsc.best_performing_keywords',
                ]));
                self::mergeLists($base['analytics']['gsc_low_ctr'], self::extractList($report, [
                    'gsc.low_ctr_keywords',
                    'gsc.lowest_ctr',
                ]));
                self::mergeLists($base['crawler']['largest_pages'], self::extractList($report, [
                    'crawler.largest_pages',
                    'crawler.pages_largest',
                ]));
                self::mergeLists($base['crawler']['slowest_pages'], self::extractList($report, [
                    'crawler.slowest_pages',
                    'crawler.pages_slowest',
                ]));
                self::mergeLists($base['crawler']['redirect_heavy'], self::extractList($report, [
                    'crawler.redirect_heavy',
                    'crawler.most_redirects',
                ]));
                self::mergeLists($base['crawler']['short_content_pages'], self::extractList($report, [
                    'crawler.short_content_pages',
                    'crawler.thin_content_pages',
                ]));

                $backlinks = $report['backlinks'] ?? $report['link_profile'] ?? null;
                if (is_array($backlinks)) {
                    $base['backlinks']['referring_domains'] = $base['backlinks']['referring_domains'] ?? ($backlinks['referring_domains'] ?? null);
                    $base['backlinks']['total_backlinks'] = $base['backlinks']['total_backlinks'] ?? ($backlinks['total_backlinks'] ?? null);
                    $base['backlinks']['new_links'] = $base['backlinks']['new_links'] ?? ($backlinks['new_links'] ?? null);
                    $base['backlinks']['lost_links'] = $base['backlinks']['lost_links'] ?? ($backlinks['lost_links'] ?? null);
                    $base['backlinks']['toxic_score'] = $base['backlinks']['toxic_score'] ?? ($backlinks['toxic_score'] ?? null);
                    $base['backlinks']['anchor_distribution'] = $base['backlinks']['anchor_distribution'] ?? ($backlinks['anchor_text_distribution'] ?? null);
                    $base['backlinks']['last_synced'] = $base['backlinks']['last_synced'] ?? ($backlinks['last_synced'] ?? null);
                }
            }

            $pages = $run['pages'] ?? [];
            if (!is_array($pages)) {
                continue;
            }

            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $base['pages'][] = $page;

                $url = trim((string) ($page['url'] ?? ''));
                $status = (int) ($page['status'] ?? 0);
                $wordCount = (int) ($page['word_count'] ?? $page['words'] ?? 0);

                if ($status >= 200 && $status < 300) {
                    $base['indexed_pages']++;
                }
                if ($status >= 400 && $status < 500) {
                    $base['issues']['broken_links']++;
                    self::setSample($base['samples'], 'broken_links', $url);
                }

                if ($wordCount > 0) {
                    $base['word_count_total'] += $wordCount;
                    $base['word_count_pages']++;
                }
                if ($wordCount > 0 && $wordCount < 300) {
                    $base['issues']['low_content']++;
                    self::setSample($base['samples'], 'low_content', $url);
                }

                $title = trim((string) ($page['title'] ?? ''));
                if ($title === '') {
                    $base['issues']['missing_titles']++;
                    self::setSample($base['samples'], 'missing_titles', $url);
                }
                $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
                if ($titleLength > 65) {
                    $base['issues']['long_titles']++;
                    self::setSample($base['samples'], 'long_titles', $url);
                }

                $meta = trim((string) ($page['meta_description'] ?? ''));
                if ($meta === '') {
                    $base['issues']['missing_meta']++;
                    self::setSample($base['samples'], 'missing_meta', $url);
                }

                $canonical = trim((string) ($page['canonical'] ?? ''));
                if ($canonical === '') {
                    $base['issues']['missing_canonical']++;
                    self::setSample($base['samples'], 'missing_canonical', $url);
                }

                $h1 = $page['h1'] ?? null;
                $h1Count = $page['h1_count'] ?? null;
                $hasH1 = true;
                if (is_string($h1)) {
                    $hasH1 = trim($h1) !== '';
                } elseif (is_array($h1)) {
                    $hasH1 = !empty(array_filter($h1, static fn ($item) => trim((string) $item) !== ''));
                } elseif ($h1Count !== null) {
                    $hasH1 = (int) $h1Count > 0;
                }
                if (!$hasH1) {
                    $base['issues']['missing_h1']++;
                    self::setSample($base['samples'], 'missing_h1', $url);
                }

                $altMissing = $page['alt_missing'] ?? $page['images_missing_alt'] ?? null;
                if (is_numeric($altMissing) && (int) $altMissing > 0) {
                    $base['issues']['alt_missing']++;
                    self::setSample($base['samples'], 'alt_missing', $url);
                }

                $redirectFlag = $page['redirect_chain'] ?? $page['is_redirect_chain'] ?? null;
                if (!empty($redirectFlag)) {
                    $base['issues']['redirect_chains']++;
                    self::setSample($base['samples'], 'redirect_chains', $url);
                }

                $mixed = $page['mixed_content'] ?? $page['has_mixed_content'] ?? null;
                if (!empty($mixed)) {
                    $base['issues']['mixed_content']++;
                    self::setSample($base['samples'], 'mixed_content', $url);
                }

                $duplicate = $page['duplicate_content'] ?? $page['is_duplicate'] ?? null;
                if (!empty($duplicate)) {
                    $base['issues']['duplicate_content']++;
                    self::setSample($base['samples'], 'duplicate_content', $url);
                }
            }
        }

        if ($base['word_count_pages'] > 0) {
            $base['avg_word_count'] = (int) round($base['word_count_total'] / $base['word_count_pages']);
        }

        return $base;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function overviewTable(string $type, array $base): array
    {
        $rows = [];

        if ($type === 'technical') {
            $rows = [
                ['Metric' => 'Total Pages Crawled', 'Value' => self::formatNumber($base['total_pages'])],
                ['Metric' => 'Indexed Pages (2xx)', 'Value' => self::formatNumber($base['status']['2xx'])],
                ['Metric' => 'Broken Links (4xx)', 'Value' => self::formatNumber($base['status']['4xx'])],
                ['Metric' => 'Missing Titles', 'Value' => self::formatNumber($base['issues']['missing_titles'])],
                ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($base['issues']['missing_meta'])],
                ['Metric' => 'Redirect Chains', 'Value' => self::formatNumber($base['issues']['redirect_chains'])],
                ['Metric' => 'Mixed Content Pages', 'Value' => self::formatNumber($base['issues']['mixed_content'])],
            ];
        } elseif ($type === 'per_page') { // using per_page as content audit
            $rows = [
                ['Metric' => 'Total Pages Crawled', 'Value' => self::formatNumber($base['total_pages'])],
                ['Metric' => 'Indexed Pages (2xx)', 'Value' => self::formatNumber($base['status']['2xx'])],
                ['Metric' => 'Average Word Count', 'Value' => self::formatNumber($base['avg_word_count'])],
                ['Metric' => 'Pages <300 Words', 'Value' => self::formatNumber($base['issues']['low_content'])],
                ['Metric' => 'Duplicate Content Issues', 'Value' => self::formatNumber($base['issues']['duplicate_content'])],
                ['Metric' => 'Missing Titles', 'Value' => self::formatNumber($base['issues']['missing_titles'])],
                ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($base['issues']['missing_meta'])],
            ];
        } else {
            $rows = [
                ['Metric' => 'Total Pages Crawled', 'Value' => self::formatNumber($base['total_pages'])],
                ['Metric' => 'Indexed Pages (2xx)', 'Value' => self::formatNumber($base['status']['2xx'])],
                ['Metric' => 'Average Word Count', 'Value' => self::formatNumber($base['avg_word_count'])],
                ['Metric' => 'Broken Links (4xx)', 'Value' => self::formatNumber($base['status']['4xx'])],
                ['Metric' => 'Missing Titles', 'Value' => self::formatNumber($base['issues']['missing_titles'])],
                ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($base['issues']['missing_meta'])],
                ['Metric' => 'Long Titles (>65 chars)', 'Value' => self::formatNumber($base['issues']['long_titles'])],
                ['Metric' => 'Pages <300 Words', 'Value' => self::formatNumber($base['issues']['low_content'])],
            ];
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => $base['total_pages'] ? null : 'Metrics will populate after the first crawler run.',
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function performanceSummaryTable(string $type, array $base): array
    {
        $rows = [];

        if ($type === 'technical') {
            $rows[] = [
                'Source' => 'Google Search Console',
                'Metric' => 'Top Crawl Errors',
                'Top Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['error', 'keyword', 'term'], 'Connect Google Search Console'),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Slowest Page',
                'Top Value' => self::firstValue($base['crawler']['slowest_pages'], ['url', 'page'], self::fallbackSlowestPage($base)),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Most Redirects',
                'Top Value' => self::firstValue($base['crawler']['redirect_heavy'], ['url', 'page'], self::fallbackRedirectHeavyPage($base)),
            ];
        } elseif ($type === 'per_page') {
            $rows[] = [
                'Source' => 'Google Analytics',
                'Metric' => 'Top Exit Page',
                'Top Value' => self::firstValue($base['analytics']['ga_top_exit_pages'], ['url', 'page'], 'Connect Google Analytics'),
            ];
            $rows[] = [
                'Source' => 'Google Search Console',
                'Metric' => 'Lowest CTR Keyword',
                'Top Value' => self::firstValue($base['analytics']['gsc_low_ctr'], ['keyword', 'term'], 'Connect Google Search Console'),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Shortest Content Page',
                'Top Value' => self::firstValue($base['crawler']['short_content_pages'], ['url', 'page'], self::fallbackShortContentPage($base)),
            ];
        } else {
            $rows[] = [
                'Source' => 'Google Analytics',
                'Metric' => 'Top Pageviews',
                'Top Value' => self::firstValue($base['analytics']['ga_top_pageviews'], ['url', 'page'], 'Connect Google Analytics'),
            ];
            $rows[] = [
                'Source' => 'Google Search Console',
                'Metric' => 'Best Performing Keyword',
                'Top Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['keyword', 'term'], 'Connect Google Search Console'),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Largest Page',
                'Top Value' => self::firstValue($base['crawler']['largest_pages'], ['url', 'page'], self::fallbackLargestPage($base)),
            ];
        }

        return [
            'headers' => ['Source', 'Metric', 'Top Value'],
            'rows' => $rows,
            'note' => empty($base['analytics']['ga_top_pageviews']) && empty($base['analytics']['gsc_best_keywords'])
                ? 'Connect analytics data sources to enrich this summary.'
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function technicalIssuesTable(string $type, array $base): array
    {
        $rows = [];

        if ($type === 'per_page') {
            $rows[] = self::issueRow('Duplicate Content', $base['issues']['duplicate_content'], $base['samples']['duplicate_content']);
            $rows[] = self::issueRow('Thin Content (<300 words)', $base['issues']['low_content'], $base['samples']['low_content']);
            $rows[] = self::issueRow('Missing Titles', $base['issues']['missing_titles'], $base['samples']['missing_titles']);
            $rows[] = self::issueRow('Missing Meta Descriptions', $base['issues']['missing_meta'], $base['samples']['missing_meta']);
            $rows[] = self::issueRow('Images Missing ALT', $base['issues']['alt_missing'], $base['samples']['alt_missing']);
        } else {
            $rows[] = self::issueRow('Broken Links (4xx)', $base['status']['4xx'], $base['samples']['broken_links']);
            $rows[] = self::issueRow('Missing Canonical', $base['issues']['missing_canonical'], $base['samples']['missing_canonical']);
            $rows[] = self::issueRow('Redirect Chains', $base['issues']['redirect_chains'], $base['samples']['redirect_chains']);
            $rows[] = self::issueRow('Missing H1', $base['issues']['missing_h1'], $base['samples']['missing_h1']);
            $rows[] = self::issueRow('HTTPS / Mixed Content', $base['issues']['mixed_content'], $base['samples']['mixed_content']);
        }

        return [
            'headers' => ['Issue Type', 'Count', 'Example URL'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function onpageTable(array $base): array
    {
        $rows = [
            [
                'Element' => 'Title',
                'Issue' => self::formatIssueCountDetail('Missing or length issues', $base['issues']['missing_titles'] + $base['issues']['long_titles']),
                'Recommendation' => 'Keep unique titles under ~65 characters.',
            ],
            [
                'Element' => 'Meta Description',
                'Issue' => self::formatIssueCountDetail('Missing descriptions', $base['issues']['missing_meta']),
                'Recommendation' => 'Add intent-driven copy around 150–160 characters.',
            ],
            [
                'Element' => 'Headings',
                'Issue' => self::formatIssueCountDetail('Missing primary H1', $base['issues']['missing_h1']),
                'Recommendation' => 'Ensure a single descriptive H1 per page.',
            ],
            [
                'Element' => 'Word Count',
                'Issue' => self::formatIssueCountDetail('Pages under 300 words', $base['issues']['low_content']),
                'Recommendation' => 'Expand content with supporting detail and internal links.',
            ],
            [
                'Element' => 'Images',
                'Issue' => self::formatIssueCountDetail('Missing ALT attributes', $base['issues']['alt_missing']),
                'Recommendation' => 'Add descriptive ALT text to key imagery.',
            ],
        ];

        return [
            'headers' => ['Element', 'Common Issue', 'Recommendation'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function keywordTable(array $base): array
    {
        $rows = [
            [
                'Metric' => 'Top keywords by impressions',
                'Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['keyword', 'term'], 'Connect Google Search Console'),
            ],
            [
                'Metric' => 'Top keywords by clicks',
                'Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['keyword', 'term'], 'Connect Google Search Console', 1),
            ],
            [
                'Metric' => 'Keywords with low CTR',
                'Value' => self::firstValue($base['analytics']['gsc_low_ctr'], ['keyword', 'term'], 'Connect Google Search Console'),
            ],
            [
                'Metric' => 'High impressions, low position',
                'Value' => self::firstValue($base['analytics']['gsc_low_ctr'], ['keyword', 'term'], 'Add GSC data to highlight opportunities', 1),
            ],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function backlinkTable(array $base): array
    {
        $rows = [
            ['Metric' => 'Referring Domains', 'Value' => self::formatNumber($base['backlinks']['referring_domains']) ?? 'Connect a backlink provider'],
            ['Metric' => 'Total Backlinks', 'Value' => self::formatNumber($base['backlinks']['total_backlinks']) ?? '—'],
            ['Metric' => 'New Links', 'Value' => self::formatNumber($base['backlinks']['new_links']) ?? '—'],
            ['Metric' => 'Lost Links', 'Value' => self::formatNumber($base['backlinks']['lost_links']) ?? '—'],
            ['Metric' => 'Average Toxic Score', 'Value' => self::formatNumber($base['backlinks']['toxic_score'], true) ?? '—'],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'note' => $base['backlinks']['anchor_distribution'] ? null : 'Import backlink data (Ahrefs, SEMrush, etc.) for richer insights.',
        ];
    }

    /**
     * @param array<string,int|string|null> $samples
     */
    private static function setSample(array &$samples, string $key, ?string $value): void
    {
        if ($value && empty($samples[$key])) {
            $samples[$key] = $value;
        }
    }

    /**
     * @param array<int,mixed> $target
     * @param array<int,mixed> $source
     */
    private static function mergeLists(array &$target, array $source): void
    {
        foreach ($source as $item) {
            $target[] = $item;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $paths
     * @return array<int,mixed>
     */
    private static function extractList(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = self::getByPath($data, $path);
            if (is_array($value) && !empty($value)) {
                return array_values($value);
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function getByPath(array $data, string $path)
    {
        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /**
     * @return array<string,string>
     */
    private static function issueRow(string $label, int $count, ?string $sample): array
    {
        return [
            'Issue Type' => $label,
            'Count' => self::formatNumber($count),
            'Example URL' => $sample ? self::shortUrl($sample) : '—',
        ];
    }

    private static function formatIssueCountDetail(string $label, int $count): string
    {
        $number = self::formatNumber($count);
        return $number . ' ' . strtolower($label);
    }

    private static function formatNumber($value, bool $allowFloat = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return is_string($value) ? $value : null;
        }
        $number = $allowFloat ? (float) $value : (int) $value;
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($number, $allowFloat ? 2 : 0);
        }
        return $allowFloat ? number_format($number, 2) : number_format((float) $number, 0);
    }

    private static function shortUrl(string $url, int $max = 60): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '—';
        }
        if (strlen($trimmed) <= $max) {
            return $trimmed;
        }
        return substr($trimmed, 0, $max - 1) . '…';
    }

    /**
     * @param array<int,mixed> $list
     */
    private static function firstValue(array $list, array $keys, string $fallback, int $offset = 0): string
    {
        if (isset($list[$offset])) {
            $item = $list[$offset];
            if (is_string($item) && $item !== '') {
                return $item;
            }
            if (is_array($item)) {
                foreach ($keys as $key) {
                    if (!empty($item[$key])) {
                        return (string) $item[$key];
                    }
                }
                if (!empty($item['value'])) {
                    return (string) $item['value'];
                }
            }
        }
        if (!empty($list)) {
            $item = $list[0];
            if (is_string($item) && $item !== '') {
                return $item;
            }
            if (is_array($item)) {
                foreach ($keys as $key) {
                    if (!empty($item[$key])) {
                        return (string) $item[$key];
                    }
                }
            }
        }

        return $fallback;
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackLargestPage(array $base): string
    {
        $best = null;
        foreach ($base['pages'] as $page) {
            $wordCount = (int) ($page['word_count'] ?? 0);
            if ($wordCount <= 0) {
                continue;
            }
            if (!$best || $wordCount > $best['word_count']) {
                $best = [
                    'url' => $page['url'] ?? '',
                    'word_count' => $wordCount,
                ];
            }
        }
        if ($best) {
            return self::shortUrl((string) $best['url']) . ' (' . self::formatNumber($best['word_count']) . ' words)';
        }
        return 'Crawler data required';
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackSlowestPage(array $base): string
    {
        $best = null;
        foreach ($base['pages'] as $page) {
            $time = $page['load_time_ms'] ?? $page['load_time'] ?? $page['response_time'] ?? null;
            if (!is_numeric($time)) {
                continue;
            }
            $time = (float) $time;
            if (!$best || $time > $best['time']) {
                $best = [
                    'url' => $page['url'] ?? '',
                    'time' => $time,
                ];
            }
        }

        if ($best) {
            $timeLabel = $best['time'] >= 1000 ? round($best['time'] / 1000, 2) . 's' : round($best['time'], 0) . 'ms';
            return self::shortUrl((string) $best['url']) . ' (' . $timeLabel . ')';
        }

        return 'Crawler timing data needed';
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackRedirectHeavyPage(array $base): string
    {
        if (!empty($base['samples']['redirect_chains'])) {
            return self::shortUrl((string) $base['samples']['redirect_chains']);
        }
        return 'No redirect chains detected';
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackShortContentPage(array $base): string
    {
        if (!empty($base['samples']['low_content'])) {
            return self::shortUrl((string) $base['samples']['low_content']);
        }
        return 'All pages exceed 300 words';
    }
}
