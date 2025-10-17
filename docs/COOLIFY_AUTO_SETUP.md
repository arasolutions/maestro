# Configuration Automatique de Coolify 🚀

## 🎯 Vue d'ensemble

**Plus besoin de configuration manuelle !** MAESTRO crée automatiquement tous les environnements Coolify en 1 clic.

## ⚡ Setup Automatique en 3 Étapes

### Étape 1: Obtenir le Token API Coolify (1 fois seulement)

1. Se connecter à Coolify: `https://coolify.maestro.ara-solutions.cloud`
2. Aller dans **Settings** → **API Tokens**
3. Créer un token avec permission **Full Access**
4. Copier le token

### Étape 2: Ajouter le Token dans Symfony

Éditer `symfony-app/.env.local`:
```env
COOLIFY_API_TOKEN="votre-token-ici"
```

### Étape 3: Initialiser via l'UI

1. Aller sur `/projects/{slug}/edit`
2. Section **"Environnements Coolify"**
3. Cliquer sur **"🚀 Initialiser Coolify"**

**C'EST TOUT !** 🎉

## 🤖 Ce qui est créé automatiquement

Quand vous cliquez sur "Initialiser Coolify", le système crée:

### 1. Projet Coolify
```
Nom: {project.name}
Description: MAESTRO - {project.slug}
```

### 2. Application STAGING
```
Nom: {project-slug}-staging
Domaine: staging-{project-slug}.maestro.ara-solutions.cloud
SSL: Activé automatiquement (Let's Encrypt)
Branch: main (changeable via API)
Build Pack: Nixpacks (détecte PHP automatiquement)
```

**Configuration auto:**
- `composer install --no-dev --optimize-autoloader`
- `php bin/console cache:clear`
- `php bin/console assets:install`
- Health check sur `/health`
- PHP-FPM port 9000

### 3. Application PRODUCTION
```
Nom: {project-slug}-production
Domaine: {project-slug}.maestro.ara-solutions.cloud
SSL: Activé automatiquement
Branch: main
Build Pack: Nixpacks
```

Mêmes paramètres que Staging, sauf `APP_DEBUG=0`

### 4. Mise à jour de la Base de Données

La table `maestro.projects.config` est automatiquement mise à jour avec:
```json
{
  "coolify_project_uuid": "abc-123",
  "coolify_staging_uuid": "xyz-456",
  "coolify_staging_url": "https://staging-mobile-app.maestro.ara-solutions.cloud",
  "coolify_production_uuid": "uvw-789",
  "coolify_production_url": "https://mobile-app.maestro.ara-solutions.cloud"
}
```

## 🖼️ Interface

### Avant l'initialisation
```
┌─────────────────────────────────────┐
│ ⚠️ Coolify Non Configuré            │
│                                     │
│ Créez automatiquement les          │
│ environnements de déploiement      │
│                                     │
│         [🚀 Initialiser Coolify]   │
│                                     │
│ Ce qui sera créé automatiquement:  │
│ • Un projet Coolify                │
│ • Application STAGING              │
│ • Application PRODUCTION           │
│ • Configuration SSL automatique    │
└─────────────────────────────────────┘
```

### Après l'initialisation
```
┌──────────────────────────────────────┐
│ ✓ Coolify Configuré                  │
│                                      │
│ ┌─────────────┐  ┌─────────────┐    │
│ │ 🟡 STAGING  │  │ 🔴 PRODUCTION│   │
│ │ Env de test │  │ Env de prod  │   │
│ │  [Ouvrir →] │  │  [Ouvrir →]  │   │
│ └─────────────┘  └─────────────┘    │
│                                      │
│  [🚀 Gérer les Déploiements]        │
└──────────────────────────────────────┘
```

## 📋 Code - Comment ça marche ?

### CoolifyService::setupProject()

```php
public function setupProject(string $projectSlug, string $projectName, string $giteaRepoUrl): array
{
    // 1. Créer le projet Coolify
    $projectResult = $this->createCoolifyProject($projectName, $projectSlug);

    // 2. Créer l'app Staging
    $stagingResult = $this->createApplication(
        $projectUuid,
        $projectSlug . '-staging',
        $giteaRepoUrl,
        'main',
        'staging-' . $projectSlug . '.maestro.ara-solutions.cloud',
        'staging'
    );

    // 3. Créer l'app Production
    $productionResult = $this->createApplication(
        $projectUuid,
        $projectSlug . '-production',
        $giteaRepoUrl,
        'main',
        $projectSlug . '.maestro.ara-solutions.cloud',
        'production'
    );

    // 4. Retourner tous les UUIDs
    return [
        'success' => true,
        'coolify_project_uuid' => $projectUuid,
        'coolify_staging_uuid' => $stagingResult['uuid'],
        'coolify_staging_url' => 'https://staging-' . $projectSlug . '.maestro.ara-solutions.cloud',
        'coolify_production_uuid' => $productionResult['uuid'],
        'coolify_production_url' => 'https://' . $projectSlug . '.maestro.ara-solutions.cloud',
    ];
}
```

### ProjectController::coolifyInit()

```php
#[Route('/projects/{slug}/coolify-init', name: 'app_coolify_init', methods: ['POST'])]
public function coolifyInit(string $slug, Request $request): Response
{
    // 1. Vérifier que Gitea est configuré
    if (!$project['gitea_repo_url']) {
        throw new \Exception('Initialisez d\'abord le dépôt Gitea.');
    }

    // 2. Appeler CoolifyService
    $result = $this->coolifyService->setupProject(
        $project['slug'],
        $project['name'],
        $project['gitea_repo_url']
    );

    // 3. Enregistrer les UUIDs dans projects.config
    $config['coolify_project_uuid'] = $result['coolify_project_uuid'];
    $config['coolify_staging_uuid'] = $result['coolify_staging_uuid'];
    // ...

    // 4. Message de succès avec les URLs
    $this->addFlash('success', 'Environnements Coolify créés !');

    return $this->redirectToRoute('app_project_edit', ['slug' => $slug]);
}
```

## ✅ Avantages de l'Auto-Setup

### ✅ Avant (Manuel)
- ⏱️ 30-45 minutes par projet
- 📋 15+ étapes manuelles
- ⚠️ Risque d'erreurs de config
- 🔁 Répétitif pour chaque projet

### 🚀 Après (Auto)
- ⚡ **30 secondes** par projet
- 🖱️ **1 clic**
- ✅ Configuration standardisée
- 🤖 Reproductible à l'infini

## 🔄 Workflow Complet

```
1. Créer un Projet MAESTRO
   ↓
2. Initialiser le Dépôt Gitea (1 clic)
   ↓
3. Initialiser Coolify (1 clic) ← NOUVEAU !
   ↓
4. Créer des User Stories
   ↓
5. Générer le Code
   ↓
6. Commit sur Git
   ↓
7. CI/CD automatique
   ↓
8. Déployer sur STAGING (1 clic)
   ↓
9. Tests manuels
   ↓
10. Déployer sur PRODUCTION (1 clic)
```

## 🐛 Troubleshooting

### Erreur: "Invalid API token"
**Solution:** Vérifier que `COOLIFY_API_TOKEN` est correctement configuré dans `.env.local`

### Erreur: "Failed to create Coolify project"
**Causes possibles:**
- Token API expiré
- Permissions insuffisantes
- Coolify non accessible

**Solution:** Vérifier les logs dans `var/log/dev.log`

### Bouton "Initialiser Coolify" désactivé
**Cause:** Le dépôt Gitea n'est pas encore initialisé

**Solution:** Cliquer d'abord sur "Initialiser le Dépôt Gitea"

## 📊 API Coolify Utilisée

### POST /api/v1/projects
Créer un projet
```json
{
  "name": "Mobile App",
  "description": "MAESTRO - mobile-app"
}
```

### POST /api/v1/applications
Créer une application
```json
{
  "project_uuid": "abc-123",
  "name": "mobile-app-staging",
  "git_repository": "https://git.maestro.ara-solutions.cloud/maestro/mobile-app.git",
  "git_branch": "main",
  "build_pack": "nixpacks",
  "domains": ["staging-mobile-app.maestro.ara-solutions.cloud"],
  "environment_variables": {
    "APP_ENV": "prod",
    "APP_DEBUG": "1"
  },
  "install_command": "composer install --no-dev --optimize-autoloader",
  "build_command": "php bin/console cache:clear && php bin/console assets:install",
  "start_command": "php-fpm",
  "port": 9000,
  "health_check_enabled": true,
  "health_check_path": "/health"
}
```

## 🎯 Résultat Final

Après l'initialisation automatique:

✅ **Projet Coolify** créé et configuré
✅ **Staging** accessible à `https://staging-{project}.maestro.ara-solutions.cloud`
✅ **Production** accessible à `https://{project}.maestro.ara-solutions.cloud`
✅ **SSL** activé sur les deux environnements
✅ **Déploiements** possibles via l'UI `/deployments`

**Prêt à déployer en production ! 🚀**
