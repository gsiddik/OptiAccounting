<?php

namespace App\Domain\Integration\Services;

use RuntimeException;

/** The source's feed could not be read (unreachable, an error status, or a page that is not the agreed contract). */
class FeedException extends RuntimeException {}
