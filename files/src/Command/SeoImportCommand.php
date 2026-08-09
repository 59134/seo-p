<?php

namespace App\Command;

use App\Service\SeoJsonImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seo:import',
    description: 'Importe des faits verifies et des seeds SEO depuis un fichier JSON genere par le Projet Claude.',
)]
class SeoImportCommand extends Command
{
    public function __construct(
        private readonly SeoJsonImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Chemin du fichier JSON a importer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyse le fichier sans rien ecrire en base')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Met a jour les entrees existantes au lieu de les ignorer')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Type a importer: all, facts ou seeds', SeoJsonImporter::SCOPE_ALL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');
        $update = (bool) $input->getOption('update');
        $scope = (string) $input->getOption('scope');

        try {
            $result = $this->importer->importFile($file, $scope, $update, $dryRun);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title(sprintf(
            'Import SEO%s%s',
            $result['client'] ? sprintf(' - client "%s"', $result['client']) : '',
            $dryRun ? ' [DRY-RUN]' : ''
        ));

        foreach (['facts', 'seeds'] as $group) {
            foreach ($result['messages'][$group] as $message) {
                $io->writeln(sprintf('%s %s', $this->badge((string) $message['level']), $message['message']));
            }
        }

        $io->section('Resume');
        $io->table(
            ['', 'Crees', 'Mis a jour', 'Ignores', 'Erreurs'],
            [
                ['Faits verifies', $result['stats']['facts']['created'], $result['stats']['facts']['updated'], $result['stats']['facts']['skipped'], $result['stats']['facts']['errors']],
                ['Seeds SEO', $result['stats']['seeds']['created'], $result['stats']['seeds']['updated'], $result['stats']['seeds']['skipped'], $result['stats']['seeds']['errors']],
            ]
        );

        if ($dryRun) {
            $io->note('Dry-run: rien n\'a ete ecrit en base. Relancer sans --dry-run pour importer.');
        }

        $hasErrors = $result['stats']['facts']['errors'] > 0 || $result['stats']['seeds']['errors'] > 0;

        if ($hasErrors) {
            $io->warning('Import termine avec des erreurs: corriger le JSON puis relancer (les entrees valides deja importees seront ignorees).');
        } else {
            $io->success('Import termine.');
        }

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function badge(string $level): string
    {
        return match ($level) {
            'created' => '<info>CREE</info> ',
            'updated' => '<info>MAJ</info>  ',
            'skipped' => '<comment>SKIP</comment> ',
            'warning' => '<comment>WARN</comment> ',
            'error' => '<error>ERR</error>  ',
            default => '<info>INFO</info> ',
        };
    }
}
