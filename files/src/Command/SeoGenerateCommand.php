<?php

namespace App\Command;

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
        $limit = max(1, (int) $input->getOption('limit'));
        $minimumCompleteness = max(0, min(100, (int) $input->getOption('minimum-completeness')));
        $modelPreference = $input->getOption('model');
        $modelPreference = is_string($modelPreference) && trim($modelPreference) !== '' ? trim($modelPreference) : null;
        $seeds = array_slice($this->seoSeedRepository->findReadyForGeneration($minimumCompleteness), 0, $limit);

        if (!$seeds) {
            $io->success('Aucun seed SEO pret a generer.');

            return Command::SUCCESS;
        }

        foreach ($seeds as $seed) {
            $model = $this->claudeSeoGenerator->resolveModelForSeed($seed, $modelPreference);
            $page = $this->claudeSeoGenerator->generate($seed, $modelPreference);
            $io->writeln(sprintf(
                '[%s] %s -> %s (score %d, modele %s)',
                $page->getStatus(),
                $seed->getMainKeyword(),
                $page->getSlug(),
                $page->getQualityScore(),
                $model
            ));
        }

        $io->success(count($seeds) . ' generation(s) terminee(s).');

        return Command::SUCCESS;
    }
}
