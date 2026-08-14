-- =====================================================
-- MIGRATION 2026-07-29b — Web Push (VAPID)
-- =====================================================

-- Configurações internas (chaves VAPID etc.)
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(64) NOT NULL,
    `value`     TEXT NOT NULL,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inscrições de push do navegador (1 por dispositivo/navegador)
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    endpoint     VARCHAR(500) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,          -- sha256 do endpoint (UNIQUE; o endpoint é longo demais para índice)
    p256dh       VARCHAR(255) NOT NULL,
    auth_key     VARCHAR(255) NOT NULL,
    user_agent   VARCHAR(255) DEFAULT NULL,
    fail_count   TINYINT UNSIGNED DEFAULT 0,
    last_sent_at DATETIME DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_endpoint (endpoint_hash),
    INDEX idx_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Novos tipos de evento no log de auditoria
ALTER TABLE logs MODIFY type ENUM(
    'login','logout','join','leave',
    'talk_start','talk_stop','talk_timeout',
    'attention','attention_blocked',
    'queue_join','queue_leave','queue_priority',
    'private_msg',
    'push_subscribe','push_unsubscribe','push_sent','push_failed',
    'flood_detected','rate_limit',
    'error','warning','info'
) NOT NULL;
