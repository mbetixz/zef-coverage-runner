<?php

declare(strict_types=1);

namespace Zef\Framework\Security {

    /**
     * Redis-backed shared rate-limit store (audit M-2).
     *
     * Fixed-window counters are kept atomic across all RoadRunner workers and
     * hosts using ONLY native Redis commands (INCR + EXPIRE + TTL + GET).
     *
     * Remediasi static-policy (2026-09): implementasi lama menjalankan skrip
     * Lua via Redis::eval(), dan upaya pertama penggantian dengan
     * WATCH/MULTI/EXEC juga ditolak gate karena nama method "exec" sama-sama
     * masuk daftar fungsi proses/eksekusi-dinamis terlarang di
     * tools/quality/StaticPolicyScanner.php (tanpa mekanisme allow-list).
     * Keduanya diganti pola INCR+EXPIRE berikut:
     *   - INCR bersifat atomik di sisi Redis, jadi agregat count tetap eksak
     *     untuk N worker/host konkuren (sifat yang sama seperti skrip Lua lama).
     *   - TTL key menyatakan sisa window; "reset" diturunkan dari TTL sehingga
     *     kontrak increment()/peek() (count + reset epoch) tetap terpenuhi.
     *   - Satu key per logical bucket (PREFIX + sha256(key)), format sama
     *     seperti implementasi Lua (counter integer dengan TTL), sehingga
     *     bucket yang sudah ada tetap terbaca dengan benar.
     *
     * Window semantics: bucket dimulai pada request pertama (TTL = window),
     * identik dengan perilaku Lua lama (reset = now + window saat bucket baru).
     * Race di perbatasan window (INCR tepat sebelum TTL habis) adalah batas
     * yang dikenal dari pola INCR+EXPIRE dan tidak mengubah agregat per window.
     *
     * Requires ext-redis and a reachable Redis (sama seperti sebelumnya).
     */
    final class RedisSharedRateLimitStore implements SharedRateLimitStoreInterface
    {
        private const string PREFIX = 'zef:ratelimit:';

        public function __construct(private readonly \Redis $redis)
        {
        }

        #[\Override]
        public function increment(string $key, int $windowSeconds, int $now): array
        {
            if ($windowSeconds < 1) {
                throw new \InvalidArgumentException('windowSeconds must be >= 1.');
            }
            $bucketKey = self::PREFIX . hash('sha256', $key);

            // INCR atomik: satu-satunya titik tulis. Aman untuk semua worker
            // yang berbagi bucket yang sama (agregat eksak tanpa eval/exec).
            // Helper redisInt() menormalkan return extension (int|string) dan
            // gagal fail-closed bila Redis mengembalikan tipe tak terduga.
            $count = $this->redisInt($this->redis->incr($bucketKey), 'INCR');

            // Baca sisa TTL untuk menurunkan epoch reset. Nilai -1 berarti key
            // tanpa TTL (kemungkinan crash antara INCR dan EXPIRE); nilai < 0
            // lainnya diperlakukan sama (recovery dengan TTL window penuh).
            $ttl = $this->redisInt($this->redis->ttl($bucketKey), 'TTL');
            if ($count === 1 || $ttl < 0) {
                // Bucket baru (count == 1) -> pasang TTL window penuh.
                // Bucket tanpa TTL yang konsisten -> perbaiki dengan TTL baru.
                $this->redis->expire($bucketKey, $windowSeconds);
                $reset = $now + $windowSeconds;
            } else {
                // Bucket sudah ada -> reset = waktu expire absolut sekarang
                // (now + sisa TTL), setara epoch reset pada implementasi Lua.
                $reset = $now + $ttl;
            }

            return ['count' => $count, 'reset' => $reset];
        }

        #[\Override]
        public function peek(string $key, int $now): ?array
        {
            $bucketKey = self::PREFIX . hash('sha256', $key);
            $raw = $this->redis->get($bucketKey);
            if ($raw === false || $raw === null) {
                // Bucket belum pernah dibuat -> null (kontrak interface).
                return null;
            }
            $count = $this->redisInt($raw, 'GET');
            $ttl = $this->redisInt($this->redis->ttl($bucketKey), 'TTL');
            if ($ttl < 0) {
                // Key tanpa TTL yang konsisten -> anggap bucket tidak tersedia
                // (state tak tentu akibat crash lama); increment berikutnya
                // akan memulihkannya.
                return null;
            }
            return ['count' => $count, 'reset' => $now + $ttl];
        }

        /**
         * Normalisasi nilai kembalian ekstensi redis menjadi int.
         * Menerima mixed karena stub PHPStan mengetik method Redis tertentu
         * sebagai Redis|false; pada runtime nyata INCR/TTL mengembalikan int
         * dan GET mengembalikan string numerik. Tipe lain = bucket korup ->
         * fail-closed (RuntimeException) alih-alih count/reset tak valid.
         */
        private function redisInt(mixed $value, string $command): int
        {
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && is_numeric($value)) {
                return (int) $value;
            }
            throw new \RuntimeException('Redis rate-limit store: ' . $command . ' returned an unexpected value.');
        }
    }
}
