<?php
// Endpoint kategori: ?a=list|create|update|delete. Semua POST, wajib login;
// create/update/delete tambahan wajib CSRF. Validasi bisnis (tree 1 level,
// guard hapus) ada di core/kategori.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/kategori.php';

/**
 * Kumpulkan field kategori dari body request (create/update), dipakai bareng.
 */
function ktReadInput(): array
{
    return [
        'name' => post('name'),
        'type' => post('type'),
        'icon' => post('icon'),
        'color' => post('color'),
        'parent_id' => post('parent_id'),
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            apiOk(['categories' => listCategoriesTree($spaceId)]);
            break;

        case 'create':
            csrf_check();
            $cat = createCategory($spaceId, ktReadInput());
            apiOk(['category' => $cat]);
            break;

        case 'update':
            csrf_check();
            $id = (int) post('id', 0);
            $cat = updateCategory($id, ktReadInput());
            apiOk(['category' => $cat]);
            break;

        case 'delete':
            csrf_check();
            $id = (int) post('id', 0);
            deleteCategory($id);
            apiOk();
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
