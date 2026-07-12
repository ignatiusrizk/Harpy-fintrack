<?php
// Endpoint transaksi: ?a=list|create|update|delete. Semua POST (list pun
// lewat window.api() yg selalu POST JSON, konsisten dgn pola akun.php), wajib
// login; create/update/delete tambahan wajib CSRF. Validasi bisnis penuh
// (amount>0, kategori cocok, transfer dst.) ada di core/transaksi.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/transaksi.php';

/**
 * Kumpulkan field transaksi dari body request (create/update), dipakai bareng.
 */
function txReadInput(): array
{
    return [
        'account_id' => post('account_id'),
        'category_id' => post('category_id'),
        'type' => post('type'),
        'amount' => post('amount'),
        'tx_date' => post('tx_date'),
        'note' => post('note'),
        'to_account_id' => post('to_account_id'),
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            $filters = [
                'from' => post('from'),
                'to' => post('to'),
                'account_id' => post('account_id'),
                'category_id' => post('category_id'),
                'type' => post('type'),
                'q' => post('q'),
                'page' => post('page', 1),
            ];
            apiOk(listTransactions($spaceId, $filters));
            break;

        case 'create':
            csrf_check();
            $tx = createTransaction($spaceId, txReadInput());
            apiOk(['transaction' => $tx]);
            break;

        case 'update':
            csrf_check();
            $id = (int) post('id', 0);
            $tx = updateTransaction($id, txReadInput());
            apiOk(['transaction' => $tx]);
            break;

        case 'delete':
            csrf_check();
            $id = (int) post('id', 0);
            deleteTransaction($id);
            apiOk();
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
