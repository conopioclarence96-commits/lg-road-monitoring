CREATE TABLE user_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,

    email VARCHAR(255) NOT NULL UNIQUE,

    login_token CHAR(64) NOT NULL UNIQUE,
    login_token_active TINYINT(1) NOT NULL DEFAULT 1,

    register_token CHAR(64) NOT NULL UNIQUE,
    register_token_expires_at DATETIME NOT NULL,
    register_token_used_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


