<?php
// ============================================================================
// TEMPLATE KONFIGURASI PRODUKSI (Hostinger).
//
// CARA PAKAI:
//   1. Di server, SALIN file ini menjadi  core/config.php
//   2. Isi kredensial DB dari hPanel → MySQL Databases.
//   3. JANGAN commit core/config.php (sudah di-.gitignore).
//
// Catatan host:
//   - Hostinger: gunakan 'localhost' (server web & MySQL satu mesin).
//   - Nama database & user berformat  u2xxxxxxxx_namamu  sesuai yang
//     dibuatkan hPanel.
// ============================================================================
return [
    'host' => 'localhost',
    'name' => 'ISI_NAMA_DATABASE',   // mis. u269895997_fintrack
    'user' => 'ISI_USER_DATABASE',   // mis. u269895997_fintrack
    'pass' => 'ISI_PASSWORD_DB',     // password yang Anda set di hPanel
];
