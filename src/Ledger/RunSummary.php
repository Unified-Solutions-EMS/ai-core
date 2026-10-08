<?php

declare(strict_types=1);

namespace Unified\AiCore\Ledger;

use InvalidArgumentException;

/**
 * The one line SSO's run index shows for a run, built by the package so
 * no app can put free text (and with it PHI) into the index.
 *
 * An app supplies a domain label and a format string whose only variable
 * parts are integer placeholders, e.g.
 *
 *   label    "CAD dispatch"
 *   template "{trips} trips, {assigned} assigned, {unassignable} unassignable"
 *   counts   ['trips' => 3, 'assigned' => 3, 'unassignable' => 0]
 *
 * which renders "CAD dispatch: 3 trips, 3 assigned, 0 unassignable"; the
 * package appends the outcome when it registers the run
 * ("…, approved"). Label and template are limited to letters, digits,
 * spaces and light punctuation, so neither can carry a name, an address
 * or a sentence lifted from the input.
 */
final class RunSummary
{
    private const SAFE_TEXT = '/^[A-Za-z0-9 ,.;:()\/_-]{1,60}$/';

    private const SAFE_TEMPLATE = '/^[A-Za-z0-9 ,.;:()\/_{}-]{1,160}$/';

    private const PLACEHOLDER = '/\{([a-z][a-z0-9_]*)\}/';

    public static function assertLabel(string $label): void
    {
        if (! preg_match(self::SAFE_TEXT, $label)) {
            throw new InvalidArgumentException("Run summary label '{$label}' may only use letters, digits, spaces and , . ; : ( ) / _ - (max 60 characters).");
        }
    }

    public static function assertTemplate(string $template): void
    {
        $literal = (string) preg_replace(self::PLACEHOLDER, '', $template);

        if (! preg_match(self::SAFE_TEMPLATE, $template) || str_contains($literal, '{') || str_contains($literal, '}')) {
            throw new InvalidArgumentException("Run summary template '{$template}' may only use letters, digits, spaces, light punctuation and {integer_placeholders} (max 160 characters).");
        }
    }

    /**
     * Counts are checked at runtime, not just by type: this is the line
     * that keeps app data out of SSO's index.
     *
     * @param  array<string, mixed>  $counts
     */
    public static function render(string $label, string $template, array $counts): string
    {
        self::assertLabel($label);
        self::assertTemplate($template);

        $body = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($counts, $template): string {
            $name = $match[1];

            if (! array_key_exists($name, $counts)) {
                throw new InvalidArgumentException("Run summary template '{$template}' uses {{$name}} but no count was supplied for it.");
            }

            if (! is_int($counts[$name])) {
                throw new InvalidArgumentException("Run summary count '{$name}' must be an integer.");
            }

            return (string) $counts[$name];
        }, $template);

        return $label.': '.$body;
    }

    /**
     * The label alone, for runs that produced nothing to count.
     */
    public static function labelOnly(string $label): string
    {
        self::assertLabel($label);

        return $label;
    }

    /**
     * What is sent to SSO: the stored summary plus the run's outcome. A
     * stored value that is not package-shaped (written outside the
     * recorder) is replaced by the domain alone.
     */
    public static function forIndex(?string $stored, string $domain, string $outcome): string
    {
        $safe = $stored !== null && preg_match('/^[A-Za-z0-9 ,.;:()\/_-]{1,255}$/', $stored)
            ? $stored
            : (preg_match(self::SAFE_TEXT, $domain) ? $domain : 'AI run');

        return $safe.', '.$outcome;
    }
}
