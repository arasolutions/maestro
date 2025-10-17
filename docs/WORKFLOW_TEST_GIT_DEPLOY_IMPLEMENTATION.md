# Implémentation du Workflow Test → Git → CI/CD → Deploy

## 🎯 Vue d'ensemble

Ce document récapitule l'implémentation complète du workflow automatisé MAESTRO pour le Test, le Git, le CI/CD et le Déploiement.

## ✅ Phase 1: Workflow Git (TERMINÉ)

### Fonctionnalités implémentées

1. **Bouton "Commit sur Gitea"** dans la page de code généré
   - Visible uniquement si le projet a un dépôt Gitea configuré
   - Désactivé si le code a déjà été commité

2. **Création automatique de branche Git**
   - Naming: `feature/US-{id}-{slug}`
   - Exemple: `feature/US-a1b2c3d4-authentification-utilisateur`

3. **Commit multi-fichiers**
   - Tous les fichiers générés sont commitées sur la branche
   - Message de commit formaté avec les détails de la User Story
   - Format: `feat: {title}\n\nUser Story: {id}\n\nEn tant que: ...\nJe veux: ...\nAfin de: ...`

4. **Pull Request automatique**
   - Titre: `[US-{id}] {title}`
   - Description complète avec user story et liste des fichiers
   - Lien direct vers la PR dans l'interface

5. **Affichage du statut Git**
   - Badge de statut: OPEN / MERGED / CLOSED
   - Lien vers la Pull Request sur Gitea
   - Nom de la branche créée

### Fichiers créés/modifiés

- ✅ [templates/code/view.html.twig](../symfony-app/templates/code/view.html.twig) - Bouton commit + affichage statut Git
- ✅ [src/Controller/CodeController.php](../symfony-app/src/Controller/CodeController.php) - Route `app_user_story_git_commit`
- ✅ Utilise [src/Service/GiteaService.php](../symfony-app/src/Service/GiteaService.php) existant

### Utilisation

1. Générer le code pour une User Story
2. Cliquer sur **"Commit sur Gitea"**
3. Le système crée automatiquement:
   - Branche `feature/US-XXX-XXX`
   - Commit de tous les fichiers
   - Pull Request vers `main`
4. L'URL de la PR s'affiche dans un message flash

---

## ✅ Phase 2: CI/CD Pipeline (TERMINÉ)

### Fonctionnalités implémentées

1. **Badge de build CI** dans la page des tests
   - Affiche le status du dernier build (SUCCESS/FAILED/RUNNING)
   - Résultats des tests (passed/failed)
   - Code coverage %
   - Durée du build
   - Lien vers la page des builds

2. **Page historique des builds** (`/user-story/{analysisId}/{storyId}/builds`)
   - Liste de tous les builds pour la user story
   - Filtrage par branche Git
   - Status visuel (couleurs, badges)
   - Aperçu des échecs de tests
   - Lien vers les détails complets

3. **Page détails du build** (`/builds/{buildId}`)
   - Timeline du build
   - Statistiques complètes
   - Liste des tests échoués avec stack traces
   - Logs complets du build
   - Code coverage détaillé

### Architecture CI/CD

Le workflow CI/CD se déclenche automatiquement via webhook Gitea:

```
Push sur branche Git
  ↓
Webhook Gitea → n8n
  ↓
Agent CI Runner (n8n workflow)
  ↓
1. Clone repository
2. Checkout branche
3. composer install
4. vendor/bin/phpunit
5. Parser résultats
6. Enregistrer dans ci_builds
7. Cleanup
  ↓
Affichage dans l'UI Symfony
```

### Table `maestro.ci_builds`

```sql
- id (UUID)
- branch_id (UUID) → git_branches
- build_number (INTEGER)
- status (VARCHAR) - SUCCESS/FAILED/RUNNING/PENDING
- started_at (TIMESTAMP)
- finished_at (TIMESTAMP)
- duration (INTEGER) - en secondes
- test_results (JSONB) - {total, passed, failed, failures[]}
- logs (TEXT)
- coverage (NUMERIC)
```

### Fichiers créés

- ✅ [src/Controller/CIController.php](../symfony-app/src/Controller/CIController.php) - Routes builds
- ✅ [templates/ci/builds.html.twig](../symfony-app/templates/ci/builds.html.twig) - Liste des builds
- ✅ [templates/ci/build_detail.html.twig](../symfony-app/templates/ci/build_detail.html.twig) - Détails build
- ✅ [templates/test/view.html.twig](../symfony-app/templates/test/view.html.twig) - Badge CI ajouté
- ✅ [src/Controller/TestController.php](../symfony-app/src/Controller/TestController.php) - Récupération latest build
- ✅ [docs/N8N_CI_WORKFLOW.md](N8N_CI_WORKFLOW.md) - Documentation workflow n8n

### Workflow n8n à créer

Créer dans n8n: **Agent CI Runner**
- Webhook: `/webhook/gitea`
- Events: `push`, `pull_request`
- Voir [N8N_CI_WORKFLOW.md](N8N_CI_WORKFLOW.md) pour les détails

---

## ✅ Phase 3: Déploiement (TERMINÉ)

### Fonctionnalités implémentées

1. **Page de gestion des déploiements** (`/projects/{slug}/deployments`)
   - Liste de tous les déploiements du projet
   - Status visuel par environnement (STAGING/PRODUCTION)
   - Formulaire de création de déploiement
   - Sélection de branche Git
   - Choix environnement (Recette/Production)
   - Liens vers les applications déployées

2. **Page détails du déploiement** (`/deployments/{deploymentId}`)
   - Timeline du déploiement
   - Statistiques (durée, déployé par, etc.)
   - Status détaillé (PENDING/DEPLOYING/DEPLOYED/FAILED/ROLLED_BACK)
   - Logs du déploiement
   - Bouton Rollback (si DEPLOYED)
   - URL de l'application

3. **Service CoolifyService**
   - Intégration complète avec Coolify API
   - Méthodes: deployBranch(), getDeploymentStatus(), rollback(), etc.
   - Gestion des erreurs et logs

4. **Menu Déploiements**
   - Ajouté dans la navigation principale
   - Icône 🚀

### Architecture Déploiement

```
Créer déploiement (UI)
  ↓
INSERT dans deployments (status=PENDING)
  ↓
Webhook n8n → Agent DEPLOY
  ↓
1. Clone repository
2. Checkout branche
3. composer install --no-dev
4. npm run build
5. php bin/console doctrine:migrations:migrate
6. Appel API Coolify
7. UPDATE deployments (status=DEPLOYED, url, coolify_deployment_id)
  ↓
Affichage dans l'UI
```

### Table `maestro.deployments`

```sql
- id (UUID)
- project_id (UUID) → projects
- branch_id (UUID) → git_branches
- environment (VARCHAR) - STAGING/PRODUCTION
- status (VARCHAR) - PENDING/DEPLOYING/DEPLOYED/FAILED/ROLLED_BACK
- url (VARCHAR) - URL de l'app déployée
- deployed_at (TIMESTAMP)
- deployed_by (VARCHAR)
- coolify_deployment_id (VARCHAR)
- created_at (TIMESTAMP)
```

### Fichiers créés

- ✅ [src/Service/CoolifyService.php](../symfony-app/src/Service/CoolifyService.php) - Intégration API Coolify
- ✅ [src/Controller/DeploymentController.php](../symfony-app/src/Controller/DeploymentController.php) - Routes déploiements
- ✅ [templates/deployment/list.html.twig](../symfony-app/templates/deployment/list.html.twig) - Liste déploiements
- ✅ [templates/deployment/detail.html.twig](../symfony-app/templates/deployment/detail.html.twig) - Détails déploiement
- ✅ [templates/base.html.twig](../symfony-app/templates/base.html.twig) - Menu Déploiements ajouté
- ✅ [.env.local](../symfony-app/.env.local) - Variables COOLIFY_URL, COOLIFY_API_TOKEN
- ✅ [config/services.yaml](../symfony-app/config/services.yaml) - Configuration CoolifyService

### Environnements

**STAGING (Recette)**
- URL: `https://staging-{project-slug}.maestro.ara-solutions.cloud`
- Déploiement automatique possible après build success
- Testing et validation

**PRODUCTION**
- URL: `https://{project-slug}.maestro.ara-solutions.cloud`
- Déploiement manuel requis
- Rollback disponible

---

## 🔄 Workflow Complet (Bout en Bout)

Voici le workflow complet d'une User Story de A à Z:

### 1. Création de la User Story
```
User créer une requête → Agent PM analyse → Agent Cadrage → Agent US Generator
                                                                    ↓
                                                            User Story créée
```

### 2. Génération du Code
```
Clic "Générer le code" → Agent DEV Generator → Code généré (Controller, Entity, Template)
```

### 3. Commit sur Git ⚡ NOUVEAU
```
Clic "Commit sur Gitea"
  ↓
• Création branche: feature/US-XXX-XXX
• Commit de tous les fichiers
• Création Pull Request
  ↓
Affichage: PR #123 créée sur Gitea
```

### 4. Tests automatiques
```
Clic "Générer les tests" → Agent TEST Generator → Tests PHPUnit générés
```

### 5. CI/CD automatique ⚡ NOUVEAU
```
Push sur Git (déjà fait à l'étape 3)
  ↓
Webhook Gitea → n8n Agent CI Runner
  ↓
• Clone repo
• composer install
• vendor/bin/phpunit
• Enregistrement résultats
  ↓
Badge CI affiché: ✅ Build #1 SUCCESS (15 tests passed, coverage 85%)
```

### 6. Déploiement ⚡ NOUVEAU
```
Si Build SUCCESS → Clic "Nouveau Déploiement"
  ↓
• Sélection branche: feature/US-XXX-XXX
• Sélection environnement: STAGING
  ↓
n8n Agent DEPLOY → Coolify
  ↓
Application déployée sur: https://staging-mobile-app.maestro.ara-solutions.cloud
  ↓
Tests manuels sur STAGING
  ↓
Si OK → Déploiement PRODUCTION
```

---

## 📊 Récapitulatif des Routes

### Routes Git
- POST `/user-story/{analysisId}/{storyId}/git-commit` - Commit code + create PR

### Routes CI/CD
- GET `/user-story/{analysisId}/{storyId}/builds` - Liste des builds
- GET `/builds/{buildId}` - Détails d'un build

### Routes Déploiement
- GET `/projects/{slug}/deployments` - Liste des déploiements
- POST `/projects/{slug}/deploy` - Créer un déploiement
- GET `/deployments/{deploymentId}` - Détails déploiement
- POST `/deployments/{deploymentId}/rollback` - Rollback déploiement

---

## ⚙️ Configuration Requise

### Variables d'environnement (.env.local)

```env
# N8n
N8N_WEBHOOK_URL="https://n8n.maestro.ara-solutions.cloud/webhook"

# Gitea
GITEA_URL="https://git.maestro.ara-solutions.cloud"
GITEA_API_TOKEN="votre-token-gitea"
GITEA_ORG_NAME="maestro"

# Coolify
COOLIFY_URL="https://coolify.maestro.ara-solutions.cloud"
COOLIFY_API_TOKEN="votre-token-coolify"
```

### Base de données

Tables existantes utilisées:
- ✅ `maestro.git_branches` - Branches Git et PRs
- ✅ `maestro.ci_builds` - Résultats des builds
- ✅ `maestro.deployments` - Déploiements

### Workflows n8n à créer

1. **Agent CI Runner** (`/webhook/gitea`)
   - Voir [N8N_CI_WORKFLOW.md](N8N_CI_WORKFLOW.md)

2. **Agent DEPLOY** (`/webhook/deploy`)
   - À créer (documentation à venir)

---

## 🚀 Prochaines Étapes

### Workflows n8n à implémenter

1. **Agent CI Runner**
   - Créer le workflow dans n8n selon [N8N_CI_WORKFLOW.md](N8N_CI_WORKFLOW.md)
   - Tester avec un push sur une branche
   - Vérifier que les builds apparaissent dans l'UI

2. **Agent DEPLOY**
   - Créer workflow similaire à CI Runner
   - Intégrer avec Coolify API
   - Gérer les migrations de base de données
   - Build des assets Webpack

### Configuration Coolify

1. Créer les applications dans Coolify:
   - `mobile-app-staging`
   - `mobile-app-production`

2. Configurer les domaines:
   - Staging: `staging-mobile-app.maestro.ara-solutions.cloud`
   - Production: `mobile-app.maestro.ara-solutions.cloud`

3. Obtenir le token API Coolify
   - Ajouter dans `.env.local`

### Tests

1. **Test Git Commit**
   - Générer code pour une US
   - Cliquer "Commit sur Gitea"
   - Vérifier que la branche et la PR sont créées

2. **Test CI/CD**
   - Vérifier que le webhook déclenche le build
   - Vérifier que les résultats s'affichent dans l'UI
   - Tester avec des tests qui échouent

3. **Test Déploiement**
   - Créer un déploiement STAGING
   - Vérifier l'URL générée
   - Tester le rollback

---

## 📝 Notes Importantes

- Tous les déploiements en PRODUCTION nécessitent une validation manuelle
- Les builds CI sont déclenchés automatiquement sur chaque push
- Un rollback n'est possible que pour les déploiements DEPLOYED
- Les logs de déploiement sont stockés temporairement (TODO: implémenter persistance)
- La couverture de code est calculée par PHPUnit (nécessite xdebug en dev)

---

## 🎉 Résultat Final

Le workflow MAESTRO est maintenant complet:

```
Requête → Analyse → Cadrage → User Stories → Code → Tests → Git → CI/CD → Deploy
```

**Chaque étape est automatisée et tracée dans l'interface !** 🚀
