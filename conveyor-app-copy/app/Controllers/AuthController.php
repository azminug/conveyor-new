<?php
// ci4_app/app/Controllers/AuthController.php

namespace App\Controllers;

use CodeIgniter\Controller;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\InvalidPassword;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Exception\Auth\EmailExists;

class AuthController extends BaseController
{
    protected $auth;
    protected $database;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(ROOTPATH . 'serviceAccountKey.json')
            ->withDatabaseUri('https://conveyor-981da-default-rtdb.asia-southeast1.firebasedatabase.app');

        $this->auth = $factory->createAuth();
        $this->database = $factory->createDatabase();
    }

    public function login()
    {
        // Jika pengguna sudah login, arahkan ke home
        if (session()->get('isLoggedIn')) {
            return redirect()->to('/');
        }

        return view('auth/login', [
            'currentMenu' => '',
            'title'       => 'Login'
        ]);
    }

    public function authenticate()
    {
        $email    = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        log_message('debug', 'Attempting login with email: ' . $email);
        log_message('debug', 'Password: ' . $password);

        try {
            // Sign in dengan Firebase Auth
            $signInResult = $this->auth->signInWithEmailAndPassword($email, $password);
            $userData     = $signInResult->data();

            // Dapatkan UID user
            $uid = $userData['localId'];
            log_message('debug', 'User UID: ' . $uid);

            // Ambil data user dari Realtime Database, misal di node "users/<uid>"
            $userInDb = $this->database->getReference('users/' . $uid)->getValue();
            log_message('debug', 'User data from DB: ' . json_encode($userInDb));

            if (!$userInDb) {
                // Jika data user tidak ada di DB, tambahkan data baru
                $newUserData = [
                    'email' => $email,
                    'role' => 'admin', // Atur sesuai kebutuhan
                    'dcName' => ''
                ];

                $this->database->getReference('users/' . $uid)->set($newUserData);
                log_message('debug', 'User data added to DB for UID: ' . $uid);

                $userInDb = $newUserData;
            }

            // Simpan data user ke session
            session()->set([
                'user_id'    => $uid,
                'email'      => $userInDb['email'],
                'role'       => $userInDb['role'],   // 'admin' atau 'dc'
                'dcName'     => isset($userInDb['dcName']) ? $userInDb['dcName'] : '',
                'isLoggedIn' => true
            ]);

            log_message('debug', 'User session set successfully for UID: ' . $uid);
            return redirect()->to('/');
        } catch (InvalidPassword $e) {
            session()->setFlashdata('error', 'Password salah.');
            log_message('error', 'Invalid password for email: ' . $email);
            return redirect()->back()->withInput();
        } catch (UserNotFound $e) {
            session()->setFlashdata('error', 'Email tidak ditemukan.');
            log_message('error', 'User not found for email: ' . $email);
            return redirect()->back()->withInput();
        } catch (\Exception $e) {
            log_message('error', 'Login error: ' . $e->getMessage());
            session()->setFlashdata('error', 'Terjadi kesalahan: ' . $e->getMessage());
            return redirect()->back()->withInput();
        }
    }


    public function logout()
    {
        session()->destroy();
        return redirect()->to('/login');
    }

    /**
     * Hanya ADMIN yang bisa akses method ini (menambah user baru).
     * Form register bisa dikontrol dengan filter.
     */
    public function register()
    {
        // Pastikan hanya admin yang bisa menambah user
        if (session()->get('role') !== 'admin') {
            return redirect()->to('/')->with('error', 'Anda tidak memiliki akses.');
        }

        return view('auth/register', [
            'title' => 'Register User'
        ]);
    }

    public function store()
    {
        // Ambil data dari form
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $confirmPassword = $this->request->getPost('confirm_password');
        $dcName = $this->request->getPost('dcName');
        $role = $this->request->getPost('role');

        // Validasi data
        if (empty($email) || empty($password) || empty($dcName) || empty($role)) {
            return redirect()->back()->with('error', 'Semua field wajib diisi.');
        }

        if ($password !== $confirmPassword) {
            return redirect()->back()->with('error', 'Password dan konfirmasi password tidak cocok.');
        }

        if (!in_array($role, ['admin', 'dc'])) {
            return redirect()->back()->with('error', 'Role tidak valid.');
        }

        if (!preg_match('/^DC-\w+$/', $dcName)) {
            return redirect()->back()->with('error', 'Format DC Name tidak valid. Gunakan format seperti DC-A.');
        }

        try {
            // Tambahkan user ke Firebase Authentication
            $user = $this->auth->createUserWithEmailAndPassword($email, $password);
            $uid = $user->uid;

            // Simpan data user ke Firebase Realtime Database
            $this->database->getReference("users/{$uid}")->set([
                'email' => $email,
                'dcName' => $dcName,
                'role' => $role,
            ]);

            return redirect()->to(base_url('register'))->with('success', 'User berhasil didaftarkan.');
        } catch (\Kreait\Firebase\Exception\Auth\AuthError $e) {
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }
}
