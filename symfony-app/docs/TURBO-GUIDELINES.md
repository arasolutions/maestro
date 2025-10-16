# Guide Turbo - MAESTRO

## Qu'est-ce que Turbo ?

Turbo (Hotwired Turbo) est un framework JavaScript qui transforme votre application Symfony en une SPA (Single Page Application) sans écrire de JavaScript. Il intercepte automatiquement tous les liens et formulaires pour faire des requêtes AJAX.

## Quand désactiver Turbo ?

### ⚠️ IMPORTANT : Formulaires avec redirections

**Vous DEVEZ ajouter `data-turbo="false"` sur tout formulaire qui :**

1. **Fait une redirection après soumission**
   ```html
   <form method="post" action="/request/delete" data-turbo="false">
       <!-- Turbo s'attend à une réponse Turbo Stream, pas une redirection -->
   </form>
   ```

2. **Télécharge un fichier**
   ```html
   <form method="post" action="/export/pdf" data-turbo="false">
       <!-- Les téléchargements ne fonctionnent pas avec Turbo -->
   </form>
   ```

3. **Soumet vers un site externe**
   ```html
   <form method="post" action="https://payment-gateway.com" data-turbo="false">
       <!-- Ne jamais laisser Turbo gérer les formulaires externes -->
   </form>
   ```

4. **Upload de fichiers avec progress bar custom**
   ```html
   <form method="post" enctype="multipart/form-data" data-turbo="false">
       <!-- Si vous gérez l'upload avec du JavaScript custom -->
   </form>
   ```

## Erreur typique

```
Error: Form responses must redirect to another location
```

**Solution :** Ajoutez `data-turbo="false"` sur le formulaire.

## Exemple complet

### ❌ MAUVAIS (causera des erreurs)
```twig
<form method="post" action="{{ path('app_request_delete', {id: request.id}) }}">
    <input type="hidden" name="_token" value="{{ csrf_token('delete_request') }}">
    <button type="submit">Supprimer</button>
</form>
```

### ✅ BON
```twig
<form method="post" action="{{ path('app_request_delete', {id: request.id}) }}" data-turbo="false">
    <input type="hidden" name="_token" value="{{ csrf_token('delete_request') }}">
    <button type="submit">Supprimer</button>
</form>
```

## Checklist avant chaque commit

- [ ] Tous les formulaires POST avec redirection ont `data-turbo="false"`
- [ ] Les modals Bootstrap avec formulaires ont `data-turbo="false"`
- [ ] Les uploads de fichiers ont `data-turbo="false"`
- [ ] Les formulaires de paiement externe ont `data-turbo="false"`

## Désactiver Turbo complètement (si trop de problèmes)

Si Turbo cause trop de problèmes, vous pouvez le désactiver complètement :

### Option 1 : Dans base.html.twig
```html
<body data-turbo="false">
    <!-- Tout le contenu -->
</body>
```

### Option 2 : Supprimer de importmap.php
```php
// Commentez ou supprimez cette ligne
// '@hotwired/turbo' => [
//     'version' => '7.3.0',
// ],
```

### Option 3 : Supprimer de assets/bootstrap.js
Supprimez l'import de Turbo si présent.

## Quand garder Turbo activé ?

Turbo est excellent pour :
- Navigation entre les pages (liens simples)
- Formulaires de recherche/filtrage qui restent sur la même page
- Formulaires inline qui mettent à jour une partie de la page avec Turbo Streams

## Ressources

- Documentation officielle : https://turbo.hotwired.dev/
- Symfony + Turbo : https://symfony.com/bundles/ux-turbo
