<?php
// ── Database ───────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');   
define('DB_PASS', '');       
define('DB_NAME', 'sistem_rute_kurir');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Koneksi database gagal: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// Koordinat gudang / titik awal kurir
define('GUDANG_LAT',  -7.6309);
define('GUDANG_LNG',  111.5227);
define('GUDANG_NAMA', 'Gudang (Titik Awal)');