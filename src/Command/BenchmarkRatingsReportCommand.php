<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Session;
use App\Repository\ReservationRepository;
use App\Repository\SessionRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:benchmark:ratings-report',
    description: 'Benchmark del flujo de reporte de calificaciones (filtros, paginacion, carga y mapeo).',
)]
final class BenchmarkRatingsReportCommand extends Command
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly SessionRepository $sessionRepository,
        private readonly PaginatorInterface $paginator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('iterations', 'i', InputOption::VALUE_REQUIRED, 'Cantidad de iteraciones', '5')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Pagina a medir', '1')
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Elementos por pagina', (string) Session::NUMBER_OF_ITEMS)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Imprime tambien el JSON completo')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $iterations = max(1, (int) $input->getOption('iterations'));
        $page = max(1, (int) $input->getOption('page'));
        $perPage = max(1, (int) $input->getOption('per-page'));

        $runs = [];
        for ($i = 0; $i < $iterations; ++$i) {
            $runs[] = $this->runOnce($page, $perPage);
        }

        $summary = $this->buildSummary($runs);

        $io->title('Benchmark reporte de calificaciones');
        $io->table(
            ['Metrica', 'Promedio (ms)', 'Min (ms)', 'Max (ms)'],
            [
                ['filterBuildMs', $summary['filterBuildMs']['avg'], $summary['filterBuildMs']['min'], $summary['filterBuildMs']['max']],
                ['paginateMs', $summary['paginateMs']['avg'], $summary['paginateMs']['min'], $summary['paginateMs']['max']],
                ['loadSessionsMs', $summary['loadSessionsMs']['avg'], $summary['loadSessionsMs']['min'], $summary['loadSessionsMs']['max']],
                ['loadAvailableCountsMs', $summary['loadAvailableCountsMs']['avg'], $summary['loadAvailableCountsMs']['min'], $summary['loadAvailableCountsMs']['max']],
                ['mapRowsMs', $summary['mapRowsMs']['avg'], $summary['mapRowsMs']['min'], $summary['mapRowsMs']['max']],
                ['totalMs', $summary['totalMs']['avg'], $summary['totalMs']['min'], $summary['totalMs']['max']],
            ]
        );

        $result = [
            'timestamp' => date('c'),
            'iterations' => $iterations,
            'page' => $page,
            'perPage' => $perPage,
            'runs' => $runs,
            'summary' => $summary,
        ];

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function runOnce(int $page, int $perPage): array
    {
        $timingStart = microtime(true);

        $ratingsQueryBuilder = $this->reservationRepository->getRatedSessionsReportQueryBuilder([], '', null);
        $timingAfterFilters = microtime(true);

        $pagination = $this->paginator->paginate($ratingsQueryBuilder, $page, $perPage);
        $timingAfterPaginate = microtime(true);

        $sessionIds = [];
        foreach ($pagination->getItems() as $item) {
            if (isset($item['sessionId'])) {
                $sessionIds[] = (int) $item['sessionId'];
            }
        }

        $sessionsById = $this->sessionRepository->findByIdsForRatings($sessionIds);
        $timingAfterSessions = microtime(true);

        $availableCounts = $this->reservationRepository->getAvailableCountForSessions($sessionIds);
        $timingAfterAvailableCounts = microtime(true);

        $rowsMapped = 0;
        foreach ($pagination->getItems() as $item) {
            $sessionId = isset($item['sessionId']) ? (int) $item['sessionId'] : 0;
            if (!isset($sessionsById[$sessionId])) {
                continue;
            }

            $rowsMapped++;
            $unused = $availableCounts[$sessionId] ?? 0;
            if ($unused < 0) {
                $rowsMapped += 0;
            }
        }

        $timingAfterRows = microtime(true);

        return [
            'filterBuildMs' => (int) round(($timingAfterFilters - $timingStart) * 1000),
            'paginateMs' => (int) round(($timingAfterPaginate - $timingAfterFilters) * 1000),
            'loadSessionsMs' => (int) round(($timingAfterSessions - $timingAfterPaginate) * 1000),
            'loadAvailableCountsMs' => (int) round(($timingAfterAvailableCounts - $timingAfterSessions) * 1000),
            'mapRowsMs' => (int) round(($timingAfterRows - $timingAfterAvailableCounts) * 1000),
            'totalMs' => (int) round(($timingAfterRows - $timingStart) * 1000),
            'rowsMapped' => $rowsMapped,
        ];
    }

    /**
     * @param array<int, array<string, int>> $runs
     *
     * @return array<string, array<string, int>>
     */
    private function buildSummary(array $runs): array
    {
        $keys = ['filterBuildMs', 'paginateMs', 'loadSessionsMs', 'loadAvailableCountsMs', 'mapRowsMs', 'totalMs'];
        $summary = [];

        foreach ($keys as $key) {
            $values = array_column($runs, $key);
            $summary[$key] = [
                'avg' => (int) round(array_sum($values) / max(count($values), 1)),
                'min' => (int) min($values),
                'max' => (int) max($values),
            ];
        }

        return $summary;
    }
}
