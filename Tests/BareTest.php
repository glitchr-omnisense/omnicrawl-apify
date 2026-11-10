<?php

namespace Omnicrawl\Apify\Tests;

use Omnicrawl\Apify\ApifyProvider;
use Omnicrawl\Testing\BareTestCase;

/** omnicrawl/apify's classes load with omnisense/omnicrawl, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return ApifyProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
