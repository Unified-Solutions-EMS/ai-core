<?php

declare(strict_types=1);

namespace Unified\AiCore\Phi;

/**
 * What may be told to the model vendor.
 *
 * Deliberately a different question from what a user may see on screen.
 * Someone allowed to view identified data sees it unmasked because that
 * is the product; handing the same values to a third party so it can
 * compose a sentence is a separate act with a separate risk, and it gets
 * its own setting (ai.phi.posture).
 *
 * Direct identifiers (columns that name a person) are blocked as a
 * filter, a sort or a breakdown on every posture but rows: a count of
 * calls per surname is a list of surnames however it is labelled.
 */
final class ModelDisclosure
{
    /** @var list<string> */
    private static array $extraIdentifiers = [];

    public static function posture(): PhiPosture
    {
        return PhiPosture::fromConfig(config('ai.phi.posture'));
    }

    public static function allowsRows(?PhiPosture $posture = null): bool
    {
        return ($posture ?? self::posture())->allowsRows();
    }

    public static function allowsIdentifiedRows(?PhiPosture $posture = null): bool
    {
        return ($posture ?? self::posture())->allowsIdentifiedRows();
    }

    /**
     * Register identifier columns an app derives at runtime (e.g. from a
     * NEMSIS field map), on top of config('ai.phi.direct_identifiers').
     *
     * @param  list<string>  $columns
     */
    public static function extend(array $columns): void
    {
        self::$extraIdentifiers = array_values(array_unique([...self::$extraIdentifiers, ...$columns]));
    }

    public static function flush(): void
    {
        self::$extraIdentifiers = [];
    }

    /**
     * @return list<string>
     */
    public static function directIdentifiers(): array
    {
        return array_values(array_unique([
            ...array_map('strval', (array) config('ai.phi.direct_identifiers', [])),
            ...self::$extraIdentifiers,
        ]));
    }

    public static function isDirectIdentifier(string $column): bool
    {
        return in_array($column, self::directIdentifiers(), true);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function directIdentifiersIn(array $columns): array
    {
        return array_values(array_intersect($columns, self::directIdentifiers()));
    }

    /**
     * Drop identified columns from rows the model will read, unless the
     * posture allows identified rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function redactRows(array $rows, ?PhiPosture $posture = null): array
    {
        if (self::allowsIdentifiedRows($posture)) {
            return $rows;
        }

        $blocked = array_flip(self::directIdentifiers());

        return array_map(fn (array $row): array => array_diff_key($row, $blocked), $rows);
    }

    /**
     * One plain sentence for the chat panel so nobody has to guess why
     * the assistant will not read them a patient's name.
     */
    public static function notice(string $appName, ?PhiPosture $posture = null): string
    {
        return match ($posture ?? self::posture()) {
            PhiPosture::Rows => "Questions are answered by a service outside {$appName}, which may be given identified patient data for anyone allowed to see it.",
            PhiPosture::AggregatesOnly => "Questions are answered by a service outside {$appName}, so it is only ever given counts and breakdowns, never individual records. Spreadsheets are built here and can include full detail.",
            PhiPosture::Redacted => "Questions are answered by a service outside {$appName}, so it is never given patient names, dates of birth, or social security numbers. Spreadsheets are built here and can include them.",
        };
    }
}
