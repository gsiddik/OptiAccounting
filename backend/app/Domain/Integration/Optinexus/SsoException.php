<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** A sign-in or logout-token refusal. The message is a stable machine code shown to the SPA (e.g. `access_denied`). */
class SsoException extends RuntimeException {}
