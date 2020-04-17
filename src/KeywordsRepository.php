<?php namespace Unitedworx\LaravelCacheKeywords;

use Closure;
use Illuminate\Cache\Repository as IlluminateRepository;
use Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException;

class KeywordsRepository extends IlluminateRepository
{
    /**
     * Internal storage for fluently selected keywords.
     *
     * @var array
     */
    protected $keywords = [];

    /**
     * Boolean indicating if the provided keywords should be added to existing keywords.
     * If false, keywords will be overwritten.
     *
     * @var bool
     */
    protected $mergeKeywords = false;

    /**
     * Internal increment variable to prevent infinite loop calls & self-deletion.
     *
     * @var int
     */
    private $_keywordsOperation = 0;

    /**
     * Resets the internal keywords storage.
     */
    protected function resetCurrentKeywords()
    {
        $this->keywords = [];

        $this->resetMergeKeywords();
    }

    /**
     * Resets the addKeywords boolean
     */
    protected function resetMergeKeywords()
    {
        $this->mergeKeywords = false;
    }

    /**
     * Increment or decrement the internal operations variable to set the nested operations level.
     * Returns the current nested level.
     *
     * @param  bool $increment
     * @return int
     */
    private function setKeywordsOperation($increment = true)
    {
        return $increment ? $this->_keywordsOperation++ : $this->_keywordsOperation--;
    }

    /**
     * Returns if the class is operating on keywords.
     *
     * @return bool
     */
    private function operatingOnKeywords()
    {
        return (bool) $this->_keywordsOperation;
    }

    /**
     * Fluently selects keywords.
     *
     * @param  mixed $keywords
     * @return $this
     */
    public function keywords($keywords = [])
    {
        $args = func_get_args();

        $this->mergeKeywords = (is_bool(end($args)) || is_int(end($args))) ? array_pop($args) : false;

        $keywords = is_array($keywords) ? $keywords : $args;

        array_walk($keywords, function (&$value) {
            $value = is_object($value) ? get_class($value) : $value;
        });

        $this->keywords = array_unique($keywords);

        return $this;
    }

    /**
     * Shortcut method to add keywords fluently.
     *
     * @param array $keywords
     * @return $this
     */
    public function mergeKeywords($keywords = [])
    {
        return call_user_func_array([$this, 'keywords'], array_merge(func_get_args(), [true]));
    }

    /**
     * Shortcut method to overwrite keywords fluently.
     *
     * @param array $keywords
     * @return $this
     */
    public function overwriteKeywords($keywords = [])
    {
        return call_user_func_array([$this, 'keywords'], func_get_args());
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

    /**
     * Checks if the given key collides with a keyword index and throws an exception.
     *
     * @param string $key
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    protected function checkReservedKeyPattern($key)
    {
        if (! $this->operatingOnKeywords() && preg_match('/^keyword(_index)?\[(.*)\]$/', $key)) {
            throw new ReservedCacheKeyPatternException($key);
        }
    }

    /**
     * Store an item in the cache using the specified method and arguments.
     *
     * @param  string $method
     * @param  array  $args
     * @return void
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    protected function writeCacheRecord($method, $args)
    {
        $this->checkReservedKeyPattern($args['key']);

        call_user_func_array([$this, 'storeKeywords'], array_only($args, ['key', 'minutes', 'keywords']));

        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        call_user_func_array(['parent', $method], array_values($args));
    }

    /**
     * Fetch or store a default item in the cache using the specified method and arguments.
     *
     * @param  string $method
     * @param  array  $args
     * @return mixed
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    protected function fetchDefaultCacheRecord($method, $args)
    {
        // Instead of using has() we directly implement the value getter
        // to avoid additional cache hits if the key exists.
        if (is_null($value = parent::get($args['key']))) {
            $this->checkReservedKeyPattern($args['key']);

            call_user_func_array([$this, 'storeKeywords'], array_only($args, ['key', 'minutes', 'keywords']));

            $this->setKeywordsOperation(true);

            $value = call_user_func_array(['parent', $method], array_values($args));

            $this->setKeywordsOperation(false);
        }

        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return $value;
    }

    /**
     * Assembles a comparison of the provided keywords against the current state for a given key.
     *
     * @param  string $key
     * @param  array  $newKeywords
     * @param  array  $oldKeywords
     * @return array
     */
    protected function determineKeywordsState($key, array $newKeywords = [], array $oldKeywords = [])
    {
        $this->setKeywordsOperation(true);

        static $state = [];

        // Build state if:
        // - not built yet
        // - $newKeywords or $oldKeywords is provided
        if (! isset($state[$key]) || func_num_args() > 1) {
            $old = empty($oldKeywords) ? parent::get($this->generateInverseIndexKey($key), []) : $oldKeywords;
            $new = $this->mergeKeywords ? array_unique(array_merge($old, $newKeywords)) : $newKeywords;
            $state[$key] = [
                'old'      => $old,
                'new'      => $new,
                'obsolete' => array_diff($old, $new),
            ];
        }

        $this->setKeywordsOperation(false);

        return $state[$key];
    }

    /**
     * Stores the defined keywords for the provided key.
     *
     * @param string             $key
     * @param \DateTime|int|null $minutes
     * @param array              $keywords
     */
    protected function storeKeywords($key, $minutes = null, array $keywords = [])
    {
        $keywords = empty($keywords) ? $this->keywords : $keywords;

        $this->determineKeywordsState($key, $keywords);

        $this->updateKeywordIndex($key);

        $this->updateInverseIndex($key, $minutes);
    }

    /**
     * Updates keyword indices for the given cache key.
     */
    protected function updateKeywordIndex($key)
    {
        $this->setKeywordsOperation(true);

        $keywordsState = $this->determineKeywordsState($key);

        foreach (array_merge($keywordsState['new'], $keywordsState['obsolete']) as $keyword) {
            $indexKey = $this->generateIndexKey($keyword);
            $oldIndex = parent::get($indexKey, []);
            $newIndex = in_array($keyword, $keywordsState['obsolete']) ?
                array_values(array_diff($oldIndex, [$key])) :
                array_values(array_unique(array_merge($oldIndex, [$key])));

            if (! empty($newIndex)) {
                parent::forever($indexKey, $newIndex);
            }
        }

        $this->setKeywordsOperation(false);
    }

    /**
     * Updates the inverse index for the given cache key.
     *
     * @param  string             $key
     * @param  \DateTime|int|null $minutes
     */
    protected function updateInverseIndex($key, $minutes = null)
    {
        $this->setKeywordsOperation(true);

        $keywordsState = $this->determineKeywordsState($key);

        $inverseIndexKey = $this->generateInverseIndexKey($key);

        if (empty($keywordsState['new'])) {
            parent::forget($inverseIndexKey);
        } elseif ($keywordsState['old'] != $keywordsState['new']) {
            $newInverseIndex = array_values($keywordsState['new']);
            is_null($minutes) ?
                parent::forever($inverseIndexKey, $newInverseIndex) :
                parent::put($inverseIndexKey, $newInverseIndex, $minutes);
        }

        $this->setKeywordsOperation(false);
    }

    /**
     * Forgets the index of (a) given keyword(s).
     * Returns all containing cache keys.
     *
     * @param  string|array $keywords
     * @return array
     */
    protected function forgetKeywordIndex($keywords)
    {
        return $this->forgetIndexType('keyword', $keywords);
    }

    /**
     * Forgets the inverse index of (a) given cache key(s).
     * Returns all containing keywords.
     *
     * @param  string|array $keys
     * @return array
     */
    protected function forgetInverseIndex($keys)
    {
        return $this->forgetIndexType('inverse', $keys);
    }

    /**
     * Forgets the given index of (a) given key(s).
     * Returns all containing keys.
     *
     * @param  string       $type
     * @param  string|array $givenKeys
     * @return array
     */
    protected function forgetIndexType($type, $givenKeys)
    {
        $this->setKeywordsOperation(true);

        $givenKeys = is_array($givenKeys) ? $givenKeys : func_get_args();

        switch ($type) {
            case 'inverse':
                $getKeyCallback = function ($givenKey) {
                    return $this->generateInverseIndexKey($givenKey);
                };
                break;
            case 'keyword':
            default:
                $getKeyCallback = function ($givenKey) {
                    return $this->generateIndexKey($givenKey);
                };
                break;
        }

        $affected = [];
        foreach ($givenKeys as $givenKey) {
            $affected = array_merge($affected, parent::pull(call_user_func($getKeyCallback, $givenKey), []));
        }

        $this->setKeywordsOperation(false);

        return array_unique($affected);
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @param  string $key
     * @return bool
     */
    public function has($key)
    {
        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

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
        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return parent::get($key, $default);
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function pull($key, $default = null)
    {
        $this->checkReservedKeyPattern($key);

        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return parent::pull($key, $default);
    }

    /**
     * Store an item in the cache.
     *
     * @param  string        $key
     * @param  mixed         $value
     * @param  \DateTime|int $minutes
     * @return void
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function put($key, $value, $minutes)
    {
        $this->writeCacheRecord(__FUNCTION__, compact('key', 'value', 'minutes'));
    }

    /**
     * Store an item in the cache if the key does not exist.
     *
     * @param  string        $key
     * @param  mixed         $value
     * @param  \DateTime|int $minutes
     * @return bool
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function add($key, $value, $minutes)
    {
        $this->checkReservedKeyPattern($key);

        $this->setKeywordsOperation(true);

        if ($result = parent::add($key, $value, $minutes)) {
            $this->storeKeywords($key, $minutes, $this->keywords);
        }

        $this->setKeywordsOperation(false);

        $this->resetCurrentKeywords();

        return $result;

    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function forever($key, $value)
    {
        $this->writeCacheRecord(__FUNCTION__, compact('key', 'value'));
    }

    /**
     * Get an item from the cache, or store the default value.
     *
     * @param  string        $key
     * @param  \DateTime|int $minutes
     * @param  \Closure      $callback
     * @return mixed
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function remember($key, $minutes, Closure $callback)
    {
        return $this->fetchDefaultCacheRecord(__FUNCTION__, compact('key', 'minutes', 'callback'));
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @return mixed
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function sear($key, Closure $callback)
    {
        return $this->fetchDefaultCacheRecord(__FUNCTION__, compact('key', 'callback'));
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @return mixed
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function rememberForever($key, Closure $callback)
    {
        return $this->fetchDefaultCacheRecord(__FUNCTION__, compact('key', 'callback'));
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string $key
     * @return bool
     * @throws \Unitedworx\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function forget($key)
    {
        $this->checkReservedKeyPattern($key);

        if ($result = parent::forget($key)) {
            if (! $this->operatingOnKeywords()) {

                $affectedKeywords = $this->forgetInverseIndex($key);

                // Set all affected keywords as old keywords and request
                // empty new keywords to remove the flushed key from other indices as well.
                $this->determineKeywordsState($key, [], $affectedKeywords);
                $this->updateKeywordIndex($key);
            }
        }

        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return $result;
    }

    /**
     * Flushes traces of records related to the provided keywords, otherwise proceeds to regular flush() method.
     */
    public function flush()
    {
        if (! empty($this->keywords)) {
            $flushedKeys = $this->forgetKeywordIndex($this->keywords);

            $affectedKeywords = $this->forgetInverseIndex($flushedKeys);

            foreach ($flushedKeys as $flushedKey) {
                // Set all affected keywords as old keywords and request
                // empty new keywords to remove the flushed key from other indices as well.
                $this->determineKeywordsState($flushedKey, [], $affectedKeywords);
                $this->updateKeywordIndex($flushedKey);
                parent::forget($flushedKey);
            }
        } else {
            parent::flush();
        }

        if (! $this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }
    }
}
