# ⚠️ PROBLÈME URGENT - Agent Orchestrator

## 🔴 Problème Identifié

Lorsque vous cliquez sur "Lancer l'Orchestration" dans l'interface, vous voyez :
```
Orchestration lancée avec succès !
4 agent(s) en cours d'exécution : CADRAGE, US, DEV (placeholder), TEST (placeholder)
```

**Mais rien ne se passe** car :
1. ✅ La base de données est propre (table `cadrages` supprimée)
2. ✅ Les templates sont propres (pas de section Cadrage)
3. ✅ L'Agent Analyzer ne propose plus CADRAGE
4. ✅ L'Agent US Generator v2 est prêt
5. ❌ **Le workflow Agent Orchestrator lance encore CADRAGE** ← PROBLÈME

## 📍 Fichiers Concernés

### Workflow n8n (CAUSE DU PROBLÈME)

**Fichier** : `n8n_data/Agent Orchestrator.json`
**Status** : ❌ Contient encore des références à CADRAGE

**Ligne 91** : Code JavaScript qui vérifie `agentsNeeded.includes('CADRAGE')`
**Ligne 115** : Nœud "Need CADRAGE?"
**Ligne 137** : Nœud "1. CADRAGE Agent"
**Ligne 167** : Code "Update Plan After CADRAGE"
**Ligne 363** : Code final qui ajoute "CADRAGE" dans les résultats

### Contrôleur Symfony (PARTIELLEMENT FIXÉ)

**Fichier** : `symfony-app/src/Controller/AnalysisController.php`
**Status** : ✅ Nettoyé mais affiche encore le message de CADRAGE car il reçoit la réponse du workflow

**Méthode** : `orchestrate()` ligne 217
- Appelle `N8nService::triggerOrchestrator()`
- Reçoit la réponse avec `agents_executed: ["CADRAGE", "US", ...]`
- Affiche le message avec CADRAGE inclus

## 🎯 Solution Requise

### Option 1 : Créer Agent Orchestrator v2 (RECOMMANDÉ)

Créer un nouveau workflow `Agent Orchestrator v2.json` qui :
1. Supprime complètement les nœuds CADRAGE
2. Simplifie le flux : PM → US → DEV → TEST → DEPLOY
3. Met à jour le code JavaScript pour ne plus vérifier `needs_cadrage`

**Flux simplifié** :
```
Webhook → Fetch Analysis → Update Status → Prepare Plan → Need US? →
  ├─ Yes → Call US Agent → Update Plan → Need DEV? → ...
  └─ No → Skip US → ...
```

### Option 2 : Modifier manuellement dans n8n (RAPIDE)

1. Se connecter à n8n : https://n8n.maestro.ara-solutions.cloud
2. Ouvrir le workflow "Agent Orchestrator"
3. Supprimer les nœuds :
   - "Need CADRAGE?"
   - "1. CADRAGE Agent"
   - "Skip CADRAGE"
   - "Update Plan After CADRAGE"
4. Reconnecter directement "Prepare Orchestration Plan" → "Need US?"
5. Modifier le code JavaScript pour retirer `needs_cadrage`
6. Sauvegarder

## 📋 Workflow Agent Orchestrator v2 Simplifié

Je recommande de créer ce fichier JSON (trop long pour être fait maintenant, nécessite ~450 lignes) :

```json
{
  "name": "Agent Orchestrator v2",
  "nodes": [
    {  },  // Webhook
    {  },  // Keep Input Data
    {  },  // Fetch Analysis
    {  },  // Update Status
    {  },  // Prepare Plan (SANS needs_cadrage)
    {  },  // Need US?
    {  },  // Call US Agent
    {  },  // Update Plan After US
    {  },  // Need DEV?
    {  },  // Call DEV Agent (placeholder)
    {  },  // Need TEST?
    {  },  // Call TEST Agent (placeholder)
    {  },  // Compile Results (SANS cadrage_id)
    {  }   // Respond
  ]
}
```

## 🔧 Modification Urgente du Code JavaScript

### AVANT (ligne 91 - Prepare Orchestration Plan)
```javascript
// Flags pour routing SÉQUENTIEL
needs_cadrage: agentsNeeded.includes('CADRAGE'),  // ← À SUPPRIMER
needs_us: agentsNeeded.includes('US'),
needs_dev: agentsNeeded.includes('DEV'),

// Stockage des IDs
cadrage_id: null,  // ← À SUPPRIMER
us_id: null,
```

### APRÈS
```javascript
// Flags pour routing SÉQUENTIEL (Agent CADRAGE supprimé 2025-10-17)
needs_us: agentsNeeded.includes('US'),
needs_dev: agentsNeeded.includes('DEV'),
needs_test: agentsNeeded.includes('TEST'),
needs_deploy: agentsNeeded.includes('DEPLOY'),

// Stockage des IDs
us_id: null,
dev_id: null
```

### AVANT (ligne 363 - Compile Results)
```javascript
// Vérifier si CADRAGE a été exécuté
if ($('1. CADRAGE Agent').all().length > 0) {
  const cadrageResult = $('1. CADRAGE Agent').first().json;
  results.cadrage_id = cadrageResult.cadrage_id;
  results.agents_executed.push('CADRAGE');  // ← Cause du message erroné
  console.log('✅ CADRAGE completed');
}
```

### APRÈS
```javascript
// Agent CADRAGE supprimé le 2025-10-17
// Voir MIGRATION_COMPLETE.md
```

## 📊 Impact

### Actuellement (BUGGÉ)
```
User clique "Lancer Orchestration"
  ↓
Symfony appelle n8n /orchestrate
  ↓
n8n Agent Orchestrator (OLD) exécute :
  - ❌ Vérifie needs_cadrage (true dans anciennes analyses)
  - ❌ Appelle Agent CADRAGE (webhook 404 ou erreur)
  - ✅ Appelle Agent US
  - ❌ Retourne agents_executed: ["CADRAGE", "US", "DEV", "TEST"]
  ↓
Symfony affiche: "4 agents en cours : CADRAGE, US, DEV, TEST"
  ↓
❌ RIEN NE SE PASSE car CADRAGE plante
```

### Après Fix (CORRECT)
```
User clique "Lancer Orchestration"
  ↓
Symfony appelle n8n /orchestrate
  ↓
n8n Agent Orchestrator v2 exécute :
  - ✅ Vérifie needs_us (true)
  - ✅ Appelle Agent US (génère User Stories)
  - ⏳ Appelle Agent DEV (placeholder pour l'instant)
  - ⏳ Appelle Agent TEST (placeholder pour l'instant)
  - ✅ Retourne agents_executed: ["US", "DEV", "TEST"]
  ↓
Symfony affiche: "3 agents en cours : US, DEV, TEST"
  ↓
✅ User Stories apparaissent dans la page
```

## 🚨 Action Immédiate Requise

**Pour que le système fonctionne, vous devez** :

1. **Soit** : Créer `Agent Orchestrator v2.json` sans CADRAGE (long, ~1h de travail)
2. **Soit** : Modifier manuellement dans n8n (rapide, ~10 min)
3. **Soit** : Utiliser le workflow via n8n en important l'ancien et en le modifiant

## 📝 Fichiers Déjà Nettoyés

- ✅ `symfony-app/templates/analysis/detail.html.twig` (section Cadrage supprimée)
- ✅ `symfony-app/src/Controller/AnalysisController.php` (références Cadrage commentées)
- ✅ `n8n_data/Agent Analyzer.json` (prompt sans CADRAGE)
- ✅ `n8n_data/Agent US Generator.json` (v2 sans cadrage_id)
- ✅ Base de données (table cadrages supprimée, colonne cadrage_id supprimée)

## 📝 Fichiers À Nettoyer

- ❌ `n8n_data/Agent Orchestrator.json` ← **URGENT**
- ⏳ `n8n_data/Agent Dev Generator.json` (doit utiliser project_cadrage pour la stack)
- ⏳ `n8n_data/Agent TEST Generator.json` (pas encore implémenté)

---

**Status** : ⚠️ BLOQUANT - L'orchestration ne fonctionne pas tant que ce workflow n'est pas fixé

**Priorité** : 🔴 CRITIQUE

**Assigné à** : Utilisateur (modification manuelle dans n8n)

**Date** : 2025-10-17
