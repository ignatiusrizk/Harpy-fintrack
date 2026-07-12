<?php
// Endpoint investasi: ?a=list|create_asset|update_asset|delete_asset|trade|
// delete_trade|set_price|history. Semua POST, wajib login; semua kecuali
// list tambahan wajib CSRF. Validasi bisnis (avg cost, guard posisi negatif,
// dll) ada di core/portfolio.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/portfolio.php';

/**
 * Kumpulkan field aset dari body request (create_asset/update_asset), dipakai
 * bareng.
 */
function ptReadAssetInput(): array
{
    return [
        'name' => post('name'),
        'type' => post('type'),
        'code' => post('code'),
        'unit_label' => post('unit_label'),
    ];
}

$user = requireLoginApi();
$spaceId = currentSpaceId();
$action = get('a', '');

try {
    switch ($action) {
        case 'list':
            apiOk(['summary' => portfolioSummary($spaceId)]);
            break;

        case 'create_asset':
            csrf_check();
            $a = createAsset($spaceId, ptReadAssetInput());
            apiOk(['asset' => $a]);
            break;

        case 'update_asset':
            csrf_check();
            $id = (int) post('id', 0);
            $a = updateAsset($id, ptReadAssetInput());
            apiOk(['asset' => $a]);
            break;

        case 'delete_asset':
            csrf_check();
            $id = (int) post('id', 0);
            deleteAsset($id);
            apiOk();
            break;

        case 'trade':
            csrf_check();
            $assetId = (int) post('asset_id', 0);
            $side = (string) post('side', '');
            $units = post('units');
            $price = post('price_per_unit');
            $fee = post('fee', 0);
            $txDate = post('tx_date');
            apiOk(['trade' => tradeAsset($assetId, $side, $units, $price, $fee, $txDate)]);
            break;

        case 'delete_trade':
            csrf_check();
            $id = (int) post('id', 0);
            deleteAssetTrade($id);
            apiOk();
            break;

        case 'set_price':
            csrf_check();
            $assetId = (int) post('asset_id', 0);
            $price = post('price_per_unit');
            $pricedAt = post('priced_at');
            apiOk(['price' => setAssetPrice($assetId, $price, $pricedAt)]);
            break;

        case 'history':
            // Dibaca lewat window.api() (selalu POST JSON, sama spt aksi lain
            // di halaman ini) -- bukan GET, walau ini aksi baca-saja. Tidak
            // wajib CSRF (baca-saja, tidak mengubah state, pola sama spt
            // ?a=list).
            $assetId = (int) post('asset_id', 0);
            apiOk(assetHistory($assetId));
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
