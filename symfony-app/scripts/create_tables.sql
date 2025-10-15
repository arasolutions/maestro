-- Création du schéma et des tables MAESTRO si elles n'existent pas

-- Créer le schéma maestro s'il n'existe pas
CREATE SCHEMA IF NOT EXISTS maestro;

-- Table projects
CREATE TABLE IF NOT EXISTS maestro.projects (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    config JSONB DEFAULT '{}',
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Index sur le slug pour les recherches rapides
CREATE INDEX IF NOT EXISTS idx_projects_slug ON maestro.projects(slug);

-- Afficher un message de succès
SELECT 'Tables créées avec succès' as status;
