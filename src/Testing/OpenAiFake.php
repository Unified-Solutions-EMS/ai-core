<?php

declare(strict_types=1);

namespace Unified\AiCore\Testing;

use Illuminate\Support\Facades\Http;

/**
 * Canned OpenAI replies for app and package tests, served through
 * Http::fake() so no test can reach the vendor.
 *
 *   OpenAiFake::chat([
 *       OpenAiFake::toolCalls(['call_1' => ['list_sources', []]]),
 *       OpenAiFake::message('You ran 42 transports.'),
 *   ]);
 */
final class OpenAiFake
{
    /**
     * Queue /chat/completions replies in order. Other OpenAI endpoints
     * are left unfaked (and so fail loudly under preventStrayRequests).
     *
     * @param  list<array<string, mixed>>  $replies
     */
    public static function chat(array $replies): void
    {
        $sequence = Http::sequence();

        foreach ($replies as $reply) {
            $sequence->push($reply);
        }

        Http::fake(['*/chat/completions' => $sequence]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(string $content, int $promptTokens = 10, int $completionTokens = 5): array
    {
        return [
            'id' => 'chatcmpl-fake',
            'object' => 'chat.completion',
            'model' => 'gpt-fake',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens, 'total_tokens' => $promptTokens + $completionTokens],
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $calls  id => [tool name, arguments]
     * @return array<string, mixed>
     */
    public static function toolCalls(array $calls, ?string $content = null, int $promptTokens = 10, int $completionTokens = 5): array
    {
        $toolCalls = [];

        foreach ($calls as $id => [$name, $arguments]) {
            $toolCalls[] = [
                'id' => (string) $id,
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => (string) json_encode((object) $arguments)],
            ];
        }

        return [
            'id' => 'chatcmpl-fake',
            'object' => 'chat.completion',
            'model' => 'gpt-fake',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens, 'total_tokens' => $promptTokens + $completionTokens],
        ];
    }

    /**
     * A Responses API reply whose output text is $data encoded as JSON.
     *
     * @param  array<string, mixed>|string  $data
     * @return array<string, mixed>
     */
    public static function response(array|string $data, int $inputTokens = 100, int $outputTokens = 50): array
    {
        return [
            'id' => 'resp_fake',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'gpt-fake',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => is_string($data) ? $data : (string) json_encode($data)]],
            ]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    /**
     * Sent chat requests' decoded bodies, in order.
     *
     * @return list<array<string, mixed>>
     */
    public static function sentChatBodies(): array
    {
        return Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/chat/completions'))
            ->map(fn (array $pair): array => json_decode($pair[0]->body(), true) ?? [])
            ->values()
            ->all();
    }
}
