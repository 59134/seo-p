<?php

use App\Entity\SeoPage;
use App\Entity\SeoSeed;
use App\Service\SeoEditorialAdvisor;
use App\Service\SeoPublicationPolicy;
use App\Service\SeoQualityScorer;
use PHPUnit\Framework\TestCase;

final class PseoPublicationPolicyTest extends TestCase
{
    /** @dataProvider issues */
    public function testHistoricAndStructuredAlertsAreClassified(string $issue, string $severity): void
    {
        $result = SeoPublicationPolicy::classify([$issue]);
        foreach (['blocking', 'review', 'advisory'] as $key) {
            self::assertSame($key === $severity ? [$issue] : [], $result[$key]);
        }
    }

    public static function issues(): array
    {
        return [
            ["Faits locaux insuffisants concernant spécifiquement l'usage du placo dans le bâti de Valenciennes au-delà de l'ancienneté générale du parc de logements.", 'advisory'],
            ["Aucun fait local propre à Denain sur l'état des toitures ou le bâti n'est documenté (le contexte minier disponible concerne l'habitat général, pas spécifiquement la toiture)", 'advisory'],
            ["Aucun fait local spécifique documenté sur le parc de chauffage à Valenciennes (types d'énergie, ancienneté des installations)", 'advisory'],
            ["Faits locaux insuffisants: aucune donnée spécifique documentée sur l'habitat ou le contexte de Raismes en dehors de la mention du quartier Sabatier (bassin minier UNESCO), non utilisée ici par prudence car trop générale pour être développée sans invention", 'review'],
            ["Faits locaux spécifiques à Valenciennes pour la maçonnerie (types de bâti concernés, contraintes locales) non documentés au-delà des données démographiques générales déjà utilisées sur d'autres pages", 'review'],
            ['Faits locaux spécifiques à Valenciennes : types de bâti et contraintes locales non documentés.', 'advisory'],
            ['Faits locaux insuffisants', 'review'],
            ['[amelioration] Statistiques locales sur le type de bâti non disponibles, non citées dans le texte.', 'advisory'],
            ['[bloquant] Faits locaux insuffisants', 'review'],
            ['[bloquant] Faits locaux : typologie de bâti non documentée', 'advisory'],
            ['[bloquant] Impossible de justifier la page par les faits fournis', 'blocking'],
            ['Service non confirmé ; prix à compléter', 'blocking'],
            ['[amelioration] Service non confirmé, adresse et téléphone manquants', 'blocking'],
            ['[relecture] Service non documenté, contraintes locales inconnues', 'blocking'],
            ['[relecture] Information inventée dans le texte', 'blocking'],
            ['Zone non couverte, tarif absent', 'blocking'],
            ['Ville non couverte', 'blocking'],
            ['GENERATION_CLAUDE_ECHOUEE', 'blocking'],
            ['Erreur Claude : délai dépassé', 'blocking'],
            ['Entreprise inconnue', 'blocking'],
            ['Preuves métier insuffisantes', 'review'],
            ['Données métier insuffisantes', 'blocking'],
            ['[amelioration] Informations inventées encore présentes dans le contenu', 'blocking'],
            ['Contenu entièrement interchangeable entre villes', 'review'],
            ['Page trop générique', 'review'],
            ['[amelioration] Absence totale de faits locaux ; statistiques non fournies', 'review'],
            ['Aucune matière locale utile pour distinguer la page', 'review'],
            ['[relecture] Vérifier la pertinence de la FAQ', 'review'],
            ['[relecture] Vérifier les faits locaux sur le parc de chauffage', 'review'],
            ['[relecture] JSON invalide', 'blocking'],
            ['Téléphone non fourni', 'advisory'],
            ['Certification non documentée, ne pas la promettre', 'advisory'],
            ['Prix non communiqué, ne pas inventer de montant', 'advisory'],
            ['[amelioration] Aucun fait local sur les statistiques des installations', 'advisory'],
        ];
    }

    public function testCriticalAndOptionalLinesStaySeparateAndAreNotDiscarded(): void
    {
        self::assertSame([
            'blocking' => ['Service non confirmé'],
            'review' => [],
            'advisory' => ['Téléphone manquant'],
        ], SeoPublicationPolicy::classify(['Téléphone manquant', 'Service non confirmé', 'Téléphone manquant', '', null]));
    }

    private function scorer(): SeoQualityScorer
    {
        $advisor = $this->createMock(SeoEditorialAdvisor::class);
        $advisor->method('warnings')->willReturn([]);
        return new SeoQualityScorer($advisor);
    }

    public function testReviewDoesNotAutomaticallyMakeGeneratedContentIndexable(): void
    {
        $page = self::completePage()->setMissingData(['Faits locaux insuffisants']);
        $result = $this->scorer()->scorePage($page);
        self::assertSame(90, $result['score']);
        self::assertFalse($result['indexable']);
        self::assertContains('Une relecture editoriale doit etre confirmee avant publication.', $result['flags']);
        self::assertSame(['Faits locaux insuffisants'], $page->getMissingData());
    }

    public static function completePage(): SeoPage
    {
        $seed = (new SeoSeed())->setMainKeyword('Rénovation Valenciennes')->setService('Rénovation')->setCity('Valenciennes')
            ->setDepartment('Nord')->setIntent('Préparer un projet adapté aux besoins')->setNotes('Zone validée, matériaux confirmés.')
            ->setSecondaryKeywords([]);
        $page = (new SeoPage())->setSeed($seed)->setMainKeyword('Rénovation Valenciennes')->setSlug('renovation-valenciennes')
            ->setTitle('Préparer vos travaux intérieurs à Valenciennes')
            ->setMetaDescription(str_repeat('Des informations utiles pour préparer vos travaux. ', 3))
            ->setH1('Accompagner votre projet à Valenciennes')
            ->setIntro(str_repeat('Le projet est préparé à partir de vos besoins et des prestations confirmées. ', 4))
            ->setContent(array_map(static fn (string $title): array => ['h2' => $title, 'body' => str_repeat('Les prestations confirmées permettent de préciser le projet et les étapes à organiser. ', 3)], ['Définir votre projet', 'Préparer les travaux', 'Planifier les étapes']))
            ->setFaq(array_fill(0, 3, ['question' => 'Comment préparer le projet ?', 'answer' => 'Rassembler les besoins et les informations disponibles.']));
        return $page;
    }

    public function testScoreIsOutOf100AndMissingDataCannotBeHiddenByExtraPoints(): void
    {
        $page = self::completePage();
        $scorer = $this->scorer();
        self::assertSame(100, $scorer->scorePage($page)['score']);
        $page->setMissingData(['Faits locaux insuffisants concernant les statistiques techniques du bâti']);
        self::assertSame(90, $scorer->scorePage($page)['score']);
        self::assertTrue($scorer->scorePage($page)['indexable']);
        $page->setMissingData(['Service non confirmé']);
        self::assertSame(90, $scorer->scorePage($page)['score']);
        self::assertFalse($scorer->scorePage($page)['indexable']);
        $page->setMissingData(['Téléphone manquant', 'Prix manquant', 'Statistiques techniques locales absentes', 'Horaires absents']);
        self::assertTrue($scorer->scorePage($page)['indexable']);
    }

    public function testEmptySectionsDoNotEarnSubstancePoints(): void
    {
        $page = self::completePage()->setContent([])->setMissingData(['Téléphone manquant']);
        self::assertSame(70, $this->scorer()->scorePage($page)['score']);
        self::assertFalse($this->scorer()->scorePage($page)['indexable']);
    }

    public function testListRecalculationIsReadOnlyAndDoesNotQueryComparisonPages(): void
    {
        $advisor = $this->createMock(SeoEditorialAdvisor::class);
        $advisor->expects(self::never())->method('warnings');
        $page = self::completePage()->setQualityScore(12)->setMissingData(['Téléphone manquant']);
        self::assertSame(90, (new SeoQualityScorer($advisor))->scorePage($page, false)['score']);
        self::assertSame(12, $page->getQualityScore());
        self::assertSame(['Téléphone manquant'], $page->getMissingData());
        self::assertSame(SeoPage::STATUS_DRAFT, $page->getStatus());
    }
}
