<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

/**
 * The parts of a /chat/completions reply the platform uses.
 */
final readonly class ChatCompletion
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public int $promptTokens,
        public int $completionTokens,
        public string $model,
    ) {}

    /**
     * @param  array<string, mixed>  $json
     */
    public static function fromResponse(array $json, string $requestedModel): self
    {
        $message = $json['choices'][0]['message'] ?? [];
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        $calls = is_array($message) ? ($message['tool_calls'] ?? []) : [];

        return new self(
            content: is_string($content) ? $content : null,
            toolCalls: array_values(array_map(
                fn (array $call): ToolCall => ToolCall::fromArray($call),
                array_filter(is_array($calls) ? $calls : [], 'is_array'),
            )),
            promptTokens: (int) ($json['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($json['usage']['completion_tokens'] ?? 0),
            model: is_string($json['model'] ?? null) ? $json['model'] : $requestedModel,
        );
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
