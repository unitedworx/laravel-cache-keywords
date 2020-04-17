# Laravel Cache Keywords


Empowers Laravel's Cache with `keywords` behavior. Keywords differ from Laravel's built-in `tags` implementation in the following aspects:

* Cache records can be fetched *without* previously set keywords.

* All cache records marked with a keyword can be flushed at once, even though being marked by other keywords as well.

* Keywords work for all cache drivers.


### Installation

1. Install the package using composer

    ```bash
    composer require propaganistas/laravel-cache-keywords ~1.0
    ```

2. In your app config, add the Service Provider to the end of the `$providers` array

   **Laravel 5**
     ```php
    'providers' => [
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        ...
        Unitedworx\LaravelCacheKeywords\CacheKeywordsServiceProvider::class,
    ],
    ```

### Usage

The provided commands are analogous to `tags()`. Define keywords on cache write queries using the `keywords()` method fluently.
Provide an array of keywords or pass each keyword as a separate argument:

```php
Cache::keywords('keyword1', 'keyword2')->put('key1', 'value1', $minutes);
Cache::keywords(['keyword2', 'keyword3'])->put('key2', 'value2', $minutes);
```

By default keywords are overwritten each time a cache record is updated. If you want to *add* the keywords to an existing set, call `mergeKeywords()` instead of `keywords()`:
```php
Cache::mergeKeywords('addedKeyword1', 'addedKeyword2')->put('key1', 'updatedValue1', $minutes);
Cache::mergeKeywords(['addedKeyword1', 'addedKeyword2'])->put('key2', 'updatedValue2', $minutes);
```

Get a cache record easily without specifying its bound keywords:

```php
Cache::get('key1');
```

Flush all records marked with a specific (set of) keyword(s) using the `flush()` command:
```php
Cache::keywords('keyword2')->flush(); // 'key1' and 'key2' are both flushed.
Cache::keywords(['keyword1', 'keyword3'])->flush(); // 'key1' and 'key2' are both flushed.
```

---

**Notice**

This package features a slightly modified version of Laravel's built-in `Illuminate\Cache\CacheManager` class and injects it into the IoC container. If you are using a custom `CacheManager` of your own, please override its `repository()` method to use this package's `Repository` class.
