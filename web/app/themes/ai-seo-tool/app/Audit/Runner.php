<?php
namespace BBSEO\Audit;

use BBSEO\Helpers\Storage;

class Runner
{
    private const STATUS_BUCKETS = ['2xx', '3xx', '4xx', '5xx', 'other'];
    private const WORDCOUNT_BINS = [
        '0-299' => [0, 299],
        '300-699' => [300, 699],
        '700+' => [700, PHP_INT_MAX],
    ];
    private const LOADTIME_BINS = [
        '<=1s' => [0, 1],
        '1-2s' => [1, 2],
        '2-3s' => [2, 3],
        '>3s' => [3, PHP_FLOAT_MAX],
    ];
    private const DEFAULT_ISSUE = [
        'id' => 'issue',
        'category' => 'Technical',
        'severity' => 'low',
        'impact' => 1,
        'effort' => 1,
        'description' => '',
        'how_to_fix' => '',
    ];
    private const ISSUE_TAXONOMY = [
        'Server error (5xx)' => [
            'id' => 'server_error_5xx',
            'category' => 'Technical',
            'severity' => 'high',
            'impact' => 5,
            'effort' => 3,
            'description' => 'Pages returning 5xx responses cannot be crawled or indexed.',
            'how_to_fix' => 'Resolve the server-side error and ensure a 200 status is returned.',
        ],
        'Client error (4xx)' => [
            'id' => 'client_error_4xx',
            'category' => 'Technical',
            'severity' => 'high',
            'impact' => 4,
            'effort' => 2,
            'description' => 'Broken URLs waste crawl budget and deliver a poor user experience.',
            'how_to_fix' => 'Reinstate the page or redirect/update links that point to it.',
        ],
        'Redirect (3xx)' => [
            'id' => 'redirect_3xx',
            'category' => 'Technical',
            'severity' => 'medium',
            'impact' => 2,
            'effort' => 2,
            'description' => 'Redirecting URLs dilute internal link equity when linked frequently.',
            'how_to_fix' => 'Update internal links to point directly at the final destination.',
        ],
        'Missing status code' => [
            'id' => 'missing_status',
            'category' => 'Technical',
            'severity' => 'medium',
            'impact' => 2,
            'effort' => 2,
            'description' => 'The crawler could not determine the HTTP response for this URL.',
            'how_to_fix' => 'Verify the URL is reachable and returns a valid HTTP status.',
        ],
        'Missing title tag' => [
            'id' => 'missing_title',
            'category' => 'On-Page',
            'severity' => 'high',
            'impact' => 4,
            'effort' => 1,
            'description' => 'Without a title tag, search engines cannot understand page context.',
            'how_to_fix' => 'Add a concise, unique <title> tag describing the page content.',
        ],
        'Title longer than 70 characters' => [
            'id' => 'long_title',
            'category' => 'On-Page',
            'severity' => 'medium',
            'impact' => 2,
            'effort' => 1,
            'description' => 'Titles longer than ~70 characters are truncated in search results.',
            'how_to_fix' => 'Rewrite the title so it remains within 50–65 characters.',
        ],
        'Title shorter than 30 characters' => [
            'id' => 'short_title',
            'category' => 'On-Page',
            'severity' => 'low',
            'impact' => 1,
            'effort' => 1,
            'description' => 'Short titles miss valuable keywords and context.',
            'how_to_fix' => 'Expand the title with descriptive keywords (roughly 40–60 chars).',
        ],
        'Missing meta description' => [
            'id' => 'missing_meta_description',
            'category' => 'On-Page',
            'severity' => 'medium',
            'impact' => 3,
            'effort' => 1,
            'description' => 'Meta descriptions influence click-through rates and social shares.',
            'how_to_fix' => 'Add a unique 110–150 character summary of the page content.',
        ],
        'Meta description longer than 160 characters' => [
            'id' => 'long_meta_description',
            'category' => 'On-Page',
            'severity' => 'low',
            'impact' => 1,
            'effort' => 1,
            'description' => 'Long descriptions are truncated, hiding important messaging.',
            'how_to_fix' => 'Trim the description to roughly 110–150 characters.',
        ],
        'Meta description shorter than 50 characters' => [
            'id' => 'short_meta_description',
            'category' => 'On-Page',
            'severity' => 'low',
            'impact' => 1,
            'effort' => 1,
            'description' => 'Very short descriptions lack meaningful context in SERPs.',
            'how_to_fix' => 'Expand the description with benefit-driven copy.',
        ],
        'Missing canonical URL' => [
            'id' => 'missing_canonical',
            'category' => 'Technical',
            'severity' => 'medium',
            'impact' => 3,
            'effort' => 1,
            'description' => 'Canonical tags help prevent duplicate content confusion.',
            'how_to_fix' => 'Add a rel=\"canonical\" tag that points to the preferred URL.',
        ],
        'Missing H1 heading' => [
            'id' => 'missing_h1',
            'category' => 'Content',
            'severity' => 'medium',
            'impact' => 3,
            'effort' => 1,
            'description' => 'Every page should introduce its topic with a clear H1 heading.',
            'how_to_fix' => 'Add a descriptive H1 that summarizes the primary intent.',
        ],
        'Multiple H1 headings' => [
            'id' => 'multiple_h1',
            'category' => 'Content',
            'severity' => 'medium',
            'impact' => 2,
            'effort' => 1,
            'description' => 'Multiple H1s dilute topical focus for search engines.',
            'how_to_fix' => 'Keep one H1 per page and demote other headings to H2/H3.',
        ],
        'Content size greater than 1MB' => [
            'id' => 'large_html',
            'category' => 'Performance',
            'severity' => 'medium',
            'impact' => 3,
            'effort' => 2,
            'description' => 'Large HTML payloads slow rendering and waste bandwidth.',
            'how_to_fix' => 'Minify markup, defer heavy modules, or split content across pages.',
        ],
        'Images without ALT text' => [
            'id' => 'img_alt_missing',
            'category' => 'Accessibility',
            'severity' => 'low',
            'impact' => 2,
            'effort' => 2,
            'description' => 'Missing ALT text hurts accessibility and image SEO.',
            'how_to_fix' => 'Add descriptive ALT attributes to informative images.',
        ],
        'Missing OG title or description' => [
            'id' => 'og_meta_missing',
            'category' => 'On-Page',
            'severity' => 'low',
            'impact' => 1,
            'effort' => 1,
            'description' => 'Incomplete Open Graph tags hurt social share previews.',
            'how_to_fix' => 'Populate og:title and og:description meta tags.',
        ],
        'Missing OG image' => [
            'id' => 'og_image_missing',
            'category' => 'On-Page',
            'severity' => 'low',
            'impact' => 1,
            'effort' => 1,
            'description' => 'Without og:image, shared links render with generic thumbnails.',
            'how_to_fix' => 'Add an og:image tag that references a 1200×630 image.',
        ],
        'No structured data' => [
            'id' => 'structured_data_missing',
            'category' => 'Content',
            'severity' => 'low',
            'impact' => 1,
            'effort' => 2,
            'description' => 'Structured data unlocks rich results in search.',
            'how_to_fix' => 'Add JSON-LD schema that matches the page type.',
        ],
    ];
    private const DEFAULT_CRAWLER = 'spatie/crawler';
    private const DEFAULT_USER_AGENT = 'AI-SEO-Tool/1.1';

    public static function run(string $project, string $runId): array
    {
        $dirs = Storage::ensureRun($project, $runId);
        $pages = self::loadPages($dirs['pages']);
        $imagesCrawled = self::countFiles($dirs['images']);
        $errors = self::countFiles($dirs['errors']);

        $statusBuckets = array_fill_keys(self::STATUS_BUCKETS, 0);
        $issueCounts = [];
        $issueCatalog = [];
        $issueByCategory = [];
        $issueBySeverity = [];
        $wordCountTotals = 0;
        $wordCountSamples = 0;
        $loadTimeTotals = 0;
        $loadTimeSamples = 0;
        $wordcountBins = array_fill_keys(array_keys(self::WORDCOUNT_BINS), 0);
        $loadtimeBins = array_fill_keys(array_keys(self::LOADTIME_BINS), 0);
        $internalLinksTotal = 0;
        $externalLinksTotal = 0;
        $imageSlots = 0;
        $imageSlotsMissingAlt = 0;
        $indexablePages = 0;
        $indexabilityBuckets = [
            'noindex' => 0,
            'canonicalized' => 0,
            'blocked_robots' => 0,
        ];

        $pageItems = [];

        foreach ($pages as $page) {
            $normalized = self::normalizePage($page);
            $item = $normalized['item'];
            $stats = $normalized['stats'];

            $pageItems[] = $item;

            $bucket = $stats['status_bucket'] ?? 'other';
            if (!isset($statusBuckets[$bucket])) {
                $bucket = 'other';
            }
            $statusBuckets[$bucket]++;

            if (isset($stats['word_count'])) {
                $wordCountTotals += (int) $stats['word_count'];
                $wordCountSamples++;
                foreach (self::WORDCOUNT_BINS as $label => [$min, $max]) {
                    if ($stats['word_count'] >= $min && $stats['word_count'] <= $max) {
                        $wordcountBins[$label]++;
                        break;
                    }
                }
            }

            if (isset($stats['load_time'])) {
                $loadTimeTotals += $stats['load_time'];
                $loadTimeSamples++;
                foreach (self::LOADTIME_BINS as $label => [$min, $max]) {
                    if ($stats['load_time'] > $min && $stats['load_time'] <= $max) {
                        $loadtimeBins[$label]++;
                        break;
                    }
                }
            }

            $internalLinksTotal += $stats['internal_links'] ?? 0;
            $externalLinksTotal += $stats['external_links'] ?? 0;
            $imageSlots += $stats['image_count'] ?? 0;
            $imageSlotsMissingAlt += $stats['images_missing_alt'] ?? 0;

            if (!empty($stats['is_indexable'])) {
                $indexablePages++;
            }
            if (!empty($stats['is_noindex'])) {
                $indexabilityBuckets['noindex']++;
            }
            if (!empty($stats['is_canonicalized'])) {
                $indexabilityBuckets['canonicalized']++;
            }
            if (!empty($stats['is_blocked'])) {
                $indexabilityBuckets['blocked_robots']++;
            }

            foreach ($item['issues'] as $issue) {
                $label = $issue['name'];
                $issueCounts[$label] = ($issueCounts[$label] ?? 0) + 1;
                $issueByCategory[$issue['category']] = ($issueByCategory[$issue['category']] ?? 0) + 1;
                $issueBySeverity[$issue['severity']] = ($issueBySeverity[$issue['severity']] ?? 0) + 1;
                self::addIssueOccurrence($issueCatalog, $issue, $item['url']);
            }
        }

        ksort($issueByCategory);
        ksort($issueBySeverity);

        $issuesList = array_values($issueCatalog);
        foreach ($issuesList as &$issue) {
            $issue['sample_urls'] = array_values(array_unique($issue['sample_urls']));
        }
        unset($issue);
        usort($issuesList, static fn($a, $b) => $b['occurrences'] <=> $a['occurrences']);

        $summaryFile = Storage::readJson($dirs['base'] . '/summary.json', []);
        $generatedAt = $summaryFile['generated'] ?? gmdate('c');
        $totalPages = count($pageItems);
        $totalImages = $imageSlots > 0 ? $imageSlots : $imagesCrawled;

        $scores = self::calculateScores($issuesList, $totalPages);
        $highlights = self::buildHighlights($issueCounts, $statusBuckets, $imageSlotsMissingAlt, $totalImages);

        $summary = [
            'totals' => [
                'pages' => $totalPages,
                'indexable_pages' => $indexablePages,
                'images' => $totalImages,
                'links_internal' => $internalLinksTotal,
                'links_external' => $externalLinksTotal,
                'errors' => $errors,
            ],
            'scores' => $scores,
            'highlights' => $highlights,
            'issue_counts' => $issueCounts,
            'status_buckets' => $statusBuckets,
        ];

        if ($wordCountSamples > 0) {
            $summary['avg_word_count'] = round($wordCountTotals / $wordCountSamples, 2);
        }
        if ($loadTimeSamples > 0) {
            $summary['avg_load_time'] = round($loadTimeTotals / $loadTimeSamples, 2);
        }

        $aggregations = [
            'status_distribution' => $statusBuckets,
            'wordcount_bins' => $wordcountBins,
            'issue_by_category' => $issueByCategory,
            'issue_by_severity' => $issueBySeverity,
            'indexability' => $indexabilityBuckets,
        ];

        if ($loadTimeSamples > 0) {
            $aggregations['loadtime_bins'] = $loadtimeBins;
        }
        if ($totalImages > 0) {
            $aggregations['image_alt_coverage'] = round(1 - self::ratio($imageSlotsMissingAlt, max(1, $totalImages)), 4);
        }

        $analyticsSummary = self::buildAnalyticsSummary($dirs['base']);
        $prevRunId = self::findPreviousRunId($project, $runId);
        $diff = self::computeDiff(
            $project,
            $runId,
            $issueCounts,
            array_map(static fn($item) => $item['url'] ?? '', $pageItems),
            $prevRunId
        );

        $runMeta = self::buildRunMetadata($dirs['base'], $runId, $prevRunId, $summaryFile);

        $out = [
            'run_id' => $runId,
            'run' => $runMeta,
            'project' => $project,
            'generated_at' => $generatedAt,
            'summary' => $summary,
            'aggregations' => $aggregations,
            'analytics_summary' => $analyticsSummary,
            'diff' => $diff,
            'issues' => $issuesList,
            'items' => $pageItems,
        ];

        Storage::writeJson($dirs['base'] . '/audit.json', $out);
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function loadPages(string $dir): array
    {
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        $pages = [];
        foreach ($files as $file) {
            $data = Storage::readJson($file, []);
            if (is_array($data) && !empty($data)) {
                $pages[] = $data;
            }
        }
        return $pages;
    }

    private static function countFiles(string $dir): int
    {
        $files = glob($dir . '/*.json');
        return $files ? count($files) : 0;
    }

    /**
     * @return array{item:array<string,mixed>,stats:array<string,mixed>}
     */
    private static function normalizePage(array $page): array
    {
        $url = (string) ($page['url'] ?? '');
        $status = isset($page['status']) ? (int) $page['status'] : 0;
        $bucket = self::bucketStatus($status);
        $canonical = (string) ($page['canonical'] ?? '');

        $wordCount = isset($page['word_count']) ? (int) $page['word_count'] : null;
        $contentLength = isset($page['content_length']) ? (int) $page['content_length'] : null;

        $imagesSummary = is_array($page['images']['summary'] ?? null) ? $page['images']['summary'] : null;
        $imageCount = $imagesSummary['total'] ?? null;
        $imagesMissingAlt = $imagesSummary['missing_alt'] ?? null;

        $linksData = is_array($page['links'] ?? null) ? $page['links'] : [];
        $internalOut = is_array($linksData['internal'] ?? null) ? count($linksData['internal']) : 0;
        $externalOut = is_array($linksData['external'] ?? null) ? count($linksData['external']) : 0;
        $nofollow = (int) ($linksData['hygiene']['nofollow'] ?? 0);
        $broken = (int) ($linksData['hygiene']['empty_or_hash'] ?? 0);
        $internalIn = is_array($page['internal_links'] ?? null) ? count($page['internal_links']) : null;

        $meta = self::filterNulls([
            'title' => $page['title'] ?? '',
            'title_length' => isset($page['title_length']) ? (int) $page['title_length'] : null,
            'meta_description' => $page['meta_description'] ?? '',
            'meta_description_length' => $page['meta_description_length'] ?? null,
            'h1' => $page['h1_text'] ?? null,
            'h1_count' => isset($page['h1_count']) ? (int) $page['h1_count'] : null,
            'canonical' => $canonical,
            'robots' => $page['meta_robots'] ?? null,
            'hreflang' => $page['hreflang'] ?? [],
        ]);

        $duplicateGroup = $page['summary_data']['seo_quality']['duplicate_group'] ?? null;
        $content = self::filterNulls([
            'word_count' => $wordCount,
            'thin_content' => $wordCount !== null ? $wordCount < 300 : null,
            'duplicate_group' => $duplicateGroup,
        ]);

        $pageWeight = $contentLength ? round($contentLength / 1024, 2) : null;
        $performance = self::filterNulls([
            'page_weight_kb' => $pageWeight,
        ]);

        $media = self::filterNulls([
            'image_count' => $imageCount,
            'images_missing_alt' => $imagesMissingAlt,
        ]);

        $links = self::filterNulls([
            'internal_inlinks' => $internalIn,
            'internal_outlinks' => $internalOut,
            'external_outlinks' => $externalOut,
            'nofollow_outlinks' => $nofollow,
            'broken_outlinks' => $broken,
        ]);

        $metaRobots = strtolower((string) ($page['meta_robots'] ?? ''));
        $isNoindex = strpos($metaRobots, 'noindex') !== false;
        $isIndexable = ($status >= 200 && $status < 400) && !$isNoindex;
        $normalizedUrl = self::normalizeUrl($url);
        $normalizedCanonical = self::normalizeUrl($canonical);
        $isCanonicalized = $normalizedCanonical !== '' && $normalizedCanonical !== $normalizedUrl;

        $pagination = is_array($page['pagination'] ?? null) ? $page['pagination'] : [];
        $paginationData = self::filterNulls([
            'rel_prev' => $pagination['prev'] ?? $pagination['rel_prev'] ?? null,
            'rel_next' => $pagination['next'] ?? $pagination['rel_next'] ?? null,
        ]);

        $isHttps = stripos($url, 'https://') === 0;
        $mixedContent = $isHttps && self::containsMixedContent($linksData['external'] ?? []);

        $structured = is_array($page['structured_data'] ?? null) ? $page['structured_data'] : [];
        $issues = self::evaluateIssues($page);

        $ai = null;
        if (!empty($page['summary_text'])) {
            $ai = ['summary' => $page['summary_text']];
        }

        $item = self::filterNulls([
            'url' => $url,
            'status' => $status ?: null,
            'hash' => self::hashForUrl($url),
            'meta' => $meta,
            'content' => $content,
            'performance' => $performance,
            'media' => $media,
            'links' => $links,
            'indexability' => self::filterNulls([
                'is_indexable' => $isIndexable,
                'is_canonicalized' => $isCanonicalized,
                'is_noindex' => $isNoindex,
                'is_blocked_by_robots' => false,
            ]),
            'pagination' => $paginationData,
            'security' => [
                'is_https' => $isHttps,
                'mixed_content' => $mixedContent,
            ],
            'structured_data' => $structured,
            'issues' => $issues,
            'ai' => $ai,
        ]);

        return [
            'item' => $item,
            'stats' => [
                'status_bucket' => $bucket,
                'word_count' => $wordCount,
                'load_time' => null,
                'internal_links' => $internalOut,
                'external_links' => $externalOut,
                'image_count' => $imageCount,
                'images_missing_alt' => $imagesMissingAlt,
                'is_indexable' => $isIndexable,
                'is_noindex' => $isNoindex,
                'is_canonicalized' => $isCanonicalized,
                'is_blocked' => false,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function evaluateIssues(array $page): array
    {
        $issues = [];
        $status = (int) ($page['status'] ?? 0);

        if ($status >= 500) {
            $issues[] = self::makeIssue('Server error (5xx)', 'HTTP ' . $status);
        } elseif ($status >= 400) {
            $issues[] = self::makeIssue('Client error (4xx)', 'HTTP ' . $status);
        } elseif ($status >= 300) {
            $issues[] = self::makeIssue('Redirect (3xx)', 'HTTP ' . $status);
        } elseif ($status === 0) {
            $issues[] = self::makeIssue('Missing status code', 'Crawler did not record a response.');
        }

        $title = trim((string) ($page['title'] ?? ''));
        $titleLen = mb_strlen($title);
        if ($title === '') {
            $issues[] = self::makeIssue('Missing title tag');
        } elseif ($titleLen > 70) {
            $issues[] = self::makeIssue('Title longer than 70 characters', sprintf('Length: %d chars', $titleLen));
        } elseif ($titleLen < 30) {
            $issues[] = self::makeIssue('Title shorter than 30 characters', sprintf('Length: %d chars', $titleLen));
        }

        $meta = trim((string) ($page['meta_description'] ?? ''));
        $metaLen = mb_strlen($meta);
        if ($meta === '') {
            $issues[] = self::makeIssue('Missing meta description');
        } elseif ($metaLen > 160) {
            $issues[] = self::makeIssue('Meta description longer than 160 characters', sprintf('Length: %d chars', $metaLen));
        } elseif ($metaLen < 50) {
            $issues[] = self::makeIssue('Meta description shorter than 50 characters', sprintf('Length: %d chars', $metaLen));
        }

        if (empty($page['canonical'])) {
            $issues[] = self::makeIssue('Missing canonical URL');
        }

        $headings = is_array($page['headings'] ?? null) ? $page['headings'] : [];
        $h1s = is_array($headings['h1'] ?? null) ? $headings['h1'] : [];
        if (count($h1s) === 0 && empty($page['h1_text'])) {
            $issues[] = self::makeIssue('Missing H1 heading');
        } elseif (count($h1s) > 1 || (isset($page['h1_count']) && (int) $page['h1_count'] > 1)) {
            $count = isset($page['h1_count']) ? (int) $page['h1_count'] : count($h1s);
            $issues[] = self::makeIssue('Multiple H1 headings', sprintf('Found %d H1 elements', $count));
        }

        $contentLength = (int) ($page['content_length'] ?? 0);
        if ($contentLength > 1024 * 1024) {
            $issues[] = self::makeIssue(
                'Content size greater than 1MB',
                sprintf('HTML transfer size %.2f KB', $contentLength / 1024)
            );
        }

        $imagesSummary = is_array($page['images']['summary'] ?? null) ? $page['images']['summary'] : null;
        $missingAlt = $imagesSummary['missing_alt'] ?? 0;
        $imageTotal = $imagesSummary['total'] ?? 0;
        if ($missingAlt > 0) {
            $issues[] = self::makeIssue(
                'Images without ALT text',
                sprintf('%d of %d images missing alt', $missingAlt, $imageTotal)
            );
        }

        $og = is_array($page['open_graph'] ?? null) ? $page['open_graph'] : [];
        if (!self::hasKeys($og, ['og:title', 'og:description'])) {
            $issues[] = self::makeIssue('Missing OG title or description');
        }
        if (!self::hasKeys($og, ['og:image'])) {
            $issues[] = self::makeIssue('Missing OG image');
        }

        $structured = is_array($page['structured_data'] ?? null) ? $page['structured_data'] : [];
        if (count($structured) === 0) {
            $issues[] = self::makeIssue('No structured data');
        }

        return $issues;
    }

    private static function makeIssue(string $label, ?string $evidence = null, ?string $selector = null): array
    {
        $definition = self::issueDefinition($label);

        return self::filterNulls([
            'id' => $definition['id'],
            'name' => $label,
            'category' => $definition['category'],
            'severity' => $definition['severity'],
            'evidence' => $evidence,
            'selector' => $selector,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function issueDefinition(string $label): array
    {
        $definition = self::ISSUE_TAXONOMY[$label] ?? self::DEFAULT_ISSUE;
        if (empty($definition['id'])) {
            $definition['id'] = self::slugify($label);
        }
        return $definition;
    }

    private static function slugify(string $label): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
        $slug = trim($slug, '_');
        return $slug !== '' ? $slug : 'issue';
    }

    /**
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $issue
     */
    private static function addIssueOccurrence(array &$catalog, array $issue, string $url): void
    {
        $definition = self::issueDefinition($issue['name']);
        $id = $issue['id'] ?? $definition['id'];

        if (!isset($catalog[$id])) {
            $catalog[$id] = [
                'id' => $id,
                'name' => $issue['name'],
                'category' => $definition['category'],
                'severity' => $definition['severity'],
                'impact' => $definition['impact'],
                'effort' => $definition['effort'],
                'description' => $definition['description'],
                'how_to_fix' => $definition['how_to_fix'],
                'occurrences' => 0,
                'sample_urls' => [],
            ];
        }

        $catalog[$id]['occurrences']++;
        if ($url !== '' && !in_array($url, $catalog[$id]['sample_urls'], true) && count($catalog[$id]['sample_urls']) < 5) {
            $catalog[$id]['sample_urls'][] = $url;
        }
    }

    private static function buildHighlights(array $issueCounts, array $statusBuckets, int $imagesMissingAlt, int $totalImages): array
    {
        $highlights = [];
        if (!empty($issueCounts)) {
            $sorted = $issueCounts;
            arsort($sorted);
            foreach (array_slice($sorted, 0, 3, true) as $label => $count) {
                $highlights[] = sprintf('%s on %d pages', $label, $count);
            }
        }

        if (!empty($statusBuckets['4xx'])) {
            $highlights[] = sprintf('%d client errors detected', $statusBuckets['4xx']);
        }
        if (!empty($statusBuckets['5xx'])) {
            $highlights[] = sprintf('%d server errors detected', $statusBuckets['5xx']);
        }
        if ($totalImages > 0 && $imagesMissingAlt > 0) {
            $coverage = 100 - (int) round(($imagesMissingAlt / max(1, $totalImages)) * 100);
            $highlights[] = sprintf('Image ALT coverage %d%%', $coverage);
        }

        $highlights = array_values(array_unique(array_filter($highlights)));
        return array_slice($highlights, 0, 5);
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     * @return array<string,int>
     */
    private static function calculateScores(array $issues, int $totalPages): array
    {
        $scores = [
            'overall' => 100,
            'technical' => 100,
            'content' => 100,
            'performance' => 100,
        ];

        $categoryMap = [
            'Technical' => 'technical',
            'Performance' => 'performance',
            'On-Page' => 'content',
            'Content' => 'content',
            'Accessibility' => 'content',
        ];

        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? 'low';
            $weight = [
                'low' => 2,
                'medium' => 5,
                'high' => 8,
            ][$severity] ?? 2;

            $ratio = $totalPages > 0 ? min(1, $issue['occurrences'] / $totalPages) : 0;
            $penalty = (int) ceil($weight * 5 * $ratio);
            $scores['overall'] -= $penalty;

            $bucket = $categoryMap[$issue['category']] ?? 'technical';
            $scores[$bucket] -= $penalty;
        }

        foreach ($scores as &$value) {
            $value = max(0, min(100, $value));
        }
        unset($value);

        return $scores;
    }

    private static function buildAnalyticsSummary(string $runDir): array
    {
        $analyticsDir = $runDir . '/analytics';
        $ga = Storage::readJson($analyticsDir . '/ga.json', []);
        $gsc = Storage::readJson($analyticsDir . '/gsc.json', []);
        $gscDetails = Storage::readJson($analyticsDir . '/gsc-details.json', []);

        $summary = [];
        if (!empty($ga)) {
            $summary['ga'] = self::filterNulls([
                'sessions_30d' => isset($ga['totals']['sessions']) ? (int) $ga['totals']['sessions'] : null,
                'users_30d' => isset($ga['totals']['totalUsers']) ? (int) $ga['totals']['totalUsers'] : null,
                'bounce_rate' => self::calculateBounceRate($ga['timeseries'] ?? []),
                'top_pages' => self::extractTopPages($ga, $gscDetails),
            ]);
        }

        if (!empty($gsc)) {
            $summary['gsc'] = self::filterNulls([
                'clicks_30d' => isset($gsc['totals']['clicks']) ? (int) $gsc['totals']['clicks'] : null,
                'impressions_30d' => isset($gsc['totals']['impressions']) ? (int) $gsc['totals']['impressions'] : null,
                'ctr_30d' => isset($gsc['totals']['ctr']) ? round((float) $gsc['totals']['ctr'], 4) : null,
                'avg_position' => isset($gsc['totals']['position']) ? round((float) $gsc['totals']['position'], 2) : null,
                'top_queries' => self::extractTopQueries($gscDetails),
            ]);
        }

        return $summary;
    }

    private static function calculateBounceRate(array $timeseries): ?float
    {
        $sessionsTotal = 0;
        $weightedBounce = 0.0;
        foreach ($timeseries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sessions = (int) ($row['sessions'] ?? 0);
            $bounce = isset($row['bounceRate']) ? (float) $row['bounceRate'] : null;
            if ($sessions <= 0 || $bounce === null) {
                continue;
            }
            $sessionsTotal += $sessions;
            $weightedBounce += $bounce * $sessions;
        }

        if ($sessionsTotal === 0) {
            return null;
        }

        return round($weightedBounce / $sessionsTotal, 4);
    }

    private static function extractTopPages(array $ga, array $gscDetails): array
    {
        $pages = [];
        if (!empty($ga['top_pages']) && is_array($ga['top_pages'])) {
            foreach ($ga['top_pages'] as $entry) {
                if (is_string($entry)) {
                    $pages[] = $entry;
                } elseif (is_array($entry)) {
                    if (!empty($entry['path'])) {
                        $pages[] = $entry['path'];
                    } elseif (!empty($entry['url'])) {
                        $pages[] = self::pathFromUrl($entry['url']);
                    }
                }
            }
        }

        if (empty($pages) && isset($gscDetails['details']['pages']) && is_array($gscDetails['details']['pages'])) {
            foreach (array_slice($gscDetails['details']['pages'], 0, 5) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pages[] = self::pathFromUrl($row['key'] ?? '');
            }
        }

        $pages = array_values(array_filter($pages));
        return array_slice($pages, 0, 5);
    }

    private static function extractTopQueries(array $gscDetails): array
    {
        $queries = [];
        if (isset($gscDetails['details']['queries']) && is_array($gscDetails['details']['queries'])) {
            foreach (array_slice($gscDetails['details']['queries'], 0, 5) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $query = (string) ($row['key'] ?? '');
                if ($query !== '') {
                    $queries[] = $query;
                }
            }
        }

        return $queries;
    }

    private static function pathFromUrl(?string $url): string
    {
        if (!$url) {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        return $path !== '' ? $path : '/';
    }

    private static function computeDiff(string $project, string $currentRunId, array $issueCounts, array $urls, ?string $prevRunId): array
    {
        if (!$prevRunId) {
            return [
                'new_pages' => 0,
                'removed_pages' => 0,
                'issues_resolved' => 0,
                'issues_regressed' => 0,
            ];
        }

        $prevDir = Storage::runDir($project, $prevRunId);
        $prevAudit = Storage::readJson($prevDir . '/audit.json', []);

        $prevUrls = [];
        if (!empty($prevAudit['items'])) {
            foreach ($prevAudit['items'] as $item) {
                if (is_array($item) && !empty($item['url'])) {
                    $prevUrls[] = (string) $item['url'];
                }
            }
        }

        if (empty($prevUrls)) {
            $pageFiles = glob($prevDir . '/pages/*.json') ?: [];
            foreach ($pageFiles as $file) {
                $data = Storage::readJson($file, []);
                if (!empty($data['url'])) {
                    $prevUrls[] = (string) $data['url'];
                }
            }
        }

        $currentSet = array_unique(array_filter($urls));
        $prevSet = array_unique(array_filter($prevUrls));

        $newPages = count(array_diff($currentSet, $prevSet));
        $removedPages = count(array_diff($prevSet, $currentSet));

        $prevIssueCounts = $prevAudit['summary']['issue_counts'] ?? [];
        $issuesResolved = 0;
        $issuesRegressed = 0;
        $keys = array_unique(array_merge(array_keys($prevIssueCounts), array_keys($issueCounts)));
        foreach ($keys as $key) {
            $previous = (int) ($prevIssueCounts[$key] ?? 0);
            $current = (int) ($issueCounts[$key] ?? 0);
            $delta = $previous - $current;
            if ($delta > 0) {
                $issuesResolved += $delta;
            } elseif ($delta < 0) {
                $issuesRegressed += abs($delta);
            }
        }

        return [
            'new_pages' => $newPages,
            'removed_pages' => $removedPages,
            'issues_resolved' => $issuesResolved,
            'issues_regressed' => $issuesRegressed,
        ];
    }

    private static function findPreviousRunId(string $project, string $currentRunId): ?string
    {
        $runsDir = Storage::runsDir($project);
        $dirs = glob($runsDir . '/*', GLOB_ONLYDIR) ?: [];
        $runs = [];
        foreach ($dirs as $dir) {
            $runs[] = basename($dir);
        }
        sort($runs);

        $previous = null;
        foreach ($runs as $run) {
            if ($run === $currentRunId) {
                return $previous;
            }
            $previous = $run;
        }

        return $previous;
    }

    private static function buildRunMetadata(string $runDir, string $runId, ?string $prevRunId, array $summaryFile): array
    {
        $meta = Storage::readJson($runDir . '/meta.json', []);
        $started = $meta['started_at'] ?? null;
        $finished = $meta['completed_at'] ?? ($summaryFile['generated'] ?? null);

        return self::filterNulls([
            'run_id' => $runId,
            'prev_run_id' => $prevRunId,
            'started_at' => $started,
            'finished_at' => $finished,
            'duration_sec' => self::calculateDuration($started, $finished),
            'crawler' => $meta['crawler'] ?? self::DEFAULT_CRAWLER,
            'user_agent' => $meta['user_agent'] ?? self::DEFAULT_USER_AGENT,
        ]);
    }

    private static function calculateDuration(?string $start, ?string $finish): ?int
    {
        if (!$start || !$finish) {
            return null;
        }

        try {
            $startTs = (new \DateTimeImmutable($start))->getTimestamp();
            $finishTs = (new \DateTimeImmutable($finish))->getTimestamp();
            return max(0, $finishTs - $startTs);
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function hashForUrl(?string $url): string
    {
        $value = $url ?: uniqid('', true);
        return substr(md5($value), 0, 8);
    }

    private static function normalizeUrl(?string $url): string
    {
        if (!$url) {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return trim($url);
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $host !== '' ? sprintf('%s://%s%s%s', $scheme, $host, $path, $query) : trim($url);
    }

    private static function containsMixedContent($externalLinks): bool
    {
        if (!is_array($externalLinks)) {
            return false;
        }
        foreach ($externalLinks as $link) {
            if (is_array($link)) {
                $target = $link['url'] ?? '';
            } else {
                $target = $link;
            }
            if (is_string($target) && stripos($target, 'http://') === 0) {
                return true;
            }
        }
        return false;
    }

    private static function bucketStatus(int $status): string
    {
        if ($status >= 500) {
            return '5xx';
        }
        if ($status >= 400) {
            return '4xx';
        }
        if ($status >= 300) {
            return '3xx';
        }
        if ($status >= 200 && $status < 300) {
            return '2xx';
        }
        return 'other';
    }

    private static function ratio(int $part, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }
        return $part / $total;
    }

    private static function filterNulls(array $payload): array
    {
        return array_filter($payload, static fn($value) => $value !== null);
    }

    private static function hasKeys(array $haystack, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($haystack[$key]) || trim((string) $haystack[$key]) === '') {
                return false;
            }
        }
        return true;
    }
}
