CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) DEFAULT NULL AFTER email;

INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES
('landing_page_private', '0'),
('hide_hero', '0'),
('hide_updates', '0'),
('hide_stats', '0'),
('hide_about', '0'),
('hide_contact', '0'),
('disable_signup', '0'),
('hide_contact_form', '0'),
('disable_search', '0'),
('custom_message', ''),
('redirect_url', '');
