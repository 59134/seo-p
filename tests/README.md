# Tests de non-regression PSEO

Depuis le depot du module, avec PHP 8.1+ (mbstring et pdo_sqlite) et Composer :

```bash
composer install --working-dir=tests --no-scripts --no-plugins
PSEO_CMS_DIR=/chemin/du/cms php tests/vendor/bin/phpunit -c tests/phpunit.xml
```

Sous PowerShell, definir `$env:PSEO_CMS_DIR` avant de lancer PHP. Le chemin sert uniquement a lire les entites et traits du CMS qui ne sont pas embarques dans le module.

Les tests utilisent le code du module, PHPUnit, Symfony, Twig et Doctrine. Les acces aux donnees sont doubles ; les expressions DQL sont aussi compilees contre les mappings du CMS avec une connexion SQLite en memoire, sans toucher a une base client. Aucun appel Claude n'est effectue.

Couverture : conservation des lieux et URL des notes parents, URL internes et sous-repertoires, modules inactifs, destinations privees/inconnues, ancres stables, themes de FAQ, alertes lexicales, prompts personnalises, conservation du slug lors d'une optimisation incomplete, rendu Twig public/preview, echappement HTML et autowiring des services modifies.

Publication en masse : tests HTTP avec le vrai routeur EasyAdmin et ses templates, acces direct, retours POST puis GET, liste vide, pages bloquees, CSRF et permissions. Les donnees, autorisations, tokens et menus sont des fixtures ; ces tests ne remplacent pas un test sur le serveur client.

EasyAdmin est volontairement fixe a 4.7.0 dans ce banc isole pour reproduire la version du CMS. Cette ancienne version a des alertes de securite : Composer peut refuser sa resolution. Une exception temporaire de politique ne doit concerner que `tests/`, jamais le CMS de production. Aucune configuration globale de Composer ne doit etre desactivee. L'extension PHP `intl` est aussi necessaire pour ce test EasyAdmin.

Les fixtures `rendered-bulk.html` et `rendered-bulk-empty.html` permettent le controle des interactions avec `node tests/bulk-browser-smoke.cjs` (Playwright et Edge requis). Aucun serveur ni aucune base client ne sont utilises.

Les fichiers `rendered-public.html` et `rendered-preview.html` sont des fixtures generees, ignorees par Git. Ils ne constituent pas une validation visuelle d'un site client. Les tests ne prouvent pas la veracite d'un texte produit par l'API : une generation reelle en brouillon et une relecture restent necessaires avant publication.
