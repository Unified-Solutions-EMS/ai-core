<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

/**
 * Helpers for strict-mode JSON schemas (Responses text.format and strict
 * function tools): every property required, no extras, nullable as anyOf.
 */
final class JsonSchema
{
    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    public static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function nullable(array $schema): array
    {
        return ['anyOf' => [$schema, ['type' => 'null']]];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function arrayOf(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    public static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    /**
     * The Responses API `text.format` block for a strict schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function format(string $name, array $schema): array
    {
        return ['type' => 'json_schema', 'name' => $name, 'strict' => true, 'schema' => $schema];
    }
}
