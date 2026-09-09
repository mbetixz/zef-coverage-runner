<?php
declare(strict_types=1);

$json = file_get_contents(dirname(__DIR__).'/Architecture/public-surface-delta-v2.5.0-beta1-g4.8-w5.json');
if ($json === false) { fwrite(STDERR, 'Unable to read W5 public surface delta.\n'); exit(1); }
$delta = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
/** @var array{classes?: array<mixed>, methods?: array<mixed>, removed?: array<mixed>} $delta */
$classes = $delta['classes'] ?? [];
$methods = $delta['methods'] ?? [];
$removed = $delta['removed'] ?? [];
printf("W5 public surface: %d classes + %d methods, %d removals\n", count($classes), count($methods), count($removed));
if ($removed !== []) exit(1);
exit(0);
