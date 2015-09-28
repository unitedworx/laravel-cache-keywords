# Laravel Cache Keywords

[![Build Status](https://travis-ci.org/Propaganistas/Laravel-Cache-Keywords.svg)](https://travis-ci.org/Propaganistas/Laravel-Cache-Keywords)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/Propaganistas/Laravel-Cache-Keywords/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/Propaganistas/Laravel-Cache-Keywords/?branch=master)
[![Latest Stable Version](https://poser.pugx.org/propaganistas/laravel-cache-keywords/v/stable)](https://packagist.org/packages/propaganistas/laravel-cache-keywords)
[![Total Downloads](https://poser.pugx.org/propaganistas/laravel-cache-keywords/downloads)](https://packagist.org/packages/propaganistas/laravel-cache-keywords)
[![License](https://poser.pugx.org/propaganistas/laravel-cache-keywords/license)](https://packagist.org/packages/propaganistas/laravel-cache-keywords)

Provides Laravel's Cache with `keywords` behavior. Keywords differ from Laravel's built-in `tags` implementation in the following aspects:

* Cache records can be fetched *without* previously set keywords.

* All cache records marked with a keyword can be flushed at once.

* Keywords work for all cache drivers.


### Installation

1. In the `require` key of `composer.json` file add the following

    ```json
    "propaganistas/laravel-cache-keywords": "~1.0"
    ```

2. Run the Composer update command

    ```bash
    $ composer update
    ```

3. In your app config, add the Service Provider to the end of the `$providers` array

   **Laravel 5**
     ```php
    'providers' => [
        App\Providers\EventServiceProvider::class,
        App\Providers\RouteServiceProvider::class,
        ...
        Propaganistas\LaravelCacheKeywords\CacheKeywordsServiceProvider::class,
    ],
    ```

### Usage

The provided commands are analogous to `tags()`. Define keywords on cache write queries using `keywords()` fluently:

```php
Cache::keywords('general')->put('ImportantKey', $importantValue, $minutes);
Cache::keywords(['general', 'user'])->put('MyUser', $userModel, $minutes);
```

Flush all records marked with a specific (set of) keyword(s) using the `flush()` command:
```php
// Deletes all records using the 'user' keyword
Cache::keywords('user')->flush();
// 'MyUser' is flushed, despite its other keyword.
```

Of course multiple keywords can be flushed at once if an array of keywords is provided.


### Notice

This package features a slightly modified version of Laravel's built-in `Illuminate\Cache\CacheManager` class and injects it into the IoC container. If you are using a custom `CacheManager` of your own, please override its `repository()` method to use this package's `Repository` class.