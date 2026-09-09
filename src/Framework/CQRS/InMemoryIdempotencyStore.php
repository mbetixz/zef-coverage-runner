<?php

declare(strict_types=1);

namespace Zef\Framework\CQRS;

final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string,array{expiresAt:int,result:mixed}> */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = 10000)
    {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('Idempotency store capacity must be positive.');
        }
    }

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Idempotency TTL must be positive.');
        }
        if ($key === '' || preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid idempotency key.');
        }
        $this->purgeExpired();
        if (isset($this->entries[$key])) {
            return $this->entries[$key]['result'];
        }
        $result = $producer();
        $this->purgeExpired();
        if (count($this->entries) >= $this->maxEntries) {
            $oldest = array_key_first($this->entries);
            if ($oldest !== null) {
                unset($this->entries[$oldest]);
            }
        }
        $this->entries[$key] = ['expiresAt' => time() + $ttlSeconds, 'result' => $result];
        return $result;
    }

    private function purgeExpired(): void
    {
        $now = time();
        foreach ($this->entries as $key => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->entries[$key]);
            }
        }
    }
}
