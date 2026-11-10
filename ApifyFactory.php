<?php

namespace Omnicrawl\Apify;

use Omnicrawl\Agent;
use Omnicrawl\Config;
use Omnicrawl\Http\JsonClient;
use Omnicrawl\ProviderFactory;
use Omnicrawl\ProviderInterface;
use Omnicrawl\Secrets\EnvSecrets;
use Omnicrawl\Secrets\SecretsInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "apify": Apify's API v2, running the Cheerio Scraper actor. Options:
 * api_key (the REFERENCE of the API token, "APIFY_TOKEN", never the
 * token), user_agent (the registry gives the gate's), base_uri
 * (https://api.apify.com/v2), actor (apify~cheerio-scraper), timeout
 * (seconds, 60); policy, usage.
 */
final class ApifyFactory extends ProviderFactory
{
    private readonly SecretsInterface $secrets;

    public function __construct(private readonly ?HttpClientInterface $http = null, ?SecretsInterface $secrets = null)
    {
        $this->secrets = $secrets ?? new EnvSecrets();
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnicrawl.factory_name' => 'apify',
            'omnicrawl.factory_title' => 'Apify',
            'omnicrawl.required_options' => ['api_key', 'user_agent'],
            'base_uri' => ApifyProvider::BASE_URI,
            'actor' => ApifyProvider::ACTOR,
            'timeout' => 60,
        ]);
    }

    protected function build(Config $c, Agent $agent): ProviderInterface
    {
        $http = ($this->http ?? HttpClient::create())->withOptions(['timeout' => $c->float('timeout', 60.0)]);

        return new ApifyProvider(new JsonClient($http, 'Apify', ['Authorization' => 'Bearer '.$this->secrets->get((string) $c->string('api_key')), 'Accept' => 'application/json'], $c->backoff()), rtrim((string) $c->string('base_uri'), '/'), (string) $c->string('actor'));
    }
}
