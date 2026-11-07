-- Схема для SQLite, адаптированная из DDL для PostgreSQL:
--  - SERIAL -> INTEGER PRIMARY KEY AUTOINCREMENT;
--  - BOOLEAN хранится как 0/1, DECIMAL — с числовой affinity (SQLite не хранит масштаб);
--  - TIMESTAMP хранится текстом 'YYYY-MM-DD HH:MM:SS';
--  - внешние ключи работают только с PRAGMA foreign_keys = ON (включается при каждом подключении);
--  - добавлены индексы по внешним ключам domains и domain_logs: ни PostgreSQL, ни SQLite
--    не создают их автоматически, а по ним идут JOIN от сертификата к доменам и логам;
--  - добавлен справочник providers и внешний ключ certificates.provider_id (связь N:1).

-- ==========================================
-- ЧАСТЬ 0: Справочники
-- ==========================================

-- 0. providers (Провайдер сертификата), справочник: у сертификата один провайдер,
--    у провайдера много сертификатов (N:1 от certificates)
CREATE TABLE providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(128) NOT NULL UNIQUE,
    website VARCHAR(255),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================
-- ЧАСТЬ 1: Цепочка 1:1 от сертификата
-- ==========================================

-- 1. certificates (Главная таблица)
CREATE TABLE certificates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id INT NOT NULL,
    code VARCHAR(64) NOT NULL UNIQUE,
    nominal DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provider_id) REFERENCES providers(id)
);

CREATE INDEX idx_certificates_provider_id ON certificates(provider_id);

-- 2. certificate_details (Детали сертификата), 1:1 с certificates
CREATE TABLE certificate_details (
    certificate_id INT PRIMARY KEY,
    buyer_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(128),
    recipient_email VARCHAR(255),
    greeting_text TEXT,
    design_template_id INT,
    FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE
);

-- 3. certificate_delivery_meta (Логистика и доставка), 1:1 с certificate_details
CREATE TABLE certificate_delivery_meta (
    certificate_id INT PRIMARY KEY,
    delivery_channel VARCHAR(32) NOT NULL,
    is_sent BOOLEAN NOT NULL DEFAULT FALSE,
    sent_at TIMESTAMP,
    external_provider_id VARCHAR(128),
    delivery_error_log TEXT,
    FOREIGN KEY (certificate_id) REFERENCES certificate_details(certificate_id) ON DELETE CASCADE
);

-- ==========================================
-- ЧАСТЬ 2: Ветвление на домены (1:N и 1:1 / 1:N)
-- ==========================================

-- 4. domains (Домены сертификата), 1:N от certificates
CREATE TABLE domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    certificate_id INT NOT NULL,
    domain_name VARCHAR(255) NOT NULL UNIQUE,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE
);

CREATE INDEX idx_domains_certificate_id ON domains(certificate_id);

-- 5. domain_configs (Настройки домена), 1:1 с domains
CREATE TABLE domain_configs (
    domain_id INT PRIMARY KEY,
    auto_renew BOOLEAN NOT NULL DEFAULT TRUE,
    dns_provider VARCHAR(64),
    ssl_redirect_enabled BOOLEAN DEFAULT TRUE,
    custom_nginx_config TEXT,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

-- 6. domain_logs (Логи домена), 1:N от domains (от 0 записей)
CREATE TABLE domain_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INT NOT NULL,
    log_level VARCHAR(16) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
);

CREATE INDEX idx_domain_logs_domain_id ON domain_logs(domain_id);
