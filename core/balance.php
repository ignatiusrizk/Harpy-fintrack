<?php
// Balance engine: saldo akun, saldo per ruang (agregat), net worth.
// Rumus saldo akun = initial_balance + income - expense - transfer keluar + transfer masuk.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
// portfolioValue() (Task 9) di-require_once di sini (bukan cuma di halaman
// investasi) supaya netWorth() di bawah SELALU menyertakannya lewat
// function_exists() hook -- kapanpun/di manapun balance.php di-require,
// portfolio.php ikut, sama spt core/auth.php me-require_once recurring.php
// utk hook pseudo-cron-nya.
require_once __DIR__ . '/portfolio.php';

/**
 * Saldo satu akun (on-the-fly, bukan kolom tersimpan). Akun tidak ditemukan -> 0.0.
 */
function accountBalance(int $accountId): float
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT a.initial_balance
              + COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = a.id AND type = "income"), 0)
              - COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = a.id AND type = "expense"), 0)
              - COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = a.id AND type = "transfer"), 0)
              + COALESCE((SELECT SUM(amount) FROM transactions WHERE to_account_id = a.id AND type = "transfer"), 0)
              AS balance
         FROM accounts a
         WHERE a.id = ?'
    );
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return 0.0;
    }
    return (float) $row['balance'];
}

/**
 * Saldo semua akun di satu ruang, SATU query agregat (bukan N+1 per akun).
 * Return [account_id => ['name', 'type', 'balance', 'is_archived']].
 */
function spaceBalances(int $spaceId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT
            a.id,
            a.name,
            a.type,
            a.is_archived,
            a.initial_balance
              + COALESCE(out_agg.amt, 0)
              + COALESCE(in_agg.amt, 0) AS balance
         FROM accounts a
         LEFT JOIN (
             SELECT
                 account_id,
                 SUM(
                     CASE
                         WHEN type = "income" THEN amount
                         WHEN type = "expense" THEN -amount
                         WHEN type = "transfer" THEN -amount
                         ELSE 0
                     END
                 ) AS amt
             FROM transactions
             WHERE space_id = ?
             GROUP BY account_id
         ) out_agg ON out_agg.account_id = a.id
         LEFT JOIN (
             SELECT to_account_id, SUM(amount) AS amt
             FROM transactions
             WHERE space_id = ? AND type = "transfer" AND to_account_id IS NOT NULL
             GROUP BY to_account_id
         ) in_agg ON in_agg.to_account_id = a.id
         WHERE a.space_id = ?
         ORDER BY a.id ASC'
    );
    $stmt->execute([$spaceId, $spaceId, $spaceId]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int) $row['id']] = [
            'name' => $row['name'],
            'type' => $row['type'],
            'balance' => (float) $row['balance'],
            'is_archived' => (bool) $row['is_archived'],
        ];
    }
    return $result;
}

/**
 * Net worth user: Σ saldo semua akun di semua ruang personal (type='personal')
 * milik user + portfolioValue($userId) kalau fungsi itu sudah ada (Task 9).
 */
function netWorth(int $userId): float
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM spaces WHERE user_id = ? AND type = 'personal'");
    $stmt->execute([$userId]);

    $total = 0.0;
    foreach ($stmt->fetchAll() as $space) {
        foreach (spaceBalances((int) $space['id']) as $account) {
            $total += $account['balance'];
        }
    }

    if (function_exists('portfolioValue')) {
        $total += portfolioValue($userId);
    }

    return $total;
}
