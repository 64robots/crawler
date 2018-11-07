<?php

namespace Spatie\Crawler;

use Generator;
use Tree\Node\Node;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Request;
use Spatie\Robots\RobotsTxt;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\UriInterface;
use Spatie\Browsershot\Browsershot;
use Spatie\Crawler\CrawlQueue\CrawlQueue;
use Spatie\Crawler\Handlers\CrawlRequestFailed;
use Spatie\Crawler\Handlers\CrawlRequestFulfilled;
use Spatie\Crawler\CrawlQueue\CollectionCrawlQueue;
use Spatie\Crawler\Exception\InvalidCrawlRequestHandler;

class Crawler
{
    /** @var \GuzzleHttp\Client */
    protected $client;

    /** @var \Psr\Http\Message\UriInterface */
    protected $baseUrl;

    /** @var \Spatie\Crawler\CrawlObserverCollection */
    protected $crawlObservers;

    /** @var \Spatie\Crawler\CrawlProfile */
    protected $crawlProfile;

    /** @var int */
    protected $concurrency;

    /** @var \Spatie\Crawler\CrawlQueue\CrawlQueue */
    protected $crawlQueue;

    /** @var int */
    protected $crawledUrlCount = 0;

    /** @var int|null */
    protected $maximumCrawlCount = null;

    /** @var int */
    protected $maximumResponseSize = 1024 * 1024 * 2;

    /** @var int|null */
    protected $maximumDepth = null;

    /** @var bool */
    protected $respectRobots = true;

    /** @var \Tree\Node\Node */
    protected $depthTree;

    /** @var bool */
    protected $executeJavaScript = false;

    /** @var Browsershot */
    protected $browsershot = null;

    /** @var \Spatie\Robots\RobotsTxt */
    protected $robotsTxt = null;

    /** @var string */
    protected $crawlRequestFulfilledClass;

    /** @var string */
    protected $crawlRequestFailedClass;

    /** @var float */
    protected $delayBetweenRequests = 0;

    /** @var   */
    protected static $defaultClientOptions = [
        RequestOptions::COOKIES => true,
        RequestOptions::CONNECT_TIMEOUT => 10,
        RequestOptions::TIMEOUT => 10,
        RequestOptions::ALLOW_REDIRECTS => false,
    ];

    /** @var array */
    protected $proxiesConfig = null;

    /** @var bool */
    protected $usingProxies = false;

    public static function create(array $clientOptions = []): Crawler
    {
        $clientOptions = (count($clientOptions))
            ? $clientOptions
            : static::$defaultClientOptions;

        $client = new Client($clientOptions);

        return new static($client);
    }

    public function __construct(Client $client, int $concurrency = 10)
    {
        $this->client = $client;

        $this->concurrency = $concurrency;

        $this->crawlProfile = new CrawlAllUrls();

        $this->crawlQueue = new CollectionCrawlQueue();

        $this->crawlObservers = new CrawlObserverCollection();

        $this->crawlRequestFulfilledClass = CrawlRequestFulfilled::class;

        $this->crawlRequestFailedClass = CrawlRequestFailed::class;
    }

    public function setConcurrency(int $concurrency): Crawler
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    public function setMaximumResponseSize(int $maximumResponseSizeInBytes): Crawler
    {
        $this->maximumResponseSize = $maximumResponseSizeInBytes;

        return $this;
    }

    public function getMaximumResponseSize(): ?int
    {
        return $this->maximumResponseSize;
    }

    public function setMaximumCrawlCount(int $maximumCrawlCount): Crawler
    {
        $this->maximumCrawlCount = $maximumCrawlCount;

        return $this;
    }

    public function getMaximumCrawlCount(): ?int
    {
        return $this->maximumCrawlCount;
    }

    public function getCrawlerUrlCount(): int
    {
        return $this->crawledUrlCount;
    }

    public function setMaximumDepth(int $maximumDepth): Crawler
    {
        $this->maximumDepth = $maximumDepth;

        return $this;
    }

    public function getPoolItemLimit(): ?int
    {
        return $this->poolItemLimit;
    }

    public function setPoolItemLimit(int $poolItemLimit): Crawler
    {
        $this->poolItemLimit = $poolItemLimit;

        return $this;
    }

    public function getMaximumDepth(): ?int
    {
        return $this->maximumDepth;
    }

    public function setDelayBetweenRequests(int $delay): Crawler
    {
        $this->delayBetweenRequests = ($delay * 1000);

        return $this;
    }

    public function getDelayBetweenRequests(): float
    {
        return $this->delayBetweenRequests;
    }

    public function ignoreRobots(): Crawler
    {
        $this->respectRobots = false;

        return $this;
    }

    public function respectRobots(): Crawler
    {
        $this->respectRobots = true;

        return $this;
    }

    public function mustRespectRobots(): bool
    {
        return $this->respectRobots;
    }

    public function getRobotsTxt(): RobotsTxt
    {
        return $this->robotsTxt;
    }

    public function setCrawlQueue(CrawlQueue $crawlQueue): Crawler
    {
        $this->crawlQueue = $crawlQueue;

        return $this;
    }

    public function getCrawlQueue(): CrawlQueue
    {
        return $this->crawlQueue;
    }

    public function executeJavaScript(): Crawler
    {
        $this->executeJavaScript = true;

        return $this;
    }

    public function doNotExecuteJavaScript(): Crawler
    {
        $this->executeJavaScript = false;

        return $this;
    }

    public function mayExecuteJavascript(): bool
    {
        return $this->executeJavaScript;
    }

    /**
     * @param \Spatie\Crawler\CrawlObserver|array[\Spatie\Crawler\CrawlObserver] $crawlObservers
     *
     * @return $this
     */
    public function setCrawlObserver($crawlObservers): Crawler
    {
        if (! is_array($crawlObservers)) {
            $crawlObservers = [$crawlObservers];
        }

        return $this->setCrawlObservers($crawlObservers);
    }

    public function setCrawlObservers(array $crawlObservers): Crawler
    {
        $this->crawlObservers = new CrawlObserverCollection($crawlObservers);

        return $this;
    }

    public function addCrawlObserver(CrawlObserver $crawlObserver): Crawler
    {
        $this->crawlObservers->addObserver($crawlObserver);

        return $this;
    }

    public function getCrawlObservers(): CrawlObserverCollection
    {
        return $this->crawlObservers;
    }

    public function setCrawlProfile(CrawlProfile $crawlProfile): Crawler
    {
        $this->crawlProfile = $crawlProfile;

        return $this;
    }

    public function getCrawlProfile(): CrawlProfile
    {
        return $this->crawlProfile;
    }

    public function setCrawlFulfilledHandlerClass(string $crawlRequestFulfilledClass): Crawler
    {
        $baseClass = CrawlRequestFulfilled::class;

        if (! is_subclass_of($crawlRequestFulfilledClass, $baseClass)) {
            throw InvalidCrawlRequestHandler::doesNotExtendBaseClass($crawlRequestFulfilledClass, $baseClass);
        }

        $this->crawlRequestFulfilledClass = $crawlRequestFulfilledClass;

        return $this;
    }

    public function setCrawlFailedHandlerClass(string $crawlRequestFailedClass): Crawler
    {
        $baseClass = CrawlRequestFailed::class;

        if (! is_subclass_of($crawlRequestFailedClass, $baseClass)) {
            throw InvalidCrawlRequestHandler::doesNotExtendBaseClass($crawlRequestFailedClass, $baseClass);
        }

        $this->crawlRequestFailedClass = $crawlRequestFailedClass;

        return $this;
    }

    public function setBrowsershot(Browsershot $browsershot)
    {
        $this->browsershot = $browsershot;

        return $this;
    }

    public function getBrowsershot(): Browsershot
    {
        if (! $this->browsershot) {
            $this->browsershot = new Browsershot();
        }

        return $this->browsershot;
    }

    public function getBaseUrl(): UriInterface
    {
        return $this->baseUrl;
    }

    /**
     * @param \Psr\Http\Message\UriInterface|string $baseUrl
     */
    public function startCrawling($baseUrl)
    {
        if (! $baseUrl instanceof UriInterface) {
            $baseUrl = new Uri($baseUrl);
        }

        if ($baseUrl->getScheme() === '') {
            $baseUrl = $baseUrl->withScheme('http');
        }

        if ($baseUrl->getPath() === '') {
            $baseUrl = $baseUrl->withPath('/');
        }

        $this->baseUrl = $baseUrl;

        $crawlUrl = CrawlUrl::create($this->baseUrl);

        $this->robotsTxt = $this->createRobotsTxt($crawlUrl->url);

        if ($this->robotsTxt->allows((string) $crawlUrl->url) ||
            ! $this->respectRobots
        ) {
            $this->addToCrawlQueue($crawlUrl);
        }

        $this->depthTree = new Node((string) $this->baseUrl);

        $this->startCrawlingQueue();

        foreach ($this->crawlObservers as $crawlObserver) {
            $crawlObserver->finishedCrawling();
        }
    }

    public function addToDepthTree(UriInterface $url, UriInterface $parentUrl, Node $node = null): ?Node
    {
        if (is_null($this->maximumDepth)) {
            return new Node((string) $url);
        }

        $node = $node ?? $this->depthTree;

        $returnNode = null;

        if ($node->getValue() === (string) $parentUrl) {
            $newNode = new Node((string) $url);

            $node->addChild($newNode);

            return $newNode;
        }

        foreach ($node->getChildren() as $currentNode) {
            $returnNode = $this->addToDepthTree($url, $parentUrl, $currentNode);

            if (! is_null($returnNode)) {
                break;
            }
        }

        return $returnNode;
    }

    protected function startCrawlingQueue()
    {
        while ($this->crawlQueue->hasPendingUrls()) {
            $pool = new Pool($this->client, $this->getCrawlRequests(), [
                'concurrency' => $this->concurrency,
                'options' => $this->getConfig(),
                'fulfilled' => new $this->crawlRequestFulfilledClass($this),
                'rejected' => new $this->crawlRequestFailedClass($this),
            ]);

            $promise = $pool->promise();

            $promise->wait();
        }
    }

    public function setProxies(array $proxyConfig)
    {
        $this->proxyConfig = $proxyConfig;
        $this->usingProxies = true;

        return $this;
    }

    protected function getConfig()
    {
        $config = $this->client->getConfig();
        if ($this->usingProxies) {
            $config['proxy'] = $this->getProxyConfig();
        }
        return $config;
    }

    protected function getProxyConfig()
    {
        $ips = collect($this->proxyConfig['ips']);
        $username = $this->proxyConfig['username'];
        $password = $this->proxyConfig['password'];
        $port = $this->proxyConfig['port'];
        
        $proxyIp = $ips->random();

        return "https://{$username}:{$password}@{$proxyIp}:{$port}";
    }

    /**
     * @deprecated This function will be removed in the next major version
     */
    public function endsWith($haystack, $needle)
    {
        return strrpos($haystack, $needle) + strlen($needle) ===
            strlen($haystack);
    }

    protected function createRobotsTxt(UriInterface $uri): RobotsTxt
    {
        return RobotsTxt::create($uri->withPath('/robots.txt'));
    }

    protected function getCrawlRequests(): Generator
    {
        $poolItemLimit = $this->getPoolItemLimit();
        $crawledUrlCount = 0;

        while ($crawlUrl = $this->crawlQueue->getFirstPendingUrl()) {
            if (! $this->crawlProfile->shouldCrawl($crawlUrl->url)) {
                $this->crawlQueue->markAsProcessed($crawlUrl);
                continue;
            }

            if ($this->crawlQueue->hasAlreadyBeenProcessed($crawlUrl)) {
                continue;
            }

            if ($poolItemLimit && $poolItemLimit >= $crawledUrlCount) {
                break;
            }

            foreach ($this->crawlObservers as $crawlObserver) {
                $crawlObserver->willCrawl($crawlUrl->url);
            }

            $crawledUrlCount++;

            $this->crawlQueue->markAsProcessed($crawlUrl);

            yield $crawlUrl->getId() => new Request('GET', $crawlUrl->url);
        }
    }

    public function addToCrawlQueue(CrawlUrl $crawlUrl): Crawler
    {
        if (! $this->getCrawlProfile()->shouldCrawl($crawlUrl->url)) {
            return $this;
        }

        if ($this->getCrawlQueue()->has($crawlUrl->url)) {
            return $this;
        }

        $this->crawledUrlCount++;

        $this->crawlQueue->add($crawlUrl);

        return $this;
    }

    public function maximumCrawlCountReached(): bool
    {
        $maximumCrawlCount = $this->getMaximumCrawlCount();

        if (is_null($maximumCrawlCount)) {
            return false;
        }

        return $this->getCrawlerUrlCount() >= $maximumCrawlCount;
    }
}
