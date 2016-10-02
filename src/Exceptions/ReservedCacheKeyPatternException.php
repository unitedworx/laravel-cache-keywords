<?php namespace Propaganistas\LaravelCacheKeywords\Exceptions;

use Exception;

class ReservedCacheKeyPatternException extends Exception
{
    /**
     * ReservedCacheKeyPatternException constructor.
     *
     * @param string          $key
     * @param int             $code
     * @param \Exception|null $previous
     */
    public function __construct($key, $code = 0, Exception $previous = null)
    {
        parent::__construct('"' . $key . '" is a reserved cache key pattern for keywords indices.', $code, $previous);
    }

}
