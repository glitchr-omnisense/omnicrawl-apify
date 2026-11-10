# omnicrawl/apify

Apify's API v2 (https://docs.apify.com/api/v2) and the Cheerio Scraper actor
(https://apify.com/apify/cheerio-scraper/input-schema), read on 2026-11-10.

| Contract | Call |
|---|---|
| crawl-runs: start | `POST /v2/actors/apify~cheerio-scraper/runs`, `Authorization: Bearer <token>`, the actor's input as the body |
| crawl-runs: run | `GET /v2/actor-runs/{id}` (`READY`, `RUNNING`, `SUCCEEDED`, `FAILED`, `TIMING-OUT`, `TIMED-OUT`, `ABORTING`, `ABORTED`) |
| crawl-runs: results | `GET /v2/datasets/{defaultDatasetId}/items?format=json&clean=true&offset=&limit=100` |

The actor's input (`ApifyProvider::input()`):

| Field | Value | Why |
|---|---|---|
| `startUrls` | the addresses admitted | nothing else |
| `linkSelector` | `""` | "page links are ignored": no link followed |
| `maxPagesPerCrawl`, `maxResultsPerCrawl` | the number of addresses | nothing more |
| `maxConcurrency` | 1 | one page at a time |
| `maxRequestRetries` | 0 | a page the target refuses is not tried again |
| `proxyConfiguration` | `{"useApifyProxy": false}` | no proxy group (residential or other) to get past blocks |
| `respectRobotsTxtFile` | true | the actor's own check, besides the gate's |
| `preNavigationHooks` | sets `user-agent` | the family's User-Agent on each request |
| `pageFunction` | gives back `url`, `loadedUrl`, `status`, `headers`, `body` | what the target served, nothing parsed |

- **No page-fetch:** an actor run per page would be a heavy way to fetch one; use the direct
  provider, Firecrawl or ScrapingBee.
- **Not verified against a live run:** whether the actor accepts `useApifyProxy: false` with no
  proxy URLs (its input schema calls the proxy "required"); if it does not, the run fails, and the
  family would rather fail than add a proxy.
- **Policy** (`policy.json`): 60 requests a second per API resource; business use only; the
  customer is solely responsible for the legality of the data it collects.
