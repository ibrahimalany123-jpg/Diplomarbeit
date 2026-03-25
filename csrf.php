<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  // Optional: Cookie-Params (setze secure=true nur bei HTTPS!)
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => false,
  ]);
  session_start();
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_validate(?string $token): void {
  $ok = is_string($token)
    && isset($_SESSION['csrf_token'])
    && hash_equals((string)$_SESSION['csrf_token'], $token);

  if (!$ok) {
    throw new RuntimeException('CSRF validation failed.');
  }
}
