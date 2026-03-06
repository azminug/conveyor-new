<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        $currentMenu = 'home'; // Atur sesuai kebutuhan
        return view('home', [
            'currentMenu' => $currentMenu,
            'title' => 'Dashboard'
        ]);
    }
}
