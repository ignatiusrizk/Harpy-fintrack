<?php
// Endpoint goals: ?a=list|create|update|delete|deposit|withdraw|finish. Semua
// POST, wajib login; create/update/delete/deposit/withdraw/finish tambahan
// wajib CSRF. Validasi bisnis (kategori Tabungan Goal, saved/pct, lock baris
// deposit/withdraw) ada di core/goals.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/transaksi.php';
require_once __DIR__ . '/../../core/goals.php';

/**
 * Kumpulkan field goal dari body request (create/update), dipakai bareng.
 */
function glReadInput(): array
{
    return [
        'name' => post('name'),
        'target_amount' => post('target_amount'),
        'target_date' => post('target_date'),
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            apiOk(['goals' => listGoals($spaceId)]);
            break;

        case 'create':
            csrf_check();
            $g = createGoal($spaceId, glReadInput());
            apiOk(['goal' => $g]);
            break;

        case 'update':
            csrf_check();
            $id = (int) post('id', 0);
            $g = updateGoal($id, glReadInput());
            apiOk(['goal' => $g]);
            break;

        case 'delete':
            csrf_check();
            $id = (int) post('id', 0);
            deleteGoal($id);
            apiOk();
            break;

        case 'finish':
            csrf_check();
            $id = (int) post('id', 0);
            apiOk(finishGoal($id));
            break;

        case 'deposit':
            csrf_check();
            $id = (int) post('goal_id', 0);
            $accountId = (int) post('account_id', 0);
            $amount = post('amount');
            $date = post('date');
            apiOk(depositGoal($id, $accountId, $amount, $date));
            break;

        case 'withdraw':
            csrf_check();
            $id = (int) post('goal_id', 0);
            $accountId = (int) post('account_id', 0);
            $amount = post('amount');
            $date = post('date');
            apiOk(withdrawGoal($id, $accountId, $amount, $date));
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
