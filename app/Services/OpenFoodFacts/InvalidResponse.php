<?php

namespace App\Services\OpenFoodFacts;

use RuntimeException;

/** Permanent for this request: 4xx other than 429. Fail the run. */
class InvalidResponse extends RuntimeException {}
