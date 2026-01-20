<?php
/**
 * API для авторизации администратора
 */

require_once __DIR__ . '/helpers.php';

// CORS headers для локальной разработки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;

    case 'logout':
        handleLogout();
        break;

    case 'check':
        handleCheck();
        break;

    default:
        sendJsonResponse(['success' => false, 'error' => 'Неизвестное действие'], 400);
}

/**
 * Вход в систему
 */
function handleLogin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'error' => 'Метод не поддерживается'], 405);
    }

    $data = getPostData();
    $password = $data['password'] ?? '';

    if (empty($password)) {
        sendJsonResponse(['success' => false, 'error' => 'Пароль не указан'], 400);
    }

    // Проверяем пароль
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_login_time'] = time();

        sendJsonResponse([
            'success' => true,
            'message' => 'Успешная авторизация'
        ]);
    } else {
        sendJsonResponse([
            'success' => false,
            'error' => 'Неверный пароль'
        ], 401);
    }
}

/**
 * Выход из системы
 */
function handleLogout() {
    $_SESSION['admin_authenticated'] = false;
    unset($_SESSION['admin_login_time']);
    session_destroy();

    sendJsonResponse([
        'success' => true,
        'message' => 'Выход выполнен'
    ]);
}

/**
 * Проверка статуса авторизации
 */
function handleCheck() {
    $isAuthenticated = isset($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true;

    sendJsonResponse([
        'success' => true,
        'authenticated' => $isAuthenticated
    ]);
}
