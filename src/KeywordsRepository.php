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
        $this->keywords = array();

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
        return $increment ? $this->_keywordsOperation ++ : $this->_keywordsOperation --;
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
    public function keywords($keywords = array(), $merge = false)
    {
        $keywords = is_array($keywords) ? $keywords : func_get_args();

        array_walk($keywords, function (&$value) {
            $value = is_object($value) ? get_class($value) : $value;
        });

        $this->keywords = array_unique($keywords);
        $this->mergeKeywords = $merge;

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

    /**
     * Checks if the given key collides with a keyword index and throws an exception.
     *
     * @param string $key
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    protected function checkReservedKeyPattern($key)
    {
        if (!$this->operatingOnKeywords() && preg_match('/^keyword(_index)?\[(.*)\]$/', $key)) {
            throw new ReservedCacheKeyPatternException($key);
        }
    }

    /**
     * Assembles a comparison of the provided keywords against the current state for a given key.
     *
     * @param  string $key
     * @param  array  $newKeywords
     * @param  array  $oldKeywords
     * @return array
     */
    protected function determineKeywordsState($key, array $newKeywords = array(), array $oldKeywords = array(), $force = false)
    {
        $this->setKeywordsOperation(true);

        static $state = array();

        if (!isset($state[$key]) || $force) {
            $old = empty($oldKeywords) ? parent::get($this->generateInverseIndexKey($key), []) : $oldKeywords;
            $new = $this->mergeKeywords ? array_unique(array_merge($old, $newKeywords)) : $newKeywords;
            $state[$key] = array(
                'old'      => $old,
                'new'      => $new,
                'obsolete' => array_diff($old, $new)
            );
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
    protected function storeKeywords($key, $minutes = null, array $keywords = array())
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
            $oldIndex = parent::pull($indexKey, []);
            $newIndex = in_array($keyword, $keywordsState['obsolete']) ?
                array_diff($oldIndex, [$key]) :
                array_unique(array_merge($oldIndex, [$key]));

            if (!empty($newIndex)) {
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
            is_null($minutes) ?
                parent::forever($inverseIndexKey, $keywordsState['new']) :
                parent::put($inverseIndexKey, $keywordsState['new'], $minutes);
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
            case 'keyword':
            default:
                $callback = function($givenKey) {
                    return parent::pull($this->generateIndexKey($givenKey), []);
                };
                break;
            case 'inverse':
                $callback = function($givenKey) {
                    return parent::pull($this->generateInverseIndexKey($givenKey), []);
                };
                break;
        }

        $affected = array();
        foreach ($givenKeys as $givenKey) {
            $affected = array_merge($affected, call_user_func($callback, $givenKey));
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
        if (!$this->operatingOnKeywords()) {
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
        if (!$this->operatingOnKeywords()) {
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
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function pull($key, $default = null)
    {
        $this->checkReservedKeyPattern($key);

        if (!$this->operatingOnKeywords()) {
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
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function put($key, $value, $minutes)
    {
        $this->checkReservedKeyPattern($key);

        $this->storeKeywords($key, $minutes);

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        parent::put($key, $value, $minutes);
    }

    /**
     * Store an item in the cache if the key does not exist.
     *
     * @param  string        $key
     * @param  mixed         $value
     * @param  \DateTime|int $minutes
     * @return bool
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
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
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function forever($key, $value)
    {
        $this->checkReservedKeyPattern($key);

        $this->storeKeywords($key);

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        parent::forever($key, $value);
    }

    /**
     * Get an item from the cache, or store the default value.
     *
     * @param  string        $key
     * @param  \DateTime|int $minutes
     * @param  \Closure      $callback
     * @return mixed
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function remember($key, $minutes, Closure $callback)
    {
        // Instead of using has() we directly implement the value getter
        // to avoid additional cache hits if the key exists.
        if (is_null($value = parent::get($key))) {
            $this->checkReservedKeyPattern($key);

            $this->storeKeywords($key, $minutes, $this->keywords);

            $value = parent::remember($key, $minutes, $callback);
        }

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return $value;
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @return mixed
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function sear($key, Closure $callback)
    {
        // Instead of using has() we directly implement the value getter
        // to avoid additional cache hits if the key exists.
        if (is_null($value = parent::get($key))) {
            $this->checkReservedKeyPattern($key);

            $this->storeKeywords($key, null, $this->keywords);

            $value = parent::sear($key, $callback);
        }

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return $value;
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string   $key
     * @param  \Closure $callback
     * @return mixed
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function rememberForever($key, Closure $callback)
    {
        // Instead of using has() we directly implement the value getter
        // to avoid additional cache hits if the key exists.
        if (is_null($value = parent::get($key))) {
            $this->checkReservedKeyPattern($key);

            $this->storeKeywords($key, null, $this->keywords);

            $value = parent::rememberForever($key, $callback);
        }

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return $value;
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string $key
     * @return bool
     * @throws \Propaganistas\LaravelCacheKeywords\Exceptions\ReservedCacheKeyPatternException
     */
    public function forget($key)
    {
        $this->checkReservedKeyPattern($key);

        if ($result = parent::forget($key)) {
            if (!$this->operatingOnKeywords()) {

                $affectedKeywords = $this->forgetInverseIndex($key);

                // Set all affected keywords as old keywords and request
                // empty new keywords to remove the flushed key from other indices as well.
                $this->determineKeywordsState($key, [], $affectedKeywords, true);
                $this->updateKeywordIndex($key);
            }
        }

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }

        return $result;
    }

    /**
     * Flushes traces of records related to the provided keywords, otherwise proceeds to regular flush() method.
     */
    public function flush()
    {
        if (!empty($this->keywords)) {
            $flushedKeys = $this->forgetKeywordIndex($this->keywords);

            $affectedKeywords = $this->forgetInverseIndex($flushedKeys);

            foreach ($flushedKeys as $flushedKey) {
                // Set all affected keywords as old keywords and request
                // empty new keywords to remove the flushed key from other indices as well.
                $this->determineKeywordsState($flushedKey, [], $affectedKeywords, true);
                $this->updateKeywordIndex($flushedKey);
                parent::forget($flushedKey);
            }
        } else {
            parent::flush();
        }

        if (!$this->operatingOnKeywords()) {
            $this->resetCurrentKeywords();
        }
    }
}
