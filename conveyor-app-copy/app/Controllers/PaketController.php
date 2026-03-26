<?php
// ci4_app/app/Controllers/PaketController.php

namespace App\Controllers;

use App\Controllers\BaseController;

class PaketController extends BaseController
{
    private function normalizeKodePos(string $kodePos): string
    {
        return trim((string) $kodePos);
    }

    private function normalizeKodePosList($value): array
    {
        if (is_array($value)) {
            $tokens = $value;
        } else {
            $raw = str_replace(["\r\n", "\n", ";", "|"], ',', (string) $value);
            $tokens = explode(',', $raw);
        }

        $normalized = [];
        foreach ($tokens as $token) {
            $kodePos = $this->normalizeKodePos((string) $token);
            if ($kodePos === '') {
                continue;
            }
            $normalized[$kodePos] = true;
        }

        return array_keys($normalized);
    }

    private function getDcOptions(): array
    {
        $dcSet = [];

        $users = $this->database->getReference('users')->getValue();
        if (is_array($users)) {
            foreach ($users as $user) {
                $dc = trim((string) ($user['dcName'] ?? ''));
                if ($dc !== '') {
                    $dcSet[$dc] = true;
                }
            }
        }

        $status = $this->database->getReference('conveyor/esp32_status')->getValue();
        if (is_array($status)) {
            foreach ($status as $row) {
                $dc = trim((string) ($row['dcName'] ?? ''));
                if ($dc !== '') {
                    $dcSet[$dc] = true;
                }
            }
        }

        $jalurRoot = $this->database->getReference('conveyor/jalur')->getValue();
        if (is_array($jalurRoot)) {
            foreach ($jalurRoot as $key => $value) {
                if (is_array($value) && isset($value['kodepos'])) {
                    // Legacy shape: conveyor/jalur/{Jalur X}
                    continue;
                }
                $dc = trim((string) $key);
                if ($dc !== '') {
                    $dcSet[$dc] = true;
                }
            }
        }

        $dcList = array_keys($dcSet);
        sort($dcList, SORT_NATURAL | SORT_FLAG_CASE);
        return $dcList;
    }

    private function getSelectedDcForJalurScope(?string $requestedDc = null): string
    {
        $role = (string) $this->session->get('role');
        $userDc = trim((string) ($this->session->get('dcName') ?? ''));

        if ($role !== 'admin') {
            return $userDc;
        }

        $dc = trim((string) ($requestedDc ?? ''));
        if ($dc !== '') {
            return $dc;
        }

        if ($userDc !== '') {
            return $userDc;
        }

        $options = $this->getDcOptions();
        return $options[0] ?? '';
    }

    private function normalizeJalurMapFromSnapshot($snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }

        $map = [];
        foreach ($snapshot as $jalurName => $info) {
            $kodePosList = $this->normalizeKodePosList($info['kodepos'] ?? []);
            if ($kodePosList === []) {
                continue;
            }
            $map[(string) $jalurName] = $kodePosList;
        }

        ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
        return $map;
    }

    private function getJalurConfigMapByDc(string $dcName): array
    {
        $dc = trim($dcName);
        if ($dc === '') {
            return [];
        }

        // New shape: conveyor/jalur/{dcName}/{jalurName}
        $scoped = $this->database->getReference('conveyor/jalur/' . $dc)->getValue();
        $map = $this->normalizeJalurMapFromSnapshot($scoped);
        if ($map !== []) {
            return $map;
        }

        // Legacy fallback: conveyor/jalur/{jalurName}
        $legacyRoot = $this->database->getReference('conveyor/jalur')->getValue();
        if (!is_array($legacyRoot)) {
            return [];
        }

        $legacy = [];
        foreach ($legacyRoot as $jalurName => $info) {
            if (!is_array($info) || !isset($info['kodepos'])) {
                continue;
            }
            $kodePosList = $this->normalizeKodePosList($info['kodepos'] ?? []);
            if ($kodePosList === []) {
                continue;
            }
            $legacy[(string) $jalurName] = $kodePosList;
        }

        ksort($legacy, SORT_NATURAL | SORT_FLAG_CASE);
        return $legacy;
    }

    private function findJalurByKodePos(string $kodePos, string $dcName): string
    {
        $needle = $this->normalizeKodePos($kodePos);
        if ($needle === '') {
            return '';
        }

        foreach ($this->getJalurConfigMapByDc($dcName) as $jalurName => $kodePosList) {
            foreach ($kodePosList as $candidate) {
                if ((string) $candidate === $needle) {
                    return (string) $jalurName;
                }
            }
        }

        return '';
    }

    private function getJalurOptions(string $dcName): array
    {
        return array_keys($this->getJalurConfigMapByDc($dcName));
    }

    private function getAllJalurOptions(): array
    {
        $options = [];
        $root = $this->database->getReference('conveyor/jalur')->getValue();
        if (!is_array($root)) {
            return [];
        }

        foreach ($root as $key => $value) {
            if (is_array($value) && isset($value['kodepos'])) {
                $options[(string) $key] = true; // Legacy shape
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $jalurName => $jalurInfo) {
                if (is_array($jalurInfo) && isset($jalurInfo['kodepos'])) {
                    $options[(string) $jalurName] = true;
                }
            }
        }

        $list = array_keys($options);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        return $list;
    }

    private function bumpJalurSignal(string $dcName): void
    {
        $dc = trim($dcName);
        if ($dc === '') {
            return;
        }

        $version = (int) round(microtime(true) * 1000);
        $this->database->getReference('conveyor/config_signal/' . $dc . '/jalur_version')->set($version);
    }

    private function defaultDeviceName(string $esp32Id, string $dcName): string
    {
        $suffix = strtoupper(substr(str_replace(':', '', $esp32Id), -6));
        return 'Conveyor ' . ($dcName ?: 'DC') . '-' . $suffix;
    }

    private function getDeviceMetadataMap(): array
    {
        $metadata = $this->database->getReference('conveyor/devices')->getValue();
        return is_array($metadata) ? $metadata : [];
    }

    private function getAccessibleEsp32Devices(): array
    {
        $role = $this->session->get('role');
        $userDC = $this->session->get('dcName');
        $statusData = $this->database->getReference('conveyor/esp32_status')->getValue();
        $metadataMap = $this->getDeviceMetadataMap();
        $now = time();
        $devices = [];

        if (!$statusData) {
            return [];
        }

        foreach ($statusData as $esp32Id => $info) {
            $dcName = $info['dcName'] ?? '-';
            if ($role !== 'admin' && $dcName !== $userDC) {
                continue;
            }

            $meta = is_array($metadataMap[$esp32Id] ?? null) ? $metadataMap[$esp32Id] : [];
            $lastSeenEpoch = isset($info['last_seen_epoch']) ? (int) $info['last_seen_epoch'] : 0;
            $lastSeen = $lastSeenEpoch ? $lastSeenEpoch : (isset($info['last_seen']) ? strtotime($info['last_seen']) : 0);

            $devices[] = [
                'id' => $esp32Id,
                'dcName' => $dcName,
                'ip' => $info['ip'] ?? '-',
                'ssid' => $info['ssid'] ?? '-',
                'last_seen' => $info['last_seen'] ?? '-',
                'last_seen_epoch' => $lastSeenEpoch,
                'is_online' => ($now - $lastSeen) < 30,
                'display_name' => trim((string) ($meta['display_name'] ?? '')) ?: $this->defaultDeviceName($esp32Id, $dcName),
                'location' => trim((string) ($meta['location'] ?? '')) ?: '-',
                'is_active' => array_key_exists('is_active', $meta) ? (bool) $meta['is_active'] : true,
                'notes' => trim((string) ($meta['notes'] ?? '')),
                'updated_at' => $meta['updated_at'] ?? '-',
            ];
        }

        usort($devices, static function ($left, $right) {
            return [$left['dcName'], $left['display_name'], $left['id']] <=> [$right['dcName'], $right['display_name'], $right['id']];
        });

        return $devices;
    }

    private function getAccessibleEsp32DeviceMap(): array
    {
        $map = [];
        foreach ($this->getAccessibleEsp32Devices() as $device) {
            $map[$device['id']] = $device;
        }
        return $map;
    }

    private function buildPaketSortPageData(): array
    {
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

        $processedData = [];
        if ($paketData) {
            foreach ($paketData as $key => $paket) {
                $processedData[] = array_merge(['key' => $key], $paket);
            }

            usort($processedData, function ($a, $b) {
                return strtotime($b['waktu_sortir'] ?? $b['updated_at'] ?? '1970-01-01') <=> strtotime($a['waktu_sortir'] ?? $a['updated_at'] ?? '1970-01-01');
            });
        }

        $filter = $this->request->getGet('filter');
        if (!empty($filter)) {
            $processedData = array_values(array_filter($processedData, function ($paket) use ($filter) {
                return ($paket['status'] ?? null) === $filter;
            }));
        }

        $damageFilter = $this->request->getGet('damageFilter');
        if (!empty($damageFilter)) {
            $processedData = array_values(array_filter($processedData, function ($paket) use ($damageFilter) {
                return ($paket['damage'] ?? 'Tidak Rusak') === $damageFilter;
            }));
        }

        $dcFilter = $this->request->getGet('dcFilter');
        if (!empty($dcFilter)) {
            $processedData = array_values(array_filter($processedData, function ($paket) use ($dcFilter) {
                return ($paket['dcName'] ?? '') === $dcFilter;
            }));
        }

        $search = $this->request->getGet('search');
        if (!empty($search)) {
            $searchTerm = strtolower($search);
            $processedData = array_values(array_filter($processedData, function ($paket) use ($searchTerm) {
                return strpos(strtolower($paket['id'] ?? ''), $searchTerm) !== false
                    || strpos(strtolower($paket['dcName'] ?? ''), $searchTerm) !== false
                    || strpos(strtolower($paket['alamat_penerima'] ?? ''), $searchTerm) !== false;
            }));
        }

        $entriesPerPage = $this->request->getGet('entries') ? (int) $this->request->getGet('entries') : 10;
        $currentPage = $this->request->getGet('page') ? (int) $this->request->getGet('page') : 1;
        $entriesPerPage = $entriesPerPage > 0 ? $entriesPerPage : 10;
        $currentPage = $currentPage > 0 ? $currentPage : 1;

        $totalEntries = count($processedData);
        $totalPages = max(1, (int) ceil($totalEntries / $entriesPerPage));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $startIndex = ($currentPage - 1) * $entriesPerPage;
        $paginatedData = array_slice($processedData, $startIndex, $entriesPerPage);

        $statusCount = [
            'Datang' => 0,
            'Tersortir' => 0,
            'Proses' => 0,
            'Gagal' => 0,
        ];

        $damageCount = [
            'Tidak Rusak' => 0,
            'Rusak Ringan' => 0,
            'Rusak' => 0,
            'Sangat Rusak' => 0,
        ];

        if ($paketData) {
            foreach ($paketData as $paket) {
                $status = $paket['status'] ?? '-';
                if (isset($statusCount[$status])) {
                    $statusCount[$status]++;
                }

                $damage = $paket['damage'] ?? 'Tidak Rusak';
                if (isset($damageCount[$damage])) {
                    $damageCount[$damage]++;
                }
            }
        }

        $lastUpdated = '-';
        if (!empty($processedData)) {
            $lastUpdated = $processedData[0]['updated_at'] ?? $processedData[0]['waktu_sortir'] ?? '-';
        }

        return [
            'paginatedData' => $paginatedData,
            'totalPages' => $totalPages,
            'currentPage' => $currentPage,
            'entriesPerPage' => $entriesPerPage,
            'statusCount' => $statusCount,
            'damageCount' => $damageCount,
            'filter' => $filter,
            'damageFilter' => $damageFilter,
            'dcFilter' => $dcFilter,
            'search' => $search,
            'totalData' => $totalEntries,
            'lastUpdated' => $lastUpdated,
        ];
    }

    public function daftarPaketSort()
    {
        // Periksa apakah pengguna sudah login
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        return view('daftar_paket_sort', array_merge($this->buildPaketSortPageData(), [
            'title' => 'Daftar Paket Sort',
            'currentMenu' => 'daftar_paket_sort',
        ]));
    }

    public function daftarPaketSortLive()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['error' => 'Unauthorized'], 401);
        }

        $viewData = $this->buildPaketSortPageData();

        return $this->response->setJSON([
            'summary_html' => view('paket_sort/_summary', $viewData),
            'table_html' => view('paket_sort/_table', $viewData),
            'pagination_html' => view('paket_sort/_pagination', $viewData),
            'lastUpdated' => $viewData['lastUpdated'],
            'totalData' => $viewData['totalData'],
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

        $jalurOptions = ($role === 'admin')
            ? $this->getAllJalurOptions()
            : $this->getJalurOptions((string) $dcName);

        return view('daftar_paket', [
            'paketData'   => $paketData,
            'jalurOptions' => $jalurOptions,
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

        $targetDc = $role === 'admin'
            ? $this->getSelectedDcForJalurScope((string) $this->request->getPost('dcName'))
            : trim((string) $dcName);

        if ($targetDc === '') {
            return $this->response->setJSON(['error' => 'DC tujuan tidak valid.'], 400);
        }

        // Tentukan jalur berdasarkan kode pos dari konfigurasi per-DC
        $jalur = $this->findJalurByKodePos((string) $kode_pos, $targetDc);

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
            'dcName'            => $targetDc,
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
        $esp32List = $this->getAccessibleEsp32Devices();
        $dcMap = [];

        foreach ($esp32List as $row) {
            $dcMap[$row['dcName']][] = $row;
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

    public function deviceMonitor()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        $devices = $this->getAccessibleEsp32Devices();

        return view('device_monitor', [
            'title'       => 'Device Monitor',
            'currentMenu' => 'device_monitor',
            'devices'     => $devices,
            'isAdmin'     => $this->session->get('role') === 'admin',
            'userDC'      => $this->session->get('dcName') ?? '-',
        ]);
    }

    public function deviceStatusData()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['devices' => []], 401);
        }

        $devices = $this->getAccessibleEsp32Devices();

        return $this->response->setJSON([
            'devices' => array_values($devices),
            'scope_label' => $this->session->get('role') === 'admin'
                ? 'Semua device semua DC'
                : 'Semua device DC ' . ($this->session->get('dcName') ?? '-'),
        ]);
    }

    public function deviceManager()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        return view('device_manager', [
            'title' => 'Manajemen Device',
            'currentMenu' => 'device_manager',
            'devices' => $this->getAccessibleEsp32Devices(),
            'isAdmin' => $this->session->get('role') === 'admin',
            'userDC' => $this->session->get('dcName') ?? '-',
        ]);
    }

    public function saveDeviceMeta()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $deviceId = trim((string) $this->request->getPost('device_id'));
        $allowedMap = $this->getAccessibleEsp32DeviceMap();

        if ($deviceId === '' || !isset($allowedMap[$deviceId])) {
            return $this->response->setJSON(['success' => false, 'message' => 'Device tidak valid.'], 403);
        }

        $existing = $this->getDeviceMetadataMap()[$deviceId] ?? [];
        $payload = [
            'display_name' => trim((string) $this->request->getPost('display_name')) ?: $this->defaultDeviceName($deviceId, $allowedMap[$deviceId]['dcName']),
            'location' => trim((string) $this->request->getPost('location')),
            'notes' => trim((string) $this->request->getPost('notes')),
            'is_active' => $this->request->getPost('is_active') === '1',
            'dcName' => $allowedMap[$deviceId]['dcName'],
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (!isset($existing['created_at'])) {
            $payload['created_at'] = date('Y-m-d H:i:s');
        } else {
            $payload['created_at'] = $existing['created_at'];
        }

        $this->database->getReference('conveyor/devices/' . $deviceId)->update($payload);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Metadata device berhasil disimpan.',
            'device' => $this->getAccessibleEsp32DeviceMap()[$deviceId] ?? null,
        ]);
    }

    public function deviceLogs()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['logs' => []]);
        }

        $after = $this->request->getGet('after');
        $afterKey = $this->request->getGet('after_key');
        $selectedDevice = trim((string) $this->request->getGet('device'));
        $allowedMap = $this->getAccessibleEsp32DeviceMap();
        $allowedDevices = array_values($allowedMap);

        if ($selectedDevice !== '' && $selectedDevice !== 'all' && !isset($allowedMap[$selectedDevice])) {
            return $this->response->setJSON([
                'logs' => [],
                'devices' => array_values($allowedDevices),
                'selected_device' => 'all',
                'scope_label' => $this->session->get('role') === 'admin'
                    ? 'Semua device semua DC'
                    : 'Semua device DC ' . ($this->session->get('dcName') ?? '-'),
            ]);
        }

        try {
            if ($selectedDevice !== '' && $selectedDevice !== 'all') {
                $entries = $this->database->getReference('conveyor/esp32_log/' . $selectedDevice)->getValue();
                $allData = [$selectedDevice => $entries];
            } else {
                $allData = $this->database->getReference('conveyor/esp32_log')->getValue();
            }
        } catch (\Exception $e) {
            return $this->response->setJSON(['logs' => [], 'error' => $e->getMessage()]);
        }

        $esp32Ids = [];
        $flat = [];
        if ($allData) {
            foreach ($allData as $esp32Id => $entries) {
                if (!isset($allowedMap[$esp32Id])) {
                    continue;
                }
                $esp32Ids[] = $esp32Id;
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $millis => $log) {
                    if (!is_array($log)) {
                        continue;
                    }
                    $log['esp32_id'] = $esp32Id;
                    $log['_key'] = (string) $millis;
                    $log['dcName'] = $allowedMap[$esp32Id]['dcName'] ?? '-';
                    $log['display_name'] = $allowedMap[$esp32Id]['display_name'] ?? $esp32Id;
                    $flat[] = $log;
                }
            }
        }

        usort($flat, function ($a, $b) {
            $wa = $a['waktu'] ?? '';
            $wb = $b['waktu'] ?? '';
            if ($wa === $wb) {
                // tie-breaker by Firebase child key when available
                $ka = (int) ($a['_key'] ?? 0);
                $kb = (int) ($b['_key'] ?? 0);
                return $ka <=> $kb;
            }
            return strcmp($wa, $wb);
        });

        if ($after) {
            // For single-device scope, allow incremental fetch within same second using after_key
            if ($selectedDevice !== '' && $selectedDevice !== 'all' && $afterKey !== null && $afterKey !== '') {
                $afterKeyInt = (int) $afterKey;
                $flat = array_values(array_filter($flat, function ($l) use ($after, $afterKeyInt) {
                    $w = $l['waktu'] ?? '';
                    if ($w > $after) {
                        return true;
                    }
                    if ($w === $after) {
                        return ((int) ($l['_key'] ?? 0)) > $afterKeyInt;
                    }
                    return false;
                }));
            } else {
                $flat = array_values(array_filter($flat, fn($l) => ($l['waktu'] ?? '') > $after));
            }
        } else {
            if (count($flat) > 100) {
                $flat = array_slice($flat, -100);
            }
        }

        return $this->response->setJSON([
            'logs' => array_values($flat),
            'esp32_ids' => $esp32Ids,
            'devices' => array_values($allowedDevices),
            'selected_device' => ($selectedDevice !== '' ? $selectedDevice : 'all'),
            'scope_label' => ($selectedDevice !== '' && $selectedDevice !== 'all')
                ? (($allowedMap[$selectedDevice]['display_name'] ?? $selectedDevice) . ' • ' . ($allowedMap[$selectedDevice]['dcName'] ?? '-'))
                : ($this->session->get('role') === 'admin'
                    ? 'Semua device semua DC'
                    : 'Semua device DC ' . ($this->session->get('dcName') ?? '-')),
        ]);
    }

    public function jalurConfig()
    {
        if (!$this->session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }

        $selectedDc = $this->getSelectedDcForJalurScope((string) $this->request->getGet('dc'));
        if ($selectedDc === '') {
            return redirect()->to('/')->with('error', 'DC tidak ditemukan untuk konfigurasi jalur.');
        }

        $jalurMap = $this->getJalurConfigMapByDc($selectedDc);
        $isAdmin = (string) $this->session->get('role') === 'admin';

        return view('jalur_config', [
            'title' => 'Konfigurasi Jalur',
            'currentMenu' => 'jalur_config',
            'jalurMap' => $jalurMap,
            'selectedDc' => $selectedDc,
            'dcOptions' => $isAdmin ? $this->getDcOptions() : [$selectedDc],
            'isAdmin' => $isAdmin,
        ]);
    }

    public function saveJalurConfig()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $jalur = trim((string) $this->request->getPost('jalur'));
        $kodePosCsv = (string) $this->request->getPost('kodepos');
        $targetDc = $this->getSelectedDcForJalurScope((string) $this->request->getPost('dcName'));

        if ($targetDc === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'DC tidak valid.'], 400);
        }

        if (!in_array($jalur, ['Jalur 1', 'Jalur 2'], true)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Jalur harus Jalur 1 atau Jalur 2.'], 400);
        }

        $kodePosList = $this->normalizeKodePosList($kodePosCsv);
        if ($kodePosList === []) {
            return $this->response->setJSON(['success' => false, 'message' => 'Minimal satu kode pos wajib diisi.'], 400);
        }

        // Enforce uniqueness in selected DC scope only
        $jalurMap = $this->getJalurConfigMapByDc($targetDc);
        foreach ($jalurMap as $jalurName => $existingList) {
            if ($jalurName === $jalur) {
                continue;
            }
            $jalurMap[$jalurName] = array_values(array_filter($existingList, function ($kodePos) use ($kodePosList) {
                return !in_array((string) $kodePos, $kodePosList, true);
            }));
        }

        $jalurMap[$jalur] = $kodePosList;

        // Persist cleaned map to Firebase in scoped DC path
        foreach ($jalurMap as $jalurName => $list) {
            $nodePath = 'conveyor/jalur/' . $targetDc . '/' . $jalurName;
            if ($list === []) {
                $this->database->getReference($nodePath)->remove();
                continue;
            }

            $this->database->getReference($nodePath)->set([
                'kodepos' => array_values($list),
                'dcName' => $targetDc,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => (string) ($this->session->get('email') ?? 'admin'),
            ]);
        }

        $this->bumpJalurSignal($targetDc);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Konfigurasi jalur berhasil disimpan.',
            'dcName' => $targetDc,
            'jalurMap' => $this->getJalurConfigMapByDc($targetDc),
        ]);
    }

    public function removeKodePosFromJalur()
    {
        if (!$this->session->get('isLoggedIn')) {
            return $this->response->setJSON(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $jalur = trim((string) $this->request->getPost('jalur'));
        $kodePos = $this->normalizeKodePos((string) $this->request->getPost('kodepos'));
        $targetDc = $this->getSelectedDcForJalurScope((string) $this->request->getPost('dcName'));
        if ($jalur === '' || $kodePos === '' || $targetDc === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Data tidak valid.'], 400);
        }

        $jalurRef = $this->database->getReference('conveyor/jalur/' . $targetDc . '/' . $jalur);
        $current = $jalurRef->getValue();
        $existingList = $this->normalizeKodePosList($current['kodepos'] ?? []);
        $nextList = array_values(array_filter($existingList, function ($item) use ($kodePos) {
            return (string) $item !== $kodePos;
        }));

        if ($nextList === []) {
            $jalurRef->remove();
        } else {
            $jalurRef->set([
                'kodepos' => $nextList,
                'dcName' => $targetDc,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => (string) ($this->session->get('email') ?? 'admin'),
            ]);
        }

        $this->bumpJalurSignal($targetDc);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Kode pos berhasil dihapus dari jalur.',
            'dcName' => $targetDc,
            'jalurMap' => $this->getJalurConfigMapByDc($targetDc),
        ]);
    }
}
