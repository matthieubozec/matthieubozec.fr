<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Tracking\RedisVisitTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:visits:stats',
    description: 'Affiche les statistiques de visites sur une période donnée'
)]
final class VisitsStatsCommand extends Command
{
    public function __construct(
        private readonly RedisVisitTracker $tracker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Nombre de jours à analyser (par défaut : 30)',
                default: 30
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $daysOption = $input->getOption('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : 30;
        $io = new SymfonyStyle($input, $output);

        $io->title(sprintf('📊 Statistiques des %d derniers jours', $days));

        $stats = $this->tracker->getStats($days);

        $io->section('Vue d’ensemble');
        $io->table(
            ['Période (jours)', 'Pages vues', 'Visiteurs uniques'],
            [[
                $stats['period_days'],
                number_format($stats['total_visits'], 0, ',', ' '),
                number_format($stats['unique_visitors'], 0, ',', ' '),
            ]]
        );

        $io->section('🏆 Pages les plus visitées');
        $this->renderTable($io, $stats['top_pages'], 'Page', 'Visites');

        $io->section('🌍 Langues principales');
        $this->renderTable($io, $stats['top_langs'], 'Langue', 'Visites');

        $io->section('🔗 Principaux référents');
        $this->renderTable($io, $stats['top_referrers'], 'Référent', 'Visites');

        $io->success('Statistiques affichées avec succès ✅');

        return Command::SUCCESS;
    }

    /**
     * @param array<string,int> $data
     */
    private function renderTable(SymfonyStyle $io, array $data, string $col1, string $col2): void
    {
        if ([] === $data) {
            $io->text('Aucune donnée disponible.');

            return;
        }

        $rows = [];
        foreach ($data as $label => $count) {
            $rows[] = [$label, number_format($count, 0, ',', ' ')];
        }

        $io->table([$col1, $col2], $rows);
    }
}
