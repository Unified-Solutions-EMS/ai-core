<?php

declare(strict_types=1);

namespace Unified\AiCore\Agent;

interface Tool
{
    public function name(): string;

    /**
     * OpenAI function-calling definition: description + JSON Schema params.
     *
     * @return array{description: string, parameters: array<string, mixed>, strict?: bool}
     */
    public function definition(): array;

    /**
     * @param  array<string, mixed>  $args  decoded model arguments; untrusted
     */
    public function execute(array $args, AgentContext $context): ToolResult;
}
