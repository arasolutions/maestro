-- Script d'initialisation PostgreSQL pour MAESTRO
-- Cr�e les bases de donn�es pour n8n et Gitea

-- Cr�er la base de donn�es n8n (si elle n'existe pas)
SELECT 'CREATE DATABASE n8n'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'n8n')\gexec

-- Cr�er la base de donn�es gitea (si elle n'existe pas)
SELECT 'CREATE DATABASE gitea'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'gitea')\gexec

-- Se connecter � la base maestro_platform pour cr�er le sch�ma
\c maestro_platform

-- Cr�er le sch�ma maestro (si il n'existe pas)
CREATE SCHEMA IF NOT EXISTS maestro;

-- Message de confirmation
DO $$
BEGIN
    RAISE NOTICE 'Bases de donn�es cr��es : n8n, gitea';
    RAISE NOTICE 'Sch�ma maestro cr�� dans maestro_platform';
END $$;
