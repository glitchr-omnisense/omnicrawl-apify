<?php

namespace Omnicrawl\Apify;

use Omnicrawl\Capabilities;
use Omnicrawl\Exception\InvalidArgumentException;
use Omnicrawl\Exception\NotSupportedException;
use Omnicrawl\Exception\ProviderException;
use Omnicrawl\Http\Data;
use Omnicrawl\Http\JsonClient;
use Omnicrawl\Model\CrawlRequest;
use Omnicrawl\Model\CrawlRun;
use Omnicrawl\Model\FetchedPage;
use Omnicrawl\Model\Fetched;
use Omnicrawl\Model\RunStatus;
use Omnicrawl\RunnerInterface;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * Apify, as the family uses it: runs of the Cheerio Scraper actor (plain
 * HTTP, no browser) over exactly the addresses admitted.
 *
 * - start: POST /actors/apify~cheerio-scraper/runs with the actor's input:
 *   the addresses as startUrls; linkSelector empty ("page links are
 *   ignored"), maxPagesPerCrawl the number of addresses - no link
 *   followed -; maxConcurrency 1; maxRequestRetries 0 (a page the target
 *   refuses is not tried again); proxyConfiguration without Apify Proxy
 *   (no proxy group, residential or other, to get past blocks);
 *   respectRobotsTxtFile true (the actor's own check, besides the
 *   gate's); a preNavigationHooks function that sets the family's
 *   User-Agent on each request; a pageFunction that gives back what the
 *   target served: the address asked and loaded, the status, the headers,
 *   the body;
 * - run: GET /actor-runs/{id};
 * - results: the run's default dataset, GET /datasets/{id}/items, a
 *   hundred items a page, the offset as the cursor.
 *
 * No page-fetch: an actor run per page would be a heavy way to fetch one.
 */
final class ApifyProvider implements RunnerInterface, DeclaresUsagePolicyInterface
{
    public const BASE_URI = 'https://api.apify.com/v2';

    public const ACTOR = 'apify~cheerio-scraper';

    public const POLICY = __DIR__.'/policy.json';

    public const PAGE = 100;

    /** What the actor gives back of each page: what the target served, nothing parsed. */
    public const PAGE_FUNCTION = <<<'JS'
        async function pageFunction(context) {
            const { request, response, body } = context;
            return {
                url: request.url,
                loadedUrl: request.loadedUrl || request.url,
                status: response.status,
                headers: response.headers,
                body: typeof body === 'string' ? body : String(body),
            };
        }
        JS;

    private static ?UsagePolicy $policy = null;

    public function __construct(private readonly JsonClient $client, private readonly string $base = self::BASE_URI, private readonly string $actor = self::ACTOR)
    {
    }

    public function getName(): string
    {
        return 'apify';
    }

    public function getTitle(): string
    {
        return 'Apify';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(crawlRuns: true, headers: true, policy: $this->usagePolicy());
    }

    public function start(CrawlRequest $request): CrawlRun
    {
        if ($request->render) {
            throw NotSupportedException::call('The Cheerio Scraper actor', 'render JavaScript');
        }
        $data = $this->client->request('POST', $this->base.'/actors/'.rawurlencode($this->actor).'/runs', ['json' => self::input($request)]) ?? throw ProviderException::answered('Apify', 'POST /actors/{actor}/runs', 404, 'No such actor.');

        return self::crawlRun($data->data('data'), \count($request->urls));
    }

    public function run(string $id): CrawlRun
    {
        $data = $this->client->get($this->base.'/actor-runs/'.rawurlencode($id)) ?? throw ProviderException::answered('Apify', 'GET /actor-runs/{id}', 404, 'No such run.');

        return self::crawlRun($data->data('data'), null);
    }

    public function results(string $id, ?string $cursor = null): FetchedPage
    {
        if (null !== $cursor && 1 !== preg_match('~^\d+$~', $cursor)) {
            throw new InvalidArgumentException('An Apify cursor is an offset.');
        }
        $run = $this->client->get($this->base.'/actor-runs/'.rawurlencode($id)) ?? throw ProviderException::answered('Apify', 'GET /actor-runs/{id}', 404, 'No such run.');
        $dataset = $run->string('data.defaultDatasetId') ?? throw ProviderException::answered('Apify', 'GET /actor-runs/{id}', 200, 'The run has no dataset.');
        $offset = (int) ($cursor ?? 0);
        $items = $this->client->get($this->base.'/datasets/'.rawurlencode($dataset).'/items', ['format' => 'json', 'clean' => 'true', 'offset' => $offset, 'limit' => self::PAGE]) ?? throw ProviderException::answered('Apify', 'GET /datasets/{id}/items', 404, 'No such dataset.');
        $pages = [];
        foreach ($items->items() as $item) {
            $url = $item->string('url');
            $status = $item->int('status');
            if (null === $url || null === $status) {
                continue;
            }
            $headers = $item->get('headers');
            $normalised = Fetched::normaliseHeaders(\is_array($headers) ? $headers : []);
            $pages[] = new Fetched($url, $item->string('loadedUrl') ?? $url, $status, $item->string('body') ?? '', $normalised, false, str_contains(strtolower($normalised['content-type'][0] ?? 'text/html'), 'html'));
        }

        return new FetchedPage($pages, \count($items->items()) === self::PAGE ? (string) ($offset + self::PAGE) : null);
    }

    /** @return array<string, mixed> the actor's input */
    public static function input(CrawlRequest $request): array
    {
        $agent = json_encode($request->agent->header(), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return [
            'startUrls' => array_map(static fn (string $url) => ['url' => $url], $request->urls),
            'linkSelector' => '',
            'maxPagesPerCrawl' => \count($request->urls),
            'maxResultsPerCrawl' => \count($request->urls),
            'maxConcurrency' => 1,
            'maxRequestRetries' => 0,
            'respectRobotsTxtFile' => true,
            'proxyConfiguration' => ['useApifyProxy' => false],
            'preNavigationHooks' => '[async (crawlingContext, gotOptions) => { gotOptions.headers = { ...gotOptions.headers, \'user-agent\': '.$agent.' }; }]',
            'pageFunction' => self::PAGE_FUNCTION,
        ];
    }

    private static function crawlRun(Data $run, ?int $total): CrawlRun
    {
        $id = $run->string('id') ?? throw ProviderException::answered('Apify', 'the run', 200, 'No run id in the answer.');

        return new CrawlRun($id, match ($run->string('status')) {
            'READY' => RunStatus::Queued,
            'RUNNING', 'TIMING-OUT', 'ABORTING' => RunStatus::Running,
            'SUCCEEDED' => RunStatus::Succeeded,
            'ABORTED' => RunStatus::Cancelled,
            default => RunStatus::Failed,
        }, 'apify', $total, $run->int('stats.requestsFinished'));
    }
}
