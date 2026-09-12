# Kontrak Gate CI — GitHub mirror `zef-coverage-runner`

Ringkasan pengikatan gate CI antara GitLab `zeflous/zef` (kanonik) dan mirror
GitHub ini. Dokumen ini sengaja non-behavioral (tidak mengubah job/step) supaya
tidak menggeser hasil paritas coverage.

## Dua required status check

| Context (nama persis) | Workflow | Trigger |
|---|---|---|
| `PHPUnit + Xdebug branch coverage (2 vCPU / 7 GB)` | `coverage.yml` | `push: [main]`, `pull_request: [main]`, `workflow_dispatch` |
| `PHPStan level max (GitHub 2 vCPU / 7 GB)` | `phpstan.yml` | `push: [main]`, `pull_request: [main]`, `workflow_dispatch` |

Keduanya terdaftar pada ruleset `22945106` (`main-protection-app-bypass`).

## Catatan penting

- Sebelum trigger `pull_request` ditambahkan, kedua context **hanya** diproduksi
  oleh `push` ke `main` — sehingga required status check tidak mungkin terpenuhi
  di PR mana pun dan satu-satunya jalur merge adalah bypass GitHub App.
- Branch protection klasik repo ini ada sebagai objek, tetapi
  `required_status_checks.enforcement_level` bernilai `off` dengan `contexts: []`.
  Karena itu **direct merge** hanya dievaluasi oleh ruleset, bukan branch protection.
- Merge Queue dapat mengevaluasi gate PR tanpa bypass, tetapi QA-CI default
  (`ci/merge-queue-bypass-proof`) dapat menahan entry di antrean sampai ada
  agen memprosesnya.

## Metrik paritas

Nilai coverage diproduksi oleh gate `tools/coverage-gate.php` dengan format baris
yang stabil, dan sengaja dipakai bersama oleh regex `coverage:` GitLab:

```
Coverage: <lines>% lines (<hit>/<total>) | threshold 80.00% | <branches>% branches (<hit>/<total>) | threshold 70.00%
```
