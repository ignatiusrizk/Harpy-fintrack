<?php
// Endpoint hutang/piutang & cicilan: ?a=list|create|pay|update|delete|settle|
// history. list & history GET tanpa CSRF (baca saja); create/pay/update/
// delete/settle POST + wajib CSRF. Validasi bisnis & kepemilikan (ownDebt)
// ada di core/hutang.php & core/helpers.php -- endpoint ini murni parsing
// input + pemanggilan. Pola sama persis dgn public/api/goals.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/transaksi.php';
require_once __DIR__ . '/../../core/hutang.php';

/**
 * Kumpulkan field debt dari body request (create), dipakai sekali di sini
 * saja -- key-nya persis parameter yg dibaca debtValidate()/createDebt().
 */
function dtReadCreateInput(): array
{
    return [
        'direction' => post('direction'),
        'party' => post('party'),
        'principal' => post('principal'),
        'note' => post('note'),
        'start_date' => post('start_date'),
        'due_date' => post('due_date'),
        'is_installment' => post('is_installment'),
        'installment_count' => post('installment_count'),
        'installment_amount' => post('installment_amount'),
        'frequency' => post('frequency'),
        'disburse' => post('disburse'),
        'account_id' => post('account_id'),
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            apiOk(debtSummary($spaceId));
            break;

        case 'create':
            csrf_check();
            $debt = createDebt($spaceId, dtReadCreateInput());
            apiOk(['debt' => $debt]);
            break;

        case 'pay':
            csrf_check();
            $id = (int) post('debt_id', 0);
            ownDebt($id);
            $result = payDebt(
                $id,
                (int) post('account_id', 0),
                post('amount'),
                post('date'),
                post('expected_next_due')
            );
            apiOk($result);
            break;

        case 'update':
            csrf_check();
            $id = (int) post('debt_id', 0);
            ownDebt($id);
            $debt = updateDebt($id, [
                'party' => post('party'),
                'note' => post('note'),
                'due_date' => post('due_date'),
                'principal' => post('principal'),
            ]);
            apiOk(['debt' => $debt]);
            break;

        case 'delete':
            csrf_check();
            deleteDebt((int) post('debt_id', 0));
            apiOk();
            break;

        case 'settle':
            csrf_check();
            apiOk(settleDebt((int) post('debt_id', 0)));
            break;

        case 'history':
            $id = (int) ($_GET['debt_id'] ?? 0);
            apiOk(['history' => debtHistory($id)]);
            break;

        default:
            apiErr('Aksi tidak dikenal', 400);
    }
} catch (Throwable $e) {
    apiErr($e);
}
