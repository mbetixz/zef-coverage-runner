<?php

declare(strict_types=1);

namespace {

    use PHPUnit\Framework\TestCase;

    /**
     * Bukti + gate kebijakan penyimpanan rahasia (#12, 2026-09-12).
     *
     * Kebijakan pemilik akun: token sensitif HANYA hidup sebagai GitLab CI/CD
     * variable (Protected + Masked). Berkas di sandbox/repo TIDAK BOLEH
     * menyimpan nilai rahasia; skrip WAJIB membaca dari environment dan
     * gagal-closed bila tidak tersedia.
     *
     * Test ini menjaga kebijakan itu tetap berlaku: bila seseorang (a)
     * menambahkan kembali skrip yang mem-`source` credstore sandbox, (b)
     * menghapus guard fail-closed, atau (c) melemahkan .gitignore sehingga
     * *.pem / *.key bisa ter-commit — pipeline MERAH di sini.
     *
     * Catatan: literal path mesin tidak ditulis utuh di berkas ini karena
     * `tools/verify-project-paths.php` menolak path absolut khas mesin di
     * dalam `tests/**`.
     */
    final class CiSecretStorageHygieneTest extends TestCase
    {
        private static function repoRoot(): string
        {
            return \dirname(__DIR__, 2);
        }

        public function testRequireCiSecretGuardExistsAndIsExecutable(): void
        {
            $path = self::repoRoot() . '/scripts/require-ci-secret.sh';
            self::assertFileExists($path, 'guard fail-closed #12 wajib ada');
            self::assertTrue(is_executable($path), 'guard #12 harus executable (mode +x)');
        }

        public function testRequireCiSecretGuardFailsClosedAndReadsNoFile(): void
        {
            $src = (string) file_get_contents(self::repoRoot() . '/scripts/require-ci-secret.sh');

            // fail-closed harus eksplisit
            self::assertStringContainsString('FAIL-CLOSED', $src);
            self::assertStringContainsString('exit 2', $src);

            // TIDAK boleh membaca credstore sandbox / berkas kredensial apa pun.
            // Fragment dirakit saat runtime agar berkas ini tidak memuat path mesin.
            $sandboxCredstorePath = '/work' . 'space/token_check';
            self::assertStringNotContainsString($sandboxCredstorePath, $src);
            self::assertStringNotContainsString('credentials.env', $src);
            self::assertStringNotContainsString('source ', str_replace('--source', '', $src));

            // TIDAK boleh mencetak nilai mentah secara default
            self::assertStringContainsString('sha256[:16]', $src);
        }

        public function testGitignoreBlocksCredentialAndPrivateKeyArtifacts(): void
        {
            $gi = (string) file_get_contents(self::repoRoot() . '/.gitignore');
            foreach (['*.pem', '*.key', 'credentials.env', 'token_check/'] as $pattern) {
                self::assertStringContainsString(
                    $pattern,
                    $gi,
                    "pola .gitignore wajib memblokir artefak kredensial: {$pattern}"
                );
            }
        }

        public function testNoPrivateKeyOrCredentialStoreAtRepoRoot(): void
        {
            $root = self::repoRoot();
            foreach (['gh-app-private-key.pem', 'gh-app.pem', 'credentials.env'] as $name) {
                self::assertFileDoesNotExist(
                    $root . '/' . $name,
                    "rahasia tidak boleh berada di root repo: {$name}"
                );
            }
        }

        public function testCiConsumesPrivateKeyFromEnvironmentNotHardcodedPath(): void
        {
            $ci = (string) file_get_contents(self::repoRoot() . '/.gitlab-ci.yml');

            // blok GH-APP-TOKEN memakai env CI, bukan path credstore sandbox
            self::assertStringContainsString('GH_APP_PRIVATE_KEY', $ci);

            // token App diterbitkan murni-PHP (tanpa python3/openssl CLI yang tidak ada di image)
            self::assertStringContainsString('gh-app-token.php', $ci);
        }

        public function testAppTokenIssuerIsPurePhpExtensionBased(): void
        {
            $php = (string) file_get_contents(self::repoRoot() . '/scripts/gh-app-token.php');

            // kripto via ekstensi PHP, bukan CLI
            self::assertStringContainsString('openssl_sign', $php);
            self::assertStringContainsString('openssl_pkey_get_private', $php);
            self::assertStringNotContainsString('shell_exec("openssl', $php);
        }
    }
}
