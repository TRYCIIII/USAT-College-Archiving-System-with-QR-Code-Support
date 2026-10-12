-- ============================================
-- DATABASE: usat_archiving
-- COMPLETE DATABASE FOR USAT ARCHIVING SYSTEM
-- ============================================

-- Drop database if exists (optional - remove if you want to keep existing data)
-- DROP DATABASE IF EXISTS usat_archiving;
-- CREATE DATABASE usat_archiving;
-- USE usat_archiving;

-- ============================================
-- TABLE: users (Admin and Staff only)
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE,
    full_name VARCHAR(100),
    role ENUM('admin', 'staff') DEFAULT 'staff',
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reset_token VARCHAR(255) NULL,
    reset_expires DATETIME NULL,
    otp_code VARCHAR(10) NULL,
    otp_expires DATETIME NULL
);

-- ============================================
-- TABLE: folders (Google Drive style folders)
-- ============================================
CREATE TABLE IF NOT EXISTS folders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    folder_name VARCHAR(255) NOT NULL,
    parent_id INT DEFAULT 0,
    folder_path VARCHAR(500),
    color VARCHAR(7) DEFAULT '#667eea',
    created_by INT,
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id)
);

-- ============================================
-- TABLE: files (with 5 required fields for archiving)
-- ============================================
CREATE TABLE IF NOT EXISTS files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50),
    file_size VARCHAR(50),
    file_extension VARCHAR(20),
    folder_id INT DEFAULT 0,
    
    -- The 5 required fields
    title_of_program VARCHAR(255) NOT NULL,
    date_conducted DATE NOT NULL,
    no_of_beneficiaries INT NOT NULL,
    type_of_community_service VARCHAR(100) NOT NULL,
    community_population_served VARCHAR(255) NOT NULL,
    
    -- Additional fields
    description TEXT,
    view_token VARCHAR(255) NULL,
    file_qr_code VARCHAR(255) NULL,
    uploaded_by INT,
    download_count INT DEFAULT 0,
    view_count INT DEFAULT 0,
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_folder (folder_id),
    INDEX idx_status (status)
);

-- ============================================
-- TABLE: file_views (track document views)
-- ============================================
CREATE TABLE IF NOT EXISTS file_views (
    id INT PRIMARY KEY AUTO_INCREMENT,
    file_id INT NOT NULL,
    viewed_by INT,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (viewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================
-- TABLE: file_downloads (track document downloads)
-- ============================================
CREATE TABLE IF NOT EXISTS file_downloads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    file_id INT NOT NULL,
    downloaded_by INT,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (downloaded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================
-- TABLE: activity_log (system activities)
-- ============================================
CREATE TABLE IF NOT EXISTS activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================
-- TABLE: password_resets (for forgot password)
-- ============================================
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) NOT NULL,
    otp_code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_token (token)
);
CREATE TABLE IF NOT EXISTS activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================
-- INSERT DEFAULT USERS
-- Password: admin123 for admin, staff123 for staff
-- ============================================

-- Admin user
INSERT INTO users (username, password, email, full_name, role, status) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@usat.edu.ph', 'System Administrator', 'admin', 'active')
ON DUPLICATE KEY UPDATE password = VALUES(password);

-- Staff users
INSERT INTO users (username, password, email, full_name, role, status) VALUES 
('jsantos', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'juan.santos@usat.edu.ph', 'Juan Santos', 'staff', 'active'),
('mreyes', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'maria.reyes@usat.edu.ph', 'Maria Reyes', 'staff', 'active')
ON DUPLICATE KEY UPDATE password = VALUES(password);

-- ============================================
-- INSERT DEFAULT FOLDERS
-- ============================================

INSERT INTO folders (folder_name, parent_id, created_by) VALUES 
('My Drive', 0, 1),
('Archived', 0, 1),
('Community Services', 0, 1)
ON DUPLICATE KEY UPDATE folder_name = folder_name;

-- ============================================
-- INSERT SAMPLE ACTIVITY LOGS
-- ============================================

INSERT INTO activity_log (user_id, action, description, ip_address) VALUES 
(1, 'LOGIN', 'System initialized', '127.0.0.1')
ON DUPLICATE KEY UPDATE id = id;

-- ============================================
-- VERIFY ALL TABLES
-- ============================================

SELECT '✅ DATABASE SETUP COMPLETE!' as Status;

SELECT '--- TABLES CREATED ---' as Info;
SHOW TABLES;

SELECT '--- USERS ---' as Info;
SELECT id, username, email, full_name, role, status FROM users;

SELECT '--- FOLDERS ---' as Info;
SELECT id, folder_name, parent_id FROM folders;

SELECT '--- FILES COUNT ---' as Info;
SELECT COUNT(*) as total_files FROM files;

-- ============================================
-- DISPLAY LOGIN CREDENTIALS
-- ============================================
SELECT '🔑 LOGIN CREDENTIALS:' as Login_Info;
SELECT 'Admin' as Role, 'admin' as Username, 'admin123' as Password
UNION ALL
SELECT 'Staff', 'jsantos', 'staff123'
UNION ALL
SELECT 'Staff', 'mreyes', 'staff123';