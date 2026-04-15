<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test:anomalous-data',
    description: 'Test de búsquedas para usuarios con datos anómalos (nombre completo en name sin lastname)',
)]
class TestAnomalousDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Número de usuarios anómalos por batch', 50)
            ->addOption('start-batch', 's', InputOption::VALUE_OPTIONAL, 'Número de batch inicial para reanudar (1-indexado)', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 TEST DE BÚSQUEDAS PARA DATOS ANÓMALOS');

        $batchSize = (int) $input->getOption('batch-size');
        $startBatch = max(1, (int) $input->getOption('start-batch'));

        // Obtener usuarios anómalos (nombre completo sin lastname)
        $anomalousUsers = $this->getAnomalousUsers();
        $totalAnomalous = count($anomalousUsers);

        if ($totalAnomalous === 0) {
            $io->info('No hay usuarios con datos anómalos.');
            return Command::SUCCESS;
        }

        $totalBatches = (int) ceil($totalAnomalous / $batchSize);
        if ($startBatch > $totalBatches) {
            $io->error("El start-batch ($startBatch) es mayor al total de batches ($totalBatches)");
            return Command::FAILURE;
        }

        $startBatchIndex = $startBatch - 1;

        $io->section("📊 Configuración del análisis");
        $io->table(
            ['Parámetro', 'Valor'],
            [
                ['Total usuarios anómalos', number_format($totalAnomalous)],
                ['Batch size', number_format($batchSize)],
                ['Total batches', number_format($totalBatches)],
                ['Start batch', number_format($startBatch)],
            ]
        );

        if (!$io->confirm('¿Deseas continuar?', true)) {
            $io->warning('Análisis cancelado');
            return Command::SUCCESS;
        }

        // Variables globales
        $globalTotalTests = 0;
        $globalTotalPassed = 0;
        $globalTotalFailed = 0;
        $globalFailuresByType = [];
        $globalUsersAnalyzed = 0;
        $processedBatches = 0;

        // Directorio para reportes
        $reportsDir = getcwd() . '/var/test-reports';
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');

        // Procesar por batches
        for ($batchNum = $startBatchIndex; $batchNum < $totalBatches; $batchNum++) {
            $offset = $batchNum * $batchSize;
            $batch = array_slice($anomalousUsers, $offset, $batchSize);

            if (empty($batch)) {
                $io->warning("Batch vacío, finalizando...");
                break;
            }

            $io->section("📦 Batch " . ($batchNum + 1) . " de $totalBatches");

            $batchTotalTests = 0;
            $batchTotalPassed = 0;
            $batchTotalFailed = 0;
            $batchFailuresByType = [];

            $batchReportFile = $reportsDir . '/ANOMALOUS_batch_' . str_pad((string)($batchNum + 1), 3, '0', STR_PAD_LEFT) . '_' . $timestamp . '.txt';
            $batchReportContent = "=== BATCH DATOS ANÓMALOS " . ($batchNum + 1) . " DE $totalBatches ===\n";
            $batchReportContent .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
            $batchReportContent .= "Usuarios en batch: " . count($batch) . "\n";
            $batchReportContent .= str_repeat('=', 80) . "\n\n";

            $progressBar = $io->createProgressBar(count($batch));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            $progressBar->setMessage('Procesando...');
            $progressBar->start();

            foreach ($batch as $user) {
                $progressBar->setMessage("ID: {$user->getId()}");
                $batchReportContent .= $this->testAnomalousUser(
                    $user,
                    $io,
                    $batchTotalTests,
                    $batchTotalPassed,
                    $batchTotalFailed,
                    $batchFailuresByType
                );
                $globalUsersAnalyzed++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);

            // Acumular globales
            $globalTotalTests += $batchTotalTests;
            $globalTotalPassed += $batchTotalPassed;
            $globalTotalFailed += $batchTotalFailed;

            foreach ($batchFailuresByType as $type => $count) {
                $globalFailuresByType[$type] = ($globalFailuresByType[$type] ?? 0) + $count;
            }

            // Resumen del batch
            $batchReportContent .= "\n" . str_repeat('=', 80) . "\n";
            $batchReportContent .= "RESUMEN BATCH ANOMALOUS " . ($batchNum + 1) . "\n";
            $batchReportContent .= str_repeat('=', 80) . "\n";
            $batchReportContent .= "Tests ejecutados: $batchTotalTests\n";
            $batchReportContent .= "✅ Pasados: $batchTotalPassed (" . round(($batchTotalPassed / max($batchTotalTests, 1)) * 100, 2) . "%)\n";
            $batchReportContent .= "❌ Fallidos: $batchTotalFailed (" . round(($batchTotalFailed / max($batchTotalTests, 1)) * 100, 2) . "%)\n";

            file_put_contents($batchReportFile, $batchReportContent);

            $io->success("Batch " . ($batchNum + 1) . " completado. Reporte: " . basename($batchReportFile));
            $io->table(
                ['Métrica', 'Valor'],
                [
                    ['Tests en batch', $batchTotalTests],
                    ['✅ Pasados', "$batchTotalPassed (" . round(($batchTotalPassed / max($batchTotalTests, 1)) * 100, 2) . "%)"],
                    ['❌ Fallidos', "$batchTotalFailed (" . round(($batchTotalFailed / max($batchTotalTests, 1)) * 100, 2) . "%)"],
                ]
            );

            $this->em->clear();
            gc_collect_cycles();
            $processedBatches++;
        }

        // RESUMEN FINAL
        $io->section('📊 RESUMEN FINAL');

        $consolidatedReportFile = $reportsDir . '/ANOMALOUS_CONSOLIDADO_' . $timestamp . '.txt';
        $consolidatedContent = "=== RESUMEN CONSOLIDADO DATOS ANÓMALOS ===\n";
        $consolidatedContent .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
        $consolidatedContent .= "Rango de batches: $startBatch-$totalBatches\n";
        $consolidatedContent .= "Total batches procesados: $processedBatches\n";
        $consolidatedContent .= "Total usuarios anómalos analizados: $globalUsersAnalyzed\n";
        $consolidatedContent .= str_repeat('=', 80) . "\n\n";

        $consolidatedContent .= "RESULTADOS DE BÚSQUEDAS:\n";
        $consolidatedContent .= str_repeat('-', 80) . "\n";
        $consolidatedContent .= "Total de tests ejecutados: $globalTotalTests\n";
        $consolidatedContent .= "✅ Pasados: $globalTotalPassed (" . round(($globalTotalPassed / max($globalTotalTests, 1)) * 100, 2) . "%)\n";
        $consolidatedContent .= "❌ Fallidos: $globalTotalFailed (" . round(($globalTotalFailed / max($globalTotalTests, 1)) * 100, 2) . "%)\n\n";

        if (!empty($globalFailuresByType)) {
            $consolidatedContent .= "ANÁLISIS DE FALLOS:\n";
            $consolidatedContent .= str_repeat('-', 80) . "\n";
            arsort($globalFailuresByType);
            foreach ($globalFailuresByType as $type => $count) {
                $percentage = round(($count / max($globalTotalFailed, 1)) * 100, 2);
                $consolidatedContent .= "$type: $count fallos ($percentage%)\n";
            }
        }

        $consolidatedContent .= "\n" . str_repeat('=', 80) . "\n";
        $consolidatedContent .= "CONCLUSIÓN:\n";
        $consolidatedContent .= str_repeat('-', 80) . "\n";
        $consolidatedContent .= "Este reporte analiza específicamente los $globalUsersAnalyzed usuarios que tienen\n";
        $consolidatedContent .= "el nombre COMPLETO en el campo 'name' sin un campo 'lastname' separado.\n";
        $consolidatedContent .= "\nEstrategia de búsqueda utilizada:\n";
        $consolidatedContent .= "1. Búsquedas por el nombre completo\n";
        $consolidatedContent .= "2. Búsquedas por la primera palabra (nombre)\n";
        $consolidatedContent .= "3. Búsquedas por la última palabra (apellido potencial)\n";
        $consolidatedContent .= "4. Búsquedas con variaciones (sin acentos, minúsculas, etc.)\n";
        $consolidatedContent .= "5. Intentos de búsqueda por 'lastname' (que fallarán porque está vacío)\n";

        file_put_contents($consolidatedReportFile, $consolidatedContent);

        $io->success("✅ Análisis completado. Reporte consolidado: " . basename($consolidatedReportFile));
        $io->table(
            ['Métrica', 'Valor'],
            [
                ['Total usuarios anómalos analizados', number_format($globalUsersAnalyzed)],
                ['---', '---'],
                ['Total tests', number_format($globalTotalTests)],
                ['✅ Pasados', number_format($globalTotalPassed) . " (" . round(($globalTotalPassed / max($globalTotalTests, 1)) * 100, 2) . "%)"],
                ['❌ Fallidos', number_format($globalTotalFailed) . " (" . round(($globalTotalFailed / max($globalTotalTests, 1)) * 100, 2) . "%)"],
            ]
        );

        $io->note("Reportes guardados en: $reportsDir");

        return Command::SUCCESS;
    }

    private function getAnomalousUsers(): array
    {
        // Obtener todos los usuarios sin lastname
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from('App\Entity\User', 'u')
            ->where('u.lastname IS NULL OR u.lastname = :empty')
            ->setParameter('empty', '')
            ->orderBy('u.id', 'DESC');

        $allUsers = $qb->getQuery()->getResult();

        // Filtrar solo aquellos con nombre completo (2+ palabras)
        $anomalous = [];
        foreach ($allUsers as $user) {
            $nameParts = array_filter(explode(' ', trim($user->getName())), 'strlen');
            if (count($nameParts) >= 2) {
                $anomalous[] = $user;
            }
        }

        return $anomalous;
    }

    private function testAnomalousUser($user, SymfonyStyle $io, &$totalTests, &$totalPassed, &$totalFailed, &$failuresByType): string
    {
        $fullName = trim($user->getName());
        $nameParts = array_filter(explode(' ', $fullName), 'strlen');
        $firstName = $nameParts[0] ?? '';
        $lastName = end($nameParts); // Última palabra como apellido potencial

        $report = "\n" . str_repeat('-', 80) . "\n";
        $report .= "USUARIO ID: {$user->getId()}\n";
        $report .= "Name: '$fullName'\n";
        $report .= "Lastname: [VACÍO]\n";
        $report .= "Estructura detectada: " . count($nameParts) . " palabras\n";
        $report .= "  - Primera palabra (nombre): '$firstName'\n";
        $report .= "  - Última palabra (apellido potencial): '$lastName'\n";
        $report .= str_repeat('-', 80) . "\n";

        // Variaciones de búsqueda
        $searchVariations = [
            // Nombre completo
            ['query' => $fullName, 'field' => 'name', 'type' => 'completo', 'note' => 'Nombre completo'],
            ['query' => strtolower($fullName), 'field' => 'name', 'type' => 'completo minúsculas', 'note' => ''],
            
            // Primera palabra
            ['query' => $firstName, 'field' => 'name', 'type' => 'primera palabra', 'note' => 'Solo el nombre'],
            
            // Última palabra como apellido
            ['query' => $lastName, 'field' => 'name', 'type' => 'última palabra (apellido potencial)', 'note' => 'Búsqueda como apellido'],
            ['query' => strtolower($lastName), 'field' => 'name', 'type' => 'última palabra minúsculas', 'note' => ''],
            
            // Búsquedas por lastname (que fallarán)
            ['query' => $lastName, 'field' => 'lastname', 'type' => 'última palabra en lastname', 'note' => '⚠️ ESPERADO: Falla - lastname está vacío'],
            
            // Sin acentos
            ['query' => $this->removeAccents($fullName), 'field' => 'name', 'type' => 'completo sin acentos', 'note' => ''],
            ['query' => $this->removeAccents($lastName), 'field' => 'name', 'type' => 'última palabra sin acentos', 'note' => ''],
        ];

        foreach ($searchVariations as $variation) {
            $totalTests++;
            $found = $this->performSearch($user, $variation);

            if ($found) {
                $totalPassed++;
                $status = "✅";
            } else {
                $totalFailed++;
                $status = "❌";
                $failureType = "{$variation['field']} - {$variation['type']}";
                $failuresByType[$failureType] = ($failuresByType[$failureType] ?? 0) + 1;
            }

            $note = $variation['note'] ?? '';
            $report .= sprintf(
                "%s | Campo: %-10s | Tipo: %-30s | Query: '%s'\n",
                $status,
                $variation['field'],
                $variation['type'],
                substr($variation['query'], 0, 40)
            );
            if ($note) {
                $report .= "   └─ $note\n";
            }
        }

        return $report;
    }

    private function performSearch($user, array $variation): bool
    {
        $field = $variation['field'];
        $query = $variation['query'];

        try {
            $qb = $this->em->createQueryBuilder()
                ->select('u')
                ->from('App\Entity\User', 'u')
                ->where("LOWER(u.{$field}) LIKE LOWER(:query)")
                ->setParameter('query', "%{$query}%");

            $result = $qb->getQuery()->getResult();

            foreach ($result as $foundUser) {
                if ($foundUser->getId() === $user->getId()) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

    private function removeAccents(string $text): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    }
}
