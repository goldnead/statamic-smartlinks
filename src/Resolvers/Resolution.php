<?php

namespace Goldnead\Smartlinks\Resolvers;

/**
 * One resolver's answer for one song: a URL, or the reason there is none.
 */
final class Resolution
{
    public const FOUND = 'found';

    public const NOT_CONFIGURED = 'not_configured';

    public const MISSING_INPUT = 'missing_input';

    public const NOT_FOUND = 'not_found';

    public const NO_CONFIDENT_MATCH = 'no_confident_match';

    public const HTTP_ERROR = 'http_error';

    public function __construct(
        public readonly string $platform,
        public readonly string $reason,
        public readonly ?string $url = null,
    ) {}

    public static function found(string $platform, string $url): self
    {
        return new self($platform, self::FOUND, $url);
    }

    public static function none(string $platform, string $reason): self
    {
        return new self($platform, $reason);
    }

    public function successful(): bool
    {
        return $this->reason === self::FOUND && $this->url !== null;
    }
}
