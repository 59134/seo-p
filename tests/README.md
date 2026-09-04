# Tests de non-regression PSEO

Depuis le depot du module, avec PHP 8.1+ (mbstring et pdo_sqlite) et Composer :

```bash
composer install --working-dir=tests --no-scripts --no-plugins
PSEO_CMS_DIR=/chemin/du/cms php tests/vendor/bin/phpunit -c tests/phpunit.xml
```

Sous PowerShell, definir `$env:PSEO_CMS_DIR` avant de lancer PHP. Le chemin sert uniquement a lire les entites et traits du CMS qui ne sont pas embarques dans le module.

Les tests utilisent le code du module, PHPUnit, Symfony, Twig et Doctrine. Les acces aux donnees sont doubles ; les expressions DQL sont aussi compilees contre les mappings du CMS avec une connexion SQLite en memoire, sans toucher a une base client. Aucun appel Claude n'est effectue.

Couverture : conservation des lieux et URL des notes parents, URL internes et sous-repertoires, modules inactifs, destinations privees/inconnues, ancres stables, themes de FAQ, alertes lexicales, prompts personnalises, conservation du slug lors d'une optimisation incomplete, rendu Twig public/preview, echappement HTML et autowiring des services modifies.

Les fichiers `rendered-public.html` et `rendered-preview.html` sont des fixtures generees, ignorees par Git. Ils ne constituent pas une validation visuelle d'un site client. Les tests ne prouvent pas la veracite d'un texte produit par l'API : une generation reelle en brouillon et une relecture restent necessaires avant publication.
