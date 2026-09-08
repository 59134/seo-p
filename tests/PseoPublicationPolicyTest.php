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
    public function testHistoricAndStructuredAlertsAreClassified(string $issue, bool $blocking): void
    {
        $result = SeoPublicationPolicy::classify([$issue]);
        self::assertSame([$issue], $result[$blocking ? 'blocking' : 'advisory']);
        self::assertSame([], $result[$blocking ? 'advisory' : 'blocking']);
    }

    public static function issues(): array
    {
        return [
            ["Faits locaux insuffisants concernant spécifiquement l'usage du placo dans le bâti de Valenciennes au-delà de l'ancienneté générale du parc de logements.", false],
            ["Aucun fait local propre à Denain sur l'état des toitures ou le bâti n'est documenté (le contexte minier disponible concerne l'habitat général, pas spécifiquement la toiture)", false],
            ["Aucun fait local spécifique documenté sur le parc de chauffage à Valenciennes (types d'énergie, ancienneté des installations)", false],
            ["Faits locaux insuffisants: aucune donnée spécifique documentée sur l'habitat ou le contexte de Raismes en dehors de la mention du quartier Sabatier (bassin minier UNESCO), non utilisée ici par prudence car trop générale pour être développée sans invention", true],
            ['Faits locaux insuffisants', true],
            ['[amelioration] Statistiques locales sur le type de bâti non disponibles, non citées dans le texte.', false],
            ['[bloquant] Faits locaux insuffisants', true],
            ['[bloquant] Impossible de justifier la page par les faits fournis', true],
            ['Service non confirmé ; prix à compléter', true],
            ['[amelioration] Service non confirmé, adresse et téléphone manquants', true],
            ['Zone non couverte, tarif absent', true],
            ['Ville non couverte', true],
            ['GENERATION_CLAUDE_ECHOUEE', true],
            ['Erreur Claude : délai dépassé', true],
            ['Entreprise inconnue', true],
            ['Preuves métier insuffisantes', true],
            ['Données métier insuffisantes', true],
            ['[amelioration] Informations inventées encore présentes dans le contenu', true],
            ['Contenu entièrement interchangeable entre villes', true],
            ['Page trop générique', true],
            ['[amelioration] Absence totale de faits locaux ; statistiques non fournies', true],
            ['Aucune matière locale utile pour distinguer la page', true],
            ['Téléphone non fourni', false],
            ['Certification non documentée, ne pas la promettre', false],
            ['Prix non communiqué, ne pas inventer de montant', false],
            ['[amelioration] Aucun fait local sur les statistiques des installations', false],
        ];
    }

    public function testCriticalAndOptionalLinesStaySeparateAndAreNotDiscarded(): void
    {
        self::assertSame([
            'blocking' => ['Service non confirmé'],
            'advisory' => ['Téléphone manquant'],
        ], SeoPublicationPolicy::classify(['Téléphone manquant', 'Service non confirmé', 'Téléphone manquant', '', null]));
    }

    private function scorer(): SeoQualityScorer
    {
        $advisor = $this->createMock(SeoEditorialAdvisor::class);
        $advisor->method('warnings')->willReturn([]);
        return new SeoQualityScorer($advisor);
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
