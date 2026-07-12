<?php
// Kategori: CRUD tervalidasi (tree parent->anak 1 level) + guard hapus.
// Semua fungsi validasi gagal -> apiErr() (menghentikan eksekusi).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const KT_TYPES = ['income', 'expense'];
const KT_DEFAULT_ICON = '🏷️';
const KT_DEFAULT_COLOR = '#94A3B8';

/**
 * Validasi & normalisasi input kategori (dipakai bareng create & update).
 * $selfId diisi saat update (utk cek self-parent & cek kategori ini sendiri
 * sudah punya anak). Aturan: name wajib; type income|expense; parent_id
 * (kalau ada) harus milik space yg sama, type sama, & bukan anak (maks 1
 * level); kategori yg SUDAH punya anak tidak boleh dijadikan anak kategori
 * lain (mencegah 2 level tak sengaja lewat update). Gagal -> apiErr.
 * Return [name, type, icon, color, parent_id].
 */
function ktValidate(int $spaceId, array $data, ?int $selfId = null): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        apiErr('Nama kategori wajib diisi (maks 100 karakter)');
    }

    $type = $data['type'] ?? '';
    if (!in_array($type, KT_TYPES, true)) {
        apiErr('Jenis kategori tidak valid');
    }

    $icon = trim((string) ($data['icon'] ?? ''));
    if ($icon === '') {
        $icon = KT_DEFAULT_ICON;
    }
    if (mb_strlen($icon) > 50) {
        apiErr('Ikon terlalu panjang');
    }

    $color = trim((string) ($data['color'] ?? ''));
    if ($color === '') {
        $color = KT_DEFAULT_COLOR;
    }
    if (mb_strlen($color) > 20) {
        apiErr('Kode warna terlalu panjang');
    }

    $parentRaw = $data['parent_id'] ?? null;
    $parentId = ($parentRaw === null || $parentRaw === '') ? null : (int) $parentRaw;

    if ($parentId !== null) {
        if ($selfId !== null && $parentId === $selfId) {
            apiErr('Kategori tidak bisa jadi induk dirinya sendiri');
        }

        $stmt = db()->prepare('SELECT id, space_id, type, parent_id FROM categories WHERE id = ?');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();
        if ($parent === false || (int) $parent['space_id'] !== $spaceId) {
            apiErr('Kategori induk tidak ditemukan', 404);
        }
        if ($parent['type'] !== $type) {
            apiErr('Kategori induk harus jenis yang sama');
        }
        if ($parent['parent_id'] !== null) {
            apiErr('Kategori induk tidak boleh sub-kategori (maks 1 level)');
        }

        if ($selfId !== null) {
            $stmt = db()->prepare('SELECT COUNT(*) c FROM categories WHERE parent_id = ?');
            $stmt->execute([$selfId]);
            if ((int) $stmt->fetch()['c'] > 0) {
                apiErr('Kategori ini sudah punya sub-kategori, tidak bisa dijadikan sub-kategori lain');
            }
        }
    }

    return [$name, $type, $icon, $color, $parentId];
}

/**
 * Buat kategori baru di $spaceId. Return row hasil (id + field ternormalisasi).
 */
function createCategory(int $spaceId, array $data): array
{
    [$name, $type, $icon, $color, $parentId] = ktValidate($spaceId, $data);

    $stmt = db()->prepare(
        'INSERT INTO categories (space_id, name, type, icon, color, parent_id) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$spaceId, $name, $type, $icon, $color, $parentId]);
    $id = (int) db()->lastInsertId();

    return [
        'id' => $id, 'space_id' => $spaceId, 'name' => $name, 'type' => $type,
        'icon' => $icon, 'color' => $color, 'parent_id' => $parentId,
    ];
}

/**
 * Update kategori $id. Kepemilikan divalidasi via ownCategory().
 */
function updateCategory(int $id, array $data): array
{
    $existing = ownCategory($id);
    $spaceId = (int) $existing['space_id'];

    // Ganti type kategori yg SUDAH punya anak akan bikin induk & anak beda
    // type (anak tidak ikut divalidasi ulang di sini) -- tolak dulu, sblm
    // ktValidate (yg hanya mengecek arah "jadi anak", bukan "sedang jadi
    // induk yg type-nya berubah").
    if (($data['type'] ?? '') !== $existing['type']) {
        $stmt = db()->prepare('SELECT COUNT(*) c FROM categories WHERE parent_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetch()['c'] > 0) {
            apiErr('Kategori ini punya sub-kategori, tidak bisa ubah jenis (induk & anak harus jenis sama)');
        }
    }

    [$name, $type, $icon, $color, $parentId] = ktValidate($spaceId, $data, $id);

    $stmt = db()->prepare(
        'UPDATE categories SET name = ?, type = ?, icon = ?, color = ?, parent_id = ? WHERE id = ?'
    );
    $stmt->execute([$name, $type, $icon, $color, $parentId, $id]);

    return [
        'id' => $id, 'space_id' => $spaceId, 'name' => $name, 'type' => $type,
        'icon' => $icon, 'color' => $color, 'parent_id' => $parentId,
    ];
}

/**
 * Hapus kategori $id. Kepemilikan divalidasi via ownCategory(). Ditolak
 * (apiErr, pesan jelas per kasus) kalau: dipakai di transactions, dipakai
 * di budgets, atau punya sub-kategori (harus dipindah/dihapus dulu). FK
 * CASCADE budgets->categories ADA di schema tapi SENGAJA tidak diandalkan --
 * guard eksplisit di sini supaya user tidak kehilangan data budget diam-diam.
 */
function deleteCategory(int $id): void
{
    ownCategory($id);
    $pdo = db();

    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM transactions WHERE category_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetch()['c'] > 0) {
        apiErr('Kategori tidak bisa dihapus karena sudah dipakai di transaksi');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM budgets WHERE category_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetch()['c'] > 0) {
        apiErr('Kategori tidak bisa dihapus karena sudah dipakai di budget');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM categories WHERE parent_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetch()['c'] > 0) {
        apiErr('Kategori ini punya sub-kategori, pindahkan atau hapus dulu sub-kategorinya');
    }

    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
}

/**
 * List kategori $spaceId sbg tree parent->anak (1 level), dikelompokkan per
 * type. Return ['expense' => [...node], 'income' => [...node]], tiap node
 * punya key 'children' (array node anak, kosong kalau tidak ada).
 */
function listCategoriesTree(int $spaceId): array
{
    $stmt = db()->prepare(
        'SELECT id, name, type, icon, color, parent_id FROM categories WHERE space_id = ? ORDER BY name ASC'
    );
    $stmt->execute([$spaceId]);
    $rows = $stmt->fetchAll();

    $roots = [];
    $childrenByParent = [];
    foreach ($rows as $r) {
        $r['id'] = (int) $r['id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int) $r['parent_id'] : null;
        $r['children'] = [];
        if ($r['parent_id'] === null) {
            $roots[$r['id']] = $r;
        } else {
            $childrenByParent[$r['parent_id']][] = $r;
        }
    }

    $tree = ['expense' => [], 'income' => []];
    foreach ($roots as $rootId => $root) {
        $root['children'] = $childrenByParent[$rootId] ?? [];
        $tree[$root['type']][] = $root;
    }

    return $tree;
}
