# seo-prog

Ce package installe le module SEO programmatique dans un projet Symfony/Webtime sans ecraser les fichiers sensibles.

Il est prevu pour etre installe temporairement dans le projet cible, lancer l'installation du module, puis etre retire du `package.json` apres verification.

## Version 1.2.0

- Les liens contextuels sont intégrés à une expression du paragraphe ou présentés comme un CTA après le texte. La phrase artificielle suivie d'une ancre isolée disparaît.
- Les liens des pages existantes passent en CTA au rendu. Pour intégrer une ancre dans le texte, générer ou optimiser la page puis la relire ; aucune réécriture automatique de la base.
- Le mode inline exige une expression exacte, unique et sans chevauchement ; sinon le lien devient un CTA. Les URL restent validées et les textes échappés.
- Les nouvelles consignes complètent aussi les prompts personnalisés. Aucune migration, compilation front ou requête supplémentaire liée à ce rendu.
- Déployer les services, l'extension Twig, le template et son nouveau partiel `_section_content.html.twig` ensemble, puis vider le cache. Voir la documentation pour une mise à jour d'un template personnalisé.

## Version 1.1.1

- Corrige l'erreur 500 de la publication en masse : accès direct et retours après POST passent par le contexte EasyAdmin.
- Conserve la route `/admin/seo-page/bulk-publish`, les droits, le CSRF et les contrôles qualité. Affiche les confirmations et la liste vide après publication du dernier lot.
- Depuis 1.1.0 : déployer le contrôleur et le template ensemble, puis `php bin/console cache:clear`. Aucune migration ni compilation front.

## Version 1.1.0

- H3 compatibles avec les contenus historiques, notes locales non transposées et règles ajoutées aux prompts personnalisés sans les écraser.
- FAQ guidées par thèmes, inventaire des pages publiques du menu, liens contextuels validés et ancres stables selon la page source.
- Alertes de duplication lexicale et de questions identiques, sans dépublication automatique.
- Aucune nouvelle migration ni compilation front pour une mise à jour depuis 1.0.19. Conserver les personnalisations de template et vider le cache après déploiement de tous les fichiers PSEO modifiés.
- Voir `files/docs/seo-programmatique.md` pour les limites, les formats compatibles et les contrôles recommandés.

## Version 1.0.17

- Securise les actions admin sensibles avec POST, CSRF et controles de droits.
- Rend la generation idempotente afin de reutiliser une page active plutot que creer un doublon.
- Renforce les validations, l'import JSON, le recalcul du score qualite et le verrouillage des statuts.
- Localise les routes, canonicales et URLs du sitemap selon la langue de chaque page.
- Masque le front et le sitemap lorsque le module SEO programmatique est desactive.
- Ajoute les index SQL utiles aux lectures publiques et au maillage.
- Affiche toutes les pages associees dans le maillage interne, sans limite arbitraire.

## Installation depuis un fichier local

Copier le fichier `.tgz` a la racine du projet cible, puis lancer :

```bash
npm install ./seo-prog-1.2.0.tgz
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
npm install git+https://github.com/59134/seo-p.git#v1.2.0
```

Si tu utilises une cle SSH configuree sur le serveur :

```bash
npm install git+ssh://git@github.com/59134/seo-p.git#v1.2.0
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
./node_modules/.bin/seo-prog --keep-backups
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

## Generation de tous les seeds

Dans `2. Seeds SEO`, le bouton `Generer toutes les pages` ouvre un suivi dans un nouvel onglet. Le module cree d'abord les seeds enfants, deduplique le lot et traite par defaut trois pages en parallele.

- Les pages deja existantes sont ignorees.
- Un enfant mis a jour depuis son seed source est regenere.
- La concurrence peut etre reglee de 1 a 4 appels simultanes.
- L'onglet doit rester ouvert pendant le traitement.
- La file peut etre mise en pause et les erreurs peuvent etre relancees seules.

## Publication en masse

Dans `3. Pages SEO`, le bouton `Publication en masse` affiche les brouillons et pages a relire. Les pages ayant un score d'au moins 75 et aucune donnee critique manquante sont preselectionnees. Apres confirmation, elles deviennent publiees, indexables et sont ajoutees au sitemap. Les pages bloquees restent intactes avec leur motif affiche.

Chaque page est enregistree separement pendant la publication en masse. Un conflit technique, notamment sur un slug, est donc affiche dans l'admin sans transformer toute l'operation en erreur 500.

## Modele Auto

Le choix `Auto` utilise Sonnet 5 pour toutes les generations. `Valeur business` et `Priorite` servent a ordonner les traitements en lot. Sonnet 4.6, Opus et Fable restent disponibles comme choix forces.

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

Pour une page deja generee, ouvrir `3. Pages SEO`, modifier la page puis cliquer sur `Trouver une image`. Il n'est pas necessaire de regenerer les textes. Une image issue d'une prestation renvoie vers sa page source ; une image d'album reste non cliquable.

Pendant une generation en ligne de commande, renseigner `SEO_SITE_URL=https://www.exemple.fr` dans `.env.local` si l'URL page prestation liee est relative.

## Nettoyage apres installation

Apres une installation reussie, l'installateur supprime maintenant automatiquement tous les fichiers `.seo-programmatique.bak-*`. En cas d'erreur pendant l'installation, les sauvegardes sont conservees pour permettre une restauration.

Pour conserver volontairement les sauvegardes apres une installation reussie :

```bash
./node_modules/.bin/seo-prog --keep-backups
```

Pour supprimer les sauvegardes laissees par une ancienne version de l'installateur :

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
rm seo-prog-1.0.17.tgz
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
- Ajoute le bouton `Trouver une image` dans Pages SEO. Une image de prestation renvoie vers sa page source, une image d'album reste non cliquable, et toutes utilisent un ALT descriptif propose par Claude quand la source n'en fournit pas.
- Ajoute un bloc d'avis clients Elfsight optionnel avant la FAQ quand `SEO_ELFSIGHT_REVIEWS_APP_ID` est renseigne.
- Ajoute les variables manquantes dans `.env.local` ou dans le fichier choisi avec `--env`.
- Garde `.env.seo-programmatique.example` en interne dans le package : ce fichier n'est pas copie dans le projet cible.
- Ajoute la route front `seo_programmatic_page` dans `config/routes.yaml` si elle n'existe pas.
- Ajoute le menu admin SEO programmatique dans `DashboardController.php` si absent.
- Ajoute les pages SEO publiees dans `SitemapController.php` si absent.
- Ajoute l'exclusion TinyMCE pour les champs JSON SEO dans `assets/js/back/script.js` si absent.
- Ajoute les variables CSS `--wt-primary` et `--wt-primary-light` dans `assets/styles/front/custom.scss` si absentes.
- Cree des sauvegardes `.seo-programmatique.bak-YYYYMMDDHHMMSS` pendant les modifications, puis les supprime automatiquement apres une installation reussie. Elles restent disponibles en cas d'echec ou avec `--keep-backups`.

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
