DROP SCHEMA IF EXISTS dm CASCADE;
CREATE SCHEMA dm;
SET search_path TO dm;

-- таблицы сущностей test/Fixture/Entity: их сверяет EntityDatabaseTest

CREATE TABLE providers (
    id      int8 PRIMARY KEY,
    name    text NOT NULL,
    api_url text NOT NULL
);

CREATE TABLE certificates (
    id           int8 PRIMARY KEY,
    domain_name  text NOT NULL,
    status       text NOT NULL,
    expires_at   date,
    date_created timestamptz NOT NULL,
    provider_id  int8 REFERENCES providers (id),
    user_id      int8 NOT NULL
);

CREATE TABLE certificate_keys (
    id             int8 PRIMARY KEY,
    certificate_id int8 NOT NULL UNIQUE REFERENCES certificates (id),
    algorithm      text NOT NULL,
    size           int4 NOT NULL
);
