<?php
// ci4_app/app/Controllers/PaketController.php

namespace App\Controllers;

use App\Controllers\BaseController;

class PaketController extends BaseController
{
    public function daftarPaketSort()
    {
        // Periksa apakah pengguna sudah login
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        // Ambil informasi session pengguna
        $role   = $this->session->get('role');   // admin atau dcName
        $dcName = $this->session->get('dcName'); // dcName jika role = DC

        // Dapatkan reference "conveyor/paket"
        $paketRef = $this->database->getReference('conveyor/paket');

        // Ambil data berdasarkan role pengguna
        if ($role === 'admin') {
            $paketData = $paketRef->getValue();
        } else {
            $paketData = $paketRef
                ->orderByChild('dcName')
                ->equalTo($dcName)
                ->getValue();
        }

        // Proses data
        $processedData = [];
        if ($paketData) {
            foreach ($paketData as $key => $paket) {
                $processedData[] = array_merge([
                    'key' => $key
                ], $paket);
            }

            // Urutkan data berdasarkan updated_at atau waktu_sortir (descending)
            usort($processedData, function ($a, $b) {
                return strtotime($b['waktu_sortir'] ?? $b['updated_at'] ?? '1970-01-01') <=> strtotime($a['waktu_sortir'] ?? $a['updated_at'] ?? '1970-01-01');
            });
        }

        // Filter Data Berdasarkan Status
        $filter = $this->request->getGet('filter');
        if (!empty($filter)) {
            $processedData = array_filter($processedData, function ($paket) use ($filter) {
                return $paket['status'] === $filter;
            });
        }

        // Filter Data Berdasarkan Kerusakan
        $damageFilter = $this->request->getGet('damageFilter');
        if (!empty($damageFilter)) {
            $processedData = array_filter($processedData, function ($paket) use ($damageFilter) {
                return $paket['damage'] === $damageFilter;
            });
        }

        // Search Data Berdasarkan ID atau Tujuan
        $search = $this->request->getGet('search');
        if (!empty($search)) {
            $searchTerm = strtolower($search);
            $processedData = array_filter($processedData, function ($paket) use ($searchTerm) {
                return strpos(strtolower($paket['id']), $searchTerm) !== false ||
                    strpos(strtolower($paket['dcName']), $searchTerm) !== false;
            });
        }

        // Setup Pagination
        $entriesPerPage = $this->request->getGet('entries') ? (int)$this->request->getGet('entries') : 10;
        $currentPage    = $this->request->getGet('page') ? (int)$this->request->getGet('page') : 1;

        $totalEntries   = count($processedData);
        $totalPages     = ceil($totalEntries / $entriesPerPage);
        $startIndex     = ($currentPage - 1) * $entriesPerPage;

        $paginatedData  = array_slice($processedData, $startIndex, $entriesPerPage);

        // Menghitung Data untuk Grafik
        $statusCount = [
            'Datang'    => 0,
            'Tersortir' => 0,
            'Proses'    => 0,
            'Gagal'     => 0,
        ];

        $damageCount = [
            'Tidak Rusak'   => 0,
            'Rusak Ringan'  => 0,
            'Rusak'         => 0,
            'Sangat Rusak'  => 0,
        ];

        if ($paketData) {
            foreach ($paketData as $paket) {
                // Hitung jumlah status untuk grafik
                $status = $paket['status'] ?? '-';
                if (isset($statusCount[$status])) {
                    $statusCount[$status]++;
                }

                // Hitung jumlah damage untuk grafik
                $damage = $paket['damage'] ?? 'Tidak Rusak';
                if (isset($damageCount[$damage])) {
                    $damageCount[$damage]++;
                }
            }
        }

        // Kirim data ke view
        return view('daftar_paket_sort', [
            'paginatedData'  => $paginatedData,
            'totalPages'     => $totalPages,
            'currentPage'    => $currentPage,
            'entriesPerPage' => $entriesPerPage,
            'statusCount'    => $statusCount,
            'damageCount'    => $damageCount,
            'title'          => 'Daftar Paket Sort',
            'currentMenu'    => 'daftar_paket_sort',
            'filter'         => $filter,          // Kirim filter ke view
            'damageFilter'   => $damageFilter,    // Kirim damageFilter ke view
            'search'         => $search,          // Kirim search term ke view
        ]);
    }


    public function daftarPaket()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        $role   = $this->session->get('role');
        $dcName = $this->session->get('dcName');

        // Referensi "conveyor/paket"
        $paketRef = $this->database->getReference('conveyor/paket');

        if ($role === 'admin') {
            $paketData = $paketRef->getValue();
        } else {
            $paketData = $paketRef
                ->orderByChild('dcName')
                ->equalTo($dcName)
                ->getValue();
        }

        return view('daftar_paket', [
            'paketData'   => $paketData,
            'currentMenu' => 'daftar_paket',
            'title'       => 'Daftar Paket',
        ]);
    }

    public function createPaket()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['error' => 'Unauthorized'], 401);
        }

        $role   = $this->session->get('role');
        $dcName = $this->session->get('dcName');

        // Ambil data dari form
        $pengirim         = $this->request->getPost('pengirim');
        $alamat_pengirim  = $this->request->getPost('alamat_pengirim');
        $telepon_pengirim = $this->request->getPost('telepon_pengirim');
        $penerima         = $this->request->getPost('penerima');
        $alamat_penerima  = $this->request->getPost('alamat_penerima');
        $telepon_penerima = $this->request->getPost('telepon_penerima');
        $kode_pos         = $this->request->getPost('kode_pos');
        $esp32_id         = $this->request->getPost('esp32_id');
        $rfid_epc         = $this->request->getPost('rfid_epc');

        // Validasi field wajib
        if (!$pengirim || !$alamat_pengirim || !$telepon_pengirim || !$penerima || !$alamat_penerima || !$telepon_penerima || !$kode_pos) {
            return $this->response->setJSON(['error' => 'Semua field wajib diisi!'], 400);
        }

        // Generate ID Paket (No Resi) otomatis
        $newId = 'PKT-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $waktu_input = date('Y-m-d H:i:s');
        $status = 'Datang';
        $damage = '';

        // Tentukan jalur berdasarkan kode pos
        $jalurSnapshot = $this->database->getReference('conveyor/jalur')->getValue();
        $jalur = '';
        if ($jalurSnapshot) {
            foreach ($jalurSnapshot as $jalurKey => $info) {
                if (isset($info['kodepos']) && in_array($kode_pos, $info['kodepos'])) {
                    $jalur = $jalurKey;
                    break;
                }
            }
        }

        // Determine scan method based on rfid_epc
        $scanMethod = $rfid_epc ? 'rfid' : 'barcode';

        $data = [
            'id'                => $newId,
            'pengirim'          => $pengirim,
            'alamat_pengirim'   => $alamat_pengirim,
            'telepon_pengirim'  => $telepon_pengirim,
            'penerima'          => $penerima,
            'alamat_penerima'   => $alamat_penerima,
            'telepon_penerima'  => $telepon_penerima,
            'kode_pos'          => $kode_pos,
            'dcName'            => $role === 'admin' ? ($this->request->getPost('dcName') ?: $dcName) : $dcName,
            'waktu_input'       => $waktu_input,
            'status'            => $status,
            'jalur'             => $jalur,
            'damage'            => $damage,
            'esp32_id'          => $esp32_id ?: '',
            'rfid_epc'          => $rfid_epc ?: '',
            'scan_method'       => $scanMethod,
        ];

        $this->database->getReference('conveyor/paket/' . $newId)->set($data);
        return $this->response->setJSON(['message' => 'Data berhasil ditambahkan!', 'id' => $newId]);
    }

    public function updatePaket()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['error' => 'Unauthorized'], 401);
        }

        $key    = $this->request->getPost('key');
        $status = $this->request->getPost('status');
        $damage = $this->request->getPost('damage');
        $jalur  = $this->request->getPost('jalur');
        $esp32_id = $this->request->getPost('esp32_id');
        $rfid_epc = $this->request->getPost('rfid_epc');

        // Field lain
        $pengirim         = $this->request->getPost('pengirim');
        $alamat_pengirim  = $this->request->getPost('alamat_pengirim');
        $telepon_pengirim = $this->request->getPost('telepon_pengirim');
        $penerima         = $this->request->getPost('penerima');
        $alamat_penerima  = $this->request->getPost('alamat_penerima');
        $telepon_penerima = $this->request->getPost('telepon_penerima');
        $kode_pos         = $this->request->getPost('kode_pos');

        if ($key) {
            $existingData = $this->database->getReference('conveyor/paket/' . $key)->getValue();
            if (!$existingData) {
                return $this->response->setJSON(['error' => 'Data tidak ditemukan!'], 404);
            }

            $role   = $this->session->get('role');
            $dcName = $this->session->get('dcName');
            if ($role !== 'admin' && ($existingData['dcName'] ?? $existingData['DC_name']) !== $dcName) {
                return $this->response->setJSON(['error' => 'Tidak memiliki hak akses untuk mengupdate data ini.'], 403);
            }

            $updatedData = [
                'status'           => $status ?: $existingData['status'],
                'damage'           => $damage ?? $existingData['damage'],
                'jalur'            => $jalur ?: $existingData['jalur'],
                'esp32_id'         => $esp32_id ?? $existingData['esp32_id'],
                'rfid_epc'         => $rfid_epc ?? $existingData['rfid_epc'] ?? '',
                'pengirim'         => $pengirim ?? $existingData['pengirim'],
                'alamat_pengirim'  => $alamat_pengirim ?? $existingData['alamat_pengirim'],
                'telepon_pengirim' => $telepon_pengirim ?? $existingData['telepon_pengirim'],
                'penerima'         => $penerima ?? $existingData['penerima'],
                'alamat_penerima'  => $alamat_penerima ?? $existingData['alamat_penerima'],
                'telepon_penerima' => $telepon_penerima ?? $existingData['telepon_penerima'],
                'kode_pos'         => $kode_pos ?? $existingData['kode_pos'],
                'scan_method'      => $existingData['scan_method'] ?? $existingData['scanType'] ?? '',
                'updated_at'       => date('Y-m-d H:i:s'),
            ];
            $this->database->getReference('conveyor/paket/' . $key)->update($updatedData);
            return $this->response->setJSON(['message' => 'Data berhasil diperbarui!']);
        }
        return $this->response->setJSON(['error' => 'Data tidak valid!'], 400);
    }

    public function deletePaket()
    {
        // Hanya admin atau DC yang boleh menghapus
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['error' => 'Unauthorized'], 401);
        }

        $key = $this->request->getPost('key');

        if ($key) {
            // Ambil data existing
            $existingData = $this->database->getReference("conveyor/paket/{$key}")->getValue();
            if (!$existingData) {
                return $this->response->setJSON(['error' => 'Data tidak ditemukan!'], 404);
            }

            // Cek hak akses
            $role   = $this->session->get('role');
            $dcName = $this->session->get('dcName');

            if ($role !== 'admin' && $existingData['dcName'] !== $dcName) {
                return $this->response->setJSON(['error' => 'Tidak memiliki hak akses untuk menghapus data ini.'], 403);
            }

            // Hapus data
            $this->database->getReference("conveyor/paket/{$key}")->remove();

            return $this->response->setJSON(['message' => 'Data berhasil dihapus!']);
        }

        return $this->response->setJSON(['error' => 'Data tidak valid!'], 400);
    }

    public function upload()
    {
        return view('detection/upload_view', [
            'title' => 'Deteksi Kerusakan Paket',
        ]);
    }

    public function detect()
    {
        $file = $this->request->getFile('image');
        if ($file->isValid() && !$file->hasMoved()) {
            $filePath = WRITEPATH . 'uploads/' . $file->getName();
            $file->move(WRITEPATH . 'uploads');

            // Kirim file ke API Flask
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => 'http://localhost:5000/detect', # Sesuaikan dengan endpoint Flask Anda
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'file' => curl_file_create($filePath)
                ]
            ]);
            $response = curl_exec($curl);
            curl_close($curl);

            $data = json_decode($response, true);
            return view('detection/result_view', [
                'title'  => 'Hasil Deteksi Paket',
                'result' => $data,
            ]);
        }

        return redirect()->back()->with('error', 'Gagal mengunggah file!');
    }

    public function stream()
    {
        // Pastikan user login
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        // Ambil dcName dari session
        $dcName = $this->session->get('dcName');
        log_message('debug', "DC Name dari session: " . $dcName);

        // Ambil data dari conveyor/paket untuk menampilkan 5 data terbaru di stream_view
        $paketData = $this->database->getReference('conveyor/paket')->getValue();

        $processedData = [];
        if ($paketData) {
            foreach ($paketData as $key => $paket) {
                $processedData[] = [
                    'key'        => $key,
                    'id'         => $paket['id'] ?? '-',
                    'jalur'      => $paket['jalur'] ?? '-',
                    'status'     => $paket['status'] ?? '-',
                    'updated_at' => $paket['updated_at'] ?? '-',
                    'damage'     => $paket['damage'] ?? '-',
                    'dcName'     => $paket['dcName'] ?? '-',
                ];
            }

            // Urutkan data terbaru berdasarkan updated_at desc
            usort($processedData, function ($a, $b) {
                return strtotime($b['updated_at']) <=> strtotime($a['updated_at']);
            });
        }

        // Ambil 5 data terbaru
        $recentPackages = array_slice($processedData, 0, 5);

        // Tampilkan view stream_view dengan data dan dcName
        return view('detection/stream_view', [
            'title'          => 'Live Video Stream',
            'currentMenu'    => 'stream',
            'recentPackages' => $recentPackages,
            'dcName'         => $dcName, // Tambahkan dcName di sini
        ]);
    }

    public function lihatGrafik()
    {
        // Ambil data dari Firebase
        $paketRef = $this->database->getReference('conveyor/paket');
        $paketData = $paketRef->getValue();

        // Inisialisasi array untuk grafik
        $statusPaket = [];
        $kerusakanPaket = [];
        $damageCountDC = [];

        // Daftar DC yang valid
        $validDCs = ['DC-A', 'DC-B', 'DC-C'];

        // Proses data untuk grafik
        if ($paketData) {
            foreach ($paketData as $key => $paket) {
                // Normalisasi dcName
                $dcName = isset($paket['dcName']) ? strtoupper(trim($paket['dcName'])) : 'UNKNOWN_DC';
                if (!in_array($dcName, $validDCs)) {
                    $dcName = 'UNKNOWN_DC';
                }

                // Grafik Status Paket dari berbagai DC
                $status = isset($paket['status']) ? trim($paket['status']) : 'UNKNOWN_STATUS';
                if (!isset($statusPaket[$dcName])) {
                    $statusPaket[$dcName] = [];
                }
                if (!isset($statusPaket[$dcName][$status])) {
                    $statusPaket[$dcName][$status] = 0;
                }
                $statusPaket[$dcName][$status]++;

                // Grafik Kerusakan Paket dari berbagai DC
                $damage = isset($paket['damage']) ? trim($paket['damage']) : 'Tidak Rusak';
                if (!isset($kerusakanPaket[$dcName])) {
                    $kerusakanPaket[$dcName] = [
                        'Tidak Rusak' => 0,
                        'Rusak Ringan' => 0,
                        'Rusak' => 0,
                        'Sangat Rusak' => 0,
                    ];
                }
                if (!isset($kerusakanPaket[$dcName][$damage])) {
                    $kerusakanPaket[$dcName][$damage] = 0;
                }
                $kerusakanPaket[$dcName][$damage]++;
                
                // Hitung total kerusakan per DC (exclude 'Tidak Rusak')
                if ($damage !== 'Tidak Rusak' && !empty($damage)) {
                    if (!isset($damageCountDC[$dcName])) {
                        $damageCountDC[$dcName] = 0;
                    }
                    $damageCountDC[$dcName]++;
                }
            }
        }
        
        // Cari DC dengan kerusakan terbanyak dan paling sedikit
        $dcPalingBanyakKerusakan = '-';
        $dcPalingSedikitKerusakan = '-';
        
        if (!empty($damageCountDC)) {
            arsort($damageCountDC);
            $dcPalingBanyakKerusakan = array_key_first($damageCountDC);
            $dcPalingSedikitKerusakan = array_key_last($damageCountDC);
        } elseif (!empty($kerusakanPaket)) {
            // Jika tidak ada kerusakan, ambil DC pertama
            $dcPalingBanyakKerusakan = array_key_first($kerusakanPaket);
            $dcPalingSedikitKerusakan = array_key_first($kerusakanPaket);
        }

        return view('grafik/lihat_grafik', [
            'statusPaket' => $statusPaket,
            'kerusakanPaket' => $kerusakanPaket,
            'damageCountDC' => $damageCountDC,
            'dcPalingBanyakKerusakan' => $dcPalingBanyakKerusakan,
            'dcPalingSedikitKerusakan' => $dcPalingSedikitKerusakan,
            'title' => 'Grafik Paket',
            'currentMenu' => 'lihat_grafik',
        ]);
    }

    public function prosesSortirPaket()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['error' => 'Unauthorized'], 401);
        }
        $id = $this->request->getPost('id');
        $damage = $this->request->getPost('damage');
        $status = $this->request->getPost('status');
        $jalur = $this->request->getPost('jalur');
        $esp32_id = $this->request->getPost('esp32_id');
        $dcName = $this->session->get('dcName');
        if (!$id || !$damage || !$status || !$jalur) {
            return $this->response->setJSON(['error' => 'Data tidak valid!'], 400);
        }
        $paket = $this->database->getReference('conveyor/paket/' . $id)->getValue();
        if (!$paket) {
            return $this->response->setJSON(['error' => 'Paket tidak ditemukan!'], 404);
        }
        // Catat histori sortir sebelum update
        $sortirLog = isset($paket['sortir_log']) ? $paket['sortir_log'] : [];
        $sortirLog[] = [
            'waktu'       => date('Y-m-d H:i:s'),
            'dcName'      => $dcName,
            'status'      => $status,
            'damage'      => $damage,
            'jalur'       => $jalur,
            'esp32_id'    => $esp32_id,
            'scan_method' => $paket['scan_method'] ?? $paket['scanType'] ?? '',
        ];
        $updateData = [
            'damage' => $damage,
            'status' => $status,
            'jalur' => $jalur,
            'esp32_id' => $esp32_id,
            'waktu_sortir' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'sortir_log' => $sortirLog,
            'dcName' => $dcName, // update dcName ke DC yang sortir
        ];
        $this->database->getReference('conveyor/paket/' . $id)->update($updateData);
        return $this->response->setJSON(['message' => 'Paket berhasil disortir!']);
    }


    public function register()
    {
        $data = [
            'currentMenu' => 'register', // Tentukan menu aktif
        ];

        return view('auth/register', $data); // Pastikan variabel ini dikirim ke view
    }

    public function get_recent_packages()
    {
        $role   = $this->session->get('role');
        $dcName = $this->session->get('dcName');

        // Referensi ke database
        $paketRef = $this->database->getReference('conveyor/paket');

        // Ambil data berdasarkan role pengguna
        if ($role === 'admin') {
            $paketData = $paketRef->getValue();
        } else {
            $paketData = $paketRef
                ->orderByChild('dcName')
                ->equalTo($dcName)
                ->getValue();
        }

        // Proses data menjadi array terurut
        $recentPackages = [];
        if ($paketData) {
            foreach ($paketData as $key => $paket) {
                $recentPackages[] = [
                    'key'        => $key,
                    'id'         => $paket['id'] ?? '-',
                    'jalur'      => $paket['jalur'] ?? '-',
                    'status'     => $paket['status'] ?? '-',
                    'updated_at' => $paket['updated_at'] ?? '-',
                    'damage'     => $paket['damage'] ?? '-',
                    'dcName'     => $paket['dcName'] ?? 'Unknown DC',
                ];
            }

            // Urutkan berdasarkan `updated_at` secara descending (data terbaru di atas)
            usort($recentPackages, function ($a, $b) {
                return strtotime($b['updated_at']) - strtotime($a['updated_at']);
            });

            // Ambil maksimal 5 data terbaru
            $recentPackages = array_slice($recentPackages, 0, 5);
        }

        // Jika tidak ada data sama sekali
        if (empty($recentPackages)) {
            return $this->response->setJSON([
                'recentPackages' => [],
                'message' => 'Tidak ada data yang tersedia.',
            ]);
        }
        return $this->response->setJSON([
            'recentPackages' => $recentPackages,
        ]);
    }

    public function pelacakanPaket($id = null)
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }
        if (!$id) {
            return redirect()->to('/daftar_paket')->with('error', 'ID paket tidak valid.');
        }
        $paket = $this->database->getReference('conveyor/paket/' . $id)->getValue();
        if (!$paket) {
            return redirect()->to('/daftar_paket')->with('error', 'Paket tidak ditemukan.');
        }
        $sortirLog = isset($paket['sortir_log']) ? $paket['sortir_log'] : [];
        // Urutkan histori terbaru di atas
        usort($sortirLog, function($a, $b) {
            return strtotime($b['waktu']) <=> strtotime($a['waktu']);
        });
        return view('pelacakan_paket', [
            'paket' => $paket,
            'sortirLog' => $sortirLog,
            'title' => 'Pelacakan Paket',
            'currentMenu' => 'pelacakan_paket',
        ]);
    }

    public function pelacakanPaketForm()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }
        if ($this->request->getMethod() === 'post') {
            $id = $this->request->getPost('id');
            if ($id) {
                return redirect()->to('/pelacakan_paket/' . $id);
            }
        }
        return view('pelacakan_paket_form', [
            'title' => 'Pelacakan Paket',
            'currentMenu' => 'pelacakan_paket',
        ]);
    }

    public function pelacakanPaketList()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }
        $role   = $this->session->get('role');
        $dcName = $this->session->get('dcName');
        $paketRef = $this->database->getReference('conveyor/paket');
        if ($role === 'admin') {
            $paketData = $paketRef->getValue();
        } else {
            $paketData = $paketRef
                ->orderByChild('dcName')
                ->equalTo($dcName)
                ->getValue();
        }
        // Proses data untuk filter dan pagination
        $processedData = [];
        if ($paketData) {
            foreach ($paketData as $key => $paket) {
                $processedData[] = array_merge([
                    'key' => $key
                ], $paket);
            }
            // Urutkan data terbaru di atas
            usort($processedData, function ($a, $b) {
                return strtotime($b['waktu_sortir'] ?? $b['updated_at'] ?? '1970-01-01') <=> strtotime($a['waktu_sortir'] ?? $a['updated_at'] ?? '1970-01-01');
            });
        }
        // Filter
        $filter = $this->request->getGet('filter');
        if (!empty($filter)) {
            $processedData = array_filter($processedData, function ($paket) use ($filter) {
                return $paket['status'] === $filter;
            });
        }
        $damageFilter = $this->request->getGet('damageFilter');
        if (!empty($damageFilter)) {
            $processedData = array_filter($processedData, function ($paket) use ($damageFilter) {
                return $paket['damage'] === $damageFilter;
            });
        }
        $search = $this->request->getGet('search');
        if (!empty($search)) {
            $searchTerm = strtolower($search);
            $processedData = array_filter($processedData, function ($paket) use ($searchTerm) {
                return strpos(strtolower($paket['id']), $searchTerm) !== false ||
                    strpos(strtolower($paket['dcName']), $searchTerm) !== false;
            });
        }
        // Pagination
        $entriesPerPage = $this->request->getGet('entries') ? (int)$this->request->getGet('entries') : 10;
        $currentPage    = $this->request->getGet('page') ? (int)$this->request->getGet('page') : 1;
        $totalEntries   = count($processedData);
        $totalPages     = ceil($totalEntries / $entriesPerPage);
        $startIndex     = ($currentPage - 1) * $entriesPerPage;
        $paginatedData  = array_slice($processedData, $startIndex, $entriesPerPage);
        return view('pelacakan_paket_list', [
            'paginatedData'  => $paginatedData,
            'totalPages'     => $totalPages,
            'currentPage'    => $currentPage,
            'entriesPerPage' => $entriesPerPage,
            'filter'         => $filter,
            'damageFilter'   => $damageFilter,
            'search'         => $search,
            'title'          => 'Pelacakan Paket',
            'currentMenu'    => 'pelacakan_paket',
        ]);
    }

    public function esp32Status()
    {
        if (!$this->session->get('isLoggedIn')) {
            if ($this->request->getGet('ajax')) {
                return $this->response->setJSON(['esp32List' => []]);
            }
            return redirect()->to('/login');
        }
        $role = $this->session->get('role');
        $userDC = $this->session->get('dcName');
        $statusRef = $this->database->getReference('conveyor/esp32_status');
        $statusData = $statusRef->getValue();
        $now = time();
        $esp32List = [];
        $dcMap = [];
        if ($statusData) {
            foreach ($statusData as $esp32_id => $info) {
                $lastSeenEpoch = isset($info['last_seen_epoch']) ? (int)$info['last_seen_epoch'] : 0;
                $lastSeen = $lastSeenEpoch ? $lastSeenEpoch : (isset($info['last_seen']) ? strtotime($info['last_seen']) : 0);
                $isOnline = ($now - $lastSeen) < 30;
                $dcName = $info['dcName'] ?? '-';
                // Filter: hanya tampilkan ESP32 sesuai DC user (kecuali admin)
                if ($role !== 'admin' && $dcName !== $userDC) continue;
                $row = [
                    'id' => $esp32_id,
                    'last_seen' => $info['last_seen'] ?? '-',
                    'last_seen_epoch' => $lastSeenEpoch,
                    'ip' => $info['ip'] ?? '-',
                    'ssid' => $info['ssid'] ?? '-',
                    'dcName' => $dcName,
                    'is_online' => $isOnline
                ];
                $esp32List[] = $row;
                $dcMap[$dcName][] = $row;
            }
        }
        if ($this->request->getGet('ajax')) {
            return $this->response->setJSON(['esp32List' => $esp32List]);
        }
        return view('esp32_status', [
            'esp32List' => $esp32List,
            'dcMap' => $dcMap,
            'title' => 'Status ESP32',
            'currentMenu' => 'esp32_status',
        ]);
    }
}
