<?php

declare(strict_types=1);

namespace Unified\AiCore\Client;

/**
 * Builders for Responses API input items, so callers do not hand-write
 * the content-part shapes.
 */
final class Input
{
    /**
     * @param  list<array<string, mixed>>|string  $content
     * @return array<string, mixed>
     */
    public static function message(string $role, array|string $content): array
    {
        return [
            'role' => $role,
            'content' => is_string($content) ? [self::text($content)] : $content,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|string  $content
     * @return array<string, mixed>
     */
    public static function user(array|string $content): array
    {
        return self::message('user', $content);
    }

    /**
     * @return array{type: string, text: string}
     */
    public static function text(string $text): array
    {
        return ['type' => 'input_text', 'text' => $text];
    }

    /**
     * An image by URL or data: URL.
     *
     * @return array{type: string, image_url: string, detail: string}
     */
    public static function image(string $url, string $detail = 'auto'): array
    {
        return ['type' => 'input_image', 'image_url' => $url, 'detail' => $detail];
    }

    /**
     * An image from raw bytes (screenshots, scanned pages), sent inline as
     * a data URL so nothing is uploaded to the vendor's file store.
     *
     * @return array{type: string, image_url: string, detail: string}
     */
    public static function imageBytes(string $bytes, string $mimeType, string $detail = 'auto'): array
    {
        return self::image('data:'.$mimeType.';base64,'.base64_encode($bytes), $detail);
    }
}
