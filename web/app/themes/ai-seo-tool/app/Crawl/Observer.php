<?php
namespace AISEO\Crawl;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;

class Observer extends CrawlObserver
{
    private string $project;
    private string $runId;
    private ?string $originUrl;
    private ?array $lastResult = null;

    public function __construct(string $project, string $runId, ?string $originUrl = null)
    {
        $this->project = $project;
        $this->runId = $runId;
        $this->originUrl = $originUrl;
    }

    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $this->lastResult = Worker::handleResponse(
            $this->project,
            $this->runId,
            $url,
            $response,
            $foundOnUrl,
            $linkText
        );
    }

    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null
    ): void {
        $response = $requestException->getResponse();
        $this->lastResult = Worker::handleFailure(
            $this->project,
            $this->runId,
            $url,
            $response,
            $requestException,
            $foundOnUrl,
            $linkText
        );
    }

    public function recordException(string $url, \Throwable $exception): void
    {
        $uri = new \GuzzleHttp\Psr7\Uri($url);
        $this->lastResult = Worker::handleFailure(
            $this->project,
            $this->runId,
            $uri,
            null,
            $exception,
            null,
            null
        );
    }

    public function getLastResult(): ?array
    {
        return $this->lastResult;
    }
}
