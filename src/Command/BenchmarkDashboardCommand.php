<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Stats\StatsService;
use Carbon\CarbonImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:benchmark:dashboard', description: 'Mide el tiempo de carga de cada bloque del dashboard')]
class BenchmarkDashboardCommand extends Command
{
    public function __construct(
        private readonly StatsService $statsService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = CarbonImmutable::today()->startOfMonth();
        $to   = CarbonImmutable::today();

        $io->title('PBStudio Dashboard — Performance Benchmark');
        $io->text([
            'Fecha: ' . date('Y-m-d H:i:s'),
            "Rango ranking: {$from->format('d/m/Y')} → {$to->format('d/m/Y')}",
            '',
        ]);

        $measure = function (string $name, callable $fn): array {
            $memBefore = memory_get_usage(true);
            $start     = hrtime(true);
            $fn();
            $elapsed   = (hrtime(true) - $start) / 1_000_000;
            $memDelta  = (memory_get_usage(true) - $memBefore) / 1024;
            return ['name' => $name, 'ms' => $elapsed, 'kb' => $memDelta];
        };

        $blocks = [];
        $blocks[] = $measure('Bloque 1  — Facturación (getBillingBlock)',              fn() => $this->statsService->getBillingBlock());
        $blocks[] = $measure('Bloque 2  — Últimas transacciones',                      fn() => $this->statsService->getBlock2LastTransactions(5));
        $blocks[] = $measure('Bloque 3  — Descuentos por sucursal',                    fn() => $this->statsService->getBlock3DiscountsBySucursal());
        $blocks[] = $measure('Bloque 4  — Métodos de pago por sucursal',               fn() => $this->statsService->getBlock4PaymentMethodsBySucursal());
        $blocks[] = $measure('Bloque 5  — Clases por sucursal',                        fn() => $this->statsService->getBlock5Classes());
        $blocks[] = $measure('Bloque 6  — Instructores',                               fn() => $this->statsService->getBlock6Instructors());
        $blocks[] = $measure('Bloque 7  — Fitpass',                                    fn() => $this->statsService->getBlock7Fitpass());
        $blocks[] = $measure('Bloque 8.1 — Ranking días más llenos',                   fn() => $this->statsService->getBlock8WeekdaysBySucursal($from, $to));
        $blocks[] = $measure('Bloque 8.2 — Ranking horarios',                          fn() => $this->statsService->getBlock8SchedulesBySucursal($from, $to));
        $blocks[] = $measure('Bloque 8.3 — Ranking paquetes',                          fn() => $this->statsService->getBlock8PackagesBySucursal($from, $to));
        $blocks[] = $measure('Bloque 8.4 — Ranking clientes favoritos',                fn() => $this->statsService->getBlock8ClientsBySucursal($from, $to));
        $blocks[] = $measure('Bloque 9  — Resumen de usuarios',                        fn() => $this->statsService->getBlock9UserSummary());
        $blocks[] = $measure('Bloque 10 — Últimos usuarios',                           fn() => $this->statsService->getBlock10LastUsers(5));

        // ─── Tabla ───────────────────────────────────────────────────────────

        $rows = [];
        $totalMs  = 0.0;
        $totalKb  = 0.0;
        $slowCount = 0;

        foreach ($blocks as $b) {
            $badge = match(true) {
                $b['ms'] < 50  => '<fg=green>✓</>',
                $b['ms'] < 200 => '<fg=yellow>~</>',
                default        => '<fg=red>✗</>',
            };
            if ($b['ms'] >= 200) {
                $slowCount++;
            }
            $rows[] = [
                $badge,
                $b['name'],
                sprintf('%.2f ms', $b['ms']),
                sprintf('%.1f KB', $b['kb']),
            ];
            $totalMs += $b['ms'];
            $totalKb += $b['kb'];
        }

        $io->table(['', 'Bloque', 'Tiempo', 'Memoria'], $rows);

        // ─── Totales ─────────────────────────────────────────────────────────

        $io->table(['', 'Métrica', 'Valor'], [
            ['', 'Bloques medidos',  count($blocks)],
            ['', 'Tiempo total',     sprintf('%.2f ms', $totalMs)],
            ['', 'Memoria total',    sprintf('%.1f KB', $totalKb)],
            ['', 'Bloques lentos (>200ms)', $slowCount],
        ]);

        // ─── Veredicto ───────────────────────────────────────────────────────

        if ($totalMs < 200) {
            $io->success(sprintf('FACTIBLE sin snapshot — total %.2f ms (< 200 ms)', $totalMs));
            $io->text('El dashboard puede quedarse con cálculo en vivo. No es necesario implementar H6 todavía.');
        } elseif ($totalMs < 800) {
            $io->warning(sprintf('ACEPTABLE por ahora — total %.2f ms', $totalMs));
            $io->text([
                'El dashboard carga en tiempo razonable.',
                'Considerar implementar H6 (snapshot) antes de que el volumen de datos crezca.',
            ]);
        } else {
            $io->error(sprintf('NECESITA precálculo — total %.2f ms (> 800 ms)', $totalMs));
            $io->text([
                'El dashboard tarda demasiado para ser viable en producción.',
                'Priorizar H6 (snapshot + cron) antes de continuar con H5.',
            ]);
        }

        // ─── Candidatos a snapshot ────────────────────────────────────────────

        $slowBlocks = array_filter($blocks, fn($b) => $b['ms'] >= 200);
        if (!empty($slowBlocks)) {
            $io->section('Bloques candidatos a snapshot');
            foreach ($slowBlocks as $b) {
                $io->text(sprintf('  → %s (%.2f ms)', $b['name'], $b['ms']));
            }
        }

        $io->newLine();
        $io->text('Referencia: ✓ < 50ms  ~  50-200ms  ✗ > 200ms');

        return Command::SUCCESS;
    }
}
