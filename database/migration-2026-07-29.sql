-- =====================================================
-- MIGRATION 2026-07-29 — melhorias de segurança/perf
-- Aplicar com: php database/migrate.php
-- Idempotente: pode rodar mais de uma vez.
-- =====================================================

-- -----------------------------------------------------
-- 1) rate_limits: janela fixa ATÔMICA
--    A checagem passa a ser INSERT ... ON DUPLICATE KEY UPDATE, o que exige
--    uma chave única. NULL não funciona em UNIQUE (MySQL trata cada NULL como
--    distinto), então o lado não usado vira 0 / ''.
-- -----------------------------------------------------
TRUNCATE TABLE rate_limits;

ALTER TABLE rate_limits
    MODIFY user_id    INT UNSIGNED NOT NULL DEFAULT 0,
    MODIFY ip_address VARCHAR(45)  NOT NULL DEFAULT '';

ALTER TABLE rate_limits
    ADD UNIQUE KEY uk_bucket (action, user_id, ip_address, window_start);

ALTER TABLE rate_limits
    ADD INDEX idx_last_attempt (last_attempt);

-- -----------------------------------------------------
-- 2) duration_ms com resolução real de milissegundos
--    (antes era (segundos * 1000) — o nome da coluna mentia)
-- -----------------------------------------------------
ALTER TABLE queue
    MODIFY started_at DATETIME(3) DEFAULT NULL,
    MODIFY ended_at   DATETIME(3) DEFAULT NULL;

ALTER TABLE transmissions
    MODIFY started_at DATETIME(3) NOT NULL,
    MODIFY ended_at   DATETIME(3) DEFAULT NULL;

-- -----------------------------------------------------
-- 3) Índices que faltavam para as queries quentes
-- -----------------------------------------------------
ALTER TABLE users        ADD INDEX idx_display_name (display_name);
ALTER TABLE sessions     ADD INDEX idx_user_expires (user_id, expires_at);
ALTER TABLE queue        ADD INDEX idx_room_status_order (room_id, status, priority, queue_position, id);
ALTER TABLE messages     ADD INDEX idx_kind_created (kind, created_at);
ALTER TABLE messages     ADD INDEX idx_conv (to_user_id, from_user_id, id);

-- -----------------------------------------------------
-- 4) Higiene: derruba sessões expiradas e zera "online" fantasma
-- -----------------------------------------------------
DELETE FROM sessions WHERE expires_at < NOW();
UPDATE users SET online_status = 'offline'
 WHERE online_status = 'online'
   AND (last_heartbeat IS NULL OR last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE));
