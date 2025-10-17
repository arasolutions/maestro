# Configuration Coolify pour MAESTRO

## 📋 Prérequis

- Coolify installé et accessible à `https://coolify.maestro.ara-solutions.cloud`
- Compte admin Coolify
- Serveur avec Docker installé
- Accès SSH au serveur

## 🔧 Étape 1 : Obtenir le Token API Coolify

### 1.1 Se connecter à Coolify
```
https://coolify.maestro.ara-solutions.cloud
```

### 1.2 Générer un API Token
1. Aller dans **Settings** (⚙️)
2. Cliquer sur **API Tokens**
3. Cliquer sur **Create New Token**
4. Nom: `maestro-api-token`
5. Permissions: **Full Access** (ou au minimum: `deployments:write`, `projects:read`)
6. Copier le token généré

### 1.3 Ajouter le token dans Symfony
Éditer `symfony-app/.env.local`:
```env
COOLIFY_API_TOKEN="votre-token-ici"
```

## 🚀 Étape 2 : Créer un Projet dans Coolify

### 2.1 Créer le projet
1. Dans Coolify, cliquer sur **Projects** → **New Project**
2. Nom: `MAESTRO Mobile App`
3. Description: `Application mobile générée par MAESTRO`
4. Cliquer sur **Create**
5. Noter le **Project UUID** (ex: `abc123-def456-...`)

### 2.2 Enregistrer le Project UUID dans la base de données

Mettre à jour la table `maestro.projects`:
```sql
UPDATE maestro.projects
SET config = jsonb_set(
    COALESCE(config, '{}'::jsonb),
    '{coolify_project_uuid}',
    '"abc123-def456-..."'
)
WHERE slug = 'mobile-app';
```

## 🌐 Étape 3 : Créer les Applications (Environnements)

### 3.1 Application STAGING

1. Dans le projet, cliquer sur **New Resource** → **Application**
2. Configuration:
   - **Name**: `mobile-app-staging`
   - **Source**: Git Repository
   - **Repository**: `https://git.maestro.ara-solutions.cloud/maestro/mobile-app.git`
   - **Branch**: `main` (sera changé dynamiquement par l'API)
   - **Build Pack**: PHP (Dockerfile ou Nixpacks)
   - **Port**: 9000 (PHP-FPM) ou 8080 (si serveur intégré)

3. **Environment Variables** (à ajouter):
   ```env
   APP_ENV=prod
   DATABASE_URL=postgresql://user:pass@host:5432/dbname
   N8N_WEBHOOK_URL=https://n8n.maestro.ara-solutions.cloud/webhook
   GITEA_URL=https://git.maestro.ara-solutions.cloud
   ```

4. **Domain**:
   - Domaine: `staging-mobile-app.maestro.ara-solutions.cloud`
   - SSL: Activé (Let's Encrypt auto)

5. **Build Command** (si Nixpacks):
   ```bash
   composer install --no-dev --optimize-autoloader && \
   php bin/console cache:clear && \
   php bin/console doctrine:migrations:migrate --no-interaction
   ```

6. Cliquer sur **Save**
7. Noter le **Application UUID** (ex: `xyz789-uvw123-...`)

### 3.2 Application PRODUCTION

Répéter les mêmes étapes avec:
- **Name**: `mobile-app-production`
- **Domain**: `mobile-app.maestro.ara-solutions.cloud` (sans prefix staging)
- **Branch**: `main` (déploiement manuel uniquement)

## 📝 Étape 4 : Configurer le Webhook Coolify → n8n (Optionnel)

Pour recevoir les notifications de déploiement:

### 4.1 Dans Coolify
1. Aller dans **Settings** de l'application
2. Section **Webhooks**
3. Ajouter un webhook:
   - URL: `https://n8n.maestro.ara-solutions.cloud/webhook/coolify-status`
   - Events: `deployment.started`, `deployment.finished`, `deployment.failed`

### 4.2 Dans n8n
Créer un workflow qui reçoit le webhook et met à jour `maestro.deployments`:
```sql
UPDATE maestro.deployments
SET
  status = CASE
    WHEN '{{ $json.event }}' = 'deployment.finished' THEN 'DEPLOYED'
    WHEN '{{ $json.event }}' = 'deployment.failed' THEN 'FAILED'
    ELSE 'DEPLOYING'
  END,
  deployed_at = CASE WHEN '{{ $json.event }}' = 'deployment.finished' THEN NOW() ELSE NULL END
WHERE coolify_deployment_id = '{{ $json.deployment_id }}'
```

## 🔌 Étape 5 : API Coolify - Endpoints à utiliser

### 5.1 Déclencher un déploiement
```bash
POST https://coolify.maestro.ara-solutions.cloud/api/v1/applications/{app_uuid}/deploy
Authorization: Bearer {token}
Content-Type: application/json

{
  "force": false,
  "git_branch": "feature/US-xxx-yyy"
}
```

Réponse:
```json
{
  "deployment_uuid": "deploy-123-456",
  "status": "queued",
  "message": "Deployment started"
}
```

### 5.2 Vérifier le status d'un déploiement
```bash
GET https://coolify.maestro.ara-solutions.cloud/api/v1/deployments/{deployment_uuid}
Authorization: Bearer {token}
```

Réponse:
```json
{
  "uuid": "deploy-123-456",
  "status": "success",
  "started_at": "2024-01-15T10:30:00Z",
  "finished_at": "2024-01-15T10:32:45Z",
  "logs": "..."
}
```

### 5.3 Obtenir les logs d'un déploiement
```bash
GET https://coolify.maestro.ara-solutions.cloud/api/v1/deployments/{deployment_uuid}/logs
Authorization: Bearer {token}
```

## 🔄 Étape 6 : Créer le Workflow n8n "Agent DEPLOY"

Créer un nouveau workflow dans n8n:

### Nœud 1: Webhook Trigger
- **URL**: `/webhook/deploy`
- **Method**: POST
- **Data**:
  ```json
  {
    "deployment_id": "uuid",
    "project_id": "uuid",
    "branch_id": "uuid",
    "environment": "STAGING|PRODUCTION"
  }
  ```

### Nœud 2: Get Deployment Info
```sql
SELECT d.*, gb.branch_name, p.slug, p.config
FROM maestro.deployments d
JOIN maestro.git_branches gb ON d.branch_id = gb.id
JOIN maestro.projects p ON d.project_id = p.id
WHERE d.id = '{{ $json.body.deployment_id }}'
```

### Nœud 3: Extract Coolify Config
**Code Node**:
```javascript
const deployment = $input.first().json;
const config = JSON.parse(deployment.config || '{}');
const environment = deployment.environment.toLowerCase();

// Déterminer le UUID de l'app Coolify
const appUuid = environment === 'staging'
  ? config.coolify_staging_uuid
  : config.coolify_production_uuid;

return {
  app_uuid: appUuid,
  branch_name: deployment.branch_name,
  deployment_id: deployment.id,
  environment: deployment.environment
};
```

### Nœud 4: Update Status to DEPLOYING
```sql
UPDATE maestro.deployments
SET status = 'DEPLOYING'
WHERE id = '{{ $json.deployment_id }}'
```

### Nœud 5: Call Coolify API
**HTTP Request Node**:
- **Method**: POST
- **URL**: `https://coolify.maestro.ara-solutions.cloud/api/v1/applications/{{ $json.app_uuid }}/deploy`
- **Headers**:
  ```json
  {
    "Authorization": "Bearer {{ $credentials.coolifyApi.token }}",
    "Content-Type": "application/json"
  }
  ```
- **Body**:
  ```json
  {
    "force": false,
    "git_branch": "{{ $json.branch_name }}"
  }
  ```

### Nœud 6: Wait for Deployment (Loop)
**Code Node avec boucle**:
```javascript
// Attendre que le déploiement soit terminé
const deploymentUuid = $input.first().json.deployment_uuid;
let status = 'running';
let attempts = 0;
const maxAttempts = 60; // 10 minutes max (60 x 10s)

while (status === 'running' && attempts < maxAttempts) {
  // Appeler l'API Coolify pour vérifier le status
  const response = await $http.get(
    `https://coolify.maestro.ara-solutions.cloud/api/v1/deployments/${deploymentUuid}`,
    {
      headers: {
        'Authorization': `Bearer ${$credentials.coolifyApi.token}`
      }
    }
  );

  status = response.data.status;
  attempts++;

  if (status === 'running') {
    await new Promise(resolve => setTimeout(resolve, 10000)); // Wait 10s
  }
}

return {
  status: status,
  deployment_uuid: deploymentUuid,
  logs: response.data.logs
};
```

### Nœud 7: Update Deployment Status
```sql
UPDATE maestro.deployments
SET
  status = CASE
    WHEN '{{ $json.status }}' = 'success' THEN 'DEPLOYED'
    WHEN '{{ $json.status }}' = 'failed' THEN 'FAILED'
    ELSE 'FAILED'
  END,
  deployed_at = CASE WHEN '{{ $json.status }}' = 'success' THEN NOW() ELSE NULL END,
  coolify_deployment_id = '{{ $json.deployment_uuid }}',
  url = CASE
    WHEN '{{ $json.status }}' = 'success' AND '{{ $node["Extract Coolify Config"].json.environment }}' = 'STAGING'
    THEN 'https://staging-{{ $node["Get Deployment Info"].json[0].slug }}.maestro.ara-solutions.cloud'
    WHEN '{{ $json.status }}' = 'success' AND '{{ $node["Extract Coolify Config"].json.environment }}' = 'PRODUCTION'
    THEN 'https://{{ $node["Get Deployment Info"].json[0].slug }}.maestro.ara-solutions.cloud'
    ELSE NULL
  END
WHERE id = '{{ $node["Extract Coolify Config"].json.deployment_id }}'
```

### Nœud 8: Send Notification (Optionnel)
Envoyer un email ou Slack notification si déploiement échoué.

## 📊 Étape 7 : Mettre à jour la configuration projet

Dans la base de données, ajouter les UUIDs Coolify au projet:

```sql
UPDATE maestro.projects
SET config = jsonb_build_object(
    'coolify_project_uuid', 'abc123-def456-...',
    'coolify_staging_uuid', 'xyz789-staging-...',
    'coolify_production_uuid', 'xyz789-production-...'
)
WHERE slug = 'mobile-app';
```

## ✅ Étape 8 : Tester le Déploiement

### Test STAGING

1. Aller dans l'UI Symfony: `/projects/mobile-app/deployments`
2. Sélectionner une branche Git (ex: `feature/US-xxx-yyy`)
3. Environnement: **STAGING**
4. Cliquer sur **Lancer le Déploiement**
5. Le système devrait:
   - Créer un record dans `deployments` (status=PENDING)
   - Appeler le webhook n8n
   - n8n appelle l'API Coolify
   - Coolify clone le repo, build et déploie
   - Status mis à jour: DEPLOYED
   - URL accessible: `https://staging-mobile-app.maestro.ara-solutions.cloud`

### Vérifications

```bash
# Vérifier les déploiements dans la DB
SELECT * FROM maestro.deployments ORDER BY created_at DESC LIMIT 5;

# Tester l'URL générée
curl https://staging-mobile-app.maestro.ara-solutions.cloud

# Vérifier les logs dans Coolify UI
```

## 🔧 Troubleshooting

### Erreur: "Invalid API token"
- Vérifier que le token est correct dans `.env.local`
- Vérifier que le token n'a pas expiré dans Coolify

### Erreur: "Application not found"
- Vérifier que les UUIDs sont corrects dans `projects.config`
- Vérifier que l'application existe dans Coolify

### Déploiement bloqué en DEPLOYING
- Vérifier les logs dans Coolify
- Vérifier que le webhook de callback fonctionne
- Timeout possible (augmenter maxAttempts dans n8n)

### Build échoue dans Coolify
- Vérifier les variables d'environnement
- Vérifier le Dockerfile ou la config Nixpacks
- Vérifier que `composer install` fonctionne
- Vérifier les migrations de base de données

## 📚 Ressources

- [Documentation Coolify API](https://coolify.io/docs/api)
- [Coolify GitHub](https://github.com/coollabsio/coolify)
- [Déploiement PHP sur Coolify](https://coolify.io/docs/resources/applications/php)

## 🎯 Checklist de Configuration

- [ ] Coolify accessible et compte admin créé
- [ ] Token API généré et ajouté dans `.env.local`
- [ ] Projet créé dans Coolify
- [ ] Application STAGING créée avec domaine configuré
- [ ] Application PRODUCTION créée avec domaine configuré
- [ ] Variables d'environnement configurées dans Coolify
- [ ] UUIDs ajoutés dans `maestro.projects.config`
- [ ] Workflow n8n "Agent DEPLOY" créé
- [ ] Test déploiement STAGING réussi
- [ ] URL STAGING accessible
- [ ] Test déploiement PRODUCTION réussi (optionnel)

Une fois tout configuré, le déploiement sera entièrement automatisé ! 🚀
