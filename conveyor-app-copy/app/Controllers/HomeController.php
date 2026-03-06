<?php
// ci4_app/app/Controllers/HomeController.php

namespace App\Controllers;

class HomeController extends BaseController
{
    public function index()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        // Get paket data from Firebase
        $paketData = $this->database->getReference('conveyor/paket')->getValue() ?? [];
        
        // Filter by dcName for non-admin users
        $userRole = $this->session->get('role');
        $userDcName = $this->session->get('dcName');
        
        if ($userRole !== 'admin' && $userDcName) {
            $paketData = array_filter($paketData, function($paket) use ($userDcName) {
                return isset($paket['dcName']) && $paket['dcName'] === $userDcName;
            });
        }
        
        // Calculate stats
        $stats = [
            'total' => count($paketData),
            'datang' => 0,
            'tersortir' => 0,
            'proses' => 0,
            'gagal' => 0,
            'rusak' => 0,
            'rfid' => 0
        ];
        
        $dcStats = [
            'DC-A' => ['datang' => 0, 'tersortir' => 0, 'proses' => 0, 'gagal' => 0],
            'DC-B' => ['datang' => 0, 'tersortir' => 0, 'proses' => 0, 'gagal' => 0],
            'DC-C' => ['datang' => 0, 'tersortir' => 0, 'proses' => 0, 'gagal' => 0]
        ];
        
        foreach ($paketData as $paket) {
            $status = $paket['status'] ?? '';
            $dcName = $paket['dcName'] ?? '';
            $damage = $paket['damage'] ?? 'Tidak Rusak';
            $scanMethod = $paket['scan_method'] ?? $paket['scanType'] ?? '';
            
            // Count status
            if ($status === 'Datang') $stats['datang']++;
            elseif ($status === 'Tersortir') $stats['tersortir']++;
            elseif ($status === 'Proses') $stats['proses']++;
            elseif ($status === 'Gagal') $stats['gagal']++;
            
            // Count damage
            if ($damage !== 'Tidak Rusak' && !empty($damage)) $stats['rusak']++;
            
            // Count RFID
            if ($scanMethod === 'rfid') $stats['rfid']++;
            
            // DC Stats
            if (isset($dcStats[$dcName])) {
                if ($status === 'Datang') $dcStats[$dcName]['datang']++;
                elseif ($status === 'Tersortir') $dcStats[$dcName]['tersortir']++;
                elseif ($status === 'Proses') $dcStats[$dcName]['proses']++;
                elseif ($status === 'Gagal') $dcStats[$dcName]['gagal']++;
            }
        }
        
        // Get recent pakets (sorted by updated_at)
        $recentPakets = $paketData;
        usort($recentPakets, function($a, $b) {
            $timeA = $a['updated_at'] ?? $a['waktu_sortir'] ?? '';
            $timeB = $b['updated_at'] ?? $b['waktu_sortir'] ?? '';
            return strcmp($timeB, $timeA);
        });
        $recentPakets = array_slice($recentPakets, 0, 10);

        return view('home', [
            'currentMenu' => 'home',
            'title' => 'Dashboard',
            'stats' => $stats,
            'dcStats' => $dcStats,
            'recentPakets' => $recentPakets
        ]);
    }
}
