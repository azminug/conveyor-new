<?php

namespace App\Libraries;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Database;

class Firebase
{
    protected $database;
    protected $auth;

    public function __construct()
    {
        // Path ke serviceAccountKey.json
        $serviceAccountPath = APPPATH . 'Config/serviceAccountKey.json';

        if (!file_exists($serviceAccountPath)) {
            throw new \Exception("Service account key file not found at: " . $serviceAccountPath);
        }

        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->withDatabaseUri('https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app');

        $this->database = $factory->createDatabase();
        $this->auth = $factory->createAuth();
    }

    /**
     * Mendapatkan instance Database Firebase
     *
     * @return Database
     */
    public function getDatabase(): Database
    {
        return $this->database;
    }

    /**
     * Mendapatkan instance Auth Firebase
     *
     * @return Auth
     */
    public function getAuth(): Auth
    {
        return $this->auth;
    }
}
