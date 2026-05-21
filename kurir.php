<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'kurir') {
    header("Location: index.php"); exit;
}
require_once 'koneksi.php';

$id_kurir = $_SESSION['id_kurir'];
$pesan    = '';

// Update status pengiriman
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi'])) {
    $aksi    = $_POST['aksi'];
    $id_up   = (int)($_POST['id_up']   ?? 0);
    $id_rute = (int)($_POST['id_rute'] ?? 0);

    if ($aksi === 'konfirmasi') {
        $conn->query("UPDATE urutan_pengiriman SET status_pengiriman='sudah_dikirim' WHERE id=$id_up");
        $conn->query("UPDATE paket SET status='sudah_dikirim'
                      WHERE id_paket=(SELECT id_paket FROM urutan_pengiriman WHERE id=$id_up)");
        // Cek apakah semua paket dalam rute sudah selesai
        $sisa = $conn->query(
            "SELECT COUNT(*) FROM urutan_pengiriman
             WHERE id_rute=$id_rute AND status_pengiriman != 'sudah_dikirim'"
        )->fetch_row()[0];
        if ($sisa == 0) {
            $conn->query("UPDATE rute SET status='selesai' WHERE id_rute=$id_rute");
        }
    }
    header("Location: kurir.php?id_rute={$id_rute}");
    exit;
}

// Ambil rute aktif milik kurir ini
$id_rute = (int)($_GET['id_rute'] ?? 0);

// Jika tidak ada id_rute, ambil rute aktif terbaru
if ($id_rute === 0) {
    $r = $conn->query(
        "SELECT id_rute FROM rute WHERE id_kurir=$id_kurir AND status='aktif'
         ORDER BY created_at DESC LIMIT 1"
    );
    if ($row = $r->fetch_row()) $id_rute = $row[0];
}

// Info rute
$info_rute = null;
$titik     = [];
if ($id_rute > 0) {
    $info_rute = $conn->query(
        "SELECT r.*, k.nama FROM rute r JOIN kurir k ON r.id_kurir=k.id_kurir
         WHERE r.id_rute=$id_rute AND r.id_kurir=$id_kurir"
    )->fetch_assoc();

    if ($info_rute) {
        $dr = $conn->query(
            "SELECT up.id AS id_up, up.urutan, up.status_pengiriman,
                    p.no_resi, p.alamat, p.zona, p.latitude, p.longitude
             FROM urutan_pengiriman up
             JOIN paket p ON up.id_paket=p.id_paket
             WHERE up.id_rute=$id_rute ORDER BY up.urutan"
        );
        while ($r = $dr->fetch_assoc()) $titik[] = $r;
    }
}

// Paket aktif yang sedang harus dikirim (urutan terkecil yang belum selesai)
$paket_aktif = null;
foreach ($titik as $t) {
    if ($t['status_pengiriman'] !== 'sudah_dikirim') {
        $paket_aktif = $t; break;
    }
}

// Daftar semua rute aktif kurir ini (untuk dropdown pilih rute)
$semua_rute = $conn->query(
    "SELECT r.id_rute, r.tanggal, r.total_jarak, COUNT(up.id) AS jml
     FROM rute r LEFT JOIN urutan_pengiriman up ON up.id_rute=r.id_rute
     WHERE r.id_kurir=$id_kurir AND r.status='aktif'
     GROUP BY r.id_rute ORDER BY r.created_at DESC"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSP Kurir</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
<div class="dashboard-container">
    <div class="sidebar">
        <h1 class="logo">TSP</h1>
        <nav class="menu">
            <a href="kurir.php" class="active">Navigasi Maps</a>
        </nav>

        <?php if (!empty($semua_rute)): ?>
        <div style="padding:0 0 10px;">
            <p style="font-size:12px;color:#888;margin-bottom:6px;">Pilih Rute:</p>
            <?php foreach ($semua_rute as $sr): ?>
            <a href="kurir.php?id_rute=<?= $sr['id_rute'] ?>"
               style="display:block;padding:8px 10px;margin-bottom:4px;border-radius:7px;font-size:12px;text-decoration:none;
                      background:<?= $sr['id_rute']==$id_rute ? '#0b57d0' : 'white' ?>;
                      color:<?= $sr['id_rute']==$id_rute ? 'white' : '#333' ?>;">
                Rute #<?= $sr['id_rute'] ?> — <?= $sr['jml'] ?> paket<br>
                <span style="font-size:11px;opacity:0.8;"><?= $sr['tanggal'] ?> | <?= $sr['total_jarak'] ?> km</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($info_rute): ?>
        <div style="background:white;border-radius:8px;padding:10px;margin-bottom:10px;font-size:12px;">
            <div style="font-weight:600;margin-bottom:6px;">Rute #<?= $id_rute ?></div>
            <div style="color:#888;">Total: <?= $info_rute['total_jarak'] ?> km</div>
            <div style="color:#888;"><?= count($titik) ?> titik pengiriman</div>
            <?php
            $selesai = count(array_filter($titik, fn($t) => $t['status_pengiriman']==='sudah_dikirim'));
            ?>
            <div style="margin-top:6px;background:#f0f4ff;border-radius:6px;padding:6px;text-align:center;">
                <strong style="color:#0b57d0;"><?= $selesai ?>/<?= count($titik) ?></strong>
                <span style="color:#888;"> selesai</span>
            </div>
        </div>

        <!-- Daftar titik pengiriman --> 
        <div style="flex-grow:1;overflow-y:auto;">
            <p style="font-size:12px;color:#888;margin-bottom:6px;">Daftar Pengiriman:</p>
            <?php foreach ($titik as $i => $t): ?>
            <div style="background:white;border-radius:7px;padding:8px 10px;margin-bottom:4px;
                        border-left:3px solid <?= $t['status_pengiriman']==='sudah_dikirim' ? '#198754' : ($paket_aktif && $paket_aktif['id_up']==$t['id_up'] ? '#0b57d0' : '#d1d9f0') ?>;">
                <div style="font-size:11px;font-weight:600;color:#333;">
                    <?= $t['urutan'] ?>. <?= htmlspecialchars($t['no_resi']) ?>
                </div>
                <div style="font-size:11px;color:#888;margin-top:2px;">
                    <?= htmlspecialchars(substr($t['alamat'],0,40)) ?>...
                </div>
                <span style="font-size:10px;" class="badge <?= $t['status_pengiriman']==='sudah_dikirim' ? 'badge-sudah' : 'badge-belum' ?>">
                    <?= $t['status_pengiriman']==='sudah_dikirim' ? 'Selesai' : 'Belum' ?>
                </span>
            </div>
            <?php endforeach; ?>
            
            <!-- Kembali ke Gudang -->
            <div style="background:#f0f9f4;border-radius:7px;padding:8px 10px;margin-bottom:4px;border-left:3px solid #198754;">
                <div style="font-size:11px;font-weight:600;color:#198754;">
                    Kembali ke Gudang
                </div>
                <div style="font-size:11px;color:#888;margin-top:2px;">
                    Titik akhir (<?= GUDANG_NAMA ?>)
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="logout" style="margin-top:10px;">
            <a href="logout.php">Log Out</a>
        </div>
    </div>

    <!-- Peta -->
    <div class="main-content maps-content">
        <?php if (!$info_rute): ?>
        <div style="display:flex;align-items:center;justify-content:center;height:100%;flex-direction:column;gap:12px;color:#888;">
            <p style="font-size:16px;">Belum ada rute aktif yang ditugaskan.</p>
            <p style="font-size:13px;">Hubungi admin untuk mendapatkan tugas pengiriman.</p>
        </div>
        <?php else: ?>
        <div class="map-fullscreen">
            <div id="map-kurir-full"></div>

            //detail paket aktif
            <?php if ($paket_aktif): ?>
            <div class="detail-paket-kurir">
                <h3>Titik <?= $paket_aktif['urutan'] ?> — <?= htmlspecialchars($paket_aktif['no_resi']) ?></h3>
                <p><?= htmlspecialchars($paket_aktif['alamat']) ?></p>
                <p style="color:#888;">Zona: <?= htmlspecialchars($paket_aktif['zona']) ?></p>
                <div class="btn-row">
                    <form method="POST">
                        <input type="hidden" name="aksi"    value="konfirmasi">
                        <input type="hidden" name="id_up"   value="<?= $paket_aktif['id_up'] ?>">
                        <input type="hidden" name="id_rute" value="<?= $id_rute ?>">
                        <button type="submit" class="btn-primary" style="margin:0;">Konfirmasi</button>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="detail-paket-kurir">
                <h3>Semua paket selesai dikirim!</h3>
                <p style="color:#198754;font-weight:600;">Rute #<?= $id_rute ?> telah selesai.</p>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var map = L.map('map-kurir-full');
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(map);

            var titik = [
                { lat: <?= GUDANG_LAT ?>, lng: <?= GUDANG_LNG ?>, label: 'G', popup: '<?= GUDANG_NAMA ?>', done: false, active: false }
                <?php foreach ($titik as $t): ?>
                ,{
                    lat:    <?= (float)$t['latitude'] ?>,
                    lng:    <?= (float)$t['longitude'] ?>,
                    label:  '<?= $t['urutan'] ?>',
                    popup:  '<?= addslashes($t['no_resi']) ?><br><?= addslashes(substr($t['alamat'],0,60)) ?>',
                    done:   <?= $t['status_pengiriman']==='sudah_dikirim' ? 'true' : 'false' ?>,
                    active: <?= ($paket_aktif && $paket_aktif['id_up']==$t['id_up']) ? 'true' : 'false' ?>
                }
                <?php endforeach; ?>
            ];

            var latlngs = [];
            titik.forEach(function(t) {
                var color  = t.done ? '#198754' : (t.active ? '#dc3545' : '#0b57d0');
                var icon   = L.divIcon({
                    className: '',
                    html: '<div style="background:' + color + ';color:white;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);">' + t.label + '</div>',
                    iconSize: [28,28], iconAnchor: [14,14]
                });
                L.marker([t.lat, t.lng], {icon: icon}).addTo(map).bindPopup(t.popup);
                latlngs.push([t.lat, t.lng]);
            });

            if (latlngs.length > 1) {
                var closedLoop = latlngs.concat([latlngs[0]]); // Tutup loop rute kembali ke gudang
                L.polyline(closedLoop, {color:'#0b57d0', weight:3, dashArray:'6,4'}).addTo(map);
                map.fitBounds(latlngs, {padding:[60,60]});
            }

            // Fokus ke titik aktif
            <?php if ($paket_aktif && $paket_aktif['latitude']): ?>
            map.setView([<?= (float)$paket_aktif['latitude'] ?>, <?= (float)$paket_aktif['longitude'] ?>], 16);
            <?php endif; ?>
        })();
        </script>
        <?php endif; ?>
    </div>
</div>
</body>
</html>