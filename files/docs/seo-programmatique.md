# Module SEO programmatique

**Version du module : 1.1.0**

## Nouveautés 1.1.0

- Les sous-titres des blocs numérotés sont maintenant des H3 sous le H2 principal. La clé JSON `h2` reste inchangée pour les anciens contenus.
- Les notes et le contexte du pilier conservent leurs lieux et leurs URL lors de la création des enfants. Aucun remplacement automatique du lieu d'une preuve ou d'une réalisation.
- Les consignes de génération distinguent implantation, zone couverte et contexte géographique. Les faits insuffisants doivent être signalés, jamais complétés par des monuments inventés.
- Un plan de thèmes de FAQ stable par seed aide à varier les besoins traités, y compris lors des générations parallèles. Ce plan ne remplace pas la vérification des réponses.
- La génération reçoit un inventaire des catégories actives et cliquables, avec de courts extraits des articles. Les liens de menu reconnus vers des modules publics actifs sont également proposés. Aucun crawl HTTP supplémentaire.
- Seules les destinations internes reconnues sont retenues dans les nouveaux liens contextuels. Les URL externes, privées, inconnues, à paramètres ou ancres de fragment sont exclues de cet inventaire. Un lien personnalisé non reconnu reste à vérifier manuellement ; la navigation générale du CMS n'est pas modifiée.
- Les liens peuvent être affichés sous la section pertinente. Le format historique `label`/`url` reste accepté ; `section` (1 à N, ou 0 pour un lien général) et `context` sont optionnels. Le HTML reste échappé.
- Les ancres de « Continuer votre recherche » varient de façon stable selon la page source et la destination, sans changer les URL ni limiter le nombre de pages associées.
- Des alertes de reprise textuelle et de questions identiques sont calculées lors de la génération ou du recalcul qualité. Elles ne changent pas le score et ne dépublient rien automatiquement.

### Mise à jour et limites

Aucune nouvelle migration ni compilation front n'est nécessaire pour passer de 1.0.19 à 1.1.0. Déployer tous les fichiers PSEO concernés, puis vider le cache Symfony. Les nouveaux services sont autowirés comme les autres services du module.

Les URL et textes stockés ne sont pas réécrits à l'installation. Les H3 et ancres s'appliquent immédiatement au template standard ; les FAQ et nouveaux liens demandent une génération ou une optimisation choisie, puis une prévisualisation. Une optimisation conserve l'URL existante et repasse la page en relecture/brouillon selon le fonctionnement habituel.

Les prompts enregistrés en base ne sont pas écrasés : les règles de sécurité et de compatibilité leur sont ajoutées à l'exécution, ainsi que le contexte s'il manque. Les faits restent sélectionnés par langue, pas par un champ ville : préciser le périmètre dans le nom et le contenu de chaque fait. La pertinence géographique et la véracité demandent toujours une relecture humaine.

La comparaison lexicale examine jusqu'à 12 autres pages, en donnant priorité au même service. Elle compare les groupes de cinq mots du corps du texte après neutralisation des villes (au moins 80 mots, alerte à partir de 0,80 de Jaccard), ainsi que les questions de FAQ. Ce seuil est un repère technique, pas un seuil Google. Ce n'est ni un contrôle exhaustif de tout le site ni une mesure sémantique ; la limite de comparaison ne limite pas le maillage affiché.

Pour les générations en ligne de commande, `SEO_SITE_URL` peut préciser le domaine et le sous-répertoire lorsque le routeur utilise encore `localhost`. L'inventaire ne fait aucun appel réseau ; les vérifications externes des faits restent à réaliser en amont.

Ce module permet de generer des pages SEO a partir de donnees metier verifiees, avec Claude, puis de les publier seulement apres controle qualite et validation admin.

L'objectif n'est pas de publier beaucoup de pages automatiquement. L'objectif est de produire des pages locales utiles, uniques, controlees, et indexables uniquement quand elles ont assez de valeur.

## Principe general

Le module fonctionne avec 4 idees principales:

1. Les `Seeds SEO` definissent les opportunites de pages.
2. Les `Faits verifies` donnent a Claude les informations qu'il a le droit d'utiliser.
3. Claude genere une page structuree en JSON.
4. La page reste en brouillon ou en relecture tant qu'elle n'est pas validee.

Une page publiee devient accessible sur:

```text
/{slug}
```

Les anciennes URLs `/seo-local/{slug}` sont redirigees en 301 vers `/{slug}`.
Elle est ajoutee au sitemap uniquement si elle est publiee et indexable.

## Ordre d'utilisation dans l'admin

Le menu admin est volontairement numerote:

1. `Documentation SEO`: comprendre le module.
2. `Faits verifies`: donner a Claude les informations vraies.
3. `Seeds SEO`: creer les pages potentielles et lancer la generation.
4. `Pages SEO`: relire, corriger, previsualiser et publier.
5. `Prompts SEO`: option avancee pour changer les consignes Claude.
6. `Historique Claude`: diagnostiquer les generations et erreurs.

## Workflow recommande

1. Aller dans l'admin, section `SEO programmatique`.
2. Creer des `Faits verifies`.
3. Creer un `Seed SEO`.
4. Cliquer sur `Generer avec Claude`.
5. Relire la page generee.
6. Completer ou corriger si besoin.
7. Publier uniquement si le score qualite est suffisant.

Le module refuse la publication si:

- le score qualite est inferieur a 75;
- il reste une donnee critique manquante: service non confirme, zone non couverte, faits locaux insuffisants, preuves metier insuffisantes ou generation Claude echouee;
- la page n'est pas suffisamment specifique.

Le champ `Canonical forcee` n'est pas obligatoire. S'il est vide, la page utilise automatiquement sa propre URL publique comme canonical.

## Guide pas a pas pour un utilisateur

### Etape 0 - Lire la documentation

La documentation explique le principe du module. Elle ne sert pas a generer de page.

Le module est fait pour fonctionner dans cet ordre:

1. Faits verifies.
2. Seeds SEO.
3. Pages SEO.
4. Prompts SEO si besoin avance.
5. Historique Claude en cas de probleme.

### Etape 1 - Remplir les Faits verifies

C'est l'etape la plus importante. Les faits verifies sont les informations que Claude peut utiliser.

Champs du formulaire:

- `Nom`: nom court pour reconnaitre le fait dans l'admin. Exemple: `Zone Lille`, `Devis`, `Entretien chaudiere`.
- `Type`: categorie du fait. Elle aide Claude a comprendre comment utiliser l'information.
- `Langue`: langue du fait. Mettre `fr` pour le francais.
- `Priorite`: importance du fait. Plus le chiffre est haut, plus le fait remonte dans le contexte donne a Claude. Exemple: `100` pour une information essentielle, `50` pour une information utile, `10` pour un detail secondaire.
- `Actif`: si actif, le fait peut etre donne a Claude. Si desactive, il reste en base mais n'est plus utilise pour les nouvelles generations.
- `Fait verifie`: le contenu exact que Claude peut utiliser.

Exemples:

```text
Type: business
Nom: Activite principale
Langue: fr
Priorite: 100
Actif: oui
Fait verifie: Top Chauffe intervient pour l'installation, l'entretien et le depannage de systemes de chauffage.

Type: service
Nom: Entretien chaudiere
Langue: fr
Priorite: 90
Actif: oui
Fait verifie: L'entreprise propose l'entretien de chaudiere gaz et le diagnostic en cas de panne.

Type: local
Nom: Zone Lille
Langue: fr
Priorite: 100
Actif: oui
Fait verifie: L'entreprise intervient a Lille, Roubaix, Tourcoing et dans plusieurs communes du Nord.

Type: proof
Nom: Devis
Langue: fr
Priorite: 70
Actif: oui
Fait verifie: Un devis clair est fourni avant intervention lorsque la situation le permet.

Type: forbidden_claim
Nom: Delai non garanti
Langue: fr
Priorite: 100
Actif: oui
Fait verifie: Ne pas promettre une intervention en moins de 30 minutes si ce delai n'est pas garanti.

Type: pricing
Nom: Prix
Langue: fr
Priorite: 80
Actif: oui
Fait verifie: Ne pas annoncer de tarif fixe si aucun prix officiel n'est fourni.
```

Regle simple:

- si l'information est vraie et utile, l'ajouter;
- si l'information est incertaine, ne pas l'ajouter;
- si Claude ne doit pas promettre quelque chose, l'ajouter en `forbidden_claim`.

### Etape 2 - Creer un Seed SEO

Un seed represente une page a generer.

Exemple complet:

```text
Mot cle principal: remplacement chaudiere Lille
Service: remplacement chaudiere
URL page prestation liee: /remplacement-chaudiere
Libelle lien prestation: Remplacement de chaudiere
Ville: Lille
Departement: Nord

Mots cles secondaires:
depannage chaudiere Lille
entretien chauffage Lille
reparation chaudiere gaz Lille

Mots cles pages a generer:
entretien chaudiere Lille
depannage chaudiere Lille
remplacement chaudiere Roubaix | remplacement chaudiere | Roubaix | Nord | Trouver un professionnel pour remplacer une chaudiere a Roubaix.

Intention utilisateur:
Trouver un chauffagiste fiable a Lille pour une panne, un entretien ou un remplacement de chauffage.

Notes:
Mettre en avant la proximite, le diagnostic, le devis clair et les interventions autour de Lille.

Valeur business: 90
Priorite: 100
Modele Claude: Auto
```

Modele Claude:

- `Auto`: choix recommande. Le module utilise toujours Sonnet 5. La valeur business et la priorite servent a ordonner les generations, pas a changer de modele.
- `Sonnet`: a utiliser pour generer beaucoup de pages avec un bon rapport qualite/cout.
- `Sonnet 5`: modele utilise par defaut en mode Auto, adapte a la generation et a l'optimisation des pages.
- `Opus`: a utiliser pour les pages importantes ou concurrentielles.
- `Fable`: a reserver aux pages les plus strategiques.

Une fois le seed cree, cliquer sur `Generer avec Claude` pour utiliser le choix du seed.
Les boutons d'optimisation comme `Sonnet 5`, `Opus` et `Fable` permettent de forcer un modele pour une generation precise.

Si le champ `Mots cles pages a generer` est rempli, cliquer sur `Generer pages mots cles`. Le module genere d'abord la page du seed principal si elle n'existe pas encore, cree ensuite un seed par mot cle page, puis genere une page par seed enfant. Il evite de recreer les seeds/pages qui existent deja.

Pour traiter tous les seeds actifs sans attendre famille par famille, utiliser `Generer toutes les pages` depuis le listing. Le suivi s'ouvre dans un nouvel onglet, prepare les pages enfants puis lance trois generations simultanees par defaut. La concurrence est reglable de 1 a 4. Garder l'onglet ouvert; la file peut etre mise en pause et les erreurs peuvent etre relancees seules.

Difference importante:

- `Mots cles secondaires`: enrichissent la page du seed.
- `Mots cles pages a generer`: creent plusieurs pages differentes.

Format avance possible:

```text
mot cle | service | ville | departement | intention
```

### Etape 3 - Relire la page dans Pages SEO

La page generee apparait dans `Pages SEO`.

Avant de publier, verifier:

- le title SEO;
- la meta description;
- le H1;
- l'introduction;
- les sections;
- la FAQ;
- les `Textes template JSON`, surtout si vous voulez ajuster les petits textes du design;
- les liens internes;
- les alertes qualite;
- les donnees manquantes, surtout si elles touchent le service, la zone ou la veracite du contenu;
- le score qualite.

Pour publier plusieurs pages, ouvrir `3. Pages SEO` puis cliquer sur `Publication en masse`. Les pages avec un score d'au moins 75 et sans donnee critique manquante sont preselectionnees. Les autres restent bloquees avec leur motif. Apres confirmation, les pages choisies deviennent publiees, indexables et sont ajoutees au sitemap.

Une page doit avoir un score d'au moins `75` pour etre publiee.

Le champ `Donnees manquantes` est editable. Une ligne correspond a une information que Claude n'a pas pu verifier. Les informations comme telephone, adresse, delai, forfait, aides, prix, marque ou garantie sont utiles pour ameliorer la page, mais ne bloquent pas automatiquement la publication. Les manques critiques, eux, peuvent bloquer: service non confirme, zone non couverte, faits locaux insuffisants, preuves metier insuffisantes ou generation Claude echouee.

Le champ `Canonical forcee` est optionnel. Le laisser vide dans le cas normal. Il sert seulement si la page doit pointer volontairement vers une autre URL canonique.

Le champ `Textes template JSON` pilote les textes courts du template front: points sous le hero, bloc situation, etapes du parcours, introduction des sections et CTA final. Si le champ est vide, le template utilise des textes par defaut pour rester compatible avec les anciennes pages.

#### Image SEO de la page

La generation tente aussi d'associer une vraie image existante a la page, sans utiliser d'API de generation d'images:

1. le module cherche l'image `og:image` de l'`URL page prestation liee`;
2. si aucune image exploitable n'est trouvee, il cherche un album actif pertinent pour la prestation;
3. si aucune source fiable n'est disponible, la page reste simplement sans image.

Le premier element de `Suggestions alt images` fourni par Claude sert d'ALT principal lorsque l'image source ne possede pas deja un ALT. Cet ALT doit decrire la prestation ou la photo sans pretendre qu'elle a ete prise dans la ville cible.

Les champs `Image SEO` et `ALT image SEO` restent modifiables dans la page. Le bouton `Trouver une image` relance la recherche pour une page deja generee. Il n'est donc pas necessaire de regenerer tout son contenu. Dans le template front, une image issue d'une prestation renvoie vers sa page source, une image d'album reste non cliquable, et le JSON-LD ajoute un `ImageObject` relie a la page et au service.

Le maillage interne affiche toutes les pages associees trouvees par le module. Aucune limite arbitraire n'est appliquee au nombre de liens associes.

Pour une page importante deja generee, utiliser `Optimiser Sonnet 5`, `Optimiser Opus` ou `Optimiser Fable` depuis la page SEO. Le module retravaille la page avec le modele choisi, garde le lien avec le seed et ajoute une ligne dans l'historique Claude.

### Etape 4 - Publier seulement si la page est vraiment bonne

Publier uniquement si:

- la ville est vraiment couverte;
- le service est vraiment propose;
- aucune information n'est inventee;
- le contenu est utile pour l'utilisateur;
- la page n'est pas une copie d'une autre page avec juste la ville changee;
- la previsualisation est correcte.

Si la publication est refusee pour donnees manquantes critiques, retourner sur la page SEO, lire le champ `Donnees manquantes`, corriger le contenu ou les faits verifies si besoin, supprimer les lignes qui ne bloquent plus, sauvegarder, puis republier.

Quand une page est publiee et indexable, elle apparait dans le sitemap.

### Etape 5 - Utiliser Historique Claude en cas de probleme

Aller dans `Historique Claude` si:

- Claude ne genere pas;
- une erreur API apparait;
- le modele semble incorrect;
- le resultat est vide ou incomplet;
- il faut verifier les tokens consommes.

## Faits verifies

Les faits verifies sont les informations que Claude peut utiliser.

Exemples utiles:

- services reels proposes;
- villes ou zones vraiment couvertes;
- marques prises en charge;
- garanties reelles;
- certifications reelles;
- processus d'intervention;
- exclusions;
- informations tarifaires si elles sont fiables;
- questions frequentes reelles;
- preuves metier.

Types disponibles:

- `business`: informations entreprise;
- `service`: details sur les services;
- `local`: zones, villes, contexte local;
- `proof`: preuves, engagements, differenciants;
- `forbidden_claim`: choses a ne pas inventer;
- `brand`: marques;
- `faq`: questions/reponses reutilisables;
- `pricing`: informations de prix.

Plus les faits sont precis, plus Claude peut generer une page solide.

## Seeds SEO

Un seed represente une page potentielle.

Champs importants:

- `Mot cle principal`: exemple `remplacement chaudiere Lille`;
- `Service`: exemple `remplacement chaudiere`;
- `URL page prestation liee`: lien interne vers la page pilier de la prestation, exemple `/remplacement-chaudiere`;
- `Libelle lien prestation`: texte du lien vers la page pilier, exemple `Remplacement de chaudiere`;
- `Ville`: exemple `Lille`;
- `Departement`: exemple `Nord`;
- `Mots cles secondaires`: un par ligne, pour enrichir la page du seed;
- `Mots cles pages a generer`: un par ligne, pour creer des seeds/pages separes;
- `Intention utilisateur`: le probleme concret de l'internaute;
- `Notes`: informations specifiques a cette page;
- `Valeur business`: priorite commerciale de 0 a 100;
- `Priorite`: ordre de traitement;
- `Modele Claude`: choix du modele pour ce seed.

Exemple de seed:

```text
Mot cle principal: remplacement chaudiere Lille
Service: remplacement chaudiere
URL page prestation liee: /remplacement-chaudiere
Libelle lien prestation: Remplacement de chaudiere
Ville: Lille
Departement: Nord
Mots cles secondaires:
depannage chaudiere Lille
entretien chauffage Lille
reparation chaudiere gaz Lille
Intention utilisateur:
Trouver rapidement un chauffagiste fiable pour une panne, un entretien ou un remplacement.
```

## Generation Claude

Le service principal est:

```text
src/Service/ClaudeSeoGenerator.php
```

Il utilise:

```text
src/Service/SeoPromptBuilder.php
src/Service/SeoQualityScorer.php
```

Claude recoit:

- les donnees du seed;
- les faits verifies actifs;
- les infos entreprise;
- les regles SEO;
- les interdictions d'invention.
- un resume des pages SEO deja generees pour limiter la duplication.
- le lien vers la page prestation liee si le seed en possede un.

Claude doit retourner une structure avec:

- slug;
- title;
- meta description;
- H1;
- intro;
- sections;
- FAQ;
- CTA;
- liens internes;
- suggestions alt image;
- schema JSON-LD;
- alertes qualite;
- recommandation index/noindex/review;
- donnees manquantes.

## Anti-duplication entre pages locales

Le module ajoute au prompt un bloc `anti_duplication`.

Objectif:

- apporter une valeur propre a chaque page, sans pretendre mesurer une similarite semantique;
- varier les introductions;
- varier les sous-titres de section (H3 dans le template, cle JSON `h2`);
- varier les exemples;
- varier les FAQ;
- varier l'angle local;
- varier les deux textes SEO principaux;
- varier la conclusion.

Claude recoit un resume des pages deja generees: title, H1, extrait d'intro, H2, extraits de sections et questions de FAQ. Il doit s'en servir pour eviter de reprendre la meme structure ou les memes formulations.

Si les donnees locales sont trop faibles pour differencier correctement une page, Claude doit le signaler dans `quality_flags` et recommander `review` ou `noindex`.

## Maillage vers la page prestation

Chaque seed peut contenir:

- `URL page prestation liee`;
- `Libelle lien prestation`.

Exemple:

```text
URL page prestation liee: /remplacement-chaudiere
Libelle lien prestation: Remplacement de chaudiere
```

Lorsque sa destination est reconnue dans l'inventaire public, ce lien est donne a Claude et ajoute aux liens internes de la page generee. Le lien prestation existant dans le seed reste utilise par les CTA du template. Les liens contextuels supplementaires sont filtres par l'inventaire et affiches dans leur section.

Si `CLAUDE_API_KEY` est absent, le module cree un brouillon local non indexable. Cela permet de tester le workflow sans appel API.

## Variables d'environnement

Ne jamais mettre la vraie cle Claude dans `.env`.

Utiliser `.env.local` ou les variables serveur:

```env
CLAUDE_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-sonnet-5
CLAUDE_MODEL_SONNET=claude-sonnet-4-6
CLAUDE_MODEL_SONNET_5=claude-sonnet-5
CLAUDE_MODEL_PREMIUM=claude-opus-4-8
CLAUDE_MODEL_OPUS=claude-opus-4-8
CLAUDE_MODEL_FABLE=claude-fable-5
CLAUDE_MAX_TOKENS_SONNET=7000
CLAUDE_MAX_TOKENS_OPUS=9000
CLAUDE_MAX_TOKENS_FABLE=9000
CLAUDE_TIMEOUT_SECONDS=180
SEO_ELFSIGHT_REVIEWS_APP_ID=
SEO_SITE_URL=
```

`SEO_SITE_URL` est optionnel. Il sert seulement aux generations lancees en ligne de commande lorsque l'URL de prestation liee est relative et qu'aucune requete web ne permet au module de connaitre le domaine. Exemple: `SEO_SITE_URL=https://www.exemple.fr`.

### Avis clients Elfsight

Le template peut afficher un widget d'avis Elfsight avant la FAQ. Dans le code d'integration Elfsight, reperer la classe:

```html
<div class="elfsight-app-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
```

Copier l'identifiant situe apres `elfsight-app-` dans `.env.local`:

```env
SEO_ELFSIGHT_REVIEWS_APP_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

La classe complete `elfsight-app-...` est egalement acceptee. Si la variable reste vide, le bloc d'avis n'est pas rendu. Apres une modification, lancer `php bin/console cache:clear`.

Pour une page SEO, utiliser de preference un widget en liste, grille ou carrousel. Un widget flottant deja affiche dans le layout global risquerait d'apparaitre deux fois. Le module ne transforme pas les avis Elfsight en `AggregateRating` et n'invente aucune note dans le JSON-LD.

Le fichier `.env` contient seulement des ids de modeles non secrets:

```env
CLAUDE_MODEL=claude-sonnet-5
CLAUDE_MODEL_SONNET=claude-sonnet-4-6
CLAUDE_MODEL_SONNET_5=claude-sonnet-5
CLAUDE_MODEL_PREMIUM=claude-opus-4-8
CLAUDE_MODEL_OPUS=claude-opus-4-8
CLAUDE_MODEL_FABLE=claude-fable-5
```

Par defaut, le module demande maintenant plus de sortie a Claude:

- Sonnet: `7000` tokens output.
- Opus: `9000` tokens output.
- Fable: `9000` tokens output.
- Timeout API: `180` secondes.

Dans `Historique Claude`, verifier `Max tokens`, `Tokens output` et `Stop reason`. Si `Tokens output` est egal a `Max tokens` et que `Stop reason` vaut `max_tokens`, la page a probablement ete coupee: augmenter le plafond ou reduire le prompt.

### Choix du modele

Modele conseille par defaut:

```env
CLAUDE_MODEL=claude-sonnet-5
CLAUDE_MODEL_SONNET_5=claude-sonnet-5
```

Pourquoi:

- meilleur compromis qualite, vitesse et cout pour generer beaucoup de pages;
- suffisant pour produire des contenus structures, FAQ, metas et JSON-LD;
- moins couteux que les modeles Opus/Fable pour du volume.

Utiliser un modele plus puissant seulement pour:

- pages tres strategiques;
- prompts tres longs;
- restructuration complexe;
- audit qualite avance.

En mode `Auto`, le module utilise toujours `CLAUDE_MODEL_SONNET_5`, soit Sonnet 5 par defaut. Les boutons et choix explicites permettent encore de forcer Sonnet 4.6, Opus ou Fable. `Valeur business` et `Priorite` determinent l'ordre des traitements en lot, sans changer le modele.

## Publication et indexation

Une page SEO peut avoir ces statuts:

- `draft`: brouillon;
- `review`: a relire;
- `published`: publiee;
- `archived`: archivee.

Pour apparaitre dans Google, elle doit etre:

- `status = published`;
- `indexable = true`;
- accessible publiquement;
- presente dans le sitemap.

Le sitemap recupere seulement les pages via:

```text
SeoPageRepository::findIndexablePages()
```

## URL publique

Route:

```text
/{slug}
```

L'ancien format `/seo-local/{slug}` reste actif uniquement comme redirection 301.

Controleur:

```text
src/Controller/SeoProgrammaticController.php
```

Template:

```text
templates/pages/seo_programmatic/show.html.twig
```

Le template ajoute:

- title;
- meta description;
- canonical;
- noindex en preview;
- contenu structure;
- FAQ visible;
- avis clients Elfsight quand `SEO_ELFSIGHT_REVIEWS_APP_ID` est configure;
- JSON-LD WebPage, Service, LocalBusiness et FAQPage quand une FAQ visible existe.

## Commande batch

Pour generer plusieurs pages depuis les seeds prets:

```bash
php bin/console app:seo:generate
```

Options:

```bash
php bin/console app:seo:generate --limit=5
php bin/console app:seo:generate --minimum-completeness=70
php bin/console app:seo:generate --limit=5 --model=sonnet
php bin/console app:seo:generate --limit=3 --model=opus
php bin/console app:seo:generate --limit=1 --model=fable
```

Important: sans `--model`, la commande respecte le modele choisi dans chaque seed. Avec `--model`, elle force le meme modele sur tout le lot. Elle genere des pages, mais ne les publie pas automatiquement.

## Migration

Les migrations du module sont:

```text
migrations/Version20260624193000.php
migrations/Version20260625103000.php
migrations/Version20260625112000.php
migrations/Version20260625123000.php
migrations/Version20260626100000.php
```

A lancer:

```bash
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
```

## Bonnes pratiques SEO

Avant publication, verifier:

- la page repond a une intention claire;
- la ville ou zone est vraiment couverte;
- aucune certification, marque, prix ou garantie n'est inventee;
- les liens internes sont utiles;
- le title et la meta sont uniques;
- la FAQ apporte une vraie aide;
- le contenu n'est pas interchangeable avec une autre ville;
- le score qualite est au moins de 75.

Ne pas publier les pages faibles. Les garder en brouillon, enrichir les faits verifies, puis regenerer ou corriger.

## Fichiers principaux

Entites:

```text
src/Entity/SeoSeed.php
src/Entity/SeoFact.php
src/Entity/SeoPage.php
src/Entity/SeoPromptTemplate.php
src/Entity/SeoGenerationRun.php
```

Services:

```text
src/Service/ClaudeSeoGenerator.php
src/Service/SeoPromptBuilder.php
src/Service/SeoQualityScorer.php
```

Admin:

```text
src/Controller/Admin/SeoSeedCrudController.php
src/Controller/Admin/SeoFactCrudController.php
src/Controller/Admin/SeoPageCrudController.php
src/Controller/Admin/SeoPromptTemplateCrudController.php
src/Controller/Admin/SeoGenerationRunCrudController.php
src/Controller/Admin/SeoWorkflowController.php
```

Public:

```text
src/Controller/SeoProgrammaticController.php
templates/pages/seo_programmatic/show.html.twig
```

Batch:

```text
src/Command/SeoGenerateCommand.php
```

## Strategie conseillee

Commencer petit:

1. Ajouter 10 a 20 faits verifies solides.
2. Creer 5 seeds prioritaires.
3. Generer les pages.
4. Relire et publier seulement les meilleures.
5. Suivre les impressions, clics et conversions.
6. Enrichir les faits verifies avant de scaler.

Le module est volontairement semi-automatique. C'est ce qui permet de faire du SEO programmatique performant sans tomber dans des pages dupliquees ou trop generiques.
