<?php
session_start();
if (isset($_SESSION['role'])) {
    header('Location: ' . ($_SESSION['role'] === 'admin' ? 'admin.php' : 'kurir.php'));
    exit;
}

$role  = isset($_GET['role']) && $_GET['role'] === 'kurir' ? 'kurir' : 'admin';
$error = '';

//Proses submit form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'koneksi.php';

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email dan password tidak boleh kosong.';
    } else {
        if ($role === 'admin') {
            $stmt = $conn->prepare("SELECT id_admin, nama, password FROM admin WHERE email = ?");
        } else {
            $stmt = $conn->prepare("SELECT id_kurir, nama, password, status FROM kurir WHERE email = ?");
        }

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Email tidak ditemukan.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Password salah.';
        } elseif ($role === 'kurir' && $user['status'] === 'nonaktif') {
            $error = 'Akun kurir Anda sedang nonaktif. Hubungi admin.';
        } else {
            // Login berhasil simpan sesi
            $_SESSION['role'] = $role;
            if ($role === 'admin') {
                $_SESSION['id_admin'] = $user['id_admin'];
                $_SESSION['nama']     = $user['nama'];
                header('Location: admin.php');
            } else {
                $_SESSION['id_kurir'] = $user['id_kurir'];
                $_SESSION['nama']     = $user['nama'];
                header('Location: kurir.php');
            }
            exit;
        }
    }
}

$label = $role === 'admin' ? 'Admin' : 'Kurir';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TSP — Login <?= htmlspecialchars($label) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">TSP</div>
        <h2 class="login-title">Login <?= htmlspecialchars($label) ?></h2>

        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Username / Email</label>
                <input
                    type="email"
                    name="email"
                    placeholder="contoh@email.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    required
                    autofocus
                >
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-primary" style="margin-top:10px;">Lanjutkan</button>
        </form>

        <p style="margin-top:16px; text-align:center; font-size:13px; color:#888;">
            <a href="index.php" style="color:#0b57d0;">← Kembali ke pilihan akun</a>
        </p>
    </div>
</div>

</body>
</html>