# omnicrawl/apify

Apify for [omnisense/omnicrawl](https://github.com/glitchr-omnisense/omnicrawl), under any PHP host.
Runs over a fixed set of addresses with the Cheerio Scraper actor: no link followed, one page at a time, no retry, no Apify Proxy, the target's raw HTML, status and headers kept.

It is reached through omnicrawl's gate only: every address is admitted first (the target's
declaration, robots.txt read by the core, TDM reservations, the host's limits), the service is
called after, and what it brings back is checked again and made a record with its provenance.
Read [what omnicrawl refuses to do, and why](https://github.com/glitchr-omnisense/omnicrawl/blob/1.x/docs/refusals.md).

```sh
composer require omnisense/omnicrawl omnicrawl/apify symfony/http-client
```

It needs a Apify account and its API key (the `api_key` option); its tests run on recorded answers and need
none. A first call with no account: omnicrawl's README (the recorded client, or its built-in `direct` provider).

```php
$registry = new Registry([new ApifyFactory($http)], [
    'apify' => ['factory' => 'apify', 'options' => ['api_key' => 'APIFY_TOKEN']],
], $gate);
```

The key goes by reference: `APIFY_TOKEN` names an environment variable (or a secret of the
`SecretsInterface` given to the factory), never the key itself. Its usage policy is
`policy.json` (limits, terms, what they say of lawful use), read on the day it says. See
[docs/index.md](docs/index.md).

Tested on recorded answers written from the service's documentation; no test calls the service.

MIT licence.
