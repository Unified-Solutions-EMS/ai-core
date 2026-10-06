<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

/**
 * One function call the model asked for. `arguments` is the raw JSON
 * string exactly as the model produced it; replaying history must send it
 * back byte-for-byte.
 */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public string $arguments,
    ) {}

    /**
     * @param  array<string, mixed>  $call
     */
    public static function fromArray(array $call): self
    {
        return new self(
            id: (string) ($call['id'] ?? ''),
            name: (string) ($call['function']['name'] ?? ''),
            arguments: (string) ($call['function']['arguments'] ?? '{}'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function args(): array
    {
        $decoded = json_decode($this->arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{id: string, type: string, function: array{name: string, arguments: string}}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => $this->arguments,
            ],
        ];
    }
}
