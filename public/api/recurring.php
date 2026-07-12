<?php
// Endpoint recurring: ?a=list|create|update|toggle|delete|confirm|skip. Semua
// POST, wajib login; create/update/toggle/delete/confirm/skip tambahan wajib
// CSRF. Validasi bisnis (frekuensi, anchor/next_run, catch-up cap, lock baris
// confirm/skip) ada di core/recurring.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/transaksi.php';
require_once __DIR__ . '/../../core/recurring.php';

/**
 * Kumpulkan field recurring dari body request (create/update), dipakai bareng.
 */
function rcReadInput(): array
{
    return [
        'account_id' => post('account_id'),
        'category_id' => post('category_id'),
        'type' => post('type'),
        'amount' => post('amount'),
        'note' => post('note'),
        'frequency' => post('frequency'),
        'mode' => post('mode'),
        'start_date' => post('start_date'),
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            apiOk(['recurrings' => listRecurrings($spaceId)]);
            break;

        case 'create':
            csrf_check();
            $r = createRecurring($spaceId, rcReadInput());
            apiOk(['recurring' => $r]);
            break;

        case 'update':
            csrf_check();
            $id = (int) post('id', 0);
            $r = updateRecurring($id, rcReadInput());
            apiOk(['recurring' => $r]);
            break;

        case 'toggle':
            csrf_check();
            $id = (int) post('id', 0);
            apiOk(toggleRecurring($id));
            break;

        case 'delete':
            csrf_check();
            $id = (int) post('id', 0);
            deleteRecurring($id);
            apiOk();
            break;

        case 'confirm':
            csrf_check();
            $id = (int) post('id', 0);
            apiOk(confirmRecurring($id));
            break;

        case 'skip':
            csrf_check();
            $id = (int) post('id', 0);
            apiOk(skipRecurring($id));
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
