# seo-prog

Ce package installe le module SEO programmatique dans un projet Symfony/Webtime sans ecraser les fichiers sensibles.

## Ce que l'installateur fait

- Copie les nouveaux fichiers du module : entites, repositories, services, controllers SEO, templates, documentation et migrations SEO.
- Ajoute les variables manquantes dans `.env.local` ou dans le fichier choisi avec `--env`.
- Ajoute la route front `seo_programmatic_page` dans `config/routes.yaml` si elle n'existe pas.
- Ajoute le menu admin SEO programmatique dans `DashboardController.php` si absent.
- Ajoute les pages SEO publiees dans `SitemapController.php` si absent.
- Ajoute l'exclusion TinyMCE pour les champs JSON SEO dans `assets/js/back/script.js` si absent.
- Cree des sauvegardes `.seo-programmatique.bak-YYYYMMDDHHMMSS` avant de modifier un fichier existant.

## Commande recommandee

Depuis la racine du projet cible :

```bash
npx seo-prog
```

## Simulation sans modification

```bash
npx seo-prog --dry-run
```

## Options utiles

```bash
npx seo-prog --env=.env.local
npx seo-prog --target=/chemin/vers/projet
npx seo-prog --force
npx seo-prog --no-backup
```

## Apres installation

```bash
php bin/console doctrine:migrations:migrate
npm run build
php bin/console cache:clear
```

Ensuite verifier dans l'admin :

- `0. Documentation SEO`
- `1. Faits verifies`
- `2. Seeds SEO`
- `3. Pages SEO`
- `4. Prompts SEO`
- `5. Historique Claude`

## Important

L'installateur est idempotent : si un ajout existe deja, il ne le remet pas une deuxieme fois.

L'ancienne commande `npx seo-programmatique-install` reste disponible comme alias.

Les fichiers sensibles ne sont pas remplaces en entier :

- `.env`
- `config/routes.yaml`
- `src/Controller/Admin/DashboardController.php`
- `src/Controller/SitemapController.php`
- `assets/js/back/script.js`
