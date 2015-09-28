<?php namespace Propaganistas\LaravelCacheKeywords;

use Closure;
use Illuminate\Cache\Repository as IRepository;
use Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException;

class KeywordsRepository extends IRepository
{

    /**
     * Internal storage for fluently selected keywords.
     *
     * @var array
     */
    protected $keywords = array();

    /**
     * Internal variable to prevent infinite loop calls & self-deletion.
     *
     * @var bool
     */
    private $_keywordsOperation = false;

    /**
     * Fluently selects keywords.
     *
     * @param  mixed $keywords
     * @return $this
     */
    public function keywords($keywords)
    {
        $keywords = is_array($keywords) ? $keywords : func_get_args();

        array_walk($keywords, function (&$value) {
            $value = is_object($value) ? get_class($value) : $value;
        });

        $this->keywords = $keywords;

        return $this;
    }

    /**
     * Generates the index cache key for a keyword.
     *
     * @param  string $keyword
     * @return string
     */
    protected function generateIndexKey($keyword)
    {
        return 'keyword[' . $keyword . ']';
    }

    /**
     * Generates the inverse index cache key for a cache key.
     *
     * @param  string $cacheKey
     * @return string
     */
    protected function generateInverseIndexKey($cacheKey)
    {
        return 'keyword_index[' . $cacheKey . ']';
    }

    protected function checkReservedKeyPattern($key)
    {
        if (!$this->_keywordsOperation && preg_match('/^keyword(_index)?\[(.*)\]$/', $key)) {
            throw new ReservedCacheKeyPatternException($key);
        }
    }

    /**
     * Stores the defined keywords for the provided key.
     *
     * @param string $key
     * @param null   $minutes
     * @param array  $keywords
     */
    protected function storeKeywords($key, $minutes = null, array $keywords = array())
    {
        $keywords = empty($keywords) ? $this->keywords : $keywords;

        // Store keyword index.
        foreach ($keywords as $keyword) {
            $indexKey = $this->generateIndexKey($keyword);
            $index = parent::get($indexKey, []);
            $index = array_merge($index, [$key]);
            parent::forever($indexKey, $index);
        }

        // Store inverse index
        $inverseIndexKey = $this->generateInverseIndexKey($key);
        $inverseIndex = parent::get($inverseIndexKey, []);
        $inverseIndex = array_merge($inverseIndex, $keywords);
        is_null($minutes) ?
            parent::forever($inverseIndexKey, $inverseIndex) :
            parent::put($inverseIndexKey, $inverseIndex, $minutes);
    }

    /**
     * Resets the internal keywords storage.
     */
    protected function resetCurrentKeywords()
    {
        $this->keywords = array();
    }

    /**
     * Flushes keywords indices if keywords were provided, otherwise proceeds to regular flush() method.
     */
    public function flush()
    {
        if (!empty($this->keywords)) {
            $flushedKeys = $this->flushKeywordsIndex();
            $this->removeTracesInKeywordsIndices($flushedKeys);
        } else {
            parent::flush();
        }

        $this->resetCurrentKeywords();
    }

    /**
     * Flushes all keys associated with the current keywords and also removes the keyword index.
     * Returns the flushed keys.
     *
     * @return array
     */
    protected function flushKeywordsIndex()
    {
        $this->_keywordsOperation = true;

        $flushedKeys = array();
        foreach ($this->keywords as $keyword) {
            $markedCaches = parent::pull($this->generateIndexKey($keyword), []);
            foreach ($markedCaches as $markedCacheKey) {
                $flushedKeys[] = $markedCacheKey;
                // Clear cache key.
                parent::forget($markedCacheKey);
            }
        }

        $this->_keywordsOperation = false;

        return $flushedKeys;
    }

    /**
     * Removes the provided keys from inverse indices.
     *
     * @param array $keys
     */
    protected function removeTracesInKeywordsIndices(array $keys)
    {
        $this->_keywordsOperation = true;

        foreach ($keys as $key) {
            $inverseIndexKey = $this->generateInverseIndexKey($key);
            // Delete inverse index.
            $inverseIndex = parent::pull($inverseIndexKey, []);
            // Remove key from affected indices.
            foreach ($inverseIndex as $affectedKeyword) {
                $indexKey = $this->generateIndexKey($affectedKeyword);
                $index = parent::pull($indexKey, []);
                $index = array_diff($index, [$key]);
                if (!empty($index)) {
                    parent::forever($indexKey, $index);
                }
            }
        }

        $this->_keywordsOperation = false;
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @param  string $key
     * @return bool
     */
    public function has($key)
    {
        $this->resetCurrentKeywords();

        return parent::has($key);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $this->resetCurrentKeywords();

        return parent::get($key, $default);
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public function pull($key, $default = null)
    {
        $this->checkReservedKeyPattern($key);

        $this->resetCurrentKeywords();

        return parent::pull($key, $default);
    }

    /**
     * Store an item in the cache.
     *
     * @param  string        $key
     * @param  mixed         $value
     * @param  \DateTime|int $minutes
     * @return void
     */
    public function put($key, $value, $minutes)
    {
        $this->checkReservedKeyPattern($key);

        $this->storeKeywords($key, $minutes);

        $this->resetCurrentKeywords();

        parent::put($key, $value, $minutes);
    }

    /**
     * Store an item in the cache if the key does not exist.
     *
     * @param  string        $key
     * @param  mixed         $value
     * @param  \DateTime|int $minutes
     * @return bool
     */
    public function add($key, $value, $minutes)
    {
        $this->checkReservedKeyPattern($key);

        $keywords = $this->keywords;
        if ($result = parent::add($key, $value, $minutes)) {
            $this->storeKeywords($key, $minutes, $keywords);
        }

        $this->resetCurrentKeywords();

        return $result;

    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public function forever($key, $value)
    {
        $this->checkReservedKeyPattern($key);

        $this->storeKeywords($key);

        $this->resetCurrentKeywords();

        parent::forever($key, $value);
    }

    /**
     * Get an item from the cache, or store the default value.
     *
     * @param  string        $key
     * @param  \DateTime|int $minutes
     * @param  \Closure      $callback
     * @return mixed
     */
    public function remember($key, $minutes, Closure $callback)
    {
        $keywords = $this->keywords;
        if (!parent::has($key)) {
            $this->checkReservedKeyPattern($key);

            $this->storeKeywords($key, $minutes, $keywords);
        }

        $this->resetCurrentKeywords();

        return parent::remember($key, $minutes, $callback);
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @return mixed
     */
    public function sear($key, Closure $callback)
    {
        $keywords = $this->keywords;
        if (!parent::has($key)) {
            $this->checkReservedKeyPattern($key);

            $this->storeKeywords($key, null, $keywords);
        }

        $this->resetCurrentKeywords();

        return parent::sear($key, $callback);
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @return mixed
     */
    public function rememberForever($key, Closure $callback)
    {
        $keywords = $this->keywords;
        if (!parent::has($key)) {
            $this->checkReservedKeyPattern($key);

            $this->storeKeywords($key, null, $keywords);
        }

        $this->resetCurrentKeywords();

        return parent::rememberForever($key, $callback);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string $key
     * @return bool
     */
    public function forget($key)
    {
        $this->checkReservedKeyPattern($key);

        $this->resetCurrentKeywords();

        if ($result = parent::forget($key)) {
            if (!$this->_keywordsOperation) {
                $this->removeTracesInKeywordsIndices([$key]);
            }
        }

        return $result;
    }
}
