<?php
namespace BBSEO\Crawl;

use BBSEO\Helpers\Storage;
use BBSEO\PostTypes\Project;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\Crawler;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Worker
{
    public static function process(string $project, string $runId): array
    {
        $qfile = Queue::next($project, $runId);
        if (!$qfile) {
            return ['message' => 'queue-empty'];
        }

        $url = trim(file_get_contents($qfile));
        $observer = new Observer($project, $runId, $url);

        $crawler = Crawler::create(self::clientOptions())
            ->setCrawlObserver($observer)
            ->setCrawlProfile(new Profile($project, $url))
            ->setConcurrency(1)
            ->setMaximumDepth(0)
            ->setTotalCrawlLimit(1)
            ->setCurrentCrawlLimit(1)
            ->setParseableMimeTypes([
                'text/html', 
                'application/xhtml+xml',
                'application/pdf',
                'image/jpeg',
                'image/jpg', 
                'image/png',
                'image/gif',
                'image/webp',
                'image/svg+xml'
            ])
            ->setUserAgent('bbseo-Bot/0.1');

        try {
            $crawler->startCrawling($url);
        } catch (\Throwable $exception) {
            $observer->recordException($url, $exception);
        } finally {
            $done = substr($qfile, 0, -5) . '.done';
            @rename($qfile, $done);
        }

        $result = $observer->getLastResult();
        return $result ?: ['message' => 'queued', 'url' => $url];
    }

    public static function handleResponse(
        string $project,
        string $runId,
        UriInterface $uri,
        ResponseInterface $response,
        ?UriInterface $foundOn = null,
        ?string $linkText = null
    ): array {
        $dirs = Storage::ensureRun($project, $runId);
        $url = (string) $uri;
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
        $contentType = $headers['content-type'][0] ?? '';
        $contentLength = self::resolveContentLength($headers, $body);

        // Enhanced error detection
        $errors = self::detectUrlErrors($status, $headers, $contentType, $body);

        $page = [
            'run_id' => $runId,
            'project' => $project,
            'url' => $url,
            'status' => $status,
            'found_on' => $foundOn ? (string) $foundOn : null,
            'link_text' => $linkText,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'headers' => self::simplifyHeaders($headers),
            'fetched_at' => gmdate('c'),
            'asset_type' => self::detectAssetType($contentType, $url),
            'errors' => $errors
        ];

        $discovered = [];

        if (self::isHtml($contentType)) {
            $parsed = self::parseHtml($project, $url, $body);
            $page = array_merge($page, $parsed['meta'], [
                'links' => $parsed['links'],
                'images' => $parsed['images'],
                'headings' => $parsed['headings'],
                'structured_data' => $parsed['structured_data'],
                'open_graph' => $parsed['open_graph'],
                'word_count' => $parsed['word_count'],
                'summary_text' => $parsed['summary_text'],
                'summary_data' => $parsed['summary_data'],
                'internal_links' => $parsed['internal_urls'],
                'body_excerpt' => $parsed['body_excerpt'],
                'first_paragraph' => $parsed['first_paragraph'],
            ]);
            $discovered = $parsed['internal_urls'];

            $pfile = $dirs['pages'] . '/' . md5($url) . '.json';
            Storage::writeJson($pfile, $page);
        } elseif (self::isPdf($contentType)) {
            $page['pdf_info'] = self::analyzePdf($body, $url);
            $pfile = $dirs['pages'] . '/' . md5($url) . '.json';
            Storage::writeJson($pfile, $page);
        } elseif (self::isImage($contentType)) {
            $page['image_info'] = self::analyzeImage($body, $url, $contentType);
            $ifile = $dirs['images'] . '/' . md5($url) . '.json';
            Storage::writeJson($ifile, $page);
        } else {
            // Other asset types can be handled here if needed
            $pfile = $dirs['pages'] . '/' . md5($url) . '.json';
            Storage::writeJson($pfile, $page);
        }

        if (!empty($discovered)) {
            Queue::enqueue($project, $discovered, $runId);
        }

        self::touchMeta($dirs['base'], ['last_processed_at' => gmdate('c')]);

        return $page;
    }

    public static function handleFailure(
        string $project,
        string $runId,
        UriInterface $uri,
        ?ResponseInterface $response = null,
        ?\Throwable $exception = null,
        ?UriInterface $foundOn = null,
        ?string $linkText = null
    ): array {
        $dirs = Storage::ensureRun($project, $runId);
        $status = $response ? $response->getStatusCode() : 0;
        $body = $response ? (string) $response->getBody() : '';
        $headers = $response ? array_change_key_case($response->getHeaders(), CASE_LOWER) : [];

        $page = [
            'run_id' => $runId,
            'project' => $project,
            'url' => (string) $uri,
            'status' => $status,
            'found_on' => $foundOn ? (string) $foundOn : null,
            'link_text' => $linkText,
            'content_type' => $headers['content-type'][0] ?? '',
            'content_length' => self::resolveContentLength($headers, $body),
            'headers' => self::simplifyHeaders($headers),
            'fetched_at' => gmdate('c'),
            'error' => $exception ? $exception->getMessage() : 'request failed',
        ];

        $efile = $dirs['errors'] . '/' . md5($page['url']) . '.json';
        Storage::writeJson($efile, $page);

        self::touchMeta($dirs['base'], ['last_processed_at' => gmdate('c')]);

        return $page;
    }

    private static function clientOptions(): array
    {
        $verify = self::resolveVerifyOption();

        return [
            'verify' => $verify,
            'timeout' => 20,
            'allow_redirects' => false,
            'headers' => [
                'User-Agent' => 'bbseo-Bot/0.1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
    }

    private static function resolveVerifyOption()
    {
        $env = getenv('BBSEO_HTTP_VERIFY_TLS');
        if (is_string($env) && $env !== '') {
            $value = strtolower(trim($env));
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (file_exists($env)) {
                return $env;
            }
        }

        if (defined('ABSPATH')) {
            $bundle = rtrim(ABSPATH, '/') . '/wp-includes/certificates/ca-bundle.crt';
            if (file_exists($bundle)) {
                return $bundle;
            }
        }

        return true;
    }

    private static function resolveContentLength(array $headers, string $body): int
    {
        if (isset($headers['content-length'][0])) {
            return (int) $headers['content-length'][0];
        }

        return strlen($body);
    }

    private static function simplifyHeaders(array $headers): array
    {
        $keep = ['content-type', 'content-length', 'cache-control', 'expires', 'last-modified', 'etag'];
        $out = [];
        foreach ($keep as $header) {
            if (isset($headers[$header])) {
                $out[$header] = implode(', ', $headers[$header]);
            }
        }
        return $out;
    }

    private static function isHtml(string $contentType): bool
    {
        return stripos($contentType, 'text/html') !== false
            || stripos($contentType, 'application/xhtml') !== false;
    }

    /**
     * parseHtml
     * Extracts detailed on-page SEO, content, and UX metrics from an HTML page.
     * Generates a concise summary text (AI-ready) to feed into automated SEO reports or analysis prompts.
     */
    private static function parseHtml(string $project, string $url, string $html): array
    {
        $crawler = new DomCrawler($html, $url);

        // --- Core meta ----------------------------------------------------------
        $title = $crawler->filter('title')->count() ? trim($crawler->filter('title')->text()) : '';
        $metaDescription = $crawler->filter('meta[name="description"]')->count()
            ? trim((string) $crawler->filter('meta[name="description"]')->attr('content'))
            : '';
        $metaKeywords = $crawler->filter('meta[name="keywords"]')->count()
            ? trim((string) $crawler->filter('meta[name="keywords"]')->attr('content'))
            : '';

        $metaRobots = $crawler->filter('meta[name="robots"]')->count()
            ? trim((string) $crawler->filter('meta[name="robots"]')->attr('content'))
            : '';

        $canonical = $crawler->filter('link[rel="canonical"]')->count()
            ? trim((string) $crawler->filter('link[rel="canonical"]')->attr('href'))
            : '';

        if ($canonical !== '') {
            $canonical = self::absolutizeUrl($canonical, $url) ?: $canonical;
        }

        $lang = $crawler->filter('html')->count() ? trim((string) $crawler->filter('html')->attr('lang') ?? '') : '';
        $charset = $crawler->filter('meta[charset]')->count()
            ? trim((string) $crawler->filter('meta[charset]')->attr('charset'))
            : '';
        $viewport = $crawler->filter('meta[name="viewport"]')->count()
            ? trim((string) $crawler->filter('meta[name="viewport"]')->attr('content'))
            : '';

        // --- Hreflang / pagination / AMP ---------------------------------------
        $hreflang = $crawler->filter('link[rel="alternate"][hreflang][href]')->each(
            fn (DomCrawler $n) => [
                'hreflang' => trim((string) $n->attr('hreflang')),
                'href'     => self::absolutizeUrl((string) $n->attr('href'), $url),
            ]
        );
        $pagination = [
            'prev' => $crawler->filter('link[rel="prev"]')->count()
                ? self::absolutizeUrl((string) $crawler->filter('link[rel="prev"]')->attr('href'), $url)
                : null,
            'next' => $crawler->filter('link[rel="next"]')->count()
                ? self::absolutizeUrl((string) $crawler->filter('link[rel="next"]')->attr('href'), $url)
                : null,
        ];
        $amphtml = $crawler->filter('link[rel="amphtml"]')->count()
            ? self::absolutizeUrl((string) $crawler->filter('link[rel="amphtml"]')->attr('href'), $url)
            : null;

        // --- Headings -----------------------------------------------------------
        $headings = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $headings[$tag] = $crawler->filter($tag)->each(fn (DomCrawler $node) => trim($node->text()));
        }
        $h1Count = count($headings['h1'] ?? []);
        $h1Text  = $h1Count > 0 ? ($headings['h1'][0] ?? '') : '';

        // --- Links --------------------------------------------------------------
        $internalLinks = [];
        $externalLinks = [];
        $linkHygiene = [
            'nofollow'        => 0,
            'ugc'             => 0,
            'sponsored'       => 0,
            'mailto'          => 0,
            'tel'             => 0,
            'empty_or_hash'   => 0,
            'javascript_href' => 0,
        ];

        $baseUrl = Project::getBaseUrl($project) ?: $url;
        $targetHost = self::normalizeHost(parse_url($baseUrl, PHP_URL_HOST) ?: '');

        $crawler->filter('a[href]')->each(function (DomCrawler $node) use (
            &$internalLinks, &$externalLinks, &$linkHygiene, $url, $targetHost
        ) {
            $href = $node->attr('href') ?? '';
            $rel  = strtolower((string) ($node->attr('rel') ?? ''));
            $text = trim($node->text() ?? '');

            if ($href === '' || $href === '#') { $linkHygiene['empty_or_hash']++; return; }
            if (preg_match('~^javascript:~i', $href)) { $linkHygiene['javascript_href']++; return; }
            if (preg_match('~^mailto:~i', $href)) { $linkHygiene['mailto']++; }
            if (preg_match('~^tel:~i', $href))    { $linkHygiene['tel']++; }

            $hrefAbs = self::absolutizeUrl($href, $url);
            if (!$hrefAbs) { return; }

            $parts  = parse_url($hrefAbs);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) { return; }

            if (str_contains($rel, 'nofollow'))  $linkHygiene['nofollow']++;
            if (str_contains($rel, 'ugc'))       $linkHygiene['ugc']++;
            if (str_contains($rel, 'sponsored')) $linkHygiene['sponsored']++;

            $entry = [
                'url'    => self::buildUrlWithoutFragment($parts),
                'anchor' => $text,
                'rel'    => $rel,
            ];

            $host = self::normalizeHost($parts['host'] ?? '');
            if ($host && $host === $targetHost) {
                $internalLinks[] = $entry;
            } else {
                $externalLinks[] = $entry;
            }
        });

        $uniqueInternal = array_values(array_unique(array_map(fn ($link) => $link['url'], $internalLinks)));

        // --- Resources / performance hints --------------------------------------
        $cssLinksCount  = $crawler->filter('link[rel="stylesheet"][href]')->count();
        $scriptSrcCount = $crawler->filter('script[src]')->count();
        $deferScripts   = $crawler->filter('script[src][defer]')->count();
        $asyncScripts   = $crawler->filter('script[src][async]')->count();
        $preloads = $crawler->filter('link[rel="preload"][href]')->each(
            fn (DomCrawler $n) => [
                'as'   => trim((string) ($n->attr('as') ?? '')),
                'href' => self::absolutizeUrl((string) $n->attr('href'), $url),
            ]
        );

        // --- Text ratio ---------------------------------------------------------
        $bodyText = $crawler->filter('body')->count()
            ? preg_replace('~\s+~u', ' ', trim($crawler->filter('body')->text()))
            : '';
        $visibleLen = mb_strlen($bodyText, 'UTF-8');
        $htmlLen    = strlen($html);
        $textHtmlRatio = $htmlLen > 0 ? round($visibleLen / $htmlLen, 4) : null;

        // --- Social / OG completeness -------------------------------------------
        $openGraph = self::extractOpenGraph($crawler);
        $twitterCard = $crawler->filter('meta[name="twitter:card"]')->count() > 0;
        $socialCardsCompleteness = [
            'og:title'       => isset($openGraph['og:title']),
            'og:description' => isset($openGraph['og:description']),
            'og:image'       => isset($openGraph['og:image']),
            'twitter:card'   => $twitterCard,
        ];

        // --- Images -------------------------------------------------------------
        $imagelinks = [];
        $images = $crawler->filter('img')->each(function (DomCrawler $node) use ($url) {
            $src = $node->attr('src') ?? '';

            // handle data-src / data-srcset for lazy-loaded images when src is empty or using base64
            if (($src === '' || str_starts_with($src, 'data:')) && $node->attr('data-src')) {
                $src = $node->attr('data-src');
            } elseif (($src === '' || str_starts_with($src, 'data:')) && $node->attr('data-srcset')) {
                // take the first URL from data-srcset
                $srcset = $node->attr('data-srcset');
                $parts = explode(',', $srcset);
                if (count($parts) > 0) {
                    $first = trim($parts[0]);
                    $srcParts = preg_split('/\s+/', $first);
                    if (count($srcParts) > 0) {
                        $src = $srcParts[0];
                    }
                }
            }


            $alt = trim($node->attr('alt') ?? '');
            $src = self::absolutizeUrl($src, $url);
            $loading = strtolower((string) ($node->attr('loading') ?? ''));
            $width   = $node->attr('width') ?? null;
            $height  = $node->attr('height') ?? null;

            return [
                'src'     => $src,
                'alt'     => $alt,
                'loading' => $loading,
                'width'   => $width,
                'height'  => $height,
            ];
        });

        $imagelinks = array_map(
            fn ($img) => $img['src'],
            array_filter($images, fn ($img) => !empty($img['src']))
        );
        
        $imagesSummary = [
            'total'          => count($images),
            'missing_alt'    => count(array_filter($images, fn ($i) => ($i['alt'] ?? '') === '')),
            'lazy'           => count(array_filter($images, fn ($i) => ($i['loading'] ?? '') === 'lazy')),
            'no_dimensions'  => count(array_filter($images, fn ($i) => empty($i['width']) || empty($i['height']))),
        ];

        $uniqueInternal = array_values(array_unique(array_merge($uniqueInternal, $imagelinks)));

        // --- Build summary text (for AI) ---------------------------------------
        $summary = sprintf(
            "Page title: \"%s\" (%d chars). Meta description: %d chars. H1 count: %d. ".
            "Contains %d images (%d missing alt). Links: %d internal, %d external, %d nofollow. ".
            "Text-to-HTML ratio: %s. Canonical: %s. Robots: %s. Lang: %s. ".
            "Social tags: OG%s, Twitter%s. Viewport: %s. Charset: %s.",
            $title,
            mb_strlen($title, 'UTF-8'),
            mb_strlen($metaDescription, 'UTF-8'),
            $h1Count,
            count($images),
            $imagesSummary['missing_alt'],
            count($internalLinks),
            count($externalLinks),
            $linkHygiene['nofollow'],
            $textHtmlRatio ?? 'N/A',
            $canonical ?: 'none',
            $metaRobots ?: 'index,follow',
            $lang ?: 'N/A',
            $socialCardsCompleteness['og:title'] ? 'yes' : 'no',
            $socialCardsCompleteness['twitter:card'] ? 'yes' : 'no',
            $viewport ?: 'missing',
            $charset ?: 'N/A'
        );

        // Also prepare a compact structured summary for machine use
        $aiSummary = [
            'seo_quality' => [
                'title_length' => mb_strlen($title, 'UTF-8'),
                'meta_desc_length' => mb_strlen($metaDescription, 'UTF-8'),
                'h1_count' => $h1Count,
                'images_missing_alt' => $imagesSummary['missing_alt'],
                'links_nofollow' => $linkHygiene['nofollow'],
                'text_html_ratio' => $textHtmlRatio,
            ],
            'technical' => [
                'css_links' => $cssLinksCount,
                'js_scripts' => $scriptSrcCount,
                'async_scripts' => $asyncScripts,
                'defer_scripts' => $deferScripts,
            ],
            'social' => $socialCardsCompleteness,
            'language' => $lang,
            'canonical' => $canonical,
            'robots' => $metaRobots,
        ];

         // === NEW: derive main content excerpt & first paragraph =================
        $mainText = self::extractMainText($crawler);
        // First paragraph guess: split by sentence/paragraph breaks
        $firstParagraph = '';
        if ($mainText !== '') {
            // Try <p> nodes inside content containers first
            $firstParagraphSel = $crawler->filter('article p, main p, [role="main"] p, .entry-content p, .post-content p, .article-content p, .content p, .page-content p')
                                        ->first();
            if ($firstParagraphSel->count()) {
                $firstParagraph = trim(preg_replace('~\s+~u', ' ', $firstParagraphSel->text('')));
            }
            if ($firstParagraph === '') {
                // Fallback: first ~400 chars up to sentence end
                $firstParagraph = self::makeExcerpt($mainText, 400);
            }
        }
        $bodyExcerpt = self::makeExcerpt($mainText, 800);

        // === NEW: accurate word count on body HTML ==============================
        $wordCount = self::get_word_count($html);

        // --- Return final structured result -------------------------------------
        return [
            'meta' => [
                'title'                => $title,
                'meta_description'     => $metaDescription,
                'meta_robots'          => $metaRobots,
                'canonical'            => $canonical,
                'title_length'         => mb_strlen($title, 'UTF-8'),
                'meta_description_length' => mb_strlen($metaDescription, 'UTF-8'),
                'meta_keywords'        => $metaKeywords,
                'lang'                 => $lang,
                'charset'              => $charset,
                'viewport'             => $viewport,
                'hreflang'             => $hreflang,
                'pagination'           => $pagination,
                'amphtml'              => $amphtml,
                'h1_count'             => $h1Count,
                'h1_text'              => $h1Text,
                'text_html_ratio'      => $textHtmlRatio,
                'css_links_count'      => $cssLinksCount,
                'js_scripts_count'     => $scriptSrcCount,
                'defer_scripts_count'  => $deferScripts,
                'async_scripts_count'  => $asyncScripts,
                'preload_resources'    => $preloads,
                'social_cards_completeness' => $socialCardsCompleteness,
            ],
            'links' => [
                'internal' => $internalLinks,
                'external' => $externalLinks,
                'hygiene'  => $linkHygiene,
            ],
            'images' => [
                'summary' => $imagesSummary,
                'items'   => $images,
            ],
            'headings' => $headings,
            'internal_urls' => $uniqueInternal,
            'structured_data' => self::extractStructuredData($crawler),
            'open_graph' => $openGraph,

            // 🧠 AI Summary Blocks
            'summary_text' => $summary, // human-readable summary for LLM prompt
            'summary_data' => $aiSummary, // structured quick stats for machine use

            // === NEW: AI-friendly fields ========================================
            'body_excerpt'     => $bodyExcerpt,     // <= short content to feed AI for meta/title suggestions
            'first_paragraph'  => $firstParagraph,  // <= even shorter, usually the primary hook
            'word_count'       => $wordCount,       // <= Unicode-safe count
        ];
    }

    /**
     * Best-effort extraction of the main article text.
     * Tries common content wrappers, falls back to <body>.
     */
    private static function extractMainText(DomCrawler $crawler): string
    {
        // Try likely content containers (ordered by specificity)
        $candidates = [
            'article',
            'main',
            '[role="main"]',
            '.entry-content, .post-content, .article-content, .content, .page-content, .post, .singlePost',
            '#content, #main, #primary'
        ];

        $collectText = function (DomCrawler $node): string {
            // Remove common boilerplate nodes from the selection before reading text
            $clone = new DomCrawler('<div></div>');
            // Symfony DomCrawler doesn’t clone fragments easily; we’ll simply
            // read text while skipping obvious noisy descendants with filter().
            // Take the node’s text and subtract text from excluded descendants.
            $full = trim(preg_replace('~\s+~u', ' ', $node->text('')));
            $excludedSel = 'script, style, nav, header, footer, aside, noscript';
            $excluded = $node->filter($excludedSel)->each(fn(DomCrawler $n) => trim($n->text('')));
            foreach ($excluded as $bad) {
                if ($bad !== '') {
                    // remove the excluded snippet (best-effort)
                    $full = str_replace($bad, '', $full);
                }
            }
            return trim(preg_replace('~\s+~u', ' ', $full));
        };

        foreach ($candidates as $sel) {
            if ($crawler->filter($sel)->count()) {
                // merge all matches text-wise
                $parts = $crawler->filter($sel)->each(fn (DomCrawler $n) => $collectText($n));
                $text = trim(implode(' ', array_filter($parts)));
                if ($text !== '') return $text;
            }
        }

        // Fallback: whole <body>, minus boilerplate
        if ($crawler->filter('body')->count()) {
            $body = $crawler->filter('body');
            return $collectText($body);
        }

        return '';
    }

    /**
     * Return a neat excerpt with hard cap, breaking on word boundary.
     */
    private static function makeExcerpt(string $text, int $max = 800): string
    {
        $text = trim(preg_replace('~\s+~u', ' ', $text));
        if (mb_strlen($text, 'UTF-8') <= $max) return $text;

        $cut = mb_substr($text, 0, $max, 'UTF-8');
        // Try to end at the last sentence/word boundary within the cut
        if (preg_match('~^(.{50,}?[\.\!\?])(?:\s|$)~u', $cut, $m)) {
            return trim($m[1]);
        }
        if (preg_match('~^(.{50,}\b)~u', $cut, $m)) {
            return trim($m[1]) . '…';
        }
        return rtrim($cut) . '…';
    }

    /**
     * Unicode-safe word count (English + international), counts numbers as tokens.
     * If you want to ignore pure numbers, remove `|\p{N}+` from the pattern.
     */
    public static function get_word_count($body) {
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (class_exists('\Normalizer')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C);
        }
        $pattern = "/\p{L}[\p{L}\p{Mn}\p{Pd}'’]*|\p{N}+/u";
        preg_match_all($pattern, $text, $m);
        return count($m[0]);
    }

    private static function absolutizeUrl(string $href, string $base): string
    {
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
            return '';
        }

        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }

        return (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($base), new \GuzzleHttp\Psr7\Uri($href));
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    private static function buildUrlWithoutFragment(array $parts): string
    {
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $port, $path, $query);
    }

    private static function extractStructuredData(DomCrawler $crawler): array
    {
        $schemas = [];
        $nodes = $crawler->filter('script[type="application/ld+json"]');
        foreach ($nodes as $node) {
            $raw = trim($node->textContent ?? '');
            if ($raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                $schemas[] = $decoded;
            } else {
                $schemas[] = $raw;
            }
        }
        return $schemas;
    }

    private static function extractOpenGraph(DomCrawler $crawler): array
    {
        $og = [];
        $nodes = $crawler->filter('meta[property^="og:"], meta[property^="OG:"], meta[name^="og:"], meta[name^="OG:"]');
        foreach ($nodes as $node) {
            $property = $node->getAttribute('property') ?: $node->getAttribute('name');
            $content = $node->getAttribute('content');
            if (!$property || $content === null) {
                continue;
            }
            $property = strtolower(trim($property));
            if ($property === '') {
                continue;
            }
            $og[$property] = trim($content);
        }
        return $og;
    }

    private static function touchMeta(string $runBase, array $extra): void
    {
        $metaPath = $runBase . '/meta.json';
        $meta = file_exists($metaPath) ? json_decode(file_get_contents($metaPath), true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta = array_merge($meta, $extra);
        Storage::writeJson($metaPath, $meta);
    }

    private static function detectAssetType(string $contentType, string $url): string
    {
        if (self::isHtml($contentType)) {
            return 'html';
        }
        if (self::isPdf($contentType)) {
            return 'pdf';
        }
        if (self::isImage($contentType)) {
            return 'image';
        }
        
        // Fallback to URL extension
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $extensionMap = [
            'pdf' => 'pdf',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 
            'gif' => 'image', 'webp' => 'image', 'svg' => 'image',
            'html' => 'html', 'htm' => 'html'
        ];
        
        return $extensionMap[$extension] ?? 'unknown';
    }

    private static function detectUrlErrors(int $status, array $headers, string $contentType, string $body): array
    {
        $errors = [];

        // HTTP status errors
        if ($status >= 400) {
            $errors[] = [
                'type' => 'http_error',
                'message' => "HTTP {$status} error",
                'severity' => $status >= 500 ? 'critical' : 'warning'
            ];
        }

        // Redirect chains (should be handled by crawl profile, but log if found)
        if ($status >= 300 && $status < 400) {
            $errors[] = [
                'type' => 'redirect',
                'message' => "Redirect status {$status}",
                'severity' => 'info'
            ];
        }

        // Content-type mismatches
        $expectedType = self::detectAssetType($contentType, '');
        if ($expectedType === 'unknown' && $status === 200) {
            $errors[] = [
                'type' => 'content_type_unknown',
                'message' => "Unknown content type: {$contentType}",
                'severity' => 'warning'
            ];
        }

        // Large file sizes (over 10MB)
        if (strlen($body) > 10 * 1024 * 1024) {
            $errors[] = [
                'type' => 'large_file',
                'message' => 'File size exceeds 10MB',
                'severity' => 'warning'
            ];
        }

        // Empty content for expected content types
        if (strlen($body) === 0 && $status === 200) {
            $errors[] = [
                'type' => 'empty_content',
                'message' => 'Response body is empty',
                'severity' => 'warning'
            ];
        }

        // Missing security headers for HTML
        if (self::isHtml($contentType)) {
            $securityHeaders = ['x-frame-options', 'x-content-type-options', 'x-xss-protection'];
            foreach ($securityHeaders as $header) {
                if (!isset($headers[$header])) {
                    $errors[] = [
                        'type' => 'missing_security_header',
                        'message' => "Missing security header: {$header}",
                        'severity' => 'info'
                    ];
                }
            }
        }

        return $errors;
    }

    private static function isPdf(string $contentType): bool
    {
        return stripos($contentType, 'application/pdf') !== false;
    }

    private static function isImage(string $contentType): bool
    {
        return stripos($contentType, 'image/') !== false;
    }

    private static function analyzePdf(string $body, string $url): array
    {
        $info = [
            'size_bytes' => strlen($body),
            'size_mb' => round(strlen($body) / (1024 * 1024), 2),
            'is_valid' => false,
            'metadata' => []
        ];

        // Basic PDF validation (check for PDF header)
        if (substr($body, 0, 4) === '%PDF') {
            $info['is_valid'] = true;
            
            // Extract PDF version
            if (preg_match('/%PDF-(\d+\.\d+)/', substr($body, 0, 100), $matches)) {
                $info['pdf_version'] = $matches[1];
            }

            // Try to extract basic metadata (title, author, etc.)
            if (preg_match('/\/Title\s*\((.*?)\)/', $body, $matches)) {
                $info['metadata']['title'] = $matches[1];
            }
            if (preg_match('/\/Author\s*\((.*?)\)/', $body, $matches)) {
                $info['metadata']['author'] = $matches[1];
            }
        }

        return $info;
    }

    private static function analyzeImage(string $body, string $url, string $contentType): array
    {
        $info = [
            'size_bytes' => strlen($body),
            'size_kb' => round(strlen($body) / 1024, 2),
            'format' => self::getImageFormat($contentType),
            'is_valid' => false,
            'dimensions' => null
        ];

        // Try to get image dimensions using getimagesizefromstring
        if (function_exists('getimagesizefromstring')) {
            $imageSize = @getimagesizefromstring($body);
            if ($imageSize !== false) {
                $info['is_valid'] = true;
                $info['dimensions'] = [
                    'width' => $imageSize[0],
                    'height' => $imageSize[1]
                ];
                $info['aspect_ratio'] = round($imageSize[0] / $imageSize[1], 2);
            }
        }

        // Basic format validation
        if (!$info['is_valid']) {
            $signatures = [
                'jpeg' => ["\xFF\xD8\xFF"],
                'png' => ["\x89PNG\r\n\x1a\n"],
                'gif' => ["GIF87a", "GIF89a"],
                'webp' => ["RIFF", "WEBP"]
            ];

            foreach ($signatures as $format => $sigs) {
                foreach ($sigs as $sig) {
                    if (substr($body, 0, strlen($sig)) === $sig || 
                        ($format === 'webp' && strpos($body, 'WEBP') !== false)) {
                        $info['is_valid'] = true;
                        break 2;
                    }
                }
            }
        }

        return $info;
    }

    private static function getImageFormat(string $contentType): string
    {
        $formats = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg', 
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        ];

        return $formats[strtolower($contentType)] ?? 'unknown';
    }
}
