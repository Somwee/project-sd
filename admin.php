<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$menu = isset($_GET['menu']) ? $_GET['menu'] : 'paket';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSP - Admin Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <h1 class="logo">TSP</h1>
            <nav class="menu">
                <a href="?menu=paket"  class="<?= ($menu=='paket')  ? 'active' : '' ?>">Manajemen Paket</a>
                <a href="?menu=rute"   class="<?= ($menu=='rute')   ? 'active' : '' ?>">Manajemen Rute</a>
                <a href="?menu=kurir"  class="<?= ($menu=='kurir')  ? 'active' : '' ?>">Manajemen Kurir</a>
            </nav>
            <div class="logout">
                <a href="logout.php"> Log Out</a>
            </div>
        </div>

        <div class="main-content">
            <div class="header-top">
                <div class="user-profile">
                    <span><?= htmlspecialchars($_SESSION['nama']) ?></span>
                </div>
            </div>

            <div class="content-area">
                <?php
                if ($menu == 'paket') {
                    include 'manajemen_paket.php';
                } elseif ($menu == 'rute') {
                    include 'manajemen_rute.php';
                } elseif ($menu == 'kurir') {
                    include 'manajemen_kurir.php';
                } else {
                    echo "<h2>Halaman tidak ditemukan</h2>";
                }
                ?>
            </div>
        </div>
    </div>
</body>
</html>