<?php

namespace Spatie\Crawler;

use Tree\Node\Node;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Link;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;
use Log;

class LinkAdder
{
    /** @var \Spatie\Crawler\Crawler */
    protected $crawler;

    protected $baseUrl;

    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;
    }

    public function addFromHtml(string $html, UriInterface $foundOnUrl)
    {
        $allLinks = $this->extractLinksFromHtml($html, $foundOnUrl);

        foreach ($allLinks as $link) {

            if (!$link instanceof UriInterface) {
                $link = new Uri($link);
            }
            if ($this->shouldCrawlUrl($link)) {
                Log::info('SHOULD CRAWL:');
                Log::info($link);
                Log::info('---');
            }
        }

        collect($allLinks)
            ->filter(function (UriInterface $url) {
                return $this->hasCrawlableScheme($url);
            })
            ->map(function (UriInterface $url) {
                return $this->normalizeUrl($url);
            })
            ->filter(function (UriInterface $url) use ($foundOnUrl) {
                if (! $node = $this->crawler->addToDepthTree($url, $foundOnUrl)) {
                    return false;
                }

                return $this->shouldCrawl($node);
            })
            ->filter(function (UriInterface $url) {
                return strpos($url->getPath(), '/tel:') === false;
            })
            ->each(function (UriInterface $url) use ($foundOnUrl) {
                if ($this->crawler->maximumCrawlCountReached()) {
                    Log::error('REACHED MAX CRAWL COUNT');
                    return;
                }

                Log::info('URL SHOULD BE ADDED:');
                Log::info($url);
                Log::info('---');

                $crawlUrl = CrawlUrl::create($url, $foundOnUrl);

                $this->crawler->addToCrawlQueue($crawlUrl);
            });
    }

    /**
     * @param string $html
     * @param \Psr\Http\Message\UriInterface $foundOnUrl
     *
     * @return \Illuminate\Support\Collection|\Tightenco\Collect\Support\Collection|null
     */
    protected function extractLinksFromHtml(string $html, UriInterface $foundOnUrl)
    {
        $domCrawler = new DomCrawler($html, $foundOnUrl);

        return collect($domCrawler->filterXpath('//a')->links())
            ->reject(function (Link $link) {
                return $link->getNode()->getAttribute('rel') === 'nofollow';
            })
            ->map(function (Link $link) {
                try {
                    return new Uri($link->getUri());
                } catch (InvalidArgumentException $exception) {
                    return;
                }
            })
            ->filter();
    }

    protected function hasCrawlableScheme(UriInterface $uri): bool
    {
        return in_array($uri->getScheme(), ['http', 'https']);
    }

    protected function normalizeUrl(UriInterface $url): UriInterface
    {
        return $url->withFragment('');
    }

    protected function shouldCrawl(Node $node): bool
    {
        if ($this->crawler->mustRespectRobots()) {
            return $this->crawler->getRobotsTxt()->allows($node->getValue());
        }

        $maximumDepth = $this->crawler->getMaximumDepth();

        if (is_null($maximumDepth)) {
            return true;
        }

        return $node->getDepth() <= $maximumDepth;
    }

    /***************************************************************************************
     ** HELPERS
     ***************************************************************************************/

    public function shouldCrawlUrl(UriInterface $url): bool
    {
        return 'www.amazon.com' === $url->getHost() && $this->passes($url);
    }

    public function passes(string $url)
    {
        if (!$this->isValidDomainName($url)) {
            return false;
        }
        if (!$this->urlContainsEachSpecifiedString($url) && !$this->urlContainsEachProductUrlString($url)) {
            return false;
        }
        if (!$this->urlMissingEachSpecifiedString($url)) {
            return false;
        }
        return true;
    }

    private function isValidDomainName(string $url)
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    public function urlContainsEachSpecifiedString(string $url)
    {
        foreach (["/b/", "node="] as $element) {
            if (!str_contains($url, $element)) {
                return false;
            }
        }
        return true;
    }

    public function urlContainsEachProductUrlString(string $url)
    {
        foreach (["/dp/"] as $element) {
            if (!str_contains($url, $element)) {
                return false;
            }
        }
        return true;
    }

    public function urlMissingEachSpecifiedString(string $url)
    {
        foreach ([] as $element) {
            if (str_contains($url, $element)) {
                return false;
            }
        }
        return true;
    }
}
