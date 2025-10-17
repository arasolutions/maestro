# Plan de Suppression de l'Agent Cadrage

## 🎯 Objectif

Supprimer l'Agent Cadrage du workflow MAESTRO pour simplifier l'architecture et éliminer l'ambiguïté sur le scope des User Stories.

## 📊 État Actuel

- ✅ **Table `cadrages`** : 0 enregistrements (aucune donnée à migrer)
- ✅ **Workflows n8n** : Agent Cadrage existe mais pas utilisé en production
- ⚠️ **Table `user_stories`** : Contient une colonne `cadrage_id` (nullable)
- ⚠️ **Table `projects`** : Contient `project_cadrage` (JSONB)

## 📋 Plan d'Action

### 1️⃣ Migration Base de Données

#### A. Supprimer la colonne `cadrage_id` de `user_stories`

```sql
-- Vérifier s'il y a des valeurs non-null (il ne devrait pas y en avoir)
SELECT COUNT(*) FROM maestro.user_stories WHERE cadrage_id IS NOT NULL;

-- Supprimer la colonne
ALTER TABLE maestro.user_stories DROP COLUMN IF EXISTS cadrage_id;
```

#### B. Renommer/Simplifier la table `cadrages` → `cadrage_proposals`

**Nouvelle philosophie** : Les cadrages deviennent des **propositions** qu'on peut appliquer au projet, pas des entités intermédiaires dans le workflow.

```sql
-- Option 1: Supprimer complètement (recommandé)
DROP TABLE IF EXISTS maestro.cadrages CASCADE;

-- Option 2: Garder pour l'historique mais renommer
ALTER TABLE maestro.cadrages RENAME TO cadrage_proposals_archive;
ALTER TABLE maestro.cadrage_proposals_archive
  ADD COLUMN archived_at TIMESTAMP DEFAULT NOW();
```

#### C. Nettoyer `project_cadrage` dans `projects`

Le champ `project_cadrage` reste mais change de rôle :
- **Avant** : Rempli par Agent Cadrage via workflow
- **Après** : Rempli manuellement ou via import, utilisé uniquement pour contexte technique

```sql
-- Optionnel: Réinitialiser project_cadrage pour repartir de zéro
UPDATE maestro.projects
SET project_cadrage = NULL,
    project_cadrage_version = NULL,
    project_cadrage_updated_at = NULL
WHERE project_cadrage IS NOT NULL;
```

### 2️⃣ Modification des Workflows n8n

#### A. Supprimer `Agent Cadrage.json`

```bash
# Fichier à supprimer
rm n8n_data/Agent\ Cadrage.json

# Ou via l'interface n8n: désactiver puis supprimer le workflow
```

#### B. Modifier `Agent Orchestrator.json`

**Changements** :

1. **Supprimer la référence à CADRAGE dans le parsing** :

```javascript
// AVANT (ligne ~90)
needs_cadrage: agentsNeeded.includes('CADRAGE'),
needs_us: agentsNeeded.includes('US'),
// ...
cadrage_id: null,

// APRÈS
needs_us: agentsNeeded.includes('US'),
needs_dev: agentsNeeded.includes('DEV'),
// ...
// Supprimer cadrage_id complètement
```

2. **Supprimer le nœud "Call Agent Cadrage"** du workflow

3. **Reconnecter directement PM → US** :

```
Agent PM (Analysis)
    ↓
Agent US Generator (si needs_us)
    ↓
Agent DEV (si needs_dev)
```

#### C. Simplifier `Agent US Generator.json`

**Fichier complet modifié** :

```json
{
  "nodes": [
    {
      "parameters": {
        "httpMethod": "POST",
        "path": "user-stories",
        "responseMode": "responseNode"
      },
      "type": "n8n-nodes-base.webhook",
      "position": [-640, 240],
      "id": "webhook-us",
      "name": "Webhook User Stories"
    },
    {
      "parameters": {
        "assignments": {
          "assignments": [
            {
              "id": "field1",
              "name": "analysis_id",
              "value": "={{ $json.body.analysis_id }}",
              "type": "string"
            },
            {
              "id": "field2",
              "name": "request_id",
              "value": "={{ $json.body.request_id }}",
              "type": "string"
            },
            {
              "id": "field3",
              "name": "project_id",
              "value": "={{ $json.body.project_id }}",
              "type": "string"
            },
            {
              "id": "field4",
              "name": "request_text",
              "value": "={{ $json.body.request_text }}",
              "type": "string"
            },
            {
              "id": "field5",
              "name": "complexity",
              "value": "={{ $json.body.complexity }}",
              "type": "string"
            }
          ]
        }
      },
      "type": "n8n-nodes-base.set",
      "position": [-440, 240],
      "id": "keep-input-us",
      "name": "Keep Input Data"
    },
    {
      "parameters": {
        "operation": "executeQuery",
        "query": "SELECT project_cadrage FROM maestro.projects WHERE id = $1::uuid",
        "options": {
          "queryReplacement": "={{ $('Keep Input Data').item.json.project_id }}"
        }
      },
      "type": "n8n-nodes-base.postgres",
      "position": [-240, 240],
      "id": "fetch-project-stack",
      "name": "Fetch Project Stack"
    },
    {
      "parameters": {
        "jsCode": "// Préparer les données pour Gemini\nconst inputData = $input.first().json;\nconst projectData = $('Fetch Project Stack').first().json;\n\n// Extraire UNIQUEMENT la stack technique\nlet stackTechnique = 'Non spécifié';\n\nif (projectData?.project_cadrage?.architecture?.stack_technique) {\n  stackTechnique = projectData.project_cadrage.architecture.stack_technique;\n}\n\nreturn [{\n  json: {\n    request_text: inputData.request_text,\n    complexity: inputData.complexity,\n    stack_technique: stackTechnique,\n    analysis_id: inputData.analysis_id,\n    project_id: inputData.project_id\n  }\n}];"
      },
      "type": "n8n-nodes-base.code",
      "position": [-40, 240],
      "id": "prepare-context",
      "name": "Prepare Context"
    },
    {
      "parameters": {
        "method": "POST",
        "url": "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key={{ $env.GEMINI_API_KEY }}",
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "={{ {\n  \"contents\": [{\n    \"parts\": [{\n      \"text\": `Tu es le Product Owner de MAESTRO, expert en rédaction de User Stories Agile.\n\n=== DEMANDE SPÉCIFIQUE À IMPLÉMENTER ===\n${$json.request_text}\n\nComplexité estimée: ${$json.complexity}\n\n=== STACK TECHNIQUE DU PROJET (contraintes uniquement) ===\n${$json.stack_technique}\n\n⚠️ RÈGLES STRICTES :\n1. Génère des User Stories UNIQUEMENT pour la demande ci-dessus\n2. N'ajoute AUCUNE fonctionnalité qui n'est pas explicitement demandée\n3. La stack technique sert UNIQUEMENT à connaître les contraintes techniques (langage, framework, base de données)\n4. Ne génère PAS de fonctionnalités annexes (auth, admin, monitoring, etc.) sauf si explicitement demandées\n\nMission :\n1. Décomposer LA DEMANDE en User Stories atomiques (1-5 jours max chacune)\n2. Format : En tant que [persona]... Je veux [action]... Afin de [bénéfice]...\n3. Critères d'acceptation en format Gherkin (Given/When/Then)\n4. Estimation en story points (Fibonacci: 1,2,3,5,8,13)\n5. Priorisation MoSCoW (MUST/SHOULD/COULD/WONT)\n6. Identifier les dépendances entre stories\n\nExemple : Si la demande est \"Créer une page de CGV\" :\n✅ US-001: Créer la structure HTML de la page CGV\n✅ US-002: Rédiger le contenu légal des CGV\n✅ US-003: Ajouter le versioning des CGV\n❌ PAS de stories sur l'authentification, la gestion utilisateurs, etc.\n\nRéponds UNIQUEMENT en JSON avec cette structure exacte :\n{\n  \"epic\": {\n    \"title\": \"Titre epic basé sur la demande\",\n    \"goal\": \"Objectif business mesurable\"\n  },\n  \"stories\": [\n    {\n      \"id\": \"US-001\",\n      \"title\": \"Titre court et explicite\",\n      \"as_a\": \"type utilisateur/persona\",\n      \"i_want\": \"action/fonctionnalité souhaitée\",\n      \"so_that\": \"bénéfice/valeur attendue\",\n      \"acceptance_criteria\": [\n        \"GIVEN [contexte] WHEN [action] THEN [résultat]\"\n      ],\n      \"story_points\": 3,\n      \"priority\": \"MUST\",\n      \"dependencies\": [],\n      \"technical_notes\": \"Notes techniques\",\n      \"test_scenarios\": [\"Scénario 1\"]\n    }\n  ],\n  \"metrics\": {\n    \"total_points\": 0,\n    \"must_have_points\": 0,\n    \"should_have_points\": 0,\n    \"could_have_points\": 0\n  }\n}`\n    }]\n  }],\n  \"generationConfig\": {\n    \"temperature\": 0.7,\n    \"topK\": 40,\n    \"topP\": 0.95,\n    \"maxOutputTokens\": 4096,\n    \"responseMimeType\": \"application/json\"\n  }\n} }}",
        "options": {
          "timeout": 90000
        }
      },
      "type": "n8n-nodes-base.httpRequest",
      "position": [160, 240],
      "id": "gemini-us",
      "name": "Gemini US Generation"
    },
    {
      "parameters": {
        "jsCode": "// Parser la réponse Gemini\nconst geminiResponse = $input.first().json;\nconst contextData = $('Prepare Context').first().json;\n\nconsole.log('=== AGENT USER STORIES ===');\nconsole.log('Analysis ID:', contextData.analysis_id);\n\nlet userStories = {};\ntry {\n  const textContent = geminiResponse.candidates[0].content.parts[0].text;\n  userStories = JSON.parse(textContent);\n  console.log('✓ ' + (userStories.stories?.length || 0) + ' User Stories générées');\n  console.log('Total points:', userStories.metrics?.total_points || 0);\n} catch(e) {\n  console.error('✗ Erreur parsing Gemini:', e.message);\n  userStories = {\n    epic: { title: 'Erreur de parsing', goal: 'N/A' },\n    stories: [],\n    metrics: { total_points: 0 }\n  };\n}\n\n// Préparer un item par story pour insertion\nconst stories = userStories.stories || [];\nconst results = stories.map(story => ({\n  project_id: contextData.project_id,\n  analysis_id: contextData.analysis_id,\n  story_id: story.id,\n  title: story.title,\n  as_a: story.as_a,\n  i_want: story.i_want,\n  so_that: story.so_that,\n  priority: story.priority || 'SHOULD',\n  story_points: story.story_points || 0,\n  acceptance_criteria: JSON.stringify(story.acceptance_criteria || []),\n  test_scenarios: JSON.stringify(story.test_scenarios || []),\n  technical_notes: story.technical_notes || '',\n  dependencies: JSON.stringify(story.dependencies || [])\n}));\n\nconsole.log('Prepared ' + results.length + ' stories for DB insertion');\n\nreturn results.map(r => ({ json: r }));"
      },
      "type": "n8n-nodes-base.code",
      "position": [360, 240],
      "id": "prepare-us-data",
      "name": "Parse & Prepare Stories"
    },
    {
      "parameters": {
        "operation": "executeQuery",
        "query": "INSERT INTO maestro.user_stories (\n  project_id,\n  analysis_id,\n  story_id,\n  title,\n  as_a,\n  i_want,\n  so_that,\n  priority,\n  story_points,\n  acceptance_criteria,\n  test_scenarios,\n  technical_notes,\n  dependencies,\n  status\n) VALUES (\n  $1::uuid,\n  $2::uuid,\n  $3,\n  $4,\n  $5,\n  $6,\n  $7,\n  $8,\n  $9,\n  $10::jsonb,\n  $11::jsonb,\n  $12,\n  $13::jsonb,\n  'PENDING'\n) RETURNING id, story_id;",
        "options": {
          "queryReplacement": "={{ [\n  $json.project_id,\n  $json.analysis_id,\n  $json.story_id,\n  $json.title,\n  $json.as_a,\n  $json.i_want,\n  $json.so_that,\n  $json.priority,\n  $json.story_points,\n  $json.acceptance_criteria,\n  $json.test_scenarios,\n  $json.technical_notes,\n  $json.dependencies\n] }}"
        }
      },
      "type": "n8n-nodes-base.postgres",
      "position": [560, 240],
      "id": "save-us",
      "name": "Save User Story"
    },
    {
      "parameters": {
        "jsCode": "// Agréger les résultats\nconst insertions = $input.all();\nconst totalStories = insertions.length;\n\nconsole.log('✓ Successfully inserted ' + totalStories + ' user stories');\n\nreturn [{\n  json: {\n    status: 'success',\n    agent: 'US',\n    message: totalStories + ' User Stories créées',\n    total_stories: totalStories,\n    story_ids: insertions.map(i => i.json.story_id)\n  }\n}];"
      },
      "type": "n8n-nodes-base.code",
      "position": [760, 240],
      "id": "aggregate-results",
      "name": "Aggregate Results"
    },
    {
      "parameters": {
        "respondWith": "json",
        "responseBody": "={{ JSON.stringify($json, null, 2) }}"
      },
      "type": "n8n-nodes-base.respondToWebhook",
      "position": [960, 240],
      "id": "respond-us",
      "name": "Respond Success"
    }
  ],
  "connections": {
    "Webhook User Stories": {
      "main": [[{"node": "Keep Input Data", "type": "main", "index": 0}]]
    },
    "Keep Input Data": {
      "main": [[{"node": "Fetch Project Stack", "type": "main", "index": 0}]]
    },
    "Fetch Project Stack": {
      "main": [[{"node": "Prepare Context", "type": "main", "index": 0}]]
    },
    "Prepare Context": {
      "main": [[{"node": "Gemini US Generation", "type": "main", "index": 0}]]
    },
    "Gemini US Generation": {
      "main": [[{"node": "Parse & Prepare Stories", "type": "main", "index": 0}]]
    },
    "Parse & Prepare Stories": {
      "main": [[{"node": "Save User Story", "type": "main", "index": 0}]]
    },
    "Save User Story": {
      "main": [[{"node": "Aggregate Results", "type": "main", "index": 0}]]
    },
    "Aggregate Results": {
      "main": [[{"node": "Respond Success", "type": "main", "index": 0}]]
    }
  }
}
```

**Changements clés** :
1. ✅ Suppression des nœuds "Has Cadrage?", "Fetch Project Cadrage", "No Cadrage"
2. ✅ Suppression de la référence `cadrage_id`
3. ✅ Ajout de `project_id` dans le input
4. ✅ Récupération UNIQUEMENT de `stack_technique`
5. ✅ Prompt Gemini simplifié et plus strict sur le scope
6. ✅ Workflow linéaire : Webhook → Keep Data → Fetch Stack → Prepare → Gemini → Parse → Save → Respond

### 3️⃣ Modification de l'Interface Symfony

#### A. Supprimer les références au cadrage dans les templates

```bash
# Rechercher les références
grep -r "cadrage" symfony-app/templates/
```

#### B. Modifier `ProjectController.php`

Supprimer les méthodes liées au cadrage (si elles existent).

#### C. Nettoyer `Entity/Project.php`

Le champ `project_cadrage` reste mais sa documentation change :

```php
/**
 * Cadrage technique du projet (stack, architecture)
 * Rempli manuellement ou via import, utilisé comme contexte pour la génération de code
 */
#[ORM\Column(type: 'json', nullable: true)]
private ?array $projectCadrage = null;
```

### 4️⃣ Script de Migration Complet

```sql
-- ======================
-- MIGRATION: Suppression Agent Cadrage
-- Date: 2025-10-17
-- ======================

BEGIN;

-- 1. Vérifications préalables
DO $$
DECLARE
  cadrage_count INT;
  us_with_cadrage INT;
BEGIN
  SELECT COUNT(*) INTO cadrage_count FROM maestro.cadrages;
  SELECT COUNT(*) INTO us_with_cadrage FROM maestro.user_stories WHERE cadrage_id IS NOT NULL;

  RAISE NOTICE '=== ÉTAT ACTUEL ===';
  RAISE NOTICE 'Cadrages existants: %', cadrage_count;
  RAISE NOTICE 'User Stories avec cadrage_id: %', us_with_cadrage;

  IF us_with_cadrage > 0 THEN
    RAISE WARNING 'ATTENTION: % user stories ont un cadrage_id non-null', us_with_cadrage;
  END IF;
END $$;

-- 2. Backup des données (au cas où)
CREATE TABLE IF NOT EXISTS maestro.cadrages_backup_20251017 AS
SELECT * FROM maestro.cadrages;

-- 3. Supprimer la colonne cadrage_id de user_stories
ALTER TABLE maestro.user_stories DROP COLUMN IF EXISTS cadrage_id CASCADE;

-- 4. Supprimer la table cadrages
DROP TABLE IF EXISTS maestro.cadrages CASCADE;

-- 5. Nettoyer les project_cadrage (optionnel)
-- Décommenter si vous voulez repartir de zéro
-- UPDATE maestro.projects
-- SET project_cadrage = NULL,
--     project_cadrage_version = NULL,
--     project_cadrage_updated_at = NULL;

-- 6. Vérification finale
DO $$
BEGIN
  RAISE NOTICE '=== MIGRATION TERMINÉE ===';
  RAISE NOTICE 'Colonne cadrage_id supprimée de user_stories';
  RAISE NOTICE 'Table cadrages supprimée';
  RAISE NOTICE 'Backup créé dans cadrages_backup_20251017';
END $$;

COMMIT;
```

## 🧪 Plan de Test

### Test 1: Créer une requête simple

```bash
curl -X POST https://maestro.ara-solutions.cloud/request/new \
  -d "title=Test sans cadrage" \
  -d "description=Page CGV simple" \
  -d "type=FEATURE" \
  -d "priority=MEDIUM"
```

**Attendu** :
- Agent PM analyse
- Agent US génère des stories (sans passer par Cadrage)
- Stories créées avec `cadrage_id = NULL`

### Test 2: Vérifier le workflow US

```bash
curl -X POST http://n8n:5678/webhook/user-stories \
  -H "Content-Type: application/json" \
  -d '{
    "analysis_id": "...",
    "request_id": "...",
    "project_id": "...",
    "request_text": "Créer une page CGV",
    "complexity": "S"
  }'
```

**Attendu** :
- Récupération de la stack technique uniquement
- Génération de 2-3 User Stories ciblées
- Pas de fonctionnalités hors scope

### Test 3: Vérifier la base de données

```sql
-- Vérifier qu'il n'y a plus de cadrage_id
SELECT column_name
FROM information_schema.columns
WHERE table_name = 'user_stories' AND column_name = 'cadrage_id';
-- Attendu: 0 résultats

-- Vérifier que la table cadrages n'existe plus
SELECT tablename
FROM pg_tables
WHERE schemaname = 'maestro' AND tablename = 'cadrages';
-- Attendu: 0 résultats
```

## 📅 Planning d'Exécution

1. **Phase 1** : Migration base de données (5 min)
2. **Phase 2** : Suppression workflow Agent Cadrage (2 min)
3. **Phase 3** : Modification Agent US Generator (10 min)
4. **Phase 4** : Modification Agent Orchestrator (10 min)
5. **Phase 5** : Tests de validation (15 min)

**Durée totale estimée** : ~45 minutes

## ✅ Checklist de Validation

- [ ] Table `cadrages` supprimée
- [ ] Colonne `cadrage_id` supprimée de `user_stories`
- [ ] Workflow Agent Cadrage supprimé de n8n
- [ ] Workflow Agent US Generator simplifié
- [ ] Workflow Agent Orchestrator mis à jour
- [ ] Test de création de requête réussi
- [ ] Test de génération US réussi
- [ ] User Stories créées sans ambiguïté de scope
- [ ] Logs n8n propres (pas d'erreurs)
- [ ] Documentation mise à jour

## 🚀 Bénéfices Attendus

✅ **Simplicité** : Workflow linéaire PM → US → DEV
✅ **Clarté** : Scope limité à la demande précise
✅ **Performance** : Moins de tokens Gemini, moins de requêtes DB
✅ **Maintenabilité** : Moins de code, moins de bugs
✅ **Rapidité** : Un agent en moins = temps de traitement réduit

---

**Prêt à exécuter ? Validez ce plan avant de continuer.**
