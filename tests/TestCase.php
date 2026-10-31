<?php

namespace BoldlyGrow\Okta\Tests;

use BoldlyGrow\AuditLog\AuditLogServiceProvider;
use BoldlyGrow\Okta\OktaApiServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every Okta endpoint a test calls must be faked, so no test can reach the network.
        Http::preventStrayRequests();
    }

    /**
     * @param  Application  $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AuditLogServiceProvider::class,
            OktaApiServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Required to encrypt the cached access token.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');
    }
}
