<?php

namespace App\Services\OpenFoodFacts;

use RuntimeException;

/** OFF told us to slow down. Release the job for Retry-After seconds. */
class RateLimitedByRemote extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Rate limited by Open Food Facts, retry after {$retryAfter}s");
    }
}
