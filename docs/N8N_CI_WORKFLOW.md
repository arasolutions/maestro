# Workflow n8n CI Runner

## Vue d'ensemble

Ce workflow automatise l'exécution des tests PHPUnit lors des commits Git via webhook Gitea.

## Workflow: `Agent CI Runner.json`

### 1. Webhook Trigger
- **Endpoint**: `/webhook/gitea`
- **Events écoutés**:
  - `push` - Nouveau commit sur une branche
  - `pull_request` - Mise à jour d'une PR

### 2. Extraction des données
```javascript
{
  "repository": "{{ $json.repository.name }}",
  "branch": "{{ $json.ref }}",
  "commit_hash": "{{ $json.after }}",
  "pusher": "{{ $json.pusher.username }}"
}
```

### 3. Récupération de la branche Git
```sql
SELECT id, user_story_id, project_id
FROM maestro.git_branches
WHERE branch_name = '{{ $node["Webhook"].json["ref"] }}'
LIMIT 1
```

### 4. Création du build record
```sql
INSERT INTO maestro.ci_builds (
  id,
  branch_id,
  build_number,
  status,
  started_at,
  created_at
) VALUES (
  uuid_generate_v4(),
  '{{ $node["Get Git Branch"].json[0]["id"] }}',
  (SELECT COALESCE(MAX(build_number), 0) + 1 FROM maestro.ci_builds WHERE branch_id = '{{ $node["Get Git Branch"].json[0]["id"] }}'),
  'RUNNING',
  NOW(),
  NOW()
)
RETURNING id
```

### 5. Clone du repository
```bash
git clone {{ $node["Webhook"].json["repository"]["clone_url"] }} /tmp/build_{{ $node["Create Build"].json[0]["id"] }}
cd /tmp/build_{{ $node["Create Build"].json[0]["id"] }}
git checkout {{ $node["Webhook"].json["ref"] }}
```

### 6. Installation des dépendances
```bash
composer install --no-interaction --no-dev --prefer-dist
```

### 7. Exécution des tests PHPUnit
```bash
vendor/bin/phpunit --testdox --coverage-text --log-json /tmp/test-results.json
```

### 8. Parse des résultats
```javascript
const results = JSON.parse($input.item.json.stdout);
const passed = results.tests.filter(t => t.status === 'passed').length;
const failed = results.tests.filter(t => t.status === 'failed').length;
const coverage = parseFloat(results.coverage.match(/(\d+\.\d+)%/)[1]);

return {
  total: results.tests.length,
  passed: passed,
  failed: failed,
  failures: results.tests.filter(t => t.status === 'failed').map(t => ({
    test: t.name,
    message: t.message,
    file: t.file,
    line: t.line
  })),
  coverage: coverage
};
```

### 9. Mise à jour du build
```sql
UPDATE maestro.ci_builds
SET
  status = CASE WHEN {{ $node["Parse Results"].json["failed"] }} = 0 THEN 'SUCCESS' ELSE 'FAILED' END,
  finished_at = NOW(),
  duration = EXTRACT(EPOCH FROM (NOW() - started_at)),
  test_results = '{{ JSON.stringify($node["Parse Results"].json) }}',
  coverage = {{ $node["Parse Results"].json["coverage"] }},
  logs = '{{ $node["Run Tests"].json["stdout"] }}'
WHERE id = '{{ $node["Create Build"].json[0]["id"] }}'
```

### 10. Nettoyage
```bash
rm -rf /tmp/build_{{ $node["Create Build"].json[0]["id"] }}
```

### 11. Notification (optionnel)
Si le build échoue, ajouter un commentaire sur la PR Gitea:

```javascript
// Via GiteaService
if (build.status === 'FAILED') {
  await giteaService.addPullRequestComment(
    repo,
    prNumber,
    `❌ Build #${buildNumber} failed\n\n${build.test_results.failed} tests échoués\n\nVoir les détails: ${buildUrl}`
  );
}
```

## Variables d'environnement requises

Dans n8n, configurer:
- `GITEA_URL` - URL de Gitea (https://git.maestro.ara-solutions.cloud)
- `GITEA_TOKEN` - Token API Gitea
- `DB_HOST` - PostgreSQL host
- `DB_PORT` - PostgreSQL port (5432)
- `DB_NAME` - Database name (maestro_platform)
- `DB_USER` - Database user
- `DB_PASSWORD` - Database password

## Schéma du workflow

```
Webhook Gitea (push)
  ↓
Extraire données (repo, branch, commit)
  ↓
Chercher git_branches dans DB
  ↓
Créer ci_builds (status=RUNNING)
  ↓
Clone repository
  ↓
Checkout branch
  ↓
Composer install
  ↓
Run PHPUnit
  ↓
Parser résultats JSON
  ↓
Update ci_builds (status=SUCCESS/FAILED, test_results, logs)
  ↓
Cleanup /tmp
  ↓
[Optionnel] Commentaire sur PR si échec
```

## Tests manuels

Pour tester le workflow:

1. Créer un commit sur une branche feature/US-XXX
2. Push vers Gitea
3. Le webhook déclenche automatiquement n8n
4. Vérifier dans la table `ci_builds`:
```sql
SELECT * FROM maestro.ci_builds ORDER BY created_at DESC LIMIT 1;
```

## Intégration Symfony

La page `/user-story/{analysisId}/{storyId}/builds` affiche automatiquement:
- Liste des builds par branche
- Status (SUCCESS/FAILED/RUNNING)
- Résultats des tests (passed/failed)
- Coverage %
- Logs complets

Badge CI affiché dans `/user-story/{analysisId}/{storyId}/tests` montre le dernier build.
