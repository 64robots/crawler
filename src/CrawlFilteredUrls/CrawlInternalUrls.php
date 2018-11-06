<?php

namespace Spatie\Crawler\CrawlFilteredUrls;

use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

class CrawlInternalUrls extends CrawlProfile
{
    protected $baseUrl;
    protected $contains;
    protected $missing;

    public function __construct($baseUrl, array $contains, array $missing)
    {
        if (! $baseUrl instanceof UriInterface) {
            $baseUrl = new Uri($baseUrl);
        }

        $this->baseUrl = $baseUrl;
        $this->contains = $contains;
        $this->missing = $missing;
    }

    public function shouldCrawl(UriInterface $url): bool
    {
        return $this->baseUrl->getHost() === $url->getHost() && $this->passes($url);
    }

    public function passes(string $url)
    {
        if (!$this->isValidDomainName($url)) {
            return false;
        }
        if (!$this->urlContainsEachSpecifiedString($url)) {
            return false;
        }
        if (!$this->urlMissingEachSpecifiedString($url)) {
            return false;
        }
        return true;
    }

    /***************************************************************************************
     ** HELPERS
     ***************************************************************************************/

    private function isValidDomainName(string $url)
    {
        return filter_var($url, FILTER_VALIDATE_URL);
    }

    public function urlContainsEachSpecifiedString(string $url)
    {
        foreach ($this->contains as $element) {
            if (!str_contains($url, $element)) {
                return false;
            }
        }
        return true;
    }

    public function urlMissingEachSpecifiedString(string $url)
    {
        foreach ($this->missing as $element) {
            if (str_contains($url, $element)) {
                return false;
            }
        }
        return true;
    }
}
