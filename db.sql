-- Subject Allotment System Database Schema
-- Create separate database for the system

CREATE DATABASE subject_allotment_system;
USE subject_allotment_system;

-- Admin table
CREATE TABLE admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Subject pools table
CREATE TABLE subject_pools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pool_name VARCHAR(100) NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    subject_name VARCHAR(200) NOT NULL,
    intake INT NOT NULL,
    allowed_programmes TEXT, -- JSON array of programme names
    batch VARCHAR(20),
    semester VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_ip VARCHAR(45),
    updated_ip VARCHAR(45),
    FOREIGN KEY (created_by) REFERENCES admin(id)
);

-- Student academic data table
CREATE TABLE student_academic_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    regno VARCHAR(20) UNIQUE NOT NULL,
    cgpa DECIMAL(4,2) DEFAULT NULL,
    backlogs INT DEFAULT 0,
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_ip VARCHAR(45),
    FOREIGN KEY (uploaded_by) REFERENCES admin(id),
    INDEX idx_regno (regno),
    INDEX idx_cgpa (cgpa),
    INDEX idx_backlogs (backlogs)
);

-- Student registrations table
CREATE TABLE student_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    regno VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    pool_id INT NOT NULL,
    priority_order TEXT, -- JSON array of subject preferences with priorities
    status ENUM('saved', 'frozen') DEFAULT 'saved',
    registration_token VARCHAR(64) UNIQUE,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    frozen_at TIMESTAMP NULL,
    registration_ip VARCHAR(45),
    last_updated_ip VARCHAR(45),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pool_id) REFERENCES subject_pools(id),
    INDEX idx_regno (regno),
    INDEX idx_pool_id (pool_id),
    INDEX idx_status (status)
);

-- Subject allotments table
CREATE TABLE subject_allotments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    regno VARCHAR(20) NOT NULL,
    pool_id INT NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    allotment_reason TEXT,
    allotment_rank INT,
    allotted_by INT,
    allotted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    allotment_ip VARCHAR(45),
    FOREIGN KEY (pool_id) REFERENCES subject_pools(id),
    FOREIGN KEY (allotted_by) REFERENCES admin(id),
    INDEX idx_regno (regno),
    INDEX idx_pool_id (pool_id),
    INDEX idx_subject_code (subject_code)
);

-- Login logs table
CREATE TABLE login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'student') NOT NULL,
    user_identifier VARCHAR(50) NOT NULL, -- admin username or student regno
    action ENUM('login', 'logout', 'failed_login') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    logout_time TIMESTAMP NULL,
    session_duration INT NULL, -- in seconds
    INDEX idx_user_type (user_type),
    INDEX idx_user_identifier (user_identifier),
    INDEX idx_action (action),
    INDEX idx_login_time (login_time)
);

-- Activity logs table
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin', 'student') NOT NULL,
    user_identifier VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values TEXT, -- JSON
    new_values TEXT, -- JSON
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_type (user_type),
    INDEX idx_user_identifier (user_identifier),
    INDEX idx_action (action),
    INDEX idx_timestamp (timestamp)
);

-- Form submission tracking (prevent resubmit on refresh)
CREATE TABLE form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_token VARCHAR(64) UNIQUE NOT NULL,
    user_type ENUM('admin', 'student') NOT NULL,
    user_identifier VARCHAR(50) NOT NULL,
    form_type VARCHAR(50) NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NOT NULL,
    INDEX idx_form_token (form_token),
    INDEX idx_user_identifier (user_identifier)
);

-- Session management table
CREATE TABLE user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) UNIQUE NOT NULL,
    user_type ENUM('admin', 'student') NOT NULL,
    user_identifier VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_session_id (session_id),
    INDEX idx_user_identifier (user_identifier),
    INDEX idx_expires_at (expires_at)
);

-- Security logs table
CREATE TABLE security_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    user_identifier VARCHAR(50),
    ip_address VARCHAR(45) NOT NULL,
    description TEXT NOT NULL,
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_timestamp (timestamp)
);

-- Insert default admin
INSERT INTO admin (username, password, email, name) VALUES 
('admin', MD5('admin123'), 'admin@college.edu', 'System Administrator');

-- Sample subject pools
INSERT INTO subject_pools (pool_name, subject_code, subject_name, intake, allowed_programmes, batch, semester, created_by, created_ip) VALUES
('Pool 1', 'CS501', 'Advanced Algorithms', 30, '["MCA", "B.Tech - CSE"]', '2024-2026', 'Third Semester', 1, '127.0.0.1'),
('Pool 1', 'CS502', 'Machine Learning', 25, '["MCA", "B.Tech - AIML"]', '2024-2026', 'Third Semester', 1, '127.0.0.1'),
('Pool 1', 'CS503', 'Database Management Systems', 35, '["MCA", "B.Tech - CSE", "B.Tech - IT"]', '2024-2026', 'Third Semester', 1, '127.0.0.1'),
('Pool 2', 'CS601', 'Cloud Computing', 20, '["MCA"]', '2024-2026', 'Fourth Semester', 1, '127.0.0.1'),
('Pool 2', 'CS602', 'Cyber Security', 25, '["MCA", "B.Tech - CSE"]', '2024-2026', 'Fourth Semester', 1, '127.0.0.1');