<?php namespace Unitedworx\LaravelCacheKeywords;

use Illuminate\Support\ServiceProvider;

class CacheKeywordsServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Override the 'cache' binding to our CacheManager.
        $this->app->extend('cache', function ($original, $app) {
            return new CacheManager($app);
        });
    }

}
