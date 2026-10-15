<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Bite generation that failed for a reason fit to tell the person who asked.
 *
 * The message is posted into the chat verbatim, so it is only ever one Snack
 * wrote. Anything else that goes wrong mid-generation — a storage driver, a
 * database, a bug — carries text meant for a developer: bucket URLs, keys,
 * stack details. Those are reported and replaced with a generic line; see
 * {@see App\Jobs\GenerateBiteMedia}.
 */
class GenerationFailed extends RuntimeException {}
