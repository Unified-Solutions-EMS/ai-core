<?php

declare(strict_types=1);

namespace Unified\AiCore\Tests\Fixtures;

use RuntimeException;
use Unified\AiCore\Agent\AgentContext;
use Unified\AiCore\Agent\Tool;
use Unified\AiCore\Agent\ToolFailure;
use Unified\AiCore\Agent\ToolResult;

class EchoTool implements Tool
{
    /** @var list<array{args: array<string, mixed>, context: AgentContext}> */
    public array $calls = [];

    public function name(): string
    {
        return 'echo';
    }

    public function definition(): array
    {
        return [
            'description' => 'Echo the text back.',
            'parameters' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
        ];
    }

    public function execute(array $args, AgentContext $context): ToolResult
    {
        $this->calls[] = ['args' => $args, 'context' => $context];

        return match ($args['text'] ?? null) {
            'refuse' => throw new ToolFailure('text may not be "refuse"'),
            'explode' => throw new RuntimeException('boom'),
            default => new ToolResult(['echo' => $args['text'] ?? null], ['rows' => 1]),
        };
    }
}
