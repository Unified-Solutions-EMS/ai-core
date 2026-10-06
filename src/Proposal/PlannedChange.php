<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

/**
 * One entry in a plan a person approves: which app and record or setting
 * changes, from what to what, why, and how risky it is.
 */
final readonly class PlannedChange
{
    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_UNKNOWN = 'unknown';

    public function __construct(
        public string $target,
        public string $entity,
        public mixed $before,
        public mixed $after,
        public string $why = '',
        public string $risk = self::RISK_UNKNOWN,
    ) {}

    /**
     * The fallback when a WriteTool does not describe its own change: the
     * tool name is the entity and the arguments are the "after".
     *
     * @param  array<string, mixed>  $args
     */
    public static function fromToolCall(string $target, string $toolName, array $args): self
    {
        return new self(
            target: $target,
            entity: $toolName,
            before: null,
            after: $args,
            why: is_string($args['why'] ?? null) ? $args['why'] : '',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            target: (string) ($data['target'] ?? ''),
            entity: (string) ($data['entity'] ?? ''),
            before: $data['before'] ?? null,
            after: $data['after'] ?? null,
            why: (string) ($data['why'] ?? ''),
            risk: (string) ($data['risk'] ?? self::RISK_UNKNOWN),
        );
    }

    /**
     * @return array{target: string, entity: string, before: mixed, after: mixed, why: string, risk: string}
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'entity' => $this->entity,
            'before' => $this->before,
            'after' => $this->after,
            'why' => $this->why,
            'risk' => $this->risk,
        ];
    }
}
