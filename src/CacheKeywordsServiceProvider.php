<?php namespace Propaganistas\LaravelCacheKeywords;

use Illuminate\Support\ServiceProvider as IServiceProvider;

class CacheKeywordsServiceProvider extends IServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(['cache', 'Illuminate\Cache\CacheManager', 'Illuminate\Contracts\Cache\Factory'], function ($app) {
            return new CacheManager($app);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['cache'];
    }

}