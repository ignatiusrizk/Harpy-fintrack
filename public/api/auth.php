<?php
// Endpoint auth: ?a=register|login|logout. Semua POST, semua wajib CSRF.

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/seed.php';
require_once __DIR__ . '/../../core/auth.php';

header('Cache-Control: no-store');

$action = get('a', '');

try {
    switch ($action) {
        case 'register':
            csrf_check();

            $name = post('name', '');
            $email = post('email', '');
            $pass = post('password', '');

            if ($name === '' || $email === '' || $pass === '') {
                apiErr('Nama, email, dan kata sandi wajib diisi');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                apiErr('Format email tidak valid');
            }
            if (strlen($pass) < 8) {
                apiErr('Kata sandi minimal 8 karakter');
            }

            $userId = registerUser($name, $email, $pass);
            logInAs($userId);

            apiOk(['user' => ['id' => $userId, 'name' => $name]]);
            break;

        case 'login':
            csrf_check();

            $email = post('email', '');
            $pass = post('password', '');
            $remember = post('remember', false);

            if ($email === '' || $pass === '') {
                apiErr('Email dan kata sandi wajib diisi');
            }

            if (!attemptLogin($email, $pass)) {
                apiErr('Email atau kata sandi salah', 401);
            }

            $stmt = db()->prepare('SELECT id, name FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            logInAs((int) $user['id']);

            if ($remember) {
                setRememberCookie((int) $user['id']);
            }

            apiOk(['user' => ['id' => (int) $user['id'], 'name' => $user['name']]]);
            break;

        case 'logout':
            csrf_check();
            logoutUser();
            apiOk();
            break;

        default:
            apiErr('Aksi tidak dikenal', 404);
    }
} catch (Throwable $e) {
    apiErr($e);
}
