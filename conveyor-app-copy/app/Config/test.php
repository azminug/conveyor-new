<?php

require __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;

try {
    $factory = (new Factory)
        ->withServiceAccount(__DIR__ . '/app/Config/serviceAccountKey.json')
        ->withDatabaseUri('https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app');

    $database = $factory->createDatabase();
    $auth = $factory->createAuth();

    echo "Koneksi Firebase berhasil!";
} catch (\Exception $e) {
    echo "Gagal terhubung ke Firebase: " . $e->getMessage();
}
