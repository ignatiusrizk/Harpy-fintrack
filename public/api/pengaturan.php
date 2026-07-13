<?php
// Endpoint pengaturan: ?a=profile|password|space_list|space_create|
// space_rename|space_delete|space_switch. Semua POST, wajib login;
// mutasi (semua kecuali space_list) wajib CSRF. Validasi bisnis (profil,
// password, ruang) ada di core/pengaturan.php.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/pengaturan.php';

$user = requireLoginApi();
$action = get('a', '');

try {
    switch ($action) {
        case 'profile':
            csrf_check();
            $name = post('name', '');
            apiOk(['user' => profileUpdate((int) $user['id'], $name)]);
            break;

        case 'password':
            csrf_check();
            $old = post('old_password', '');
            $new = post('new_password', '');
            passwordChange((int) $user['id'], $old, $new);
            apiOk();
            break;

        case 'space_list':
            apiOk(['spaces' => spaceList((int) $user['id'])]);
            break;

        case 'space_create':
            csrf_check();
            $name = post('name', '');
            $type = post('type', 'personal');
            apiOk(['space' => spaceCreate((int) $user['id'], $name, $type)]);
            break;

        case 'space_rename':
            csrf_check();
            $id = (int) post('id', 0);
            $name = post('name', '');
            apiOk(['space' => spaceRename($id, $name)]);
            break;

        case 'space_delete':
            csrf_check();
            $id = (int) post('id', 0);
            spaceDelete($id);
            apiOk();
            break;

        case 'space_switch':
            csrf_check();
            $id = (int) post('id', 0);
            apiOk(['space' => spaceSwitch($id)]);
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
