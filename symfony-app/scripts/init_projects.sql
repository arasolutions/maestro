-- Script d'initialisation de projets de test pour MAESTRO

-- Supprimer les projets existants si nécessaire (attention aux données!)
-- DELETE FROM maestro.projects;

-- Projet 1: E-commerce
INSERT INTO maestro.projects (id, slug, name, description, config, created_at)
VALUES (
    gen_random_uuid(),
    'ecommerce-platform',
    'Plateforme E-commerce',
    'Application de vente en ligne avec gestion de panier, paiement, stock et tableau de bord admin',
    '{"tech_stack": ["Symfony", "React", "PostgreSQL"], "team_size": 5}',
    NOW()
) ON CONFLICT (slug) DO NOTHING;

-- Projet 2: API REST
INSERT INTO maestro.projects (id, slug, name, description, config, created_at)
VALUES (
    gen_random_uuid(),
    'api-rest-microservices',
    'API REST Microservices',
    'Architecture microservices avec API REST, authentification JWT et documentation Swagger',
    '{"tech_stack": ["Node.js", "Express", "MongoDB"], "team_size": 3}',
    NOW()
) ON CONFLICT (slug) DO NOTHING;

-- Projet 3: Mobile App
INSERT INTO maestro.projects (id, slug, name, description, config, created_at)
VALUES (
    gen_random_uuid(),
    'mobile-app',
    'Application Mobile',
    'Application mobile cross-platform iOS/Android avec synchronisation offline',
    '{"tech_stack": ["React Native", "Firebase"], "team_size": 4}',
    NOW()
) ON CONFLICT (slug) DO NOTHING;

-- Afficher les projets créés
SELECT slug, name, description, created_at
FROM maestro.projects
ORDER BY created_at DESC;
