<?php

namespace App\Controllers;

class AdminController extends BaseController
{
    public function index()
    {
        return view('admin/dashboard', ['title' => 'Dashboard Admin']);
    }

    public function createDC()
    {
        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        try {
            $user = $this->auth->createUserWithEmailAndPassword($email, $password);
            $this->database->getReference("users/{$user->uid}")->set([
                'email' => $email,
                'role' => 'dc',
            ]);

            return $this->response->setJSON(['status' => 'success', 'message' => 'Akun DC berhasil dibuat.']);
        } catch (\Exception $e) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Gagal membuat akun DC.']);
        }
    }

    public function listAllPakets()
    {
        $paketSortData = $this->database->getReference('conveyor/paket_sort')->getValue();
        return view('admin/all_pakets', ['paketData' => $paketSortData, 'title' => 'Semua Data Paket']);
    }
}
