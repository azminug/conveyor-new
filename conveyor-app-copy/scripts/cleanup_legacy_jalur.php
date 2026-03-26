<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;

$factory = (new Factory())
    ->withServiceAccount(__DIR__ . '/../serviceAccountKey.json')
    ->withDatabaseUri('https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app');

$db = $factory->createDatabase();
$root = $db->getReference('conveyor/jalur')->getValue();

if (!is_array($root)) {
    echo "No conveyor/jalur node found.\n";
    exit(0);
}

$legacy = [];
foreach ($root as $key => $value) {
    if (is_array($value) && isset($value['kodepos'])) {
        $legacy[(string) $key] = $value;
    }
}

if ($legacy === []) {
    echo "No legacy global jalur nodes found. Nothing to clean.\n";
    exit(0);
}

$timestamp = date('Ymd_His');
$backupFile = __DIR__ . '/backup_legacy_jalur_' . $timestamp . '.json';
file_put_contents($backupFile, json_encode($legacy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$db->getReference('conveyor/jalur_legacy_backup/' . $timestamp)->set([
    'source_path' => 'conveyor/jalur',
    'created_at' => date('Y-m-d H:i:s'),
    'legacy_nodes' => $legacy,
]);

$deleted = [];
foreach (array_keys($legacy) as $jalurName) {
    $db->getReference('conveyor/jalur/' . $jalurName)->remove();
    $deleted[] = $jalurName;
}

echo 'Backed up legacy nodes to Firebase: conveyor/jalur_legacy_backup/' . $timestamp . PHP_EOL;
echo 'Backed up legacy nodes to file: ' . $backupFile . PHP_EOL;
echo 'Deleted legacy nodes: ' . implode(', ', $deleted) . PHP_EOL;
