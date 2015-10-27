<?php

/**
 * This Service Provider extends Laravel's built-in CacheServiceProvider class on a very specific purpose.
 * In fact we're actually overriding ALL bindings registered in CacheServiceProvider to be handled
 * through this class (although most of them are transparently passed to CacheServiceProvider).
 * Why? Because Artisan loads all deferred providers at once using a key-sorted providers array:
 *
 *  ...
 *  "cache" => CacheKeywordsServiceProvider,
 *  "cache.store" => CacheServiceProvider,
 *  ...
 *
 * As you can see, the cache.store binding calls the built-in CacheServiceProvider afterwards and hence renders
 * our binding useless if we would only bind our override. So we are obliged to convert the providers array to:
 *
 * ...
 *  "cache" => CacheKeywordsServiceProvider,
 *  "cache.store" => CacheKeywordsServiceProvider,
 *  ...
 *
 * in order to have our override resolve correctly.
 *
 * It's sort of a hack, but it's the only way to make it work across the entire Application.
 */

namespace Propaganistas\LaravelCacheKeywords;

use Illuminate\Cache\CacheServiceProvider;

class CacheKeywordsServiceProvider extends CacheServiceProvider
{

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Override CacheServiceProvider bindings to use this Service Provider transparently.
        parent::register();

        // Override the 'cache' binding to our CacheManager.
        $this->app->singleton('cache', function ($app) {
            return new CacheManager($app);
        });
    }

}
