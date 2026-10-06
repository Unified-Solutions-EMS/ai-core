<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * A tool execution result. `payload` is JSON-encoded back to the model;
 * `ui` is a small blob streamed to the browser (download links, saved
 * report links, row counts) so the frontend can render rich affordances
 * without parsing model text.
 */
readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $ui
     */
    public function __construct(
        public array $payload,
        public ?array $ui = null,
    ) {}

    public static function error(string $message): self
    {
        return new self(['error' => $message]);
    }

    public function failed(): bool
    {
        return isset($this->payload['error']);
    }
}
