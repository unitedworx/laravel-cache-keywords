<?php namespace Propaganistas\LaravelCacheKeywords\Tests;

use Illuminate\Foundation\Testing\TestCase;
use Propaganistas\LaravelCacheKeywords\ServiceProvider;
use Propaganistas\LaravelCacheKeywords\CacheManager;

class CacheKeywordsTest extends TestCase
{

    /**
     * Laravel's Cache implementation.
     *
     * @var
     */
    protected $cache;

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../../../../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app->register(ServiceProvider::class);

        return $app;
    }

    public function setUp()
    {
        parent::setUp();

        $this->cache = $this->app->make('cache');

        $this->cache->keywords(['keyword1', 'keyword2'])->put('key1', 'value1', 60);
        $this->cache->keywords(['keyword2', 'keyword3'])->add('key2', 'value2', 60);
        $this->cache->keywords(['keyword3', 'keyword4'])->forever('key3', 'value3');
        $this->cache->keywords(['keyword4'])->rememberForever('key4', function() {
            return 'value4';
        });
        $this->cache->keywords(['keyword5'])->sear('key5', function() {
            return 'value5';
        });
    }

    public function tearDown()
    {
        $this->cache->flush();

        parent::tearDown();
    }

    public function testValueIsStillInsertedInCache()
    {
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->remember('key2', 60, function() { return []; }));
        $this->assertEquals('value3', $this->cache->pull('key3'));
        $this->assertEquals('value4', $this->cache->get('key4'));
        $this->assertEquals('value5', $this->cache->get('key5'));
    }

    public function testKeywordIndicesAreInsertedInCache()
    {
        $this->assertEquals(['key1'], $this->cache->get('keyword[keyword1]'));
        $this->assertEquals(['key1', 'key2'], $this->cache->get('keyword[keyword2]'));
        $this->assertEquals(['key2', 'key3'], $this->cache->get('keyword[keyword3]'));
        $this->assertEquals(['key3', 'key4'], $this->cache->get('keyword[keyword4]'));

        $this->assertEquals(['keyword1', 'keyword2'], $this->cache->get('keyword_index[key1]'));
        $this->assertEquals(['keyword2', 'keyword3'], $this->cache->get('keyword_index[key2]'));
        $this->assertEquals(['keyword3', 'keyword4'], $this->cache->get('keyword_index[key3]'));
        $this->assertEquals(['keyword4'], $this->cache->get('keyword_index[key4]'));
    }

    public function testFlush()
    {
        $this->cache->keywords('keyword2')->flush();
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertTrue($this->cache->has('key3'));
        $this->assertTrue($this->cache->has('key4'));

        $this->assertFalse($this->cache->has('keyword[keyword1]'));
        $this->assertFalse($this->cache->has('keyword[keyword2]'));
        $this->assertEquals(['key3'], array_values($this->cache->get('keyword[keyword3]')));
        $this->assertEquals(['key3', 'key4'], array_values($this->cache->get('keyword[keyword4]')));

        $this->assertFalse($this->cache->has('keyword_index[key1]'));
        $this->assertFalse($this->cache->has('keyword_index[key2]'));
        $this->assertEquals(['keyword3', 'keyword4'], $this->cache->get('keyword_index[key3]'));
        $this->assertEquals(['keyword4'], $this->cache->get('keyword_index[key4]'));

        $this->cache->flush();
        $this->assertFalse($this->cache->has('key3'));
        $this->assertFalse($this->cache->has('key4'));
    }
}