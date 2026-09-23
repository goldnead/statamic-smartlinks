<?php

namespace Goldnead\Smartlinks\Resolvers;

use RuntimeException;

/**
 * A service failed (as opposed to: it answered "not found"). Carries the
 * Resolution reason code, `http_error` or `rate_limited`. The chain catches
 * it per service, so one service down never stops the others.
 */
class ServiceError extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function http(string $service, int $status): self
    {
        return $status === 429
            ? new self(Resolution::RATE_LIMITED, "{$service}: 429")
            : new self(Resolution::HTTP_ERROR, "{$service}: HTTP {$status}");
    }
}
