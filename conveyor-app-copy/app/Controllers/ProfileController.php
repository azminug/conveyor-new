<?php
// ci4_app/app/Controllers/ProfileController.php

namespace App\Controllers;

class ProfileController extends BaseController
{
    public function index()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        $email = $this->session->get('email');
        return view('profile', [
            'email'        => $email,
            'currentMenu'  => 'profile',
            'title'        => 'Profil Pengguna'
        ]);
    }

    public function updatePassword()
    {
        if ($this->request->getMethod() !== 'post') {
            return $this->response->setJSON(['success' => false, 'message' => 'Permintaan tidak valid.']);
        }

        $currentPassword = $this->request->getPost('currentPassword');
        $newPassword     = $this->request->getPost('newPassword');

        $email = $this->session->get('email');

        try {
            // Autentikasi ulang pengguna menggunakan password lama
            $signInResult = $this->auth->signInWithEmailAndPassword($email, $currentPassword);
            $user         = $signInResult->data();

            // Update password
            $this->auth->changeUserPassword($user['localId'], $newPassword);

            return $this->response->setJSON(['success' => true]);
        } catch (\Exception $e) {
            return $this->response->setJSON(['success' => false, 'message' => 'Password lama salah atau terjadi kesalahan lainnya.']);
        }
    }
}
