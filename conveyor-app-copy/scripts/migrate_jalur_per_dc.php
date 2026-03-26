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
    echo "No jalur root found\n";
    exit(0);
}

$legacy = [];
foreach ($root as $key => $value) {
    if (is_array($value) && isset($value['kodepos'])) {
        $legacy[(string) $key] = $value;
    }
}

if ($legacy === []) {
    echo "No legacy global jalur nodes to migrate.\n";
    exit(0);
}

$dcs = [];
$users = $db->getReference('users')->getValue();
if (is_array($users)) {
    foreach ($users as $user) {
        $dc = trim((string) ($user['dcName'] ?? ''));
        if ($dc !== '') {
            $dcs[$dc] = true;
        }
    }
}

$status = $db->getReference('conveyor/esp32_status')->getValue();
if (is_array($status)) {
    foreach ($status as $row) {
        $dc = trim((string) ($row['dcName'] ?? ''));
        if ($dc !== '') {
            $dcs[$dc] = true;
        }
    }
}

$dcList = array_keys($dcs);
sort($dcList, SORT_NATURAL | SORT_FLAG_CASE);

$migrated = [];
$skipped = [];

foreach ($dcList as $dc) {
    $dcPath = 'conveyor/jalur/' . $dc;
    $dcNode = $db->getReference($dcPath)->getValue();

    if (is_array($dcNode) && count($dcNode) > 0) {
        $skipped[] = $dc;
        continue;
    }

    $payload = [];
    foreach ($legacy as $jalurName => $info) {
        $rawKodePos = $info['kodepos'] ?? [];
        $kodePosList = [];
        foreach ((array) $rawKodePos as $item) {
            $kodePos = trim((string) $item);
            if ($kodePos !== '') {
                $kodePosList[$kodePos] = true;
            }
        }

        $payload[$jalurName] = [
            'kodepos' => array_keys($kodePosList),
            'dcName' => $dc,
            'migrated_from' => 'legacy_global',
            'migrated_at' => date('Y-m-d H:i:s'),
        ];
    }

    $db->getReference($dcPath)->set($payload);
    $migrated[] = $dc;
}

echo 'Legacy nodes: ' . implode(', ', array_keys($legacy)) . "\n";
echo 'DC detected: ' . implode(', ', $dcList) . "\n";
echo 'DC migrated: ' . implode(', ', $migrated) . "\n";
echo 'DC skipped (already had mapping): ' . implode(', ', $skipped) . "\n";
