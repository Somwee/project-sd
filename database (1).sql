-- =============================================
-- DATABASE: sistem_rute_kurir (v2 - FIXED)
-- Perbaikan: FK urutan_pengiriman pakai id_paket
-- bukan no_resi agar tipe data konsisten
-- =============================================

DROP DATABASE IF EXISTS sistem_rute_kurir;

CREATE DATABASE sistem_rute_kurir
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sistem_rute_kurir;

-- ---------------------------------------------
-- 1. Tabel: admin
-- ---------------------------------------------
CREATE TABLE admin (
    id_admin   INT AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------
-- 2. Tabel: kurir
-- ---------------------------------------------
CREATE TABLE kurir (
    id_kurir   INT AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL UNIQUE,
    no_hp      VARCHAR(20),
    password   VARCHAR(255) NOT NULL,
    status     ENUM('aktif','nonaktif') DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------
-- 3. Tabel: paket
-- FK target pakai id_paket (INT), bukan no_resi
-- ---------------------------------------------
CREATE TABLE paket (
    id_paket   INT AUTO_INCREMENT PRIMARY KEY,
    no_resi    VARCHAR(20)   NOT NULL UNIQUE,
    alamat     TEXT          NOT NULL,
    zona       VARCHAR(50)   NOT NULL,
    latitude   DECIMAL(10,7) DEFAULT NULL,
    longitude  DECIMAL(10,7) DEFAULT NULL,
    status     ENUM('belum_dikirim','sedang_dikirim','sudah_dikirim') DEFAULT 'belum_dikirim',
    id_admin   INT           DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_admin) REFERENCES admin(id_admin) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------
-- 4. Tabel: rute
-- ---------------------------------------------
CREATE TABLE rute (
    id_rute     INT AUTO_INCREMENT PRIMARY KEY,
    id_kurir    INT  DEFAULT NULL,
    tanggal     DATE NOT NULL,
    total_jarak DECIMAL(10,3) DEFAULT 0,
    tipe_rute   ENUM('terpendek','terjauh') DEFAULT 'terpendek',
    status      ENUM('aktif','selesai') DEFAULT 'aktif',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_kurir) REFERENCES kurir(id_kurir) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------
-- 5. Tabel: urutan_pengiriman
-- FK ke id_paket (INT) — tipe sudah cocok
-- ---------------------------------------------
CREATE TABLE urutan_pengiriman (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    id_rute           INT NOT NULL,
    id_paket          INT NOT NULL,
    urutan            INT NOT NULL,
    status_pengiriman ENUM('belum_dikirim','sedang_dikirim','sudah_dikirim') DEFAULT 'belum_dikirim',
    FOREIGN KEY (id_rute)  REFERENCES rute(id_rute)   ON DELETE CASCADE,
    FOREIGN KEY (id_paket) REFERENCES paket(id_paket) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================
-- DATA AWAL (Seed)
-- Password admin : "admin123"
-- Password kurir : "password123"
-- =============================================

INSERT INTO admin (nama, email, password) VALUES
('Admin Utama', 'admin@tsp.com',
 '$2y$10$q4Kjlv2ZpH/.7tvFfEfcgOWWPA4X5OBmcoTw40N8DV3JK1AGSmOji');

INSERT INTO kurir (nama, email, no_hp, password, status) VALUES
('Jono',     'jono@gmail.com',     '0811-111-1111',
 '$2y$10$UdKAvb25mJLQBLlQkX2lRuoWFHLqB/3uAtjmAV36jKiIEbyzzoK22', 'aktif'),
('Sugianto', 'sugianto@gmail.com', '0822-222-2222',
 '$2y$10$UdKAvb25mJLQBLlQkX2lRuoWFHLqB/3uAtjmAV36jKiIEbyzzoK22', 'aktif'),
('Agus',     'agus@gmail.com',     '0833-333-3333',
 '$2y$10$UdKAvb25mJLQBLlQkX2lRuoWFHLqB/3uAtjmAV36jKiIEbyzzoK22', 'aktif');

INSERT INTO paket (no_resi, alamat, zona, latitude, longitude, status, id_admin) VALUES
('AA-ABCD-11', 'Jl. Bali No. 10, Madiun',      'A', -7.6298, 111.5239, 'belum_dikirim', 1),
('AA-ABCD-12', 'Jl. Halmahera No. 5, Madiun',  'A', -7.6250, 111.5270, 'belum_dikirim', 1),
('AA-ABCD-13', 'Jl. Flores No. 3, Madiun',     'A', -7.6240, 111.5310, 'belum_dikirim', 1),
('AA-ABCD-14', 'Jl. Sumbawa No. 7, Madiun',    'A', -7.6320, 111.5300, 'belum_dikirim', 1),
('AA-ABCD-15', 'Jl. Sumatera No. 12, Madiun',  'A', -7.6350, 111.5200, 'belum_dikirim', 1);
