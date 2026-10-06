<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

/**
 * A Whisper transcription. Segments are present for verbose_json replies.
 */
final readonly class Transcript
{
    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public function __construct(
        public string $text,
        public array $segments,
        public string $model,
        public ?float $duration = null,
    ) {}
}
