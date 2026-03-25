<?php
declare(strict_types=1);

require __DIR__ . '/mail_config.php';

function send_twofa_email(string $to, string $code): void {
  $subject = APP_NAME . ' Login-Code';
  $body = "Dein Code ist: $code\nGültig für 10 Minuten.\n";

  $msg =
    "From: " . APP_NAME . " <" . GMAIL_FROM . ">\n" .
    "To: <$to>\n" .
    "Subject: $subject\n" .
    "Content-Type: text/plain; charset=UTF-8\n\n" .
    $body;

  $proc = proc_open('/usr/sbin/sendmail -t -i', [
    0 => ['pipe','r'],
    1 => ['pipe','w'],
    2 => ['pipe','w'],
  ], $pipes);

  if (!is_resource($proc)) {
    throw new RuntimeException('sendmail start failed');
  }

  fwrite($pipes[0], $msg);
  fclose($pipes[0]);

  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);

  $exit = proc_close($proc);
  if ($exit !== 0) {
    throw new RuntimeException('sendmail failed: ' . $stderr);
  }
}
