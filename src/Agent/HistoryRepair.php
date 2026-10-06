<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

/**
 * Makes stored history acceptable to the API before replay.
 *
 * 1. Truncating to the last N messages can strand tool results whose
 *    assistant tool_calls message fell outside the window; the API
 *    rejects those leading orphans, so they are dropped.
 * 2. A connection that dies mid-tool persists an assistant tool_calls
 *    message without its tool results. The API rejects that history on
 *    every later turn, bricking the conversation, so every unanswered
 *    call gets a synthetic "interrupted" result.
 */
final class HistoryRepair
{
    public const INTERRUPTED = 'This tool call was interrupted before it finished. Call the tool again if the result is still needed.';

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function repair(array $messages): array
    {
        while ($messages !== [] && ($messages[0]['role'] ?? null) === 'tool') {
            array_shift($messages);
        }

        return self::answerInterruptedToolCalls($messages);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public static function answerInterruptedToolCalls(array $messages): array
    {
        $healed = [];
        $pending = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'tool') {
                $pending = array_values(array_diff($pending, [$message['tool_call_id']]));
                $healed[] = $message;

                continue;
            }

            array_push($healed, ...self::syntheticResults($pending));
            $healed[] = $message;

            $pending = $message['role'] === 'assistant'
                ? array_map(fn (array $call): string => (string) $call['id'], $message['tool_calls'] ?? [])
                : [];
        }

        array_push($healed, ...self::syntheticResults($pending));

        return $healed;
    }

    /**
     * @param  list<string>  $toolCallIds
     * @return list<array<string, mixed>>
     */
    private static function syntheticResults(array $toolCallIds): array
    {
        return array_map(fn (string $id): array => [
            'role' => 'tool',
            'tool_call_id' => $id,
            'content' => json_encode(['error' => self::INTERRUPTED]),
        ], $toolCallIds);
    }
}
