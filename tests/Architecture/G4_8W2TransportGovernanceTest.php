<?php
declare(strict_types=1);
require __DIR__.'/_assert.php';
$root = dirname(__DIR__, 2);
$source = $root.'/src/Framework/Transport/Transport.php';
architecture_check(is_file($source), 'W2 transport source exists');
$text = (string) file_get_contents($source);
foreach (['$GLOBALS', 'Promise', 'Future', 'Swoole', 'React\\Promise', 'Amp\\', 'eventLoop', 'stream_socket_client', 'curl_init'] as $forbidden) {
    architecture_check(!str_contains($text, $forbidden), 'W2 remains vendor/runtime-neutral: '.$forbidden);
}
architecture_check(str_contains($text, 'interface RemoteTransportInterface'), 'transport interface exists');
architecture_check(str_contains($text, 'enum TransportOutcome'), 'transport outcome taxonomy exists');
architecture_check(str_contains($text, 'final readonly class RemoteRequest'), 'remote request is immutable');
architecture_check(str_contains($text, 'final readonly class RemoteTransportResult'), 'transport result is immutable');
architecture_check(str_contains($text, '1_048_576'), 'payload bound exists');
architecture_check(str_contains($text, '32'), 'metadata count bound exists');
architecture_check(str_contains($text, 'isCancelled'), 'cancellation boundary is explicit');
architecture_check(str_contains($text, 'INDETERMINATE'), 'indeterminate outcome is first-class');
architecture_check(str_contains($text, 'NO AUTOMATIC RETRY') === false, 'W2 does not embed retry policy text as executable behavior');

echo "G4.8 W2 architecture governance PASS\n";
