<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

use JsonException;

/**
 * A Responses API reply. With a json_schema format, `data()` is the
 * decoded object the schema held the model to.
 */
final readonly class StructuredResponse
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        try {
            $decoded = json_decode($this->text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw AiException::unreadable('reply is not JSON: '.$e->getMessage());
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw AiException::unreadable('reply is not a JSON object');
        }

        return $decoded;
    }
}
