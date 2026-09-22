<?php

namespace RobertBoes\Patchbay\Concerns;

use Laravel\Reverb\Loggers\Log;
use Throwable;

/**
 * For work scheduled on Reverb's event loop. An exception thrown from a
 * timer escapes the loop and stops the server, dropping every connection
 * over what is usually a passing database or cache hiccup. Reported and
 * swallowed, the next tick simply tries again.
 */
trait SurvivesFailures
{
    protected function survive(string $task, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);

            Log::error("Patchbay {$task} failed: {$exception->getMessage()}");
        }
    }
}
