<?php
// ci4_app/app/Config/Routes.php

namespace Config;

// Create a new instance of our RouteCollection class.
$routes = Services::routes();

// Load the system's routing file first, so that the app and ENVIRONMENT
// can override as needed.
if (file_exists(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

/**
 * --------------------------------------------------------------------
 * Router Setup
 * --------------------------------------------------------------------
 */
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('HomeController');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
// $routes->setAutoRoute(false);

/**
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// Auth Routes
$routes->get('login', 'AuthController::login', ['as' => 'login']);
$routes->post('authenticate', 'AuthController::authenticate', ['as' => 'authenticate']);
$routes->get('logout', 'AuthController::logout', ['as' => 'logout']);

// Apply 'adminOnly' filter to register routes
$routes->group('', ['filter' => 'adminOnly'], function ($routes) {
    $routes->get('register', 'AuthController::register', ['as' => 'register']);
    $routes->post('register', 'AuthController::store', ['as' => 'store_register']);
    /* $routes->get('/tambah_user', 'PaketController::tambahUser');
    $routes->post('/tambah_user_proses', 'PaketController::tambahUserProses'); */
});

// Home Route
$routes->get('/', 'HomeController::index', ['as' => 'home']);

// Paket Routes
$routes->get('daftar_paket_sort', 'PaketController::daftarPaketSort', ['as' => 'daftar_paket_sort']);
$routes->get('daftar_paket', 'PaketController::daftarPaket', ['as' => 'daftar_paket']);
$routes->post('update_paket', 'PaketController::updatePaket', ['as' => 'update_paket']);
$routes->post('delete_paket', 'PaketController::deletePaket', ['as' => 'delete_paket']);
$routes->post('create_paket', 'PaketController::createPaket', ['as' => 'create_paket']);

$routes->get('fetch_data', 'PaketController::fetchData', ['as' => 'fetch_data']);

// Profile Routes
$routes->get('profile', 'ProfileController::index', ['as' => 'profile']);
$routes->post('profile/updatePassword', 'ProfileController::updatePassword', ['as' => 'update_password']);

// Deteksi Paket
$routes->get('paket/upload', 'PaketController::upload', ['as' => 'paket_upload']);
$routes->post('paket/detect', 'PaketController::detect', ['as' => 'paket_detect']);

// Stream
$routes->get('paket/stream', 'PaketController::stream', ['as' => 'paket_stream']);

$routes->get('detection/get_recent_packages', 'PaketController::get_recent_packages');

$routes->get('get_filtered_data', 'PaketController::get_filtered_data');

// Lihat Grafik
$routes->get('lihat_grafik', 'PaketController::lihatGrafik', ['filter' => 'auth', 'filter' => 'adminOnly']);


/**
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 *
 * Load additional route files per environment if they exist.
 */
if (file_exists(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
