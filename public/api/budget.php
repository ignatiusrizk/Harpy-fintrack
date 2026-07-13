<?php
// Endpoint budget: ?a=list|set|copy_prev. Semua POST, wajib login; set/copy_prev
// tambahan wajib CSRF. Validasi bisnis (period, kepemilikan kategori, upsert,
// aturan sub-kategori) ada di core/budget.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/budget.php';

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            $period = (string) post('period', date('Y-m'));
            apiOk([
                'period' => $period,
                'budgets' => budgetStatus($spaceId, $period),
                'unbudgeted' => unbudgetedCategories($spaceId, $period),
            ]);
            break;

        case 'set':
            csrf_check();
            $categoryId = (int) post('category_id', 0);
            $period = (string) post('period', '');
            $amount = post('amount');
            $result = setBudget($spaceId, $categoryId, $period, $amount);
            apiOk(['budget' => $result]);
            break;

        case 'copy_prev':
            csrf_check();
            $period = (string) post('period', '');
            $copied = copyPrevBudgets($spaceId, $period);
            apiOk(['copied' => $copied]);
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
