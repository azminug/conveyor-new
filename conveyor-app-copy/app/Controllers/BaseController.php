<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class BaseController extends Controller
{
    protected $firebase;
    protected $auth;
    protected $database;
    protected $session;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        $this->firebase = new \App\Libraries\Firebase();
        $this->auth = $this->firebase->getAuth();
        $this->database = $this->firebase->getDatabase();
        $this->session = session();
        helper(['url', 'form']);
    }
}
