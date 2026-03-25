<?php
declare(strict_types=1);

function generate_6_digit_code(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function utc_now(): DateTimeImmutable {
  return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function utc_plus_minutes(int $m): string {
  return utc_now()->modify("+$m minutes")->format('Y-m-d H:i:s');
}
