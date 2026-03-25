<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function is_logged_in(): bool {
  return isset($_SESSION['user_id'], $_SESSION['username']);
}

function require_login(): void {
  if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
  }
}

function login_user(int $user_id, string $username): void {
  // Session Fixation verhindern
  session_regenerate_id(true);

  $_SESSION['user_id'] = $user_id;
  $_SESSION['username'] = $username;
  $_SESSION['logged_in_at'] = time();
}

function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
}
