<?php
// Seed default: dipanggil saat sebuah space baru dibuat (registrasi user baru,
// atau saat user menambah ruang usaha baru).

require_once __DIR__ . '/db.php';

/**
 * Buat space baru untuk user + seed kategori default (10 expense + 4 income,
 * masing-masing dengan icon emoji & warna hex). Return id space baru.
 */
function createSpaceWithDefaults(int $userId, string $name, string $type): int
{
    $pdo = db();

    $stmt = $pdo->prepare('INSERT INTO spaces (user_id, name, type) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $name, $type]);
    $spaceId = (int) $pdo->lastInsertId();

    // [nama, type, icon, warna]
    $categories = [
        ['Makan & Minum', 'expense', '🍔', '#F97316'],
        ['Transportasi', 'expense', '🚗', '#3B82F6'],
        ['Belanja', 'expense', '🛍️', '#EC4899'],
        ['Tagihan & Utilitas', 'expense', '💡', '#EAB308'],
        ['Kesehatan', 'expense', '🏥', '#EF4444'],
        ['Pendidikan', 'expense', '📚', '#8B5CF6'],
        ['Hiburan', 'expense', '🎬', '#F43F5E'],
        ['Rumah Tangga', 'expense', '🏠', '#14B8A6'],
        ['Tabungan Goal', 'expense', '🎯', '#0EA5E9'],
        ['Lainnya', 'expense', '📦', '#94A3B8'],
        ['Gaji', 'income', '💰', '#22C55E'],
        ['Bonus', 'income', '🎁', '#84CC16'],
        ['Hasil Investasi', 'income', '📈', '#10B981'],
        ['Lainnya', 'income', '📥', '#64748B'],
    ];

    $ins = $pdo->prepare(
        'INSERT INTO categories (space_id, name, type, icon, color) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($categories as [$catName, $catType, $icon, $color]) {
        $ins->execute([$spaceId, $catName, $catType, $icon, $color]);
    }

    return $spaceId;
}
