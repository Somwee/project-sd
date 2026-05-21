<?php
require_once 'koneksi.php';

$id_admin   = $_SESSION['id_admin'];
$pesan      = '';
$pesan_type = '';

function generateResi($conn) {
    do {
        $kode = 'AA-' . strtoupper(substr(md5(uniqid()), 0, 4)) . '-' . rand(10, 99);
        $cek  = $conn->query("SELECT id_paket FROM paket WHERE no_resi='$kode'");
    } while ($cek->num_rows > 0);
    return $kode;
}

// Geocoding
function geocode($alamat) {
    $q   = urlencode($alamat);
    $url = "https://nominatim.openstreetmap.org/search?q={$q}&format=json&limit=1&countrycodes=id";
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: SistemRuteKurir/1.0\r\n",
        'timeout' => 6,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data)) {
            return [(float)$data[0]['lat'], (float)$data[0]['lon']];
        }
    }
    return [null, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'tambah') {
        $alamat    = trim($_POST['alamat'] ?? '');
        $zona_sel  = $_POST['zona']        ?? '';
        $zona_baru = trim($_POST['zona_baru'] ?? '');
        $zona      = ($zona_sel === '__new__' && $zona_baru !== '') ? $zona_baru : $zona_sel;

        if (empty($alamat) || empty($zona)) {
            $pesan = 'Alamat dan zona tidak boleh kosong.';
            $pesan_type = 'error';
        } else {
            $no_resi    = generateResi($conn);
            [$lat, $lng] = geocode($alamat);

            $stmt = $conn->prepare(
                "INSERT INTO paket (no_resi, alamat, zona, latitude, longitude, status, id_admin)
                 VALUES (?, ?, ?, ?, ?, 'belum_dikirim', ?)"
            );
            $stmt->bind_param('sssddi', $no_resi, $alamat, $zona, $lat, $lng, $id_admin);
            if ($stmt->execute()) {
                $info = $lat ? 'Koordinat ditemukan.' : 'Koordinat tidak ditemukan, isi manual.';
                $pesan = "Paket {$no_resi} berhasil ditambahkan. {$info}";
                $pesan_type = 'success';
            } else {
                $pesan = 'Gagal menyimpan paket.'; $pesan_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($aksi === 'hapus') {
        $id = (int)($_POST['id_paket'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM paket WHERE id_paket=? AND id_admin=?");
        $stmt->bind_param('ii', $id, $id_admin);
        $stmt->execute();
        $pesan = $stmt->affected_rows > 0 ? 'Paket berhasil dihapus.' : 'Gagal menghapus.';
        $pesan_type = $stmt->affected_rows > 0 ? 'success' : 'error';
        $stmt->close();
    }

    if ($aksi === 'update_status') {
        $id     = (int)($_POST['id_paket'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['belum_dikirim','sedang_dikirim','sudah_dikirim'])) {
            $stmt = $conn->prepare("UPDATE paket SET status=? WHERE id_paket=?");
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $pesan = 'Status diperbarui.'; $pesan_type = 'success';
            $stmt->close();
        }
    }

    if ($aksi === 'update_koordinat') {
        $id  = (int)($_POST['id_paket'] ?? 0);
        $lat = (float)($_POST['latitude']  ?? 0);
        $lng = (float)($_POST['longitude'] ?? 0);
        $stmt = $conn->prepare("UPDATE paket SET latitude=?, longitude=? WHERE id_paket=?");
        $stmt->bind_param('ddi', $lat, $lng, $id);
        $stmt->execute();
        $pesan = 'Koordinat diperbarui.'; $pesan_type = 'success';
        $stmt->close();
    }

    // Geocode ulang otomatis
    if ($aksi === 'geocode_ulang') {
        $id     = (int)($_POST['id_paket'] ?? 0);
        $alamat = trim($_POST['alamat'] ?? '');
        [$lat, $lng] = geocode($alamat);
        if ($lat) {
            $stmt = $conn->prepare("UPDATE paket SET latitude=?, longitude=? WHERE id_paket=?");
            $stmt->bind_param('ddi', $lat, $lng, $id);
            $stmt->execute();
            $pesan = 'Koordinat berhasil ditemukan.'; $pesan_type = 'success';
            $stmt->close();
        } else {
            $pesan = 'Koordinat tidak ditemukan. Coba perjelas alamat atau isi manual.';
            $pesan_type = 'error';
        }
    }
}

// Query
$tab    = $_GET['tab']    ?? 'belum_dikirim';
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 8; $offset = ($page - 1) * $limit;
if (!in_array($tab, ['belum_dikirim','sedang_dikirim','sudah_dikirim'])) $tab = 'belum_dikirim';

$where  = "WHERE status=?"; $params = [$tab]; $types = 's';
if ($search !== '') {
    $where .= " AND (no_resi LIKE ? OR alamat LIKE ? OR zona LIKE ?)";
    $like   = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like]); $types .= 'sss';
}

$cs = $conn->prepare("SELECT COUNT(*) FROM paket $where");
$cs->bind_param($types, ...$params); $cs->execute();
$total_rows  = $cs->get_result()->fetch_row()[0]; $cs->close();
$total_pages = ceil($total_rows / $limit);

$ds = $conn->prepare("SELECT * FROM paket $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
$params[] = $limit; $params[] = $offset; $types .= 'ii';
$ds->bind_param($types, ...$params); $ds->execute();
$paket_list = $ds->get_result()->fetch_all(MYSQLI_ASSOC); $ds->close();

$stat = [];
foreach (['belum_dikirim','sedang_dikirim','sudah_dikirim'] as $s) {
    $stat[$s] = $conn->query("SELECT COUNT(*) FROM paket WHERE status='$s'")->fetch_row()[0];
}

$zr = $conn->query("SELECT DISTINCT zona FROM paket ORDER BY zona");
$zona_list = [];
while ($z = $zr->fetch_row()) $zona_list[] = $z[0];
if (empty($zona_list)) $zona_list = ['A'];

$new_resi  = generateResi($conn);
$edit_data = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $es  = $conn->prepare("SELECT * FROM paket WHERE id_paket=? AND id_admin=?");
    $es->bind_param('ii', $eid, $id_admin); $es->execute();
    $edit_data = $es->get_result()->fetch_assoc(); $es->close();
}

$tab_labels = ['belum_dikirim'=>'Belum Dikirim','sedang_dikirim'=>'Sedang Dikirim','sudah_dikirim'=>'Sudah Dikirim'];
$badge_map  = ['belum_dikirim'=>['badge-belum','Belum Dikirim'],'sedang_dikirim'=>['badge-sedang','Sedang Dikirim'],'sudah_dikirim'=>['badge-sudah','Sudah Dikirim']];
?>

<h2 class="page-title">Manajemen Paket</h2>

<?php if ($pesan): ?>
<div class="alert-<?= $pesan_type === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($pesan) ?></div>
<?php endif; ?>

<div class="panel-container">
<div class="panel-left">

<?php if ($edit_data): ?>
    <h3>Edit Koordinat</h3>
    <p style="font-size:13px;color:#555;margin-bottom:10px;">
        Resi: <strong><?= htmlspecialchars($edit_data['no_resi']) ?></strong>
    </p>
    <!-- Geocode ulang otomatis -->
    <form method="POST" action="?menu=paket&tab=<?= $tab ?>" style="margin-bottom:10px;">
        <input type="hidden" name="aksi"     value="geocode_ulang">
        <input type="hidden" name="id_paket" value="<?= $edit_data['id_paket'] ?>">
        <input type="hidden" name="alamat"   value="<?= htmlspecialchars($edit_data['alamat']) ?>">
        <button type="submit" class="btn-secondary" style="width:100%;margin:0;">Cari Koordinat Otomatis</button>
    </form>
    <p style="font-size:12px;color:#888;margin-bottom:10px;text-align:center;">atau isi manual:</p>
    <form method="POST" action="?menu=paket&tab=<?= $tab ?>">
        <input type="hidden" name="aksi"     value="update_koordinat">
        <input type="hidden" name="id_paket" value="<?= $edit_data['id_paket'] ?>">
        <label>Latitude</label>
        <input type="number" step="0.0000001" name="latitude"  value="<?= $edit_data['latitude'] ?>"  required>
        <label>Longitude</label>
        <input type="number" step="0.0000001" name="longitude" value="<?= $edit_data['longitude'] ?>" required>
        <button type="submit" class="btn-primary">SIMPAN KOORDINAT</button>
    </form>
    <a href="?menu=paket&tab=<?= $tab ?>" style="display:block;text-align:center;margin-top:10px;font-size:13px;color:#888;">Batal</a>

<?php else: ?>
    <h3>Input Paket Baru</h3>
    <form method="POST" action="?menu=paket&tab=<?= $tab ?>">
        <input type="hidden" name="aksi" value="tambah">
        <label>Nomor Resi</label>
        <input type="text" value="<?= htmlspecialchars($new_resi) ?>" readonly>
        <label>Alamat Tujuan</label>
        <textarea name="alamat" rows="3" placeholder="Contoh: Jl. Bali No. 10, Madiun, Jawa Timur" required><?= htmlspecialchars($_POST['alamat'] ?? '') ?></textarea>
        <small style="color:#aaa;font-size:11px;">Format: Nama Jalan, Nomor, Kota, Provinsi</small>
        <label>Zona Pengiriman</label>
        <select name="zona" id="zona_select" required>
            <?php foreach ($zona_list as $z): ?>
                <option value="<?= htmlspecialchars($z) ?>"><?= htmlspecialchars($z) ?></option>
            <?php endforeach; ?>
            <option value="__new__">+ Tambah Zona Baru</option>
        </select>
        <input type="text" name="zona_baru" id="zona_baru" placeholder="Nama zona baru" style="display:none;margin-top:6px;">
        <button type="submit" class="btn-primary">SIMPAN</button>
    </form>
<?php endif; ?>

    <div class="stat-row">
        <div class="stat-card"><div class="stat-num"><?= $stat['belum_dikirim'] ?></div><div class="stat-label">Belum Kirim</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#0b57d0;"><?= $stat['sedang_dikirim'] ?></div><div class="stat-label">Sedang Kirim</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#198754;"><?= $stat['sudah_dikirim'] ?></div><div class="stat-label">Selesai</div></div>
    </div>
</div>

<div class="panel-right">
    <h3>Data Paket</h3>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px;">
        <input type="hidden" name="menu" value="paket">
        <input type="hidden" name="tab"  value="<?= $tab ?>">
        <input type="text" name="search" class="search-input" placeholder="Cari No. Resi / Alamat / Zona"
               value="<?= htmlspecialchars($search) ?>" style="margin:0;flex:1;">
        <button type="submit" class="btn-secondary" style="width:auto;padding:9px 16px;margin:0;">Cari</button>
        <?php if ($search): ?>
            <a href="?menu=paket&tab=<?= $tab ?>" class="btn-secondary" style="width:auto;padding:9px 14px;margin:0;text-decoration:none;">X</a>
        <?php endif; ?>
    </form>

    <div class="tabs">
        <?php foreach ($tab_labels as $key => $label): ?>
            <a href="?menu=paket&tab=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?>"
               class="tab <?= $tab===$key ? 'active' : '' ?>" style="text-decoration:none;"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($paket_list)): ?>
        <p style="text-align:center;color:#aaa;padding:40px 0;">Tidak ada data paket.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead><tr><th>No. Resi</th><th>Alamat</th><th>Zona</th><th>Koordinat</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($paket_list as $p): ?>
        <tr>
            <td><strong><?= htmlspecialchars($p['no_resi']) ?></strong></td>
            <td style="max-width:180px;font-size:12px;word-break:break-word;">
                <?= htmlspecialchars(substr($p['alamat'],0,60)) ?><?= strlen($p['alamat'])>60 ? '...' : '' ?>
            </td>
            <td><?= htmlspecialchars($p['zona']) ?></td>
            <td style="font-size:11px;">
                <?php if ($p['latitude']): ?>
                    <span style="color:#198754;">Tersedia</span><br>
                    <span style="color:#888;"><?= round($p['latitude'],5) ?>, <?= round($p['longitude'],5) ?></span>
                <?php else: ?>
                    <span style="color:#dc3545;">Belum ada</span>
                <?php endif; ?>
            </td>
            <td>
                <?php [$bc,$bl] = $badge_map[$p['status']]; ?>
                <span class="badge <?= $bc ?>"><?= $bl ?></span>
            </td>
            <td>
                <div style="display:flex;flex-direction:column;gap:4px;min-width:110px;">
                    <form method="POST" action="?menu=paket&tab=<?= $tab ?>&page=<?= $page ?>">
                        <input type="hidden" name="aksi"     value="update_status">
                        <input type="hidden" name="id_paket" value="<?= $p['id_paket'] ?>">
                        <select name="status" onchange="this.form.submit()"
                                style="width:100%;font-size:12px;padding:5px;border-radius:5px;border:1px solid #ccc;">
                            <option value="belum_dikirim"  <?= $p['status']=='belum_dikirim'  ? 'selected':'' ?>>Belum Dikirim</option>
                            <option value="sedang_dikirim" <?= $p['status']=='sedang_dikirim' ? 'selected':'' ?>>Sedang Dikirim</option>
                            <option value="sudah_dikirim"  <?= $p['status']=='sudah_dikirim'  ? 'selected':'' ?>>Sudah Dikirim</option>
                        </select>
                    </form>
                    <a href="?menu=paket&edit=<?= $p['id_paket'] ?>&tab=<?= $tab ?>"
                       class="btn-sm btn-edit" style="text-align:center;text-decoration:none;">Koordinat</a>
                    <form method="POST" action="?menu=paket&tab=<?= $tab ?>&page=<?= $page ?>"
                          onsubmit="return confirm('Hapus paket ini?')">
                        <input type="hidden" name="aksi"     value="hapus">
                        <input type="hidden" name="id_paket" value="<?= $p['id_paket'] ?>">
                        <button type="submit" class="btn-sm btn-hapus" style="width:100%;">Hapus</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <span style="font-size:13px;color:#888;">
            <?= $offset+1 ?>-<?= min($offset+$limit,$total_rows) ?> dari <?= $total_rows ?>
        </span>
        <?php for ($pg=1; $pg<=$total_pages; $pg++): ?>
            <a href="?menu=paket&tab=<?= $tab ?>&page=<?= $pg ?><?= $search ? '&search='.urlencode($search) : '' ?>"
               class="<?= $pg===$page ? 'current' : '' ?>"><?= $pg ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script src="script.js"></script>