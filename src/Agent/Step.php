<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * One persisted turn of a conversation: the user's message, an assistant
 * reply (text or tool calls), or one tool result.
 */
final readonly class Step
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const TOOL = 'tool';

    /**
     * @param  list<array<string, mixed>>|null  $toolCalls  OpenAI tool_calls shape
     * @param  array<string, mixed>|null  $arguments  decoded tool arguments (tool steps)
     * @param  array<string, mixed>|null  $result  tool payload (tool steps)
     */
    public function __construct(
        public string $role,
        public ?string $content = null,
        public ?array $toolCalls = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public ?array $arguments = null,
        public ?array $result = null,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}

    public static function user(string $content): self
    {
        return new self(self::USER, $content);
    }

    /**
     * @param  list<array<string, mixed>>|null  $toolCalls
     */
    public static function assistant(?string $content, ?array $toolCalls = null, int $promptTokens = 0, int $completionTokens = 0): self
    {
        return new self(self::ASSISTANT, $content, $toolCalls ?: null, promptTokens: $promptTokens, completionTokens: $completionTokens);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     */
    public static function tool(string $toolCallId, string $toolName, array $arguments, array $payload): self
    {
        return new self(
            self::TOOL,
            (string) json_encode($payload),
            toolCallId: $toolCallId,
            toolName: $toolName,
            arguments: $arguments,
            result: $payload,
        );
    }

    public function isFinalAnswer(): bool
    {
        return $this->role === self::ASSISTANT && $this->toolCalls === null;
    }

    /**
     * The step as an OpenAI chat message for replay.
     *
     * @return array<string, mixed>
     */
    public function toMessage(): array
    {
        return match ($this->role) {
            self::ASSISTANT => array_filter([
                'role' => 'assistant',
                'content' => $this->content ?? '',
                'tool_calls' => $this->toolCalls,
            ], fn ($value) => $value !== null),
            self::TOOL => [
                'role' => 'tool',
                'tool_call_id' => $this->toolCallId,
                'content' => $this->content ?? '{}',
            ],
            default => ['role' => 'user', 'content' => $this->content ?? ''],
        };
    }
}
