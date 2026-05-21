<?php
require_once 'koneksi.php';

// Auto-migration: tambah kolom tipe_rute jika belum ada
$check_col = $conn->query("SHOW COLUMNS FROM rute LIKE 'tipe_rute'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE rute ADD COLUMN tipe_rute ENUM('terpendek','terjauh') DEFAULT 'terpendek' AFTER total_jarak");
}

$pesan = ''; $pesan_type = '';

// TSP Brute Force
// Menghitung jarak Haversine antara dua koordinat (km)
function haversine($lat1, $lon1, $lat2, $lon2) {
    $R  = 6371;
    $dL = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lon2 - $lon1);
    $a  = sin($dL/2)*sin($dL/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dl/2)*sin($dl/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

// Menghasilkan semua permutasi array
function permutasi($arr) {
    if (count($arr) <= 1) return [$arr];
    $result = [];
    foreach ($arr as $i => $item) {
        $rest = array_values(array_filter($arr, fn($v) => $v !== $item));
        foreach (permutasi($rest) as $p) {
            $result[] = array_merge([$item], $p);
        }
    }
    return $result;
}

// TSP Brute Force: mencoba semua kemungkinan urutan, kembalikan urutan terpendek DAN terjauh
function tspBruteForce($titik_list) {
    $depot  = $titik_list[0];
    $tujuan = array_slice($titik_list, 1);
    $n      = count($tujuan);

    if ($n === 0) return ['terpendek' => ['urutan' => [$depot], 'jarak' => 0], 'terjauh' => ['urutan' => [$depot], 'jarak' => 0]];
    if ($n === 1) {
        $jarak = haversine($depot['lat'], $depot['lng'], $tujuan[0]['lat'], $tujuan[0]['lng']);
        $jarak += haversine($tujuan[0]['lat'], $tujuan[0]['lng'], $depot['lat'], $depot['lng']);
        $result = ['urutan' => [$depot, $tujuan[0]], 'jarak' => round($jarak, 3)];
        return ['terpendek' => $result, 'terjauh' => $result];
    }

    $perms      = permutasi($tujuan);
    $best_dist  = PHP_FLOAT_MAX;
    $best_perm  = [];
    $worst_dist = 0;
    $worst_perm = [];

    foreach ($perms as $perm) {
        $dist  = haversine($depot['lat'], $depot['lng'], $perm[0]['lat'], $perm[0]['lng']);
        for ($i = 0; $i < count($perm) - 1; $i++) {
            $dist += haversine($perm[$i]['lat'], $perm[$i]['lng'], $perm[$i+1]['lat'], $perm[$i+1]['lng']);
        }
        $last  = end($perm);
        $dist += haversine($last['lat'], $last['lng'], $depot['lat'], $depot['lng']);
        if ($dist < $best_dist) {
            $best_dist = $dist;
            $best_perm = $perm;
        }
        if ($dist > $worst_dist) {
            $worst_dist = $dist;
            $worst_perm = $perm;
        }
    }

    return [
        'terpendek' => [
            'urutan' => array_merge([$depot], $best_perm),
            'jarak'  => round($best_dist, 3),
        ],
        'terjauh' => [
            'urutan' => array_merge([$depot], $worst_perm),
            'jarak'  => round($worst_dist, 3),
        ],
    ];
}


// Proses POST 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    // GENERATE RUTE TSP — hitung kedua rute, simpan di session untuk dipilih user
    if ($aksi === 'generate') {
        $zona          = $_POST['zona_filter']  ?? '';
        $id_kurir      = (int)($_POST['id_kurir'] ?? 0);
        $paket_dipilih = $_POST['paket_dipilih'] ?? [];

        if (empty($paket_dipilih)) {
            $pesan = 'Pilih minimal 1 paket.'; $pesan_type = 'error';
        } elseif ($id_kurir === 0) {
            $pesan = 'Pilih kurir terlebih dahulu.'; $pesan_type = 'error';
        } else {
            $ids      = implode(',', array_map('intval', $paket_dipilih));
            $res      = $conn->query(
                "SELECT id_paket, no_resi, alamat, latitude, longitude FROM paket
                 WHERE id_paket IN ($ids) AND latitude IS NOT NULL AND longitude IS NOT NULL"
            );
            $titik_list = [[
                'id_paket' => 0,
                'no_resi'  => GUDANG_NAMA,
                'alamat'   => GUDANG_NAMA,
                'lat'      => GUDANG_LAT,
                'lng'      => GUDANG_LNG,
            ]];
            while ($r = $res->fetch_assoc()) {
                $titik_list[] = [
                    'id_paket' => $r['id_paket'],
                    'no_resi'  => $r['no_resi'],
                    'alamat'   => $r['alamat'],
                    'lat'      => (float)$r['latitude'],
                    'lng'      => (float)$r['longitude'],
                ];
            }

            if (count($titik_list) < 2) {
                $pesan = 'Paket yang dipilih belum memiliki koordinat. Lengkapi koordinat terlebih dahulu.';
                $pesan_type = 'error';
            } elseif (count($titik_list) > 11) {
                $pesan = 'Maksimal 10 paket per generate rute.';
                $pesan_type = 'error';
            } else {
                $tsp = tspBruteForce($titik_list);

                // Simpan hasil ke session untuk ditampilkan & dipilih
                $_SESSION['tsp_hasil'] = [
                    'terpendek' => $tsp['terpendek'],
                    'terjauh'   => $tsp['terjauh'],
                    'id_kurir'  => $id_kurir,
                    'paket_dipilih' => $paket_dipilih,
                ];

                header("Location: ?menu=rute&pilih_rute=1");
                exit;
            }
        }
    }

    // SIMPAN RUTE YANG DIPILIH
    if ($aksi === 'simpan_rute') {
        $tipe_rute = $_POST['tipe_rute'] ?? 'terpendek';
        if (!in_array($tipe_rute, ['terpendek', 'terjauh'])) $tipe_rute = 'terpendek';

        if (empty($_SESSION['tsp_hasil'])) {
            $pesan = 'Sesi generate rute sudah kadaluarsa. Silakan generate ulang.';
            $pesan_type = 'error';
        } else {
            $hasil    = $_SESSION['tsp_hasil'];
            $chosen   = $hasil[$tipe_rute];
            $urutan   = $chosen['urutan'];
            $jarak    = $chosen['jarak'];
            $id_kurir = $hasil['id_kurir'];

            $tanggal = date('Y-m-d');
            $stmt    = $conn->prepare(
                "INSERT INTO rute (id_kurir, tanggal, total_jarak, tipe_rute, status) VALUES (?, ?, ?, ?, 'aktif')"
            );
            $stmt->bind_param('isds', $id_kurir, $tanggal, $jarak, $tipe_rute);
            $stmt->execute();
            $id_rute = $conn->insert_id;
            $stmt->close();

            foreach ($urutan as $seq => $titik) {
                if ($titik['id_paket'] === 0) continue;
                $urutan_ke = $seq;
                $id_paket  = $titik['id_paket'];
                $stmt2     = $conn->prepare(
                    "INSERT INTO urutan_pengiriman (id_rute, id_paket, urutan, status_pengiriman)
                     VALUES (?, ?, ?, 'belum_dikirim')"
                );
                $stmt2->bind_param('iii', $id_rute, $id_paket, $urutan_ke);
                $stmt2->execute();
                $stmt2->close();

                $conn->query("UPDATE paket SET status='sedang_dikirim' WHERE id_paket={$id_paket}");
            }

            unset($_SESSION['tsp_hasil']);

            header("Location: ?menu=rute&id_rute={$id_rute}&sukses=1");
            exit;
        }
    }

    // HAPUS RUTE
    if ($aksi === 'hapus_rute') {
        $id = (int)($_POST['id_rute'] ?? 0);
        $up = $conn->query(
            "SELECT id_paket FROM urutan_pengiriman WHERE id_rute=$id"
        );
        while ($r = $up->fetch_assoc()) {
            $conn->query("UPDATE paket SET status='belum_dikirim' WHERE id_paket={$r['id_paket']}");
        }
        $conn->query("DELETE FROM rute WHERE id_rute=$id");
        $pesan = 'Rute berhasil dihapus.'; $pesan_type = 'success';
    }
}

if (isset($_GET['sukses'])) { $pesan = 'Rute berhasil dibuat.'; $pesan_type = 'success'; }

// Cek apakah sedang mode pilih rute
$mode_pilih = isset($_GET['pilih_rute']) && !empty($_SESSION['tsp_hasil']);

// Data untuk form
$zona_filter = $_GET['zona_filter'] ?? 'Semua';

// Zona list
$zr = $conn->query("SELECT DISTINCT zona FROM paket WHERE status='belum_dikirim' ORDER BY zona");
$zona_list = ['Semua'];
while ($z = $zr->fetch_row()) $zona_list[] = $z[0];

// Paket belum dikirim sesuai zona
$sql_paket = "SELECT id_paket, no_resi, alamat, zona, latitude, longitude
              FROM paket WHERE status='belum_dikirim'";
if ($zona_filter !== 'Semua') {
    $zf         = $conn->real_escape_string($zona_filter);
    $sql_paket .= " AND zona='{$zf}'";
}
$sql_paket .= " ORDER BY zona, created_at";
$paket_tersedia = $conn->query($sql_paket)->fetch_all(MYSQLI_ASSOC);

// Kurir aktif
$kurir_list = $conn->query(
    "SELECT id_kurir, nama FROM kurir WHERE status='aktif' ORDER BY nama"
)->fetch_all(MYSQLI_ASSOC);

// Riwayat rute
$riwayat = $conn->query(
    "SELECT r.*, k.nama AS nama_kurir,
            COUNT(up.id) AS jumlah_paket
     FROM rute r
     LEFT JOIN kurir k ON r.id_kurir = k.id_kurir
     LEFT JOIN urutan_pengiriman up ON up.id_rute = r.id_rute
     GROUP BY r.id_rute ORDER BY r.created_at DESC LIMIT 10"
)->fetch_all(MYSQLI_ASSOC);

// Detail rute untuk ditampilkan di peta (setelah simpan atau klik lihat)
$id_rute_tampil = (int)($_GET['id_rute'] ?? 0);
$detail_rute    = [];
$info_rute      = null;
if ($id_rute_tampil > 0) {
    $info_rute = $conn->query(
        "SELECT r.*, k.nama AS nama_kurir FROM rute r
         LEFT JOIN kurir k ON r.id_kurir=k.id_kurir
         WHERE r.id_rute={$id_rute_tampil}"
    )->fetch_assoc();

    $dr = $conn->query(
        "SELECT up.urutan, p.no_resi, p.alamat, p.zona, p.latitude, p.longitude, up.status_pengiriman
         FROM urutan_pengiriman up
         JOIN paket p ON up.id_paket = p.id_paket
         WHERE up.id_rute={$id_rute_tampil}
         ORDER BY up.urutan"
    );
    while ($r = $dr->fetch_assoc()) $detail_rute[] = $r;
}
?>

<h2 class="page-title">Manajemen Rute</h2>

<?php if ($pesan): ?>
<div class="alert-<?= $pesan_type==='success' ? 'success' : 'error' ?>"><?= htmlspecialchars($pesan) ?></div>
<?php endif; ?>

<div class="panel-container">

<!-- Panel Kiri: Form generate rute -->
<div class="panel-left">
    <h3>Buat Rute</h3>

    <!-- Filter zona -->
    <form method="GET" style="margin-bottom:12px;">
        <input type="hidden" name="menu" value="rute">
        <label style="font-size:13px;font-weight:500;color:#555;">Zona Paket</label>
        <select name="zona_filter" onchange="this.form.submit()" style="width:100%;margin-top:5px;padding:9px;border:1px solid #d1d9f0;border-radius:7px;font-size:13px;background:#f8faff;">
            <?php foreach ($zona_list as $z): ?>
                <option value="<?= htmlspecialchars($z) ?>" <?= $zona_filter===$z ? 'selected':'' ?>>
                    <?= htmlspecialchars($z) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Form generate -->
    <form method="POST" action="?menu=rute">
        <input type="hidden" name="aksi"        value="generate">
        <input type="hidden" name="zona_filter" value="<?= htmlspecialchars($zona_filter) ?>">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <p style="font-size:12px;color:#888;margin:0;">Pilih paket (maks. 10):</p>
            <label style="font-size:11px;color:#0b57d0;cursor:pointer;display:flex;align-items:center;gap:4px;margin:0;">
                <input type="checkbox" id="select-all-paket" style="width:auto;margin:0;">
                Pilih Semua
            </label>
        </div>
        <div class="list-paket">
            <?php if (empty($paket_tersedia)): ?>
                <div style="text-align:center;padding:20px 10px;">
                    <p style="color:#aaa;font-size:13px;margin:0;">Tidak ada paket untuk zona ini.</p>
                </div>
            <?php else: ?>
                <?php foreach ($paket_tersedia as $p): ?>
                <label class="paket-item">
                    <input type="checkbox" name="paket_dipilih[]" value="<?= $p['id_paket'] ?>">
                    <div class="paket-info">
                        <div class="paket-info-header">
                            <span class="paket-resi"><?= htmlspecialchars($p['no_resi']) ?></span>
                            <span class="paket-zona">Zona <?= htmlspecialchars($p['zona']) ?></span>
                            <?php if (!$p['latitude']): ?>
                                <span class="paket-nokoord">No Koord</span>
                            <?php endif; ?>
                        </div>
                        <div class="paket-alamat"><?= htmlspecialchars($p['alamat']) ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <label style="font-size:13px;font-weight:500;color:#555;display:block;margin-bottom:5px;">
            Pilih Kurir Yang Tersedia
        </label>
        <div style="display:flex;gap:8px;align-items:center;">
            <select name="id_kurir" style="flex:1;padding:9px;border:1px solid #d1d9f0;border-radius:7px;font-size:13px;background:#f8faff;">
                <option value="0">-- Pilih Kurir --</option>
                <?php foreach ($kurir_list as $k): ?>
                    <option value="<?= $k['id_kurir'] ?>"><?= htmlspecialchars($k['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn-primary" style="margin-top:14px;">BUAT RUTE</button>
    </form>
</div>

<!-- Panel Kanan: Peta + hasil rute -->
<div class="panel-right">

    <?php if ($mode_pilih && !empty($_SESSION['tsp_hasil'])): ?>
    <!-- ===== HASIL GENERATE: Pilih antara Terpendek / Terjauh ===== -->
    <?php
        $tsp_hasil   = $_SESSION['tsp_hasil'];
        $rute_pendek = $tsp_hasil['terpendek'];
        $rute_jauh   = $tsp_hasil['terjauh'];
        $nama_kurir_pilih = '';
        foreach ($kurir_list as $k) {
            if ($k['id_kurir'] == $tsp_hasil['id_kurir']) {
                $nama_kurir_pilih = $k['nama'];
                break;
            }
        }
    ?>

    <div class="rute-result">
        Hasil Generate &nbsp;|&nbsp;
        Kurir: <strong><?= htmlspecialchars($nama_kurir_pilih) ?></strong> &nbsp;|&nbsp;
        Jumlah Paket: <strong><?= count($rute_pendek['urutan']) - 1 ?></strong>
        &nbsp;|&nbsp;
        <a href="?menu=rute" style="color:#e53935;font-size:12px;text-decoration:none;">Batal</a>
    </div>

    <!-- Info jarak kedua rute -->
    <div style="display:flex;gap:10px;margin-bottom:14px;">
        <div class="rute-info-box rute-info-pendek" id="info-pendek" onclick="pilihRuteView('terpendek')" style="cursor:pointer;">
            <div style="font-size:12px;color:#555;margin-bottom:4px;">Rute Terpendek</div>
            <div style="font-size:18px;font-weight:700;color:#0b57d0;"><?= $rute_pendek['jarak'] ?> km</div>
            <form method="POST" action="?menu=rute" style="margin-top:8px;">
                <input type="hidden" name="aksi" value="simpan_rute">
                <input type="hidden" name="tipe_rute" value="terpendek">
                <button type="submit" class="btn-sm" style="background:#0b57d0;color:white;width:100%;">Pilih</button>
            </form>
        </div>
        <div class="rute-info-box rute-info-jauh" id="info-jauh" onclick="pilihRuteView('terjauh')" style="cursor:pointer;">
            <div style="font-size:12px;color:#555;margin-bottom:4px;">Rute Terjauh</div>
            <div style="font-size:18px;font-weight:700;color:#e53935;"><?= $rute_jauh['jarak'] ?> km</div>
            <form method="POST" action="?menu=rute" style="margin-top:8px;">
                <input type="hidden" name="aksi" value="simpan_rute">
                <input type="hidden" name="tipe_rute" value="terjauh">
                <button type="submit" class="btn-sm" style="background:#e53935;color:white;width:100%;">Pilih</button>
            </form>
        </div>
    </div>

    <!-- Peta -->
    <div id="map-rute" style="height:320px;border-radius:10px;margin-bottom:14px;border:1px solid #d1d9f0;"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function() {
        var rutePendek = <?= json_encode(array_map(function($t) {
            return ['lat' => $t['lat'], 'lng' => $t['lng'], 'label' => $t['no_resi']];
        }, $rute_pendek['urutan'])) ?>;

        var ruteJauh = <?= json_encode(array_map(function($t) {
            return ['lat' => $t['lat'], 'lng' => $t['lng'], 'label' => $t['no_resi']];
        }, $rute_jauh['urutan'])) ?>;

        var map = L.map('map-rute');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var currentLayer = L.layerGroup().addTo(map);

        function tampilkanRute(data, color) {
            currentLayer.clearLayers();
            var latlngs = [];
            data.forEach(function(wp, idx) {
                var isDepot = (idx === 0);
                var icon = L.divIcon({
                    className: '',
                    html: '<div style="background:' + (isDepot ? '#198754' : color) + ';color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);">' + idx + '</div>',
                    iconSize: [26, 26], iconAnchor: [13, 13]
                });
                currentLayer.addLayer(L.marker([wp.lat, wp.lng], {icon: icon}).bindPopup(wp.label));
                latlngs.push([wp.lat, wp.lng]);
            });
            if (latlngs.length > 1) {
                currentLayer.addLayer(L.polyline(latlngs.concat([latlngs[0]]), {color: color, weight: 4, opacity: 0.8}));
                map.fitBounds(latlngs, {padding: [30, 30]});
            } else if (latlngs.length === 1) {
                map.setView(latlngs[0], 14);
            }
        }

        tampilkanRute(rutePendek, '#0b57d0');
        document.getElementById('info-pendek').classList.add('rute-info-active');

        window.pilihRuteView = function(tipe) {
            document.getElementById('info-pendek').classList.toggle('rute-info-active', tipe === 'terpendek');
            document.getElementById('info-jauh').classList.toggle('rute-info-active', tipe === 'terjauh');
            if (tipe === 'terpendek') {
                tampilkanRute(rutePendek, '#0b57d0');
            } else {
                tampilkanRute(ruteJauh, '#e53935');
            }
        };
    })();
    </script>

    <?php elseif ($id_rute_tampil > 0 && $info_rute): ?>
    <!-- ===== DETAIL RUTE TERSIMPAN ===== -->
    <?php
        $tipe_label = ($info_rute['tipe_rute'] ?? 'terpendek') === 'terjauh' ? 'Terjauh' : 'Terpendek';
        $tipe_class = ($info_rute['tipe_rute'] ?? 'terpendek') === 'terjauh' ? 'badge-terjauh' : 'badge-terpendek';
    ?>
    <div class="rute-result">
        Rute #<?= $id_rute_tampil ?>
        <span class="badge <?= $tipe_class ?>"><?= $tipe_label ?></span>
        &nbsp;|&nbsp;
        Kurir: <strong><?= htmlspecialchars($info_rute['nama_kurir']) ?></strong> &nbsp;|&nbsp;
        Total Jarak: <strong><?= $info_rute['total_jarak'] ?> km</strong> &nbsp;|&nbsp;
        Tanggal: <?= $info_rute['tanggal'] ?>
    </div>

    <div id="map-rute" style="height:320px;border-radius:10px;margin-bottom:14px;border:1px solid #d1d9f0;"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function() {
        var map = L.map('map-rute');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var ruteColor = '<?= ($info_rute['tipe_rute'] ?? 'terpendek') === 'terjauh' ? '#e53935' : '#0b57d0' ?>';

        var waypoints = [
            { lat: <?= GUDANG_LAT ?>, lng: <?= GUDANG_LNG ?>, label: '0', popup: '<?= GUDANG_NAMA ?>' }
            <?php foreach ($detail_rute as $i => $d): ?>
            ,{ lat: <?= (float)$d['latitude'] ?>, lng: <?= (float)$d['longitude'] ?>,
               label: '<?= $d['urutan'] ?>',
               popup: '<?= addslashes($d['no_resi']) ?><br><?= addslashes(substr($d['alamat'],0,60)) ?>' }
            <?php endforeach; ?>
        ];

        var latlngs = [];
        waypoints.forEach(function(wp, idx) {
            var isDepot = (idx === 0);
            var icon = L.divIcon({
                className: '',
                html: '<div style="background:' + (isDepot ? '#198754' : ruteColor) + ';color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);">' + wp.label + '</div>',
                iconSize: [26, 26], iconAnchor: [13, 13]
            });
            L.marker([wp.lat, wp.lng], {icon: icon}).addTo(map).bindPopup(wp.popup);
            latlngs.push([wp.lat, wp.lng]);
        });

        if (latlngs.length > 1) {
            L.polyline(latlngs.concat([latlngs[0]]), {color: ruteColor, weight: 4, opacity: 0.8}).addTo(map);
            map.fitBounds(latlngs, {padding: [30, 30]});
        } else if (latlngs.length === 1) {
            map.setView(latlngs[0], 14);
        }
    })();
    </script>

    <table class="data-table" style="margin-top:0;">
        <thead><tr><th>Urutan</th><th>No. Resi</th><th>Alamat</th><th>Zona</th><th>Status</th></tr></thead>
        <tbody>
        <tr>
            <td>0</td>
            <td colspan="2"><strong><?= GUDANG_NAMA ?></strong></td>
            <td>-</td><td>-</td>
        </tr>
        <?php foreach ($detail_rute as $d): ?>
        <tr>
            <td><?= $d['urutan'] ?></td>
            <td><?= htmlspecialchars($d['no_resi']) ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars(substr($d['alamat'],0,50)) ?>...</td>
            <td><?= htmlspecialchars($d['zona']) ?></td>
            <td><span class="badge badge-<?= explode('_',$d['status_pengiriman'])[0] ?>">
                <?= str_replace('_',' ',ucfirst($d['status_pengiriman'])) ?>
            </span></td>
        </tr>
        <?php endforeach; ?>

        <tr style="background:#f0f9f4;font-style:italic;">
            <td><?= count($detail_rute) + 1 ?></td>
            <td colspan="2"><strong>Kembali ke <?= GUDANG_NAMA ?></strong></td>
            <td>-</td><td>-</td>
        </tr>
        </tbody>
    </table>

    <?php else: ?>
    <!-- ===== DEFAULT: Peta + instruksi ===== -->
    <div id="map-rute" style="height:360px;border-radius:10px;margin-bottom:14px;border:1px solid #d1d9f0;"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function() {
        var map = L.map('map-rute').setView([<?= GUDANG_LAT ?>, <?= GUDANG_LNG ?>], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        L.marker([<?= GUDANG_LAT ?>, <?= GUDANG_LNG ?>], {
            icon: L.divIcon({
                className: '',
                html: '<div style="background:#198754;color:white;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);">G</div>',
                iconSize:[26,26], iconAnchor:[13,13]
            })
        }).addTo(map).bindPopup('<?= GUDANG_NAMA ?>');

        var paket = <?= json_encode(array_values(array_filter($paket_tersedia, fn($p) => $p['latitude']))) ?>;
        paket.forEach(function(p, i) {
            L.marker([parseFloat(p.latitude), parseFloat(p.longitude)], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:#0b57d0;color:white;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.3);">' + (i+1) + '</div>',
                    iconSize:[22,22], iconAnchor:[11,11]
                })
            }).addTo(map).bindPopup('<strong>' + p.no_resi + '</strong><br>' + p.zona + '<br>' + p.alamat.substring(0,60));
        });
    })();
    </script>
    <p style="font-size:13px;color:#888;text-align:center;">
        Pilih paket dan kurir di sebelah kiri, lalu klik <strong>BUAT RUTE</strong>.
    </p>
    <?php endif; ?>

    <!-- Riwayat Rute -->
    <?php if (!empty($riwayat)): ?>
    <h3 style="margin-top:20px;">Riwayat Rute</h3>
    <table class="data-table">
        <thead><tr><th>ID</th><th>Kurir</th><th>Tanggal</th><th>Jarak</th><th>Tipe</th><th>Paket</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($riwayat as $rv): ?>
        <tr>
            <td>#<?= $rv['id_rute'] ?></td>
            <td><?= htmlspecialchars($rv['nama_kurir'] ?? '-') ?></td>
            <td><?= $rv['tanggal'] ?></td>
            <td><?= $rv['total_jarak'] ?> km</td>
            <td>
                <?php
                    $rv_tipe = $rv['tipe_rute'] ?? 'terpendek';
                    $rv_label = $rv_tipe === 'terjauh' ? 'Terjauh' : 'Terpendek';
                    $rv_class = $rv_tipe === 'terjauh' ? 'badge-terjauh' : 'badge-terpendek';
                ?>
                <span class="badge <?= $rv_class ?>"><?= $rv_label ?></span>
            </td>
            <td><?= $rv['jumlah_paket'] ?> paket</td>
            <td style="display:flex;gap:4px;">
                <a href="?menu=rute&id_rute=<?= $rv['id_rute'] ?>"
                   class="btn-sm btn-edit" style="text-decoration:none;">Lihat</a>
                <form method="POST" action="?menu=rute"
                      onsubmit="return confirm('Hapus rute ini?')">
                    <input type="hidden" name="aksi"    value="hapus_rute">
                    <input type="hidden" name="id_rute" value="<?= $rv['id_rute'] ?>">
                    <button type="submit" class="btn-sm btn-hapus">Hapus</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</div>
</div>

<script src="script.js"></script>