<?php
namespace AISEO\Crawl;

use AISEO\PostTypes\Project;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlProfiles\CrawlProfile;

class Profile extends CrawlProfile
{
    private string $host;

    public function __construct(string $project, string $referenceUrl)
    {
        $base = Project::getBaseUrl($project) ?: $referenceUrl;
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $this->host = $this->normalizeHost($host);
    }

    public function shouldCrawl(UriInterface $url): bool
    {
        $host = $this->normalizeHost($url->getHost() ?? '');
        return $host !== '' && $host === $this->host;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }
}
