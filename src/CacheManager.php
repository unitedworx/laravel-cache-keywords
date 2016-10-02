<?php namespace Propaganistas\LaravelCacheKeywords;

use Illuminate\Cache\CacheManager as IlluminateCacheManager;
use Illuminate\Contracts\Cache\Store;

class CacheManager extends IlluminateCacheManager
{
    /**
     * Create a new cache repository with the given implementation.
     *
     * @param  \Illuminate\Contracts\Cache\Store $store
     * @return \Propaganistas\LaravelCacheKeywords\KeywordsRepository
     */
    public function repository(Store $store)
    {
        $repository = new KeywordsRepository($store);

        if ($this->app->bound('Illuminate\Contracts\Events\Dispatcher')) {
            $repository->setEventDispatcher(
                $this->app['Illuminate\Contracts\Events\Dispatcher']
            );
        }

        return $repository;
    }
}
