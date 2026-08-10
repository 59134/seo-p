<?php

namespace App\Service;

use App\Entity\SeoGenerationRun;
use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClaudeSeoGenerator
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private SeoPromptBuilder $promptBuilder,
        private SeoQualityScorer $qualityScorer,
        private SeoPageImageResolver $imageResolver
    ) {
    }

    public function generate(SeoSeed $seed, ?string $modelPreference = null): SeoPage
    {
        return $this->generateFromSeed($seed, $modelPreference);
    }

    public function improvePage(SeoPage $page, ?string $modelPreference = null): SeoPage
    {
        $seed = $page->getSeed();

        if (!$seed) {
            throw new \RuntimeException('Impossible d optimiser cette page: aucun seed SEO n est lie.');
        }

        return $this->generateFromSeed($seed, $modelPreference, $page);
    }

    public function resolveModelForSeed(SeoSeed $seed, ?string $modelPreference = null): string
    {
        $modelPreference = $this->normalizeModelPreference($modelPreference ?? $seed->getClaudeModelPreference());

        if (str_starts_with($modelPreference, 'claude-')) {
            return $modelPreference;
        }

        return match ($modelPreference) {
            SeoSeed::CLAUDE_MODEL_SONNET => $this->sonnetModel(),
            SeoSeed::CLAUDE_MODEL_SONNET_5 => $this->sonnet5Model(),
            SeoSeed::CLAUDE_MODEL_OPUS => $this->opusModel(),
            SeoSeed::CLAUDE_MODEL_FABLE => $this->fableModel(),
            'premium' => $this->premiumModel(),
            default => $this->autoModel($seed),
        };
    }

    private function generateFromSeed(SeoSeed $seed, ?string $modelPreference = null, ?SeoPage $pageToImprove = null): SeoPage
    {
        $prompt = $this->promptBuilder->build($seed);

        if ($pageToImprove) {
            $prompt = $this->withExistingPageContext($prompt, $pageToImprove);
        }

        $model = $this->resolveModelForSeed($seed, $modelPreference);
        $apiKey = $_ENV['CLAUDE_API_KEY'] ?? $_SERVER['CLAUDE_API_KEY'] ?? '';
        $payload = $this->buildRequestPayload($model, $prompt);

        $run = (new SeoGenerationRun())
            ->setSeed($seed)
            ->setModel($model)
            ->setPromptHash(hash('sha256', json_encode($prompt, JSON_UNESCAPED_UNICODE)))
            ->setRequestPayload($this->redactPayloadForStorage($payload));

        $this->entityManager->persist($run);

        try {
            if (!$apiKey) {
                throw new \RuntimeException('CLAUDE_API_KEY absent.');
            } else {
                $response = $this->httpClient->request('POST', self::API_URL, [
                    'headers' => [
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => $this->intEnv('CLAUDE_TIMEOUT_SECONDS', 180, 30, 300),
                ]);

                $responsePayload = $response->toArray(false);
                $run->setResponsePayload($responsePayload);
                $generated = $this->extractToolInput($responsePayload);

                $run->setStatus(SeoGenerationRun::STATUS_SUCCESS);
                $run->setInputTokens($responsePayload['usage']['input_tokens'] ?? null);
                $run->setOutputTokens($responsePayload['usage']['output_tokens'] ?? null);
            }
        } catch (\Throwable $exception) {
            if ($pageToImprove) {
                $run->setStatus(SeoGenerationRun::STATUS_ERROR);
                $run->setErrorMessage($exception->getMessage());
                $this->entityManager->flush();

                throw $exception;
            }

            $generated = $this->fallbackPayload($seed, 'Erreur Claude: ' . $exception->getMessage());
            $run->setStatus(SeoGenerationRun::STATUS_ERROR);
            $run->setErrorMessage($exception->getMessage());
        }

        $page = $this->hydratePage($seed, $generated, $pageToImprove);
        $this->imageResolver->resolve($page);
        $run->setPage($page);

        $this->entityManager->persist($page);
        $this->entityManager->flush();

        return $page;
    }

    private function buildRequestPayload(string $model, array $prompt): array
    {
        return [
            'model' => $model,
            'max_tokens' => $this->maxTokensForModel($model),
            'system' => $prompt['system'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt['user'],
                ],
            ],
            'tools' => [
                $this->promptBuilder->outputTool(),
            ],
            'tool_choice' => [
                'type' => 'tool',
                'name' => 'create_seo_page',
            ],
        ];
    }

    private function extractToolInput(array $responsePayload): array
    {
        foreach ($responsePayload['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'create_seo_page') {
                return $block['input'] ?? [];
            }
        }

        throw new \RuntimeException('Claude n\'a pas retourne l\'outil create_seo_page.');
    }

    private function hydratePage(SeoSeed $seed, array $payload, ?SeoPage $page = null): SeoPage
    {
        $page ??= new SeoPage();

        if ($page->getId()) {
            $payload = $this->preserveExistingStructuredContent($payload, $page);
        }

        $score = $this->qualityScorer->score($payload, $seed);

        $slug = $page->getId() && $page->getSlug()
            ? $page->getSlug()
            : $this->uniqueSlug($this->slugify($payload['slug'] ?? $seed->getMainKeyword()), $seed->getLocale());

        $page
            ->setSeed($seed)
            ->setLocale($seed->getLocale())
            ->setSlug($slug)
            ->setTitle($payload['title'] ?? $seed->getMainKeyword())
            ->setMetaDescription($payload['meta_description'] ?? $seed->getMainKeyword())
            ->setH1($payload['h1'] ?? $seed->getMainKeyword())
            ->setIntro($payload['intro'] ?? null)
            ->setContent($payload['sections'] ?? [])
            ->setFaq($payload['faq'] ?? [])
            ->setSchemaJson($payload['schema_json_ld'] ?? [])
            ->setInternalLinks($this->internalLinksWithSeedLinks($seed, $payload['internal_links'] ?? []))
            ->setTemplateCopy($payload['template_copy'] ?? [])
            ->setImageAltSuggestions($payload['image_alt_suggestions'] ?? [])
            ->setCta($payload['cta'] ?? null)
            ->setQualityFlags($score['flags'])
            ->setMissingData($score['missing_data'])
            ->setRawClaudeResponse(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
            ->setStatus($score['indexable'] ? SeoPage::STATUS_REVIEW : SeoPage::STATUS_DRAFT)
            ->setIndexable(false)
            ->setMainKeyword($seed->getMainKeyword())
            ->setQualityScore($score['score'])
            ->setGeneratedAt(new \DateTimeImmutable());

        return $page;
    }

    private function preserveExistingStructuredContent(array $payload, SeoPage $page): array
    {
        if (!$this->hasStructuredItems($payload['sections'] ?? null, ['h2', 'body'], 3) && $page->getContent() !== []) {
            $payload['sections'] = $page->getContent();
            $payload['quality_flags'][] = 'Claude a renvoyé des sections vides ou incomplètes pendant l optimisation: les sections existantes ont été conservées.';
        }

        if (!$this->hasStructuredItems($payload['faq'] ?? null, ['question', 'answer'], 3) && $page->getFaq() !== []) {
            $payload['faq'] = $page->getFaq();
            $payload['quality_flags'][] = 'Claude a renvoyé une FAQ vide ou incomplète pendant l optimisation: la FAQ existante a été conservée.';
        }

        if (!is_array($payload['template_copy'] ?? null) && $page->getTemplateCopy() !== []) {
            $payload['template_copy'] = $page->getTemplateCopy();
            $payload['quality_flags'][] = 'Claude n a pas renvoyé les textes template pendant l optimisation: les textes existants ont été conservés.';
        }

        return $payload;
    }

    /**
     * @param string[] $requiredKeys
     */
    private function hasStructuredItems(mixed $items, array $requiredKeys, int $minimum): bool
    {
        if (!is_array($items) || count($items) < $minimum) {
            return false;
        }

        $validItems = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ($requiredKeys as $key) {
                if (trim((string) ($item[$key] ?? '')) === '') {
                    continue 2;
                }
            }

            $validItems++;
        }

        return $validItems >= $minimum;
    }

    private function internalLinksWithSeedLinks(SeoSeed $seed, array $internalLinks): array
    {
        $links = [];

        foreach ($internalLinks as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = trim((string) ($link['url'] ?? ''));
            $label = trim((string) ($link['label'] ?? ''));

            if ($url === '') {
                continue;
            }

            $links[] = [
                'label' => $label !== '' ? $label : $url,
                'url' => $url,
            ];
        }

        if ($seed->getServicePageUrl()) {
            $links[] = [
                'label' => $seed->getServicePageLabel() ?: $this->defaultServicePageLabel($seed),
                'url' => $seed->getServicePageUrl(),
            ];
        }

        $uniqueLinks = [];
        foreach ($links as $link) {
            $key = strtolower((string) $link['url']);
            $uniqueLinks[$key] = $link;
        }

        return array_values($uniqueLinks);
    }

    private function defaultServicePageLabel(SeoSeed $seed): string
    {
        $service = trim((string) $seed->getService());

        return $service !== '' ? ucfirst($service) : 'Voir la prestation';
    }

    private function fallbackPayload(SeoSeed $seed, string $flag): array
    {
        $location = trim(($seed->getCity() ?: '') . ' ' . ($seed->getDepartment() ? '(' . $seed->getDepartment() . ')' : ''));
        $service = $seed->getService() ?: $seed->getMainKeyword();
        $slug = trim($service . ' ' . $seed->getCity());

        return [
            'slug' => $slug ?: $seed->getMainKeyword(),
            'title' => substr($seed->getMainKeyword() . ' | Page SEO a completer', 0, 60),
            'meta_description' => 'Brouillon SEO a completer avec les donnees metier avant publication.',
            'h1' => $seed->getMainKeyword(),
            'intro' => sprintf('Cette page prepare le contenu SEO pour %s %s. Elle doit etre enrichie avec des faits verifies avant publication.', $service, $location),
            'sections' => [
                [
                    'h2' => 'Besoin utilisateur',
                    'body' => $seed->getIntent() ?: 'Preciser ici le probleme principal de l utilisateur et le contexte local.',
                ],
                [
                    'h2' => 'Intervention et accompagnement',
                    'body' => 'Ajouter le processus reel, les limites, les preuves et les informations pratiques avant publication.',
                ],
                [
                    'h2' => 'Pourquoi cette page doit etre revue',
                    'body' => $flag,
                ],
            ],
            'faq' => [
                ['question' => 'Cette page est-elle prete a etre indexee ?', 'answer' => 'Non, elle doit etre completee et validee manuellement.'],
                ['question' => 'Quelles donnees faut-il ajouter ?', 'answer' => 'Ajoutez des faits locaux, des preuves metier, les services reels et les limites.'],
                ['question' => 'Quand publier ?', 'answer' => 'Publiez uniquement si le score qualite est suffisant et les informations verifiees.'],
            ],
            'cta' => 'Demander un devis',
            'internal_links' => [],
            'template_copy' => [
                'hero_points' => [
                    'Réponse claire avant engagement',
                    'Accompagnement adapté à votre besoin',
                    $seed->getCity() ? 'Présence locale à ' . $seed->getCity() : 'Présence locale',
                ],
                'situation_title' => 'Un point de départ clair pour votre recherche.',
                'situation_text' => "Cette page rassemble les informations utiles pour comprendre le service, le secteur et les prochaines étapes avant de contacter l'entreprise.",
                'situation_bullets' => [
                    'Vous recherchez une prestation précise et localisée.',
                    "Vous voulez comprendre les étapes avant de contacter l'entreprise.",
                    $seed->getCity() ? 'Votre demande concerne ' . $seed->getCity() . ' ou un secteur proche.' : 'Votre demande concerne le secteur couvert.',
                    'Vous préférez transmettre les bonnes informations dès le premier échange.',
                ],
                'flow_title' => 'Comment ça se passe concrètement ?',
                'flow_text' => 'Le parcours reste volontairement court pour éviter les échanges inutiles et avancer avec les bonnes informations dès le départ.',
                'steps' => [
                    [
                        'title' => 'Vous décrivez votre besoin',
                        'text' => 'Vous indiquez le service recherché, le secteur concerné et les informations utiles pour comprendre votre demande.',
                    ],
                    [
                        'title' => "L'équipe vous recontacte",
                        'text' => "L'échange sert à comprendre votre situation, vos contraintes et le niveau d'urgence réel.",
                    ],
                    [
                        'title' => 'Vous avancez avec une suite claire',
                        'text' => "La réponse proposée dépend de votre situation. Les éléments incertains restent à confirmer avec l'entreprise.",
                    ],
                ],
                'content_eyebrow' => 'À savoir',
                'content_title' => 'Les points utiles avant de faire votre demande',
                'content_text' => 'Ces informations complètent votre décision avec un angle local, pratique et adapté à la prestation recherchée.',
                'final_eyebrow' => 'Prochaine étape',
                'final_title' => 'Voyons si votre demande peut avancer simplement.',
                'final_text' => "Remplissez le formulaire, indiquez votre secteur et votre besoin. L'équipe vous recontacte pour étudier la suite avec vous.",
            ],
            'image_alt_suggestions' => [],
            'schema_json_ld' => [],
            'quality_flags' => [$flag],
            'indexation_recommendation' => 'review',
            'missing_data' => ['generation_claude', 'faits_locaux', 'preuves_metier'],
        ];
    }

    private function slugify(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value = preg_replace('/[^a-zA-Z0-9]+/', '-', $value ?: '');
        $value = strtolower(trim((string) $value, '-'));

        return $value ?: uniqid('seo-page-', false);
    }

    private function uniqueSlug(string $slug, string $locale): string
    {
        $candidate = $slug;
        $suffix = 2;

        while ($this->entityManager->getRepository(SeoPage::class)->findOneBy(['slug' => $candidate, 'locale' => $locale])) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeModelPreference(?string $modelPreference): string
    {
        $modelPreference = trim((string) $modelPreference);

        if ($modelPreference === '') {
            return SeoSeed::CLAUDE_MODEL_AUTO;
        }

        if (str_starts_with($modelPreference, 'claude-')) {
            return $modelPreference;
        }

        return in_array($modelPreference, array_merge(SeoSeed::CLAUDE_MODEL_PREFERENCES, ['premium']), true)
            ? $modelPreference
            : SeoSeed::CLAUDE_MODEL_AUTO;
    }

    private function autoModel(SeoSeed $seed): string
    {
        if ($seed->getBusinessValue() >= 80 || $seed->getPriority() >= 80) {
            return $this->premiumModel();
        }

        return $this->normalModel();
    }

    private function normalModel(): string
    {
        return $this->env('CLAUDE_MODEL', $this->sonnetModel());
    }

    private function sonnetModel(): string
    {
        return $this->env('CLAUDE_MODEL_SONNET', 'claude-sonnet-4-6');
    }

    private function sonnet5Model(): string
    {
        return $this->env('CLAUDE_MODEL_SONNET_5', 'claude-sonnet-5');
    }

    private function opusModel(): string
    {
        return $this->env('CLAUDE_MODEL_OPUS', 'claude-opus-4-8');
    }

    private function fableModel(): string
    {
        return $this->env('CLAUDE_MODEL_FABLE', 'claude-fable-5');
    }

    private function premiumModel(): string
    {
        return $this->env('CLAUDE_MODEL_PREMIUM', $this->opusModel());
    }

    private function maxTokensForModel(string $model): int
    {
        $normalizedModel = strtolower($model);

        if (str_contains($normalizedModel, 'opus')) {
            return $this->intEnv('CLAUDE_MAX_TOKENS_OPUS', 9000, 1000, 20000);
        }

        if (str_contains($normalizedModel, 'fable')) {
            return $this->intEnv('CLAUDE_MAX_TOKENS_FABLE', 9000, 1000, 20000);
        }

        if (str_contains($normalizedModel, 'sonnet')) {
            return $this->intEnv('CLAUDE_MAX_TOKENS_SONNET', 7000, 1000, 20000);
        }

        return $this->intEnv('CLAUDE_MAX_TOKENS_DEFAULT', 7000, 1000, 20000);
    }

    private function intEnv(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        if (!is_numeric($value)) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }

    private function env(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function withExistingPageContext(array $prompt, SeoPage $page): array
    {
        $currentPage = [
            'title' => $page->getTitle(),
            'meta_description' => $page->getMetaDescription(),
            'h1' => $page->getH1(),
            'intro' => $page->getIntro(),
            'sections' => $page->getContent(),
            'faq' => $page->getFaq(),
            'template_copy' => $page->getTemplateCopy(),
            'cta' => $page->getCta(),
            'quality_score' => $page->getQualityScore(),
            'quality_flags' => $page->getQualityFlags(),
            'missing_data' => $page->getMissingData(),
        ];

        $prompt['user'] .= "\n\nPage actuelle a optimiser:\n";
        $prompt['user'] .= json_encode($currentPage, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt['user'] .= "\n\nObjectif: ameliorer cette page sans inventer d'informations, corriger les faiblesses SEO, renforcer la valeur locale et retourner uniquement l'appel d'outil demande.";

        return $prompt;
    }

    private function redactPayloadForStorage(array $payload): array
    {
        unset($payload['messages'][0]['content']);
        $payload['messages'][0]['content_preview'] = 'Prompt masque. Voir le contexte via les seeds et faits SEO.';

        return $payload;
    }
}
