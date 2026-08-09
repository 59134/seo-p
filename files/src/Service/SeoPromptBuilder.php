<?php

namespace App\Service;

use App\Entity\SeoSeed;
use App\Repository\ConfigAdminRepository;
use App\Repository\CoordonneeRepository;
use App\Repository\SeoFactRepository;
use App\Repository\SeoPageRepository;
use App\Repository\SeoPromptTemplateRepository;

class SeoPromptBuilder
{
    public function __construct(
        private SeoFactRepository $seoFactRepository,
        private SeoPromptTemplateRepository $seoPromptTemplateRepository,
        private ConfigAdminRepository $configAdminRepository,
        private CoordonneeRepository $coordonneeRepository,
        private SeoPageRepository $seoPageRepository
    ) {
    }

    public function build(SeoSeed $seed): array
    {
        $context = $this->buildContext($seed);
        $template = $this->seoPromptTemplateRepository->findActive('service_city', $seed->getLocale());

        $system = $template?->getSystemPrompt() ?: $this->defaultSystemPrompt();
        $user = $template?->getUserPrompt() ?: $this->defaultUserPrompt();

        if (!str_contains($user, 'anti_duplication')) {
            $user .= "\n\n" . $this->antiDuplicationUserPromptAddon();
        }

        if (!str_contains($user, 'secondary_keywords')) {
            $user .= "\n\n" . $this->secondaryKeywordsUserPromptAddon();
        }

        if (!str_contains($user, 'template_copy')) {
            $user .= "\n\n" . $this->templateCopyUserPromptAddon();
        }

        return [
            'system' => $system,
            'user' => str_replace('{{context_json}}', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $user),
            'context' => $context,
        ];
    }

    public function outputTool(): array
    {
        return [
            'name' => 'create_seo_page',
            'description' => 'Retourne une page SEO programmatique sous forme de donnees structurees validables.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'slug',
                    'title',
                    'meta_description',
                    'h1',
                    'intro',
                    'sections',
                    'faq',
                    'cta',
                    'template_copy',
                    'internal_links',
                    'image_alt_suggestions',
                    'schema_json_ld',
                    'quality_flags',
                    'indexation_recommendation',
                    'missing_data',
                ],
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Slug URL court, sans accent, en minuscules.'],
                    'title' => ['type' => 'string', 'description' => 'Title SEO, idealement 45 a 60 caracteres.'],
                    'meta_description' => ['type' => 'string', 'description' => 'Meta description, idealement 130 a 155 caracteres.'],
                    'h1' => ['type' => 'string'],
                    'intro' => ['type' => 'string'],
                    'sections' => [
                        'type' => 'array',
                        'minItems' => 3,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['h2', 'body'],
                            'properties' => [
                                'h2' => [
                                    'type' => 'string',
                                    'description' => 'Titre H2 naturel. Si des mots clés secondaires existent, intégrer une expression secondaire ou complémentaire. Un seul H2 maximum peut reprendre le mot clé principal identique au H1.',
                                ],
                                'body' => [
                                    'type' => 'string',
                                    'description' => 'Texte utile qui intègre naturellement des mots clés secondaires lorsque le contexte le permet, sans bourrage.',
                                ],
                            ],
                        ],
                    ],
                    'faq' => [
                        'type' => 'array',
                        'minItems' => 3,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['question', 'answer'],
                            'properties' => [
                                'question' => ['type' => 'string'],
                                'answer' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'cta' => [
                        'type' => 'string',
                        'maxLength' => 38,
                        'description' => 'Libellé court de bouton uniquement, 2 à 4 mots. Exemple: Faire une demande, Tester mon éligibilité, Demander un devis. Ne jamais mettre une phrase complète.',
                    ],
                    'template_copy' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'hero_points',
                            'situation_title',
                            'situation_text',
                            'situation_bullets',
                            'flow_title',
                            'flow_text',
                            'steps',
                            'content_eyebrow',
                            'content_title',
                            'content_text',
                            'final_eyebrow',
                            'final_title',
                            'final_text',
                        ],
                        'properties' => [
                            'hero_points' => [
                                'type' => 'array',
                                'minItems' => 3,
                                'maxItems' => 3,
                                'items' => ['type' => 'string', 'maxLength' => 95],
                            ],
                            'situation_title' => ['type' => 'string', 'maxLength' => 95],
                            'situation_text' => ['type' => 'string', 'maxLength' => 360],
                            'situation_bullets' => [
                                'type' => 'array',
                                'minItems' => 3,
                                'maxItems' => 4,
                                'items' => ['type' => 'string', 'maxLength' => 120],
                            ],
                            'flow_title' => ['type' => 'string', 'maxLength' => 90],
                            'flow_text' => ['type' => 'string', 'maxLength' => 260],
                            'steps' => [
                                'type' => 'array',
                                'minItems' => 3,
                                'maxItems' => 3,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['title', 'text'],
                                    'properties' => [
                                        'title' => ['type' => 'string', 'maxLength' => 80],
                                        'text' => ['type' => 'string', 'maxLength' => 260],
                                    ],
                                ],
                            ],
                            'content_eyebrow' => ['type' => 'string', 'maxLength' => 35],
                            'content_title' => ['type' => 'string', 'maxLength' => 95],
                            'content_text' => ['type' => 'string', 'maxLength' => 260],
                            'final_eyebrow' => ['type' => 'string', 'maxLength' => 35],
                            'final_title' => ['type' => 'string', 'maxLength' => 95],
                            'final_text' => ['type' => 'string', 'maxLength' => 260],
                        ],
                    ],
                    'internal_links' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['label', 'url'],
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'image_alt_suggestions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'schema_json_ld' => [
                        'type' => 'object',
                        'description' => 'JSON-LD indicatif. Doit proposer un @graph avec WebPage, Service, LocalBusiness si les donnees entreprise/adresse existent, et FAQPage uniquement si la FAQ est visible et non vide.',
                    ],
                    'quality_flags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'indexation_recommendation' => [
                        'type' => 'string',
                        'enum' => ['index', 'noindex', 'review'],
                    ],
                    'missing_data' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    private function buildContext(SeoSeed $seed): array
    {
        $facts = [];
        foreach ($this->seoFactRepository->findActiveForPrompt($seed->getLocale()) as $fact) {
            $facts[$fact->getType()][] = [
                'name' => $fact->getName(),
                'content' => $fact->getContent(),
                'priority' => $fact->getPriority(),
            ];
        }

        $config = $this->configAdminRepository->find(1);
        $coordonnee = $this->coordonneeRepository->find(1);

        return [
            'business' => [
                'name' => $config?->getRaisonSociale(),
                'city_reference' => $config?->getVilleOpti(),
                'contact_url' => $config?->getLienContact(),
                'phone' => $coordonnee?->getTel1(),
                'email' => $coordonnee?->getEmail(),
                'address' => [
                    'street' => $coordonnee?->getAdresse(),
                    'postal_code' => $coordonnee?->getCp(),
                    'city' => $coordonnee?->getVille(),
                    'country' => $coordonnee?->getPays() ?: 'FR',
                ],
                'verified_facts' => $facts,
            ],
            'page_intent' => [
                'service' => $seed->getService(),
                'city' => $seed->getCity(),
                'department' => $seed->getDepartment(),
                'main_keyword' => $seed->getMainKeyword(),
                'linked_service_page' => [
                    'url' => $seed->getServicePageUrl(),
                    'label' => $seed->getServicePageLabel() ?: $this->defaultServicePageLabel($seed),
                    'usage' => 'Add this page to internal_links when the URL is provided. It is the main service pillar page linked to this local SEO page.',
                ],
                'secondary_keywords' => $seed->getSecondaryKeywords(),
                'user_problem' => $seed->getIntent(),
                'business_value' => $seed->getBusinessValue(),
                'data_completeness_score' => $seed->getDataCompletenessScore(),
                'notes' => $seed->getNotes(),
                'locale' => $seed->getLocale(),
            ],
            'seo_rules' => [
                'do_not_invent' => [
                    'certifications',
                    'prices',
                    'response times',
                    'reviews',
                    'guarantees',
                    'covered cities',
                    'brands',
                ],
                'required_value' => [
                    'local usefulness',
                    'clear service explanation',
                    'conversion-oriented CTA',
                    'specific FAQ',
                    'internal links only if they are relevant',
                ],
                'secondary_keywords_usage' => [
                    'Use secondary keywords naturally in section headings and body copy when provided.',
                    'At most one H2 may repeat the exact main keyword or H1.',
                    'Other H2 headings must use secondary or complementary expressions.',
                    'Do not stuff keywords; adapt wording naturally to the user intent.',
                ],
                'publication_rule' => 'Recommend noindex or review if local facts or proof are too thin.',
                'structured_data' => [
                    'format' => 'JSON-LD',
                    'required_graph_nodes' => [
                        'WebPage for the current generated page',
                        'Service for the local service intent',
                        'LocalBusiness for the real business when name and address are available',
                        'FAQPage only when FAQ questions and answers are visible on the page',
                    ],
                    'do_not_invent' => [
                        'street address',
                        'postal code',
                        'phone number',
                        'opening hours',
                        'geo coordinates',
                        'reviews or aggregate ratings',
                    ],
                ],
            ],
            'anti_duplication' => [
                'target_editorial_similarity_percent' => 55,
                'maximum_editorial_similarity_percent' => 65,
                'objective' => 'Create a page that is meaningfully different from other generated local pages, even when the service is identical.',
                'must_vary' => [
                    'intro tied to the target city',
                    'local section',
                    'FAQ questions and answers',
                    'H2 headings',
                    'examples of customer situations',
                    'angle by commune',
                    'nearby districts or communes only when factually safe',
                    'SEO body text 1',
                    'SEO body text 2',
                    'conclusion',
                ],
                'avoid_reusing' => [
                    'same sentence structure',
                    'same introductions',
                    'same transitions',
                    'same examples',
                    'same H2 order',
                    'same FAQ wording',
                ],
                'existing_pages_to_differentiate_from' => $this->buildExistingPagesContext($seed),
            ],
        ];
    }

    private function buildExistingPagesContext(SeoSeed $seed): array
    {
        $pages = [];

        foreach ($this->seoPageRepository->findForAntiDuplicationPrompt($seed) as $page) {
            $seedContext = $page->getSeed();
            $sections = [];

            foreach (array_slice($page->getContent(), 0, 5) as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $sections[] = [
                    'h2' => $section['h2'] ?? null,
                    'body_excerpt' => $this->limitText((string) ($section['body'] ?? ''), 240),
                ];
            }

            $faqQuestions = [];
            foreach (array_slice($page->getFaq(), 0, 4) as $faq) {
                if (is_array($faq) && isset($faq['question'])) {
                    $faqQuestions[] = $faq['question'];
                }
            }

            $pages[] = [
                'main_keyword' => $page->getMainKeyword(),
                'city' => $seedContext?->getCity(),
                'service' => $seedContext?->getService(),
                'title' => $page->getTitle(),
                'h1' => $page->getH1(),
                'intro_excerpt' => $this->limitText((string) $page->getIntro(), 260),
                'section_patterns_to_avoid' => $sections,
                'faq_questions_to_avoid_copying' => $faqQuestions,
            ];
        }

        return $pages;
    }

    private function limitText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?: '');

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
            return mb_substr($text, 0, $limit) . '...';
        }

        if (!function_exists('mb_strlen') && strlen($text) > $limit) {
            return substr($text, 0, $limit) . '...';
        }

        return $text;
    }

    private function defaultServicePageLabel(SeoSeed $seed): string
    {
        $service = trim((string) $seed->getService());

        return $service !== '' ? ucfirst($service) : 'Voir la prestation';
    }

    private function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un expert SEO programmatique senior pour un site de services local.
Ton objectif est de creer une page utile pour un humain, pas une page fabriquee uniquement pour Google.
Tu dois respecter strictement les faits fournis. N'invente jamais de certification, prix, delai, avis client, zone couverte ou marque.
Si une information manque, ajoute-la dans missing_data et adapte le texte sans faire de promesse.
Ecris en francais naturel, precis, rassurant, sans bourrage de mots-cles.
Chaque page doit avoir une valeur propre: contexte local, probleme utilisateur, explication claire, preuves, FAQ utile et CTA.
Tu dois aussi lutter contre la duplication editoriale. Une page locale ne doit jamais etre une copie d'une autre page avec seulement la ville changee.
Retourne uniquement l'appel d'outil demande.
PROMPT;
    }

    private function defaultUserPrompt(): string
    {
        return <<<'PROMPT'
Genere une page SEO programmatique a partir du contexte JSON ci-dessous.

Contraintes:
- le slug doit etre court, stable, sans accent ni caractere special;
- tous les textes doivent etre en UTF-8 lisible avec de vrais accents francais. Ne jamais retourner d'entites HTML comme &eacute;, &agrave;, &Agrave;, &oelig; ou &amp;;
- le title doit rester lisible et non sur-optimise;
- la meta description doit donner envie de cliquer sans promesse inventee;
- l'intro doit parler du besoin de l'utilisateur;
- les sections doivent etre concretes, scannables et differentes les unes des autres;
- si page_intent.secondary_keywords contient des mots clés, utilise-les naturellement dans les H2 et les paragraphes;
- un seul H2 maximum peut reprendre le mot clé principal ou le H1 de manière identique;
- les autres H2 doivent varier avec des mots clés secondaires, des angles complémentaires ou des expressions de longue traîne;
- les textes de section doivent intégrer plusieurs mots clés secondaires de façon naturelle, sans liste artificielle ni bourrage;
- la FAQ doit repondre a de vraies questions de conversion;
- cta doit etre uniquement un libellé court de bouton, 2 à 4 mots, maximum 38 caractères. Ne mets jamais une phrase complète. Exemples: "Faire une demande", "Tester mon éligibilité", "Demander un devis";
- template_copy contient les textes courts du design. Ils doivent être génériques pour un site vitrine mais adaptés au service, à la ville et à l'intention. Ne mets pas de promesse inventée. Varie ces textes d'une page locale à l'autre;
- si page_intent.linked_service_page.url est renseigne, ajoute ce lien dans internal_links avec un libelle naturel;
- schema_json_ld doit contenir un @graph JSON-LD coherent avec WebPage, Service, LocalBusiness si les donnees entreprise/adresse existent, et FAQPage uniquement si la FAQ visible contient des questions/reponses;
- ne jamais inventer adresse, telephone, horaires, coordonnees geo, avis ou note dans schema_json_ld;
- indexation_recommendation doit etre "index" uniquement si la page a assez de valeur specifique.

Objectif anti-duplication:
- cree une page unique et suffisamment differente des autres pages deja generees;
- vise une similarite editoriale inferieure a 55 %;
- ne depasse jamais 65 % de similarite avec une autre page;
- ne reutilise pas la meme structure de phrases, les memes introductions, les memes transitions ni les memes exemples;
- conserve les faits verifies, mais reformule naturellement;
- si le contenu risque d'etre trop proche d'une page existante, change l'angle, les exemples, les H2 et la FAQ;
- ajoute une alerte dans quality_flags si les donnees fournies sont trop faibles pour differencier correctement la page.

Chaque page locale doit contenir:
- une intro unique liee a la ville cible;
- au moins une section locale vraiment differente;
- des H2 differents des pages existantes et construits avec des mots clés secondaires quand ils existent;
- aucun enchainement de H2 qui repete seulement le mot clé principal avec une ville différente;
- des exemples de situations differents;
- un angle different selon la commune;
- des quartiers ou communes proches uniquement si c'est factuellement prudent;
- deux textes SEO de corps de page vraiment reecrits, pas de simple reformulation minimale;
- une derniere section qui sert de conclusion differente.

Pour te differencier des pages existantes, utilise le bloc anti_duplication.existing_pages_to_differentiate_from du contexte:
- evite de reprendre leurs H1, intros, H2, transitions et FAQ;
- ne copie pas leur ordre de sections;
- varie le vocabulaire et la progression argumentative;
- adapte la page a la ville, a l'intention utilisateur et aux notes du seed.

Contexte:
{{context_json}}
PROMPT;
    }

    private function antiDuplicationUserPromptAddon(): string
    {
        return <<<'PROMPT'
Objectif anti-duplication obligatoire:
- cree une page unique et suffisamment differente des autres pages deja generees;
- vise une similarite editoriale inferieure a 55 %;
- ne depasse jamais 65 % de similarite avec une autre page;
- ne reutilise pas la meme structure de phrases, les memes introductions, les memes transitions ni les memes exemples;
- conserve les faits verifies, mais reformule naturellement;
- si le contenu risque d'etre trop proche d'une page existante, change l'angle, les exemples, les H2 et la FAQ;
- utilise le bloc anti_duplication.existing_pages_to_differentiate_from du contexte pour eviter les reprises;
- ajoute une alerte dans quality_flags si les donnees fournies sont trop faibles pour differencier correctement la page.
PROMPT;
    }

    private function secondaryKeywordsUserPromptAddon(): string
    {
        return <<<'PROMPT'
Utilisation obligatoire des mots clés secondaires:
- si page_intent.secondary_keywords contient des mots clés, utilise-les naturellement dans les H2, les paragraphes et certaines FAQ;
- un seul H2 maximum peut reprendre le mot clé principal ou le H1 de manière identique;
- les autres H2 doivent varier avec des mots clés secondaires, des angles complémentaires ou des expressions de longue traîne;
- les textes de section doivent intégrer plusieurs mots clés secondaires de façon naturelle, sans liste artificielle ni bourrage;
- si les mots clés secondaires sont trop proches les uns des autres, varie les formulations tout en conservant l'intention.
PROMPT;
    }

    private function templateCopyUserPromptAddon(): string
    {
        return <<<'PROMPT'
Textes dynamiques du template:
- remplis template_copy avec des textes courts, naturels et adaptés au service, à la ville et à l'intention utilisateur;
- ces textes servent uniquement à habiller le design, ils ne remplacent pas l'intro, les sections SEO ni la FAQ;
- hero_points: 3 bénéfices courts, concrets et compatibles avec les faits fournis;
- situation_title, situation_text et situation_bullets: expliquer pourquoi cette page aide l'utilisateur à se situer avant de contacter l'entreprise;
- flow_title, flow_text et steps: décrire un parcours simple sans inventer de délai, prix, garantie ou intervention non vérifiée;
- content_eyebrow, content_title et content_text: introduire les sections SEO générées;
- final_eyebrow, final_title et final_text: conclure avec une prochaine étape claire;
- évite les formulations trop spécifiques à un métier si elles ne sont pas confirmées par les faits vérifiés;
- tous ces textes doivent être en français avec accents lisibles, sans entités HTML.
PROMPT;
    }
}
