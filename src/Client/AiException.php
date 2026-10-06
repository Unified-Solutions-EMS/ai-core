<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

use RuntimeException;
use Throwable;

/**
 * Every way a call to the model vendor can fail, as one exception with a
 * reason code callers can branch on (retry when busy, tell the user when
 * refused, hide the feature when not configured).
 */
class AiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAVAILABLE = 'unavailable';

    public const TIMED_OUT = 'timed_out';

    public const BUSY = 'busy';

    public const REJECTED = 'rejected';

    public const REFUSED = 'refused';

    public const INCOMPLETE = 'incomplete';

    public const UNREADABLE = 'unreadable';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'The AI service is not configured on this deployment.');
    }

    public static function unavailable(string $detail, ?Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, "The AI service is unavailable: {$detail}", $previous);
    }

    public static function timedOut(string $detail, ?Throwable $previous = null): self
    {
        return new self(self::TIMED_OUT, "The AI service timed out: {$detail}", $previous);
    }

    public static function busy(string $detail): self
    {
        return new self(self::BUSY, "The AI service is rate limiting requests: {$detail}");
    }

    public static function rejected(string $detail): self
    {
        return new self(self::REJECTED, "The AI service refused the request: {$detail}");
    }

    public static function refused(string $detail): self
    {
        return new self(self::REFUSED, "The model declined to answer: {$detail}");
    }

    public static function incomplete(string $detail): self
    {
        return new self(self::INCOMPLETE, "The model's reply was incomplete: {$detail}");
    }

    public static function unreadable(string $detail): self
    {
        return new self(self::UNREADABLE, "The model's reply could not be read: {$detail}");
    }
}
