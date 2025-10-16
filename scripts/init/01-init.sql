-- Script d'initialisation PostgreSQL pour MAESTRO
-- Crée les bases de données pour n8n et Gitea

-- Créer la base de données n8n (si elle n'existe pas)
SELECT 'CREATE DATABASE n8n'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'n8n')\gexec

-- Créer la base de données gitea (si elle n'existe pas)
SELECT 'CREATE DATABASE gitea'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'gitea')\gexec

-- Se connecter à la base maestro_platform pour créer le schéma
\c maestro_platform

-- Créer le schéma maestro (si il n'existe pas)
CREATE SCHEMA IF NOT EXISTS maestro;

-- Message de confirmation
DO $$
BEGIN
    RAISE NOTICE 'Bases de données créées : n8n, gitea';
    RAISE NOTICE 'Schéma maestro créé dans maestro_platform';
END $$;
