-- Script de création des tables MAESTRO
-- À exécuter manuellement ou via docker-entrypoint-initdb.d

\c maestro_platform

-- Créer le schéma maestro
CREATE SCHEMA IF NOT EXISTS maestro;

-- Table des projets
CREATE TABLE IF NOT EXISTS maestro.projects (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    slug VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    config JSONB DEFAULT '{}',
    gitea_repo_url TEXT,
    project_cadrage JSONB,
    project_cadrage_version INTEGER DEFAULT 1,
    project_cadrage_updated_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des requêtes (demandes utilisateur)
CREATE TABLE IF NOT EXISTS maestro.requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    request_type VARCHAR(50) DEFAULT 'FEATURE',
    priority VARCHAR(20) DEFAULT 'MEDIUM',
    status VARCHAR(20) DEFAULT 'PENDING',
    n8n_execution_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des analyses (PM Agent)
CREATE TABLE IF NOT EXISTS maestro.analyses (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    request_id UUID REFERENCES maestro.requests(id) ON DELETE CASCADE,
    request_text TEXT NOT NULL,
    analysis_type VARCHAR(50),
    complexity VARCHAR(10),
    priority VARCHAR(20),
    confidence DECIMAL(3,2),
    agents_needed JSONB DEFAULT '[]',
    estimated_hours INTEGER,
    next_steps JSONB DEFAULT '[]',
    risks JSONB DEFAULT '[]',
    full_response JSONB,
    webhook_execution_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des cadrages (Architecture Agent)
CREATE TABLE IF NOT EXISTS maestro.cadrages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    analysis_id UUID REFERENCES maestro.analyses(id) ON DELETE CASCADE,
    perimetre JSONB,
    contraintes JSONB,
    architecture JSONB,
    swot JSONB,
    estimation JSONB,
    full_response JSONB,
    status VARCHAR(20) DEFAULT 'PENDING',
    applied_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des user stories (US Agent)
CREATE TABLE IF NOT EXISTS maestro.user_stories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    analysis_id UUID REFERENCES maestro.analyses(id) ON DELETE CASCADE,
    cadrage_id UUID REFERENCES maestro.cadrages(id) ON DELETE SET NULL,
    stories JSONB NOT NULL,
    acceptance_criteria JSONB,
    story_points INTEGER,
    priority_order JSONB,
    dependencies JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des branches Git
CREATE TABLE IF NOT EXISTS maestro.git_branches (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    request_id UUID REFERENCES maestro.requests(id) ON DELETE CASCADE,
    branch_name VARCHAR(255) NOT NULL,
    branch_type VARCHAR(50) DEFAULT 'feature',
    base_branch VARCHAR(100) DEFAULT 'main',
    status VARCHAR(20) DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    merged_at TIMESTAMP,
    UNIQUE(project_id, branch_name)
);

-- Table des scénarios de test
CREATE TABLE IF NOT EXISTS maestro.test_scenarios (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    user_story_id UUID REFERENCES maestro.user_stories(id) ON DELETE CASCADE,
    scenario_name VARCHAR(255) NOT NULL,
    test_type VARCHAR(50) DEFAULT 'functional',
    test_data JSONB,
    expected_results JSONB,
    status VARCHAR(20) DEFAULT 'PENDING',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des builds CI
CREATE TABLE IF NOT EXISTS maestro.ci_builds (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    branch_id UUID REFERENCES maestro.git_branches(id) ON DELETE CASCADE,
    build_number INTEGER,
    status VARCHAR(20) DEFAULT 'RUNNING',
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    logs TEXT,
    artifacts JSONB
);

-- Table des déploiements
CREATE TABLE IF NOT EXISTS maestro.deployments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id UUID REFERENCES maestro.projects(id) ON DELETE CASCADE,
    branch_id UUID REFERENCES maestro.git_branches(id) ON DELETE CASCADE,
    environment VARCHAR(50) NOT NULL,
    status VARCHAR(20) DEFAULT 'PENDING',
    deployment_url TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    logs TEXT
);

-- Index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_requests_project ON maestro.requests(project_id);
CREATE INDEX IF NOT EXISTS idx_requests_status ON maestro.requests(status);
CREATE INDEX IF NOT EXISTS idx_analyses_project ON maestro.analyses(project_id);
CREATE INDEX IF NOT EXISTS idx_analyses_request ON maestro.analyses(request_id);
CREATE INDEX IF NOT EXISTS idx_cadrages_project ON maestro.cadrages(project_id);
CREATE INDEX IF NOT EXISTS idx_cadrages_analysis ON maestro.cadrages(analysis_id);
CREATE INDEX IF NOT EXISTS idx_user_stories_project ON maestro.user_stories(project_id);
CREATE INDEX IF NOT EXISTS idx_user_stories_analysis ON maestro.user_stories(analysis_id);
CREATE INDEX IF NOT EXISTS idx_git_branches_project ON maestro.git_branches(project_id);
CREATE INDEX IF NOT EXISTS idx_deployments_project ON maestro.deployments(project_id);

-- Message de confirmation
DO $$
BEGIN
    RAISE NOTICE 'Tables MAESTRO créées avec succès dans le schéma maestro';
END $$;
