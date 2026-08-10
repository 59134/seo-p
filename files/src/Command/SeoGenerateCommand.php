<?php

namespace App\Command;

use App\Repository\ModuleRepository;
use App\Repository\SeoPageRepository;
use App\Repository\SeoSeedRepository;
use App\Service\ClaudeSeoGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seo:generate',
    description: 'Genere des pages SEO programmatiques depuis les seeds valides.'
)]
class SeoGenerateCommand extends Command
{
    public function __construct(
        private SeoSeedRepository $seoSeedRepository,
        private SeoPageRepository $seoPageRepository,
        private ModuleRepository $moduleRepository,
        private ClaudeSeoGenerator $claudeSeoGenerator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de seeds a traiter.', 10)
            ->addOption('minimum-completeness', null, InputOption::VALUE_REQUIRED, 'Score minimum de donnees du seed.', 50)
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Force le modele Claude sur tout le lot: auto, sonnet, opus, fable, premium ou un id claude-*. Vide = choix du seed.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (
            !$this->moduleRepository->findOneBy(['name' => 'SeoSeed', 'valid' => true])
            || !$this->moduleRepository->findOneBy(['name' => 'SeoPage', 'valid' => true])
        ) {
            $io->error('Les modules SeoSeed et SeoPage doivent etre actifs.');

            return Command::FAILURE;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $minimumCompleteness = max(0, min(100, (int) $input->getOption('minimum-completeness')));
        $modelPreference = $input->getOption('model');
        $modelPreference = is_string($modelPreference) && trim($modelPreference) !== '' ? trim($modelPreference) : null;
        $seeds = array_slice($this->seoSeedRepository->findReadyForGeneration($minimumCompleteness), 0, $limit);

        if (!$seeds) {
            $io->success('Aucun seed SEO pret a generer.');

            return Command::SUCCESS;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($seeds as $seed) {
            if ($this->seoPageRepository->findActiveForSeed($seed)) {
                $io->writeln(sprintf('[ignore] %s -> une page active existe deja', $seed->getMainKeyword()));
                $skipped++;
                continue;
            }

            $model = $this->claudeSeoGenerator->resolveModelForSeed($seed, $modelPreference);
            $page = $this->claudeSeoGenerator->generate($seed, $modelPreference);
            $generated++;
            $io->writeln(sprintf(
                '[%s] %s -> %s (score %d, modele %s)',
                $page->getStatus(),
                $seed->getMainKeyword(),
                $page->getSlug(),
                $page->getQualityScore(),
                $model
            ));
        }

        $io->success(sprintf('%d generation(s) terminee(s), %d seed(s) ignore(s).', $generated, $skipped));

        return Command::SUCCESS;
    }
}
