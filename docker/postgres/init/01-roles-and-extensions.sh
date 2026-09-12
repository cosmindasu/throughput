#!/bin/bash
# Rulat de imaginea oficială postgres la primul boot (docker-entrypoint-initdb.d).
set -euo pipefail

: "${APP_DB_PASSWORD:?APP_DB_PASSWORD lipsește}"
: "${MIGRATOR_DB_PASSWORD:?MIGRATOR_DB_PASSWORD lipsește}"

psql -v ON_ERROR_STOP=1 \
     -v app_pw="$APP_DB_PASSWORD" \
     -v mig_pw="$MIGRATOR_DB_PASSWORD" \
     -v dbname="$POSTGRES_DB" \
     --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Rol de aplicație: NU are BYPASSRLS, NU e proprietar de tabele.
-- Conexiunea `pgsql` din config/database.php folosește acest rol.
SELECT format('CREATE ROLE throughput_app LOGIN PASSWORD %L', :'app_pw')
 WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'throughput_app')
\gexec
ALTER ROLE throughput_app NOBYPASSRLS;

-- Rol de migrare/întreținere: are BYPASSRLS, devine proprietarul tabelelor create de migrații.
-- Conexiunea `pgsql_migrations` folosește acest rol. NICIODATĂ folosit de request-uri.
SELECT format('CREATE ROLE throughput_migrator LOGIN PASSWORD %L BYPASSRLS CREATEDB', :'mig_pw')
 WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'throughput_migrator')
\gexec

-- Numele bazei vine din mediu, interpolat ca IDENTIFICATOR (:"dbname", cu ghilimele duble),
-- nu hardcodat: CI rulează pe `throughput_test`, nu pe `throughput` (capcana 4).
GRANT CONNECT ON DATABASE :"dbname" TO throughput_app;
GRANT ALL PRIVILEGES ON DATABASE :"dbname" TO throughput_migrator;
GRANT USAGE ON SCHEMA public TO throughput_app;
-- Obligatoriu pe PostgreSQL 15+: CREATE nu mai e acordat implicit pe `public` (capcana 6).
GRANT CREATE, USAGE ON SCHEMA public TO throughput_migrator;

-- `FOR ROLE throughput_migrator` e partea care contează (capcana 5): privilegiile implicite
-- se aplică obiectelor create de rolul numit aici, iar tabelele le creează migratorul.
ALTER DEFAULT PRIVILEGES FOR ROLE throughput_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO throughput_app;
ALTER DEFAULT PRIVILEGES FOR ROLE throughput_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO throughput_app;

-- Retroactiv, pentru orice tabelă creată înaintea acestui script (rerulare pe bază existentă).
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO throughput_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO throughput_app;
SQL
