<?php
declare(strict_types=1);

require __DIR__ . '/_inc/auth.php';
logout_user();
header('Location: /login.php');
exit;
