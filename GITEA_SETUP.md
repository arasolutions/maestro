# 🚀 Installation Gitea pour MAESTRO

## 📋 Prérequis

- Docker et Docker Compose installés
- Ports 3000 (HTTP) et 2222 (SSH) disponibles

---

## 🛠️ Installation

### 1. Créer les dossiers nécessaires

```bash
cd /c/wamp64/www/maestro
mkdir -p gitea/data gitea/postgres
```

### 2. Démarrer les services

```bash
docker-compose -f docker-compose.gitea.yml up -d
```

### 3. Vérifier que les services sont up

```bash
docker-compose -f docker-compose.gitea.yml ps
```

Vous devriez voir :
```
NAME                      STATUS
maestro-gitea             Up
maestro-gitea-postgres    Up (healthy)
```

### 4. Accéder à Gitea

Ouvrez votre navigateur : **http://localhost:3000**

---

## ⚙️ Configuration initiale (Premier démarrage)

### 1. Page d'installation

Lors du premier accès, Gitea affiche un formulaire de configuration.

**Paramètres à vérifier/modifier** :

#### Base de données
- Type : `PostgreSQL`
- Hôte : `gitea-postgres:5432`
- Utilisateur : `gitea_user`
- Mot de passe : `gitea_password_secure_2024`
- Base : `gitea`

#### Paramètres généraux
- Titre du site : `MAESTRO Git`
- URL racine : `http://localhost:3000/`
- Port SSH : `2222`
- Désactiver l'enregistrement : **✅ Coché**

#### Compte administrateur
- Nom d'utilisateur : `maestro_admin`
- Mot de passe : `MaestroGit2024!`
- Email : `admin@maestro.local`

Cliquez sur **Installer Gitea**.

---

## 🔑 Générer un token API

### 1. Se connecter

- URL : http://localhost:3000
- Utilisateur : `maestro_admin`
- Mot de passe : `MaestroGit2024!`

### 2. Créer un token

1. Cliquer sur l'avatar en haut à droite → **Paramètres**
2. Menu latéral → **Applications**
3. Section "Gérer les jetons d'accès"
4. Nom du jeton : `MAESTRO API Token`
5. Sélectionner les permissions :
   - ✅ `repo` (read/write)
   - ✅ `admin:repo_hook` (webhooks)
   - ✅ `admin:org` (organisations)
6. Cliquer sur **Générer le jeton**
7. **IMPORTANT** : Copier le token (il ne sera plus affiché)

Exemple de token : `a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0`

### 3. Stocker le token

Ajouter dans `.env.local` :

```env
GITEA_URL=http://localhost:3000
GITEA_API_TOKEN=a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0
```

---

## 🏢 Créer l'organisation MAESTRO

### 1. Via l'interface

1. Cliquer sur **+** en haut à droite → **Nouvelle organisation**
2. Nom : `maestro`
3. Visibilité : Privée
4. Cliquer sur **Créer l'organisation**

### 2. Via l'API (alternative)

```bash
curl -X POST http://localhost:3000/api/v1/orgs \
  -H "Authorization: token a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "maestro",
    "full_name": "MAESTRO Projects",
    "description": "Repositories managed by MAESTRO AI",
    "visibility": "private"
  }'
```

---

## ✅ Vérification de l'installation

### Test 1 : Accès à l'API

```bash
curl http://localhost:3000/api/v1/version
```

Résultat attendu :
```json
{
  "version": "1.21.x"
}
```

### Test 2 : Authentification API

```bash
curl -H "Authorization: token VOTRE_TOKEN_ICI" \
  http://localhost:3000/api/v1/user
```

Résultat attendu :
```json
{
  "id": 1,
  "login": "maestro_admin",
  "email": "admin@maestro.local",
  ...
}
```

### Test 3 : Créer un repository de test

```bash
curl -X POST http://localhost:3000/api/v1/orgs/maestro/repos \
  -H "Authorization: token VOTRE_TOKEN_ICI" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "test-repo",
    "description": "Test repository for MAESTRO",
    "private": true,
    "auto_init": true
  }'
```

Si la réponse contient `"id":`, le test est réussi ✅

---

## 🔄 Commandes utiles

### Redémarrer Gitea

```bash
docker-compose -f docker-compose.gitea.yml restart
```

### Voir les logs

```bash
docker-compose -f docker-compose.gitea.yml logs -f gitea
```

### Arrêter Gitea

```bash
docker-compose -f docker-compose.gitea.yml down
```

### Supprimer tout (⚠️ Attention : supprime les données)

```bash
docker-compose -f docker-compose.gitea.yml down -v
rm -rf gitea/
```

---

## 📊 Consommation ressources

Vérifier la consommation mémoire :

```bash
docker stats maestro-gitea maestro-gitea-postgres
```

Consommation typique :
- **Gitea** : 300-500 MB RAM
- **PostgreSQL** : 50-100 MB RAM
- **TOTAL** : ~600 MB

---

## 🐛 Dépannage

### Gitea ne démarre pas

1. Vérifier les logs :
   ```bash
   docker logs maestro-gitea
   ```

2. Vérifier que PostgreSQL est healthy :
   ```bash
   docker-compose -f docker-compose.gitea.yml ps
   ```

3. Vérifier les ports :
   ```bash
   netstat -ano | findstr :3000
   netstat -ano | findstr :2222
   ```

### Cannot connect to database

1. Vérifier que PostgreSQL est démarré
2. Vérifier les credentials dans `docker-compose.gitea.yml`
3. Attendre que PostgreSQL soit "healthy" (peut prendre 30s)

### Port already in use

Si le port 3000 est utilisé, modifier dans `docker-compose.gitea.yml` :
```yaml
ports:
  - "3001:3000"  # Utiliser 3001 au lieu de 3000
```

Et mettre à jour `GITEA__server__ROOT_URL=http://localhost:3001/`

---

## 📝 Prochaines étapes

1. ✅ Installation Gitea terminée
2. ➡️ Créer le service `GiteaService.php` dans Symfony
3. ➡️ Tester la création de repos via l'API
4. ➡️ Intégrer dans le workflow Agent DEV

---

**Installation Gitea terminée ! 🎉**
