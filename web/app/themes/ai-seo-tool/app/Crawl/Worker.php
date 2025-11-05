<?php
namespace AISEO\Crawl;

use AISEO\Helpers\Storage;
use AISEO\PostTypes\Project;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\Crawler;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Worker
{
    public static function process(string $project): array
    {
        $qfile = Queue::next($project);
        if (!$qfile) {
            return ['message' => 'queue-empty'];
        }

        $url = trim(file_get_contents($qfile));
        $observer = new Observer($project, $url);

        $crawler = Crawler::create(self::clientOptions())
            ->setCrawlObserver($observer)
            ->setCrawlProfile(new Profile($project, $url))
            ->setConcurrency(1)
            ->setMaximumDepth(0)
            ->setTotalCrawlLimit(1)
            ->setCurrentCrawlLimit(1)
            ->setParseableMimeTypes(['text/html', 'application/xhtml+xml'])
            ->setUserAgent('AISEO-Bot/0.1');

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
        UriInterface $uri,
        ResponseInterface $response,
        ?UriInterface $foundOn = null,
        ?string $linkText = null
    ): array {
        $url = (string) $uri;
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);
        $contentType = $headers['content-type'][0] ?? '';
        $contentLength = self::resolveContentLength($headers, $body);

        $page = [
            'url' => $url,
            'status' => $status,
            'found_on' => $foundOn ? (string) $foundOn : null,
            'link_text' => $linkText,
            'content_type' => $contentType,
            'content_length' => $contentLength,
            'headers' => self::simplifyHeaders($headers),
            'fetched_at' => gmdate('c'),
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
            ]);
            $discovered = $parsed['internal_urls'];
        }

        $pdir = Storage::projectDir($project) . '/pages';
        $pfile = $pdir . '/' . md5($url) . '.json';
        Storage::writeJson($pfile, $page);

        if (!empty($discovered)) {
            Queue::enqueue($project, $discovered);
        }

        return $page;
    }

    public static function handleFailure(
        string $project,
        UriInterface $uri,
        ?ResponseInterface $response = null,
        ?\Throwable $exception = null,
        ?UriInterface $foundOn = null,
        ?string $linkText = null
    ): array {
        $status = $response ? $response->getStatusCode() : 0;
        $body = $response ? (string) $response->getBody() : '';
        $headers = $response ? array_change_key_case($response->getHeaders(), CASE_LOWER) : [];

        $page = [
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

        $pdir = Storage::projectDir($project) . '/pages';
        $pfile = $pdir . '/' . md5($page['url']) . '.json';
        Storage::writeJson($pfile, $page);

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
                'User-Agent' => 'AISEO-Bot/0.1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ];
    }

    private static function resolveVerifyOption()
    {
        $env = getenv('AISEO_HTTP_VERIFY_TLS');
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

    private static function parseHtml(string $project, string $url, string $html): array
    {
        $crawler = new DomCrawler($html, $url);

        $title = $crawler->filter('title')->count() ? trim($crawler->filter('title')->text()) : '';
        $metaDescription = $crawler->filter('meta[name="description"]')->count()
            ? trim((string) $crawler->filter('meta[name="description"]')->attr('content'))
            : '';
        $metaRobots = $crawler->filter('meta[name="robots"]')->count()
            ? trim((string) $crawler->filter('meta[name="robots"]')->attr('content'))
            : '';
        $canonical = $crawler->filter('link[rel="canonical"]')->count()
            ? trim((string) $crawler->filter('link[rel="canonical"]')->attr('href'))
            : '';

        $headings = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $headings[$tag] = $crawler->filter($tag)->each(fn (DomCrawler $node) => trim($node->text()));
        }

        $images = $crawler->filter('img')->each(function (DomCrawler $node) use ($url) {
            $src = $node->attr('src') ?? '';
            $alt = trim($node->attr('alt') ?? '');
            $src = self::absolutizeUrl($src, $url);
            return [
                'src' => $src,
                'alt' => $alt,
            ];
        });

        $internalLinks = [];
        $externalLinks = [];

        $baseUrl = Project::getBaseUrl($project) ?: $url;
        $targetHost = self::normalizeHost(parse_url($baseUrl, PHP_URL_HOST) ?: '');

        $crawler->filter('a[href]')->each(function (DomCrawler $node) use (&$internalLinks, &$externalLinks, $url, $targetHost) {
            $href = $node->attr('href') ?? '';
            $href = self::absolutizeUrl($href, $url);
            if (!$href) {
                return;
            }

            $parts = parse_url($href);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return;
            }

            $entry = [
                'url' => self::buildUrlWithoutFragment($parts),
                'anchor' => trim($node->text() ?? ''),
            ];

            $host = self::normalizeHost($parts['host'] ?? '');
            if ($host && $host === $targetHost) {
                $internalLinks[] = $entry;
            } else {
                $externalLinks[] = $entry;
            }
        });

        $uniqueInternal = array_values(array_unique(array_map(fn ($link) => $link['url'], $internalLinks)));

        return [
            'meta' => [
                'title' => $title,
                'meta_description' => $metaDescription,
                'meta_robots' => $metaRobots,
                'canonical' => $canonical,
            ],
            'links' => [
                'internal' => $internalLinks,
                'external' => $externalLinks,
            ],
            'images' => $images,
            'headings' => $headings,
            'internal_urls' => $uniqueInternal,
            'structured_data' => self::extractStructuredData($crawler),
            'open_graph' => self::extractOpenGraph($crawler),
        ];
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
}
