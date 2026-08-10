# seo-prog

Ce package installe le module SEO programmatique dans un projet Symfony/Webtime sans ecraser les fichiers sensibles.

Il est prevu pour etre installe temporairement dans le projet cible, lancer l'installation du module, puis etre retire du `package.json` apres verification.

## Installation depuis un fichier local

Copier le fichier `.tgz` a la racine du projet cible, puis lancer :

```bash
npm install ./seo-prog-1.0.11.tgz
```

Verifier ce qui sera modifie sans rien ecrire :

```bash
./node_modules/.bin/seo-prog --dry-run
```

Installer le module :

```bash
./node_modules/.bin/seo-prog
```

## Installation depuis Git

Si le repository Git est accessible depuis le serveur :

```bash
npm install git+https://github.com/59134/seo-p.git#v1.0.11
```

Si tu utilises une cle SSH configuree sur le serveur :

```bash
npm install git+ssh://git@github.com/59134/seo-p.git#v1.0.11
```

Puis lancer l'installation :

```bash
./node_modules/.bin/seo-prog --dry-run
./node_modules/.bin/seo-prog
```

Pour mettre a jour un module deja installe, verifier d'abord la simulation puis autoriser le remplacement des fichiers du module :

```bash
./node_modules/.bin/seo-prog --dry-run --force
./node_modules/.bin/seo-prog --force
```

## Commande npx

Une fois le package installe dans le projet, cette commande peut aussi fonctionner :

```bash
npx seo-prog --dry-run
npx seo-prog
```

Sur certains serveurs, `npx seo-prog` peut chercher `seo-prog` sur le registre npm public. Dans ce cas, utiliser plutot :

```bash
./node_modules/.bin/seo-prog --dry-run
./node_modules/.bin/seo-prog
```

## Options utiles

```bash
./node_modules/.bin/seo-prog --env=.env.local
./node_modules/.bin/seo-prog --target=/chemin/vers/projet
./node_modules/.bin/seo-prog --force
./node_modules/.bin/seo-prog --no-backup
./node_modules/.bin/seo-prog --clean-backups
```

## Apres installation

Lancer les migrations, reconstruire les assets puis vider le cache :

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

## Import JSON SEO

Depuis l'admin, utiliser le bouton `Importer JSON` dans :

- `1. Faits verifies`
- `2. Seeds SEO`

Le bouton des faits importe uniquement la cle `facts`.
Le bouton des seeds importe uniquement la cle `seeds`.

Le meme import existe aussi en ligne de commande :

```bash
php bin/console app:seo:import fichier.json --dry-run
php bin/console app:seo:import fichier.json --scope=facts
php bin/console app:seo:import fichier.json --scope=seeds --update
```

## Avis clients Elfsight

Pour afficher les vrais avis du client avant la FAQ des pages SEO, renseigner dans `.env.local` l'identifiant du widget Elfsight :

```env
SEO_ELFSIGHT_REVIEWS_APP_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

L'identifiant correspond a la partie situee apres `elfsight-app-` dans le code fourni par Elfsight. La classe complete est aussi acceptee. Laisser la variable vide masque entierement le bloc.

Utiliser de preference un widget en liste, grille ou carrousel. Puis vider le cache :

```bash
php bin/console cache:clear
```

## Images SEO existantes

Lors d'une generation, le module cherche d'abord l'`og:image` de la page prestation liee, puis une photo dans un album actif pertinent. Claude fournit l'ALT descriptif. Si aucune image fiable n'est trouvee, la page reste sans image.

Pour une page deja generee, ouvrir `3. Pages SEO`, modifier la page puis cliquer sur `Trouver une image`. Il n'est pas necessaire de regenerer les textes. L'image front renvoie vers `/albums`.

Pendant une generation en ligne de commande, renseigner `SEO_SITE_URL=https://www.exemple.fr` dans `.env.local` si l'URL page prestation liee est relative.

## Nettoyage apres installation

Supprimer d'abord les sauvegardes `.seo-programmatique.bak-*` creees par l'installateur :

```bash
./node_modules/.bin/seo-prog --clean-backups --dry-run
./node_modules/.bin/seo-prog --clean-backups
```

La commande courte suivante fait la meme chose :

```bash
./node_modules/.bin/seo-prog-clean-backups --dry-run
./node_modules/.bin/seo-prog-clean-backups
```

Ensuite retirer uniquement le package d'installation du projet :

```bash
npm remove seo-prog
```

Cette commande retire `seo-prog` de `package.json`, `package-lock.json` et `node_modules`.
Elle ne supprime pas les fichiers Symfony installes par le module.

Si le fichier `.tgz` a ete copie a la racine du projet, tu peux aussi le supprimer :

```bash
rm seo-prog-1.0.11.tgz
```

Si un ancien essai a copie le fichier d'exemple `.env.seo-programmatique.example` dans le projet et que tu n'en as plus besoin :

```bash
rm .env.seo-programmatique.example
```

Pour voir manuellement les sauvegardes creees par l'installateur :

```bash
find . -name "*.seo-programmatique.bak-*" -type f
```

Apres verification uniquement, tu peux les supprimer :

```bash
find . -name "*.seo-programmatique.bak-*" -type f -delete
```

## Ce que l'installateur fait

- Copie les nouveaux fichiers du module : entites, repositories, services, controllers SEO, templates, documentation et migrations SEO.
- Ajoute l'import JSON SEO dans les modules Faits verifies et Seeds SEO.
- Ajoute un JSON-LD SEO renforce sur le template front: WebPage, Service, LocalBusiness et FAQPage uniquement si la FAQ est visible.
- Cherche une vraie image SEO depuis l'`og:image` de la prestation liee, puis dans les albums pertinents, et ajoute son `ImageObject` au JSON-LD.
- Ajoute le bouton `Trouver une image` dans Pages SEO. L'image affichee renvoie vers `/albums` et utilise un ALT descriptif propose par Claude quand la source n'en fournit pas.
- Ajoute un bloc d'avis clients Elfsight optionnel avant la FAQ quand `SEO_ELFSIGHT_REVIEWS_APP_ID` est renseigne.
- Ajoute les variables manquantes dans `.env.local` ou dans le fichier choisi avec `--env`.
- Garde `.env.seo-programmatique.example` en interne dans le package : ce fichier n'est pas copie dans le projet cible.
- Ajoute la route front `seo_programmatic_page` dans `config/routes.yaml` si elle n'existe pas.
- Ajoute le menu admin SEO programmatique dans `DashboardController.php` si absent.
- Ajoute les pages SEO publiees dans `SitemapController.php` si absent.
- Ajoute l'exclusion TinyMCE pour les champs JSON SEO dans `assets/js/back/script.js` si absent.
- Ajoute les variables CSS `--wt-primary` et `--wt-primary-light` dans `assets/styles/front/custom.scss` si absentes.
- Cree des sauvegardes `.seo-programmatique.bak-YYYYMMDDHHMMSS` avant de modifier un fichier existant.

## Important

L'installateur est idempotent : si un ajout existe deja, il ne le remet pas une deuxieme fois.

L'ancienne commande `seo-programmatique-install` reste disponible comme alias.

Les fichiers sensibles ne sont pas remplaces en entier :

- `.env`
- `config/routes.yaml`
- `src/Controller/Admin/DashboardController.php`
- `src/Controller/SitemapController.php`
- `assets/js/back/script.js`
- `assets/styles/front/custom.scss`
