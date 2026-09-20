<?php
/**
 * Regenerate urumiai-store-for-ai/schema/tools.json from the implementation.
 *
 * The published schema is derived, never hand-written: store-for-ai.test.js
 * pins the file against Urumiai_Store_For_Ai_Tools::public_definitions(), so
 * any drift fails the suite and the fix is to re-run this script.
 *
 * Usage: php tests/php/generate-schema.php
 */

$cli = __DIR__ . '/store-cli.php';
$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));

$process = proc_open('php ' . escapeshellarg($cli), $descriptors, $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "could not start the CLI harness\n");
    exit(1);
}

fwrite($pipes[0], json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list')) . "\n");
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

$decoded = json_decode(trim($stdout), true);
if (!isset($decoded['result']['tools'])) {
    fwrite(STDERR, "unexpected tools/list response: {$stdout}\n");
    exit(1);
}

$document = array(
    '$schema'    => 'https://modelcontextprotocol.io/schema/tools',
    '_comment'   => 'GENERATED from Urumiai_Store_For_Ai_Tools::public_definitions(). Do not hand-edit — store-for-ai.test.js pins this file against the implementation, so drift fails the suite. Regenerate with: php tests/php/generate-schema.php',
    'serverName' => 'urumiai-store-for-ai',
    'tools'      => $decoded['result']['tools'],
);

$target = realpath(dirname(__DIR__, 2)) . '/urumiai-store-for-ai/schema/tools.json';

file_put_contents($target, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "wrote {$target}\n";
