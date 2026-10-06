<?php

declare(strict_types=1);

namespace Unified\AiCore\Phi;

/**
 * What a tool may put into the model's context from a data source.
 *
 *   Redacted        default. Row-level results pass, but PHI columns are
 *                   withheld. A no-op for sources with no PHI columns.
 *   AggregatesOnly  no individual rows at all; counts and breakdowns only.
 *   Rows            full row access. Only once the BAA (API + zero data
 *                   retention) is confirmed to cover the deployed model.
 *
 * Files rendered server-side and streamed to the user (spreadsheets) are
 * outside this entirely: their contents never enter a prompt.
 */
enum PhiPosture: string
{
    case Redacted = 'redacted';
    case AggregatesOnly = 'aggregates_only';
    case Rows = 'rows';

    /**
     * Anything unrecognised, including null, reads as the safest posture.
     */
    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::Redacted) : self::Redacted;
    }

    public function allowsRows(): bool
    {
        return $this !== self::AggregatesOnly;
    }

    public function allowsIdentifiedRows(): bool
    {
        return $this === self::Rows;
    }
}
