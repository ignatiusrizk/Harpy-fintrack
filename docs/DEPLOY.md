# Deploy Harpy FinTrack ke Hostinger

Stack: PHP 8 + MySQL/MariaDB. Tidak ada build step, tidak ada dependency
eksternal (murni PHP + vanilla JS/CSS). Deploy = taruh kode + buat DB + arahkan
document root ke `public/`.

Repo: `git@github.com:ignatiusrizk/Harpy-fintrack.git` (branch `main`).

---

## Bagian A — yang HARUS Anda lakukan di hPanel (butuh kredensial/izin akun)

### A1. Buat database MySQL
hPanel → **Databases → MySQL Databases → Create New**:
- Database name: mis. `fintrack` → jadi `u269895997_fintrack`
- Username: mis. `fintrack` → jadi `u269895997_fintrack`
- Password: buat yang kuat, **simpan** (dibutuhkan di A3).
Catat: nama DB, user, password. Host = `localhost`.

### A2. Taruh kode di server
Pilih salah satu:
- **Git (disarankan):** hPanel → **Advanced → Git** → Create repository →
  URL `https://github.com/ignatiusrizk/Harpy-fintrack.git`, branch `main`,
  install path mis. `domains/NAMADOMAIN/fintrack`. Klik **Deploy** untuk pull.
  (Update berikutnya cukup klik Deploy lagi.)
- **Manual:** unduh ZIP repo, upload & extract ke folder `.../fintrack`.

### A3. Buat file konfigurasi DB di server
Di **File Manager**, masuk ke `fintrack/core/`, salin
`config.production.example.php` menjadi **`config.php`**, lalu isi `name`,
`user`, `pass` dari langkah A1. (File `config.php` sudah di-.gitignore, tidak
akan tertimpa saat deploy ulang.)

### A4. Arahkan document root ke folder `public/`  ⚠️ PENTING KEAMANAN
hPanel → **Domains → (domain/subdomain) → Website root / Document root** →
set ke `.../fintrack/public`.

Kenapa wajib: `core/config.php` berisi password DB dan berada SATU LEVEL di atas
`public/`. Kalau document root diarahkan ke root repo, file itu bisa terekspos.
(Ada `.htaccess` jaring pengaman, tapi document root yang benar adalah pertahanan
utama.)

Kalau plan Anda tidak mengizinkan mengubah document root (terkunci di
`public_html`): pindahkan **isi** folder `public/` ke `public_html/`, dan taruh
folder `core/`, `db/` SATU LEVEL DI ATAS `public_html/`. Hubungi saya bila perlu
— penyesuaian path-nya kecil.

### A5. Import skema database
DB baru masih kosong. Pilih salah satu:
- **phpMyAdmin** (hPanel → Databases → phpMyAdmin → pilih DB → **Import** →
  unggah `db/schema.sql`).
- **SSH:** `mysql -h localhost -u USER -p NAMA_DB < db/schema.sql`

`db/schema.sql` sudah lengkap untuk instalasi baru (17 tabel, termasuk modul
Hutang). Tidak perlu migrasi tambahan.

### A6. Uji
Buka `https://NAMADOMAIN/` → halaman **Masuk** muncul → **Daftar** akun →
dashboard tampil. Selesai.

---

## Bagian B — yang sudah otomatis / disiapkan di repo

- `public/.htaccess` & `.htaccess` (root): matikan directory listing, blokir
  akses langsung ke `core/`, `db/`, `docs/`, file `.sql`/`.md`/config.
- `core/config.production.example.php`: template kredensial.
- `db/schema.sql`: skema penuh siap import (fresh install).
- Timezone app di-set `Asia/Jakarta` di kode; sesi MySQL di-set `+07:00`.
- Header keamanan dasar (no-store pada halaman dinamis, CSRF, password hash,
  rate-limit login) sudah aktif dari v1.

---

## Bagian C — sebelum go-live (disarankan)

- **HTTPS:** aktifkan SSL gratis Hostinger (Auto SSL). Setelah HTTPS aktif,
  aktifkan cookie `Secure` — beri tahu saya, saya tambahkan
  `session.cookie_secure` + flag `Secure` pada cookie remember-me (dibiarkan off
  di dev karena http).
- **Kredensial:** jangan pernah commit `core/config.php`.
- **Backup:** aktifkan auto-backup DB di hPanel.
