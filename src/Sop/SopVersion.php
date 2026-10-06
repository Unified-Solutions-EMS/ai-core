<?php

declare(strict_types=1);

namespace Unified\AiCore\Sop;

/**
 * One version of an agency's SOP for a domain, as served by SSO. A
 * candidate (being tested in a replay) has no id.
 */
final readonly class SopVersion
{
    public function __construct(
        public int|string|null $id,
        public string $domain,
        public string $body,
        public ?int $version = null,
        public int|string|null $sopId = null,
        public ?string $title = null,
        public ?string $activatedAt = null,
    ) {}

    public static function candidate(string $domain, string $body): self
    {
        return new self(id: null, domain: $domain, body: $body);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $domain): self
    {
        return new self(
            id: $data['id'] ?? null,
            domain: (string) ($data['domain'] ?? $domain),
            body: (string) ($data['body'] ?? ''),
            version: isset($data['version']) ? (int) $data['version'] : null,
            sopId: $data['sop_id'] ?? null,
            title: isset($data['title']) ? (string) $data['title'] : null,
            activatedAt: isset($data['activated_at']) ? (string) $data['activated_at'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'body' => $this->body,
            'version' => $this->version,
            'sop_id' => $this->sopId,
            'title' => $this->title,
            'activated_at' => $this->activatedAt,
        ];
    }

    public function isCandidate(): bool
    {
        return $this->id === null;
    }
}
