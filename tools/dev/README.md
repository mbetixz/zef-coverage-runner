# ZEF Development Toolchain

Direktori ini berisi tool lifecycle untuk pekerjaan cleanup baseline. Toolchain dipasang di `.zef/toolchain/`, bukan di `vendor/` proyek dan bukan ke `/usr/local/bin`. Dengan demikian, cleanup dapat dilakukan tanpa mengganggu dependency runtime aplikasi.

## Install

```bash
./tools/dev/install-toolchain.sh
```

Installer membutuhkan PHP 8.4 atau lebih baru dan Composer. Versi Rector, PHP-CS-Fixer, dan Deptrac dapat dipin melalui environment variable berikut:

```bash
ZEF_RECTOR_VERSION='^2.0' \
ZEF_CS_FIXER_VERSION='^3.70' \
ZEF_DEPTRAC_VERSION='^4.0' \
./tools/dev/install-toolchain.sh
```

Installer hanya menggunakan Composer project terpisah dan mencatat ownership pada `.zef/toolchain/manifest.env`. Tool supply-chain seperti Syft dan Grype sengaja tidak diunduh sebagai `latest`; keduanya harus dipasang melalui langkah terpisah dengan versi serta checksum yang dikunci oleh release policy.

## Jalankan pemeriksaan

```bash
.zef/toolchain/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff
.zef/toolchain/bin/rector process --config=rector.php --dry-run
.zef/toolchain/bin/deptrac analyse --config-file=deptrac.yaml --no-cache
```

Rector harus dijalankan pada branch refactoring dan hasilnya harus direview. Jangan menerapkan diff massal ke baseline hanya karena dry-run menghasilkan perubahan.

## Buat source release bersih

```bash
./tools/dev/clean-release.sh
```

Builder membuat ZIP dari temporary staging directory. Ia mengecualikan `vendor/`, `var/`, `.zef/`, `.git/`, `src.preR3/`, `tests/_history/`, log, PID, cache, dan evidence runtime. Source workspace asli tidak dihapus.

## Buat runtime bundle RoadRunner

```bash
ZEF_RR_BIN=/usr/local/bin/rr ./tools/dev/build-runtime-bundle.sh
```

Builder ini menghasilkan `dist/runtime-bundle/zef-runtime-bundle.zip` yang berisi binary `rr`, tiga konfigurasi RoadRunner, README runtime, dan `SHA256SUMS.txt`. Bundle ini sengaja terpisah dari source package; ia tidak membawa source aplikasi dan tidak boleh dianggap sebagai source distribution.

## Uninstall setelah rilis

```bash
./tools/dev/uninstall-toolchain.sh
```

Uninstaller hanya menghapus `.zef/toolchain/` milik installer ini dan menghapus parent `.zef/` bila kosong. Ia tidak menyentuh `vendor/`, `src/`, `tests/`, baseline archive, atau dependency aplikasi.

## Kontrak cleanup

Sebelum release final, verifikasi bahwa paket source tidak mengandung `vendor/`, cache, log, PID, binary RoadRunner, `.git`, atau temporary output. Jika runtime bundle memang diperlukan, buat bundle tersebut sebagai artefak terpisah dan dokumentasikan dependency serta versi binary-nya secara eksplisit.
