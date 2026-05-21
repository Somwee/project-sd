<?php
session_start();
if (isset($_SESSION['role'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin.php' : 'kurir.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSP — Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">TSP</div>
        <h2 class="login-title">Selamat Datang</h2>
        <p class="login-subtitle">Pilih jenis akun untuk masuk</p>

        <a href="login.php?role=admin" class="btn-role btn-admin">
             &nbsp; Admin
        </a>
        <a href="login.php?role=kurir" class="btn-role btn-kurir">
             &nbsp; Kurir
        </a>
    </div>
</div>

</body>
</html>