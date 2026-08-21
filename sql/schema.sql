-- Admissions Leads Dashboard schema
-- Import this once via phpMyAdmin (cPanel) into your new database.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  role ENUM('admin','client') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  received_date DATE NOT NULL,
  source ENUM('lead_form','whatsapp','facebook','call_in','walk_in','referral','other') NOT NULL DEFAULT 'other',
  grade VARCHAR(50) DEFAULT '',
  contact VARCHAR(50) DEFAULT '',
  parent_name VARCHAR(120) DEFAULT '',
  child_name VARCHAR(120) DEFAULT '',
  current_school VARCHAR(180) DEFAULT '',
  location VARCHAR(120) DEFAULT '',
  fb_name VARCHAR(120) DEFAULT '',
  inquiry_notes TEXT,
  transfer_period VARCHAR(120) DEFAULT '',
  reason VARCHAR(150) DEFAULT '',
  status ENUM(
    'new',
    'contacted',
    'high_quality',
    'follow_up',
    'visit_scheduled',
    'visited',
    'converted',
    'not_interested',
    'rejected',
    'random_click'
  ) NOT NULL DEFAULT 'new',
  rejection_reason VARCHAR(255) DEFAULT NULL,
  visit_date DATE DEFAULT NULL,
  converted_date DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS follow_ups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  followup_number INT NOT NULL,
  followup_date DATE NOT NULL,
  followup_time TIME DEFAULT NULL,
  notes TEXT,
  next_action_date DATE DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default login: username "admin", password "admin123" — CHANGE THIS after first login.
-- Hash below is for "admin123".
INSERT INTO users (username, password_hash, display_name, role)
VALUES ('admin', '$2b$10$YRCDl8bN83wFch8LTUApFOt10mtfXy/XEbC8jiAC4KeIGU9V3v8Hy', 'Admin', 'admin')
ON DUPLICATE KEY UPDATE username=username;
