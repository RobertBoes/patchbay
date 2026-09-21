<?php

namespace RobertBoes\Patchbay\Reload;

/**
 * A single change to a single application. Carrying the ID is the point: a
 * signal that only says "something changed" leaves the server no option but to
 * reload everything.
 */
class AppChange
{
    public const UPSERT = 'upsert';

    public const DELETE = 'delete';

    public function __construct(
        public readonly string $id,
        public readonly string $operation,
        public readonly int $version = 0,
    ) {
        //
    }

    public static function upsert(string $id, int $version = 0): self
    {
        return new self($id, self::UPSERT, $version);
    }

    public static function delete(string $id, int $version = 0): self
    {
        return new self($id, self::DELETE, $version);
    }

    public function isDelete(): bool
    {
        return $this->operation === self::DELETE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            (string) $payload['id'],
            (string) ($payload['operation'] ?? self::UPSERT),
            (int) ($payload['version'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'version' => $this->version,
        ];
    }

    public function withVersion(int $version): self
    {
        return new self($this->id, $this->operation, $version);
    }
}
