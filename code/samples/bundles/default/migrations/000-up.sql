
CREATE TABLE rocks (
    id INTEGER UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    uid VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    nick_name VARCHAR(255) NOT NULL,
    size VARCHAR(32),
    color VARCHAR(32),

    INDEX rocks_uid_idx (uid),
    INDEX rocks_nick_name_idx (nick_name),
    INDEX rocks_size_idx (size),
    INDEX rocks_color_idx (color),
    INDEX rocks_size_color_idx (size, color)
);
