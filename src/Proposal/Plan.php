<?php

declare(strict_types=1);

namespace Unified\AiCore\Proposal;

/**
 * What the agent proposes: a summary and the changes. Its hash is what an
 * approval binds to, so any edit to the plan invalidates a prior approval.
 */
final readonly class Plan
{
    /**
     * @param  list<PlannedChange>  $changes
     */
    public function __construct(
        public string $summary,
        public array $changes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            summary: (string) ($data['summary'] ?? ''),
            changes: array_values(array_map(
                fn (array $change): PlannedChange => PlannedChange::fromArray($change),
                array_filter((array) ($data['changes'] ?? []), 'is_array'),
            )),
        );
    }

    /**
     * @return array{summary: string, changes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'changes' => array_map(fn (PlannedChange $change): array => $change->toArray(), $this->changes),
        ];
    }

    /**
     * sha256 over a canonical encoding, so the same plan hashes the same
     * however it was assembled and after a JSON storage round trip: keys
     * are sorted at every depth and a float with no fractional part is
     * written as an integer (12.0 → 12), because json_decode() reads 12.0
     * back as int 12 once the plan has been stored.
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode(self::canonical($this->toArray()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_float($value)) {
            return self::isIntegral($value) ? (int) $value : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => self::canonical($item), $value);
    }

    /**
     * Beyond the int range a whole float stays a float: casting would
     * overflow, and json_encode() writes it in exponent form, which
     * json_decode() reads back as the same float.
     */
    private static function isIntegral(float $value): bool
    {
        return is_finite($value)
            && floor($value) === $value
            && $value >= PHP_INT_MIN
            && $value < PHP_INT_MAX;
    }
}
