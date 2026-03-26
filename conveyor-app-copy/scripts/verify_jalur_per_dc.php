<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;

$factory = (new Factory())
    ->withServiceAccount(__DIR__ . '/../serviceAccountKey.json')
    ->withDatabaseUri('https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app');

$db = $factory->createDatabase();

$dcs = ['DC-A', 'DC-B'];
foreach ($dcs as $dc) {
    $node = $db->getReference('conveyor/jalur/' . $dc)->getValue();
    $count = is_array($node) ? count($node) : 0;
    echo $dc . ' jalur count: ' . $count . PHP_EOL;
    if (is_array($node)) {
        echo '  keys: ' . implode(', ', array_keys($node)) . PHP_EOL;
    }
}
