<?php

namespace App\Services\OpenFoodFacts;

use RuntimeException;

/** Transient: 5xx, timeouts, maintenance pages. Retry with backoff. */
class RemoteUnavailable extends RuntimeException {}
