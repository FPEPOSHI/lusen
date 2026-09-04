<?php

declare(strict_types=1);

namespace Lusen\Tests;

use Illuminate\Foundation\Application;
use Lusen\LusenServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LusenServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('lusen.title', 'Test API');
        $app['config']->set('lusen.version', '2.1.0');
        $app['config']->set('lusen.base_url', 'https://api.test');

        // Off for the suite at large: a shared cache would leak endpoints
        // between tests, and writing one would pollute Testbench's skeleton
        // inside vendor/. BuildCacheTest turns it on against a temp path.
        $app['config']->set('lusen.cache.enabled', false);

        // The runtime renderer is off by default in real installs; the feature
        // suite needs it on to exercise the routes and Blade views.
        $app['config']->set('lusen.runtime', [
            'enabled' => true,
            'path' => 'docs',
            'middleware' => [],
        ]);
    }
}
