-- Complete database schema for Crystal Pre-School System
CREATE DATABASE IF NOT EXISTS crystal_preschool;
USE crystal_preschool;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('parent','admin','staff') DEFAULT 'parent',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_login DATETIME,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Applications table
CREATE TABLE applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id VARCHAR(20) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    child_first_name VARCHAR(50) NOT NULL,
    child_last_name VARCHAR(50) NOT NULL,
    child_dob DATE NOT NULL,
    child_gender ENUM('male','female','other') NOT NULL,
    parent_name VARCHAR(100) NOT NULL,
    parent_relationship VARCHAR(50) NOT NULL,
    parent_email VARCHAR(100) NOT NULL,
    parent_phone VARCHAR(20) NOT NULL,
    preferred_branch ENUM('section-b2','stand-561') NOT NULL,
    program_type ENUM('full-day','half-day-am','half-day-pm') NOT NULL,
    status ENUM('pending','reviewed','approved','rejected') DEFAULT 'pending',
    submitted_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_application_id (application_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_branch (preferred_branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Branches table
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    address TEXT NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    facilities TEXT,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email queue for async processing
CREATE TABLE email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(100) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    sent_at DATETIME,
    created_at DATETIME NOT NULL,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME NOT NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default branches with updated addresses
INSERT INTO branches (branch_code, name, address, phone, email, facilities, created_at) VALUES
('section-b2', 'Section B2', 'Next to Salvation Army Church, Mnele Village, Polokwane, Limpopo, South Africa', '078 318 7635', 'crystallearning@gmail.com', 'Modern classrooms, science lab, computer lab, library', NOW()),
('stand-561', 'Stand No. 561', 'Mnele Village, Polokwane, Limpopo, South Africa', '078 318 7635', 'crystallearning@gmail.com', 'Spacious classrooms, art studio, music room, sports field', NOW());

-- Create admin user (password: admin123)
INSERT INTO users (name, email, phone, password, role, created_at, updated_at) VALUES
('Admin User', 'admin@crystal.com', '0783187635', '$2y$10$YourHashedPasswordHere', 'admin', NOW(), NOW());