-- =====================================================
-- WALKIE TALKIE WEB - SCHEMA MYSQL
-- Compatível MySQL 5.7+ / MariaDB 10.3+
-- =====================================================

CREATE DATABASE IF NOT EXISTS walkie_talkie
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE walkie_talkie;

-- -----------------------------------------------------
-- Tabela: users
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            VARCHAR(36)  NOT NULL UNIQUE,
    username        VARCHAR(64)  NOT NULL UNIQUE,
    display_name    VARCHAR(120) NOT NULL,
    password_hash   VARCHAR(255) DEFAULT NULL,
    avatar_color    VARCHAR(7)   DEFAULT '#22c55e',
    role            ENUM('user','admin') DEFAULT 'user',
    online_status   ENUM('online','offline','away') DEFAULT 'offline',
    last_heartbeat  DATETIME DEFAULT NULL,
    last_seen       DATETIME DEFAULT NULL,
    last_attention_at DATETIME DEFAULT NULL,
    attention_count INT UNSIGNED DEFAULT 0,
    talk_count      INT UNSIGNED DEFAULT 0,
    total_talk_seconds INT UNSIGNED DEFAULT 0,
    is_banned       TINYINT(1) DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_uuid (uuid),
    INDEX idx_username (username),
    INDEX idx_display_name (display_name),
    INDEX idx_online (online_status),
    INDEX idx_heartbeat (last_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: rooms
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            VARCHAR(36)  NOT NULL UNIQUE,
    name            VARCHAR(120) NOT NULL,
    slug            VARCHAR(120) NOT NULL UNIQUE,
    description     TEXT DEFAULT NULL,
    is_private      TINYINT(1) DEFAULT 0,
    password_hash   VARCHAR(255) DEFAULT NULL,
    max_users       INT UNSIGNED DEFAULT 50,
    max_talk_seconds INT UNSIGNED DEFAULT 30,
    is_active       TINYINT(1) DEFAULT 1,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_uuid (uuid),
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    CONSTRAINT fk_room_creator FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: sessions
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token           VARCHAR(128) NOT NULL UNIQUE,
    socket_id       VARCHAR(64)  DEFAULT NULL,
    ip_address      VARCHAR(45)  DEFAULT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    last_activity   DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_token (token),
    INDEX idx_user (user_id),
    INDEX idx_user_expires (user_id, expires_at),
    INDEX idx_expires (expires_at),
    CONSTRAINT fk_session_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: queue (fila de transmissão)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS queue (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id         INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    queue_position  INT UNSIGNED NOT NULL DEFAULT 0,
    priority        TINYINT UNSIGNED DEFAULT 0,
    attention_requested TINYINT(1) DEFAULT 0,
    status          ENUM('waiting','talking','done','cancelled') DEFAULT 'waiting',
    -- DATETIME(3): duration_ms precisa de milissegundos de verdade
    started_at      DATETIME(3) DEFAULT NULL,
    ended_at        DATETIME(3) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_room_status (room_id, status),
    INDEX idx_room_status_order (room_id, status, priority, queue_position, id),
    INDEX idx_user (user_id),
    INDEX idx_position (room_id, queue_position),
    INDEX idx_priority (priority),
    CONSTRAINT fk_queue_room FOREIGN KEY (room_id)
        REFERENCES rooms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_queue_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: transmissions (histórico de transmissões)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS transmissions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id         INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    duration_ms     INT UNSIGNED DEFAULT 0,
    had_priority    TINYINT(1) DEFAULT 0,
    started_at      DATETIME(3) NOT NULL,
    ended_at        DATETIME(3) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_room (room_id),
    INDEX idx_user (user_id),
    INDEX idx_started (started_at),
    CONSTRAINT fk_trans_room FOREIGN KEY (room_id)
        REFERENCES rooms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_trans_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: logs (auditoria/eventos)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED DEFAULT NULL,
    room_id         INT UNSIGNED DEFAULT NULL,
    type            ENUM(
        'login','logout','join','leave',
        'talk_start','talk_stop','talk_timeout',
        'attention','attention_blocked',
        'queue_join','queue_leave','queue_priority',
        'private_msg',
        'flood_detected','rate_limit',
        'error','warning','info'
    ) NOT NULL,
    message         VARCHAR(500) DEFAULT NULL,
    metadata        JSON DEFAULT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_user (user_id),
    INDEX idx_room (room_id),
    INDEX idx_type (type),
    INDEX idx_created (created_at),
    CONSTRAINT fk_log_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_log_room FOREIGN KEY (room_id)
        REFERENCES rooms(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: rate_limits (controle anti-flood)
-- -----------------------------------------------------
-- user_id/ip_address são NOT NULL (0 / '') porque UNIQUE ignora NULLs — a chave
-- uk_bucket é o que torna o rate limit atômico (INSERT ... ON DUPLICATE KEY).
CREATE TABLE IF NOT EXISTS rate_limits (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL DEFAULT 0,
    ip_address      VARCHAR(45) NOT NULL DEFAULT '',
    action          VARCHAR(64) NOT NULL,
    count           INT UNSIGNED DEFAULT 1,
    window_start    DATETIME NOT NULL,
    last_attempt    DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bucket (action, user_id, ip_address, window_start),
    INDEX idx_user_action (user_id, action),
    INDEX idx_ip_action (ip_address, action),
    INDEX idx_window (window_start),
    INDEX idx_last_attempt (last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabela: messages (chat privado 1-a-1 / recados)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            VARCHAR(36) NOT NULL UNIQUE,
    room_id         INT UNSIGNED DEFAULT NULL,
    from_user_id    INT UNSIGNED NOT NULL,
    to_user_id      INT UNSIGNED NOT NULL,
    kind            ENUM('text','audio') NOT NULL DEFAULT 'text',
    body            VARCHAR(2000) NOT NULL,
    media_path      VARCHAR(255) DEFAULT NULL,
    duration_ms     INT UNSIGNED DEFAULT NULL,
    delivered_at    DATETIME DEFAULT NULL,
    read_at         DATETIME DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_uuid (uuid),
    INDEX idx_to_undelivered (to_user_id, delivered_at),
    INDEX idx_pair (from_user_id, to_user_id, id),
    INDEX idx_conv (to_user_id, from_user_id, id),
    INDEX idx_kind_created (kind, created_at),
    CONSTRAINT fk_msg_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_to   FOREIGN KEY (to_user_id)   REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DADOS INICIAIS
-- =====================================================

-- Sala principal padrão
INSERT IGNORE INTO rooms (uuid, name, slug, description, max_users, max_talk_seconds)
VALUES (
    UUID(),
    'Canal Principal',
    'principal',
    'Canal de comunicação geral',
    100,
    30
);

-- Usuário admin padrão (senha: admin123 - TROCAR EM PRODUÇÃO)
INSERT IGNORE INTO users (uuid, username, display_name, password_hash, role, avatar_color)
VALUES (
    UUID(),
    'admin',
    'Administrador',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    '#ef4444'
);
