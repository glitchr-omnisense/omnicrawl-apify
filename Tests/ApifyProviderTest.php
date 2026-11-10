<?php

namespace Omnicrawl\Apify\Tests;

use Omnicrawl\Agent;
use Omnicrawl\Apify\ApifyFactory;
use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\Exception\RefusedException;
use Omnicrawl\Exception\TargetRefusedException;
use Omnicrawl\Guard\Gate;
use Omnicrawl\Model\RunStatus;
use Omnicrawl\Registry;
use Omnicrawl\Secrets\ArraySecrets;
use Omnicrawl\Target\Targets;
use Omnicrawl\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/** Apify's API v2 and the Cheerio Scraper's input as their documentation writes them, answered from recordings: no live call. */
final class ApifyProviderTest extends TestCase
{
    private const UA = 'ExampleBot/1.0 (+https://example.org/bot; bot@example.org)';

    private static function registry(RecordedHttpClient $api): Registry
    {
        $site = RecordedHttpClient::routes(['~/robots\.txt$~' => [200, "User-agent: *\nAllow: /\n"]]);
        $targets = new Targets([['target' => 'archive.example.org', 'collection' => 'allowed', 'authority' => 'published-terms', 'reference' => 'https://archive.example.org/reuse', 'date' => '2026-11-10', 'operations' => ['page-fetch', 'extract', 'crawl-runs'], 'rateLimits' => [['max' => 100, 'window' => 'hour']]]]);

        return new Registry([new ApifyFactory($api, new ArraySecrets(['APIFY_TOKEN' => 'apify_api_not-a-real-token']))], ['apify' => ['factory' => 'apify', 'options' => ['api_key' => 'APIFY_TOKEN']]], new Gate(Agent::parse(self::UA), $site, $targets));
    }

    public function testARunOfTheCheerioScraperOverTheAddressesGiven(): void
    {
        $api = RecordedHttpClient::routes([
            '#^POST https://api\.apify\.com/v2/actors/apify~cheerio-scraper/runs$#' => [201, '@'.__DIR__.'/Fixtures/run-started.json'],
            '~^GET https://api\.apify\.com/v2/actor-runs/HG7ML7M8z78YcAPEB$~' => [200, '@'.__DIR__.'/Fixtures/run-succeeded.json'],
            '~^GET https://api\.apify\.com/v2/datasets/wmKPijuyDnPZAPRMk/items\?format=json&clean=true&offset=0&limit=100$~' => [200, '@'.__DIR__.'/Fixtures/items.json'],
        ]);
        $runs = self::registry($api)->crawlRuns('apify');
        $urls = ['https://archive.example.org/2025/report', 'https://archive.example.org/2024/report'];

        $run = $runs->start($urls);

        $input = json_decode(RecordedHttpClient::body($api->requests[0]['options']), true);
        self::assertIsArray($input);
        self::assertSame([['url' => $urls[0]], ['url' => $urls[1]]], $input['startUrls']);
        self::assertSame(['', 2, 1, 0, true, ['useApifyProxy' => false]], [$input['linkSelector'], $input['maxPagesPerCrawl'], $input['maxConcurrency'], $input['maxRequestRetries'], $input['respectRobotsTxtFile'], $input['proxyConfiguration']]);
        self::assertIsString($input['preNavigationHooks']);
        self::assertIsString($input['pageFunction']);
        self::assertStringContainsString("'user-agent': \"".self::UA.' omnicrawl/1"', $input['preNavigationHooks']);
        self::assertStringContainsString('response.headers', $input['pageFunction']);
        self::assertSame('Bearer apify_api_not-a-real-token', $api->header('Authorization'));
        self::assertSame(['HG7ML7M8z78YcAPEB', RunStatus::Queued, 2], [$run->id, $run->status, $run->total]);

        self::assertSame([RunStatus::Succeeded, 2], [$runs->run($run->id)->status, $runs->run($run->id)->completed]);
        $results = $runs->results($run->id);
        self::assertCount(1, $results->records);
        self::assertSame('https://archive.example.org/2025/report', $results->records[0]->url);
        self::assertTrue($results->records[0]->signals->noindex());
        self::assertSame([['https://archive.example.org/2024/report', RefusedException::REASON_TDM]], array_map(static fn (array $r) => [$r['url'], $r['reason']], $results->refused), 'a page that reserves mining, under published terms, is dropped from the run');
        self::assertNull($results->next);
    }

    public function testAnUndeclaredSiteNeverReachesApify(): void
    {
        $api = RecordedHttpClient::routes([]);

        try {
            self::registry($api)->crawlRuns('apify')->start(['https://news.example.com/1']);
            self::fail('A run over an undeclared site started.');
        } catch (TargetRefusedException $e) {
            self::assertSame(RefusedException::REASON_OPERATION, $e->reason);
        }
        self::assertSame([], $api->requests);
    }

    public function testNoPageFetchAndNoRendering(): void
    {
        $registry = self::registry(RecordedHttpClient::routes([]));
        self::assertSame(['crawl-runs'], $registry->get('apify')->capabilities()->contracts());
        self::assertSame(['60/second/application/*'], array_map(static fn ($l) => $l->id(), $registry->policy('apify')->rateLimits ?? []));

        $this->expectException(NotSupportedException::class);
        $registry->pageFetch('apify');
    }
}
