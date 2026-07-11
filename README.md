# Harpy FinTrack

Aplikasi web pencatat keuangan pribadi multi-user, berbahasa Indonesia, mobile-first.
Lihat `docs/superpowers/specs/2026-07-12-harpy-fintrack-design.md` untuk desain lengkap.

## Stack

PHP 8 murni + MySQL/MariaDB (PDO), tanpa framework. Pola halaman-per-fitur + endpoint AJAX JSON di `public/api/`.

## Setup dev lokal

1. Pastikan MariaDB lokal jalan dan database `fintracker` + user `fintracker` sudah ada.
2. Salin config:
   ```
   cp core/config.example.php core/config.php
   ```
   Isi kredensial DB dev di `core/config.php` (file ini gitignored).
3. Load skema:
   ```
   /opt/homebrew/bin/mariadb --no-defaults fintracker < db/schema.sql
   ```
4. Jalankan test:
   ```
   php tests/run.php
   ```

## Struktur direktori

```
fintracker/
  public/            # docroot (halaman + public/api/ endpoint AJAX JSON)
  core/              # db.php, helpers.php, config.php (gitignored)
  db/                # schema.sql
  tests/             # test CLI PHP (tests/run.php menjalankan semua test_*.php)
```
