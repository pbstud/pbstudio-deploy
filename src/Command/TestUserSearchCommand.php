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
    name: 'app:test:user-search',
    description: 'Test de búsquedas de usuarios con múltiples variaciones - TODOS los usuarios por batches',
)]
class TestUserSearchCommand extends Command
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
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Número de usuarios por batch', 100)
            ->addOption('max-users', 'm', InputOption::VALUE_OPTIONAL, 'Máximo de usuarios a procesar (o "all" para todos)', 'all')
            ->addOption('start-batch', 's', InputOption::VALUE_OPTIONAL, 'Número de batch inicial para reanudar (1-indexado)', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 TEST EXHAUSTIVO DE BÚSQUEDAS DE USUARIOS - PROCESAMIENTO POR BATCHES');

        $batchSize = (int) $input->getOption('batch-size');
        $maxUsersOption = $input->getOption('max-users');
        $maxUsers = $maxUsersOption === 'all' ? null : (int) $maxUsersOption;
        $startBatch = max(1, (int) $input->getOption('start-batch'));

        // Obtener total de usuarios en la BD
        $totalUsers = (int) $this->em->createQuery(
            'SELECT COUNT(u.id) FROM App\Entity\User u'
        )->getSingleScalarResult();

        if ($totalUsers === 0) {
            $io->error('No hay usuarios en la BD');
            return Command::FAILURE;
        }

        $usersToProcess = $maxUsers ?? $totalUsers;
        if ($usersToProcess > $totalUsers) {
            $usersToProcess = $totalUsers;
        }

        $totalBatches = (int) ceil($usersToProcess / $batchSize);
        if ($startBatch > $totalBatches) {
            $io->error("El start-batch ($startBatch) es mayor al total de batches ($totalBatches)");
            return Command::FAILURE;
        }

        $startBatchIndex = $startBatch - 1;

        $io->section("📊 Configuración del análisis");
        $io->table(
            ['Parámetro', 'Valor'],
            [
                ['Total usuarios en BD', number_format($totalUsers)],
                ['Usuarios a procesar', number_format($usersToProcess)],
                ['Batch size', number_format($batchSize)],
                ['Total batches', number_format($totalBatches)],
                ['Start batch', number_format($startBatch)],
            ]
        );

        if (!$io->confirm('¿Deseas continuar con el análisis?', true)) {
            $io->warning('Análisis cancelado');
            return Command::SUCCESS;
        }

        $io->section('🚀 Iniciando análisis por batches...');
        
        // Variables globales para consolidar resultados
        $globalUsersWithLastname = 0;
        $globalUsersWithoutLastname = 0;
        $globalUsersWithFullNameInName = 0;
        $globalTotalTests = 0;
        $globalTotalPassed = 0;
        $globalTotalFailed = 0;
        $globalFailuresByType = [];
        $processedBatches = 0;
        
        // Directorio para reportes
        $reportsDir = getcwd() . '/var/test-reports';
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $consolidatedReportFile = $reportsDir . '/CONSOLIDADO_search_test_' . $timestamp . '.txt';

        // PROCESAR POR BATCHES
        for ($batchNum = $startBatchIndex; $batchNum < $totalBatches; $batchNum++) {
            $offset = $batchNum * $batchSize;
            
            $io->section("📦 Batch " . ($batchNum + 1) . " de $totalBatches (offset: $offset)");
            
            // Obtener usuarios del batch actual
            $users = $this->em->createQuery(
                'SELECT u FROM App\Entity\User u ORDER BY u.id DESC'
            )
                ->setFirstResult($offset)
                ->setMaxResults($batchSize)
                ->getResult();

            if (empty($users)) {
                $io->warning("Batch vacío, finalizando...");
                break;
            }

            // Análisis preliminar del batch
            $batchUsersWithLastname = 0;
            $batchUsersWithoutLastname = 0;
            $batchUsersWithFullNameInName = 0;

            foreach ($users as $user) {
                if (!empty(trim($user->getLastname()))) {
                    $batchUsersWithLastname++;
                    $globalUsersWithLastname++;
                } else {
                    $batchUsersWithoutLastname++;
                    $globalUsersWithoutLastname++;
                    
                    // Detectar si el nombre tiene múltiples palabras (posible nombre completo)
                    $nameParts = explode(' ', trim($user->getName()));
                    if (count($nameParts) > 1) {
                        $batchUsersWithFullNameInName++;
                        $globalUsersWithFullNameInName++;
                    }
                }
            }

            $io->text([
                "Usuarios en batch: " . count($users),
                "Con apellido: $batchUsersWithLastname",
                "SIN apellido: $batchUsersWithoutLastname",
                "Con nombre completo en 'name': $batchUsersWithFullNameInName"
            ]);

            // Archivo para guardar resultados del batch
            $batchReportFile = $reportsDir . '/batch_' . str_pad((string)($batchNum + 1), 3, '0', STR_PAD_LEFT) . '_' . $timestamp . '.txt';
            $batchReportContent = "=== BATCH " . ($batchNum + 1) . " DE $totalBatches ===\n";
            $batchReportContent .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
            $batchReportContent .= "Offset: $offset\n";
            $batchReportContent .= "Usuarios en batch: " . count($users) . "\n";
            $batchReportContent .= "Usuarios CON apellido: $batchUsersWithLastname\n";
            $batchReportContent .= "Usuarios SIN apellido: $batchUsersWithoutLastname\n";
            $batchReportContent .= "Usuarios con nombre completo en 'name': $batchUsersWithFullNameInName\n";
            $batchReportContent .= str_repeat('=', 80) . "\n\n";

            $batchTotalTests = 0;
            $batchTotalPassed = 0;
            $batchTotalFailed = 0;
            $batchFailuresByType = [];

            $progressBar = $io->createProgressBar(count($users));
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
            $progressBar->setMessage('Procesando...');
            $progressBar->start();

            foreach ($users as $user) {
                $progressBar->setMessage("Usuario ID: {$user->getId()}");
                $batchReportContent .= $this->testUserSearchVariations(
                    $user, 
                    $io, 
                    $batchTotalTests, 
                    $batchTotalPassed, 
                    $batchTotalFailed, 
                    $batchFailuresByType
                );
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);

            // Acumular en globales
            $globalTotalTests += $batchTotalTests;
            $globalTotalPassed += $batchTotalPassed;
            $globalTotalFailed += $batchTotalFailed;
            
            foreach ($batchFailuresByType as $type => $count) {
                $globalFailuresByType[$type] = ($globalFailuresByType[$type] ?? 0) + $count;
            }

            // Resumen del batch
            $batchReportContent .= "\n" . str_repeat('=', 80) . "\n";
            $batchReportContent .= "RESUMEN BATCH " . ($batchNum + 1) . "\n";
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

            // Liberar memoria
            $this->em->clear();
            gc_collect_cycles();
            $processedBatches++;
        }

        // RESUMEN CONSOLIDADO FINAL
        $io->section('📊 RESUMEN CONSOLIDADO FINAL');
        
        $consolidatedContent = "=== RESUMEN CONSOLIDADO DE TODOS LOS BATCHES ===\n";
        $consolidatedContent .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
        $consolidatedContent .= "Rango batch procesado: $startBatch-$totalBatches\n";
        $consolidatedContent .= "Total batches procesados: $processedBatches\n";
        $consolidatedContent .= "Total usuarios analizados: " . ($globalUsersWithLastname + $globalUsersWithoutLastname) . "\n";
        $consolidatedContent .= str_repeat('=', 80) . "\n\n";
        
        $consolidatedContent .= "DISTRIBUCIÓN DE USUARIOS:\n";
        $consolidatedContent .= str_repeat('-', 80) . "\n";
        $consolidatedContent .= "Usuarios CON apellido: $globalUsersWithLastname (" . round(($globalUsersWithLastname / max($globalUsersWithLastname + $globalUsersWithoutLastname, 1)) * 100, 2) . "%)\n";
        $consolidatedContent .= "Usuarios SIN apellido: $globalUsersWithoutLastname (" . round(($globalUsersWithoutLastname / max($globalUsersWithLastname + $globalUsersWithoutLastname, 1)) * 100, 2) . "%)\n";
        $consolidatedContent .= "Usuarios con nombre completo en 'name': $globalUsersWithFullNameInName\n\n";
        
        $consolidatedContent .= "RESULTADOS DE TESTS:\n";
        $consolidatedContent .= str_repeat('-', 80) . "\n";
        $consolidatedContent .= "Total de tests ejecutados: $globalTotalTests\n";
        $consolidatedContent .= "✅ Pasados: $globalTotalPassed (" . round(($globalTotalPassed / max($globalTotalTests, 1)) * 100, 2) . "%)\n";
        $consolidatedContent .= "❌ Fallidos: $globalTotalFailed (" . round(($globalTotalFailed / max($globalTotalTests, 1)) * 100, 2) . "%)\n\n";

        if (!empty($globalFailuresByType)) {
            $consolidatedContent .= "ANÁLISIS DE FALLOS POR TIPO:\n";
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
        
        $lastnameEmptyPercentage = round(($globalUsersWithoutLastname / max($globalUsersWithLastname + $globalUsersWithoutLastname, 1)) * 100, 2);
        if ($lastnameEmptyPercentage > 50) {
            $consolidatedContent .= "⚠️  PROBLEMA CRÍTICO CONFIRMADO:\n";
            $consolidatedContent .= "   $lastnameEmptyPercentage% de usuarios tienen el campo 'lastname' VACÍO\n";
            $consolidatedContent .= "   Estos usuarios NO pueden ser encontrados por búsquedas en campo lastname\n";
            $consolidatedContent .= "   Recomendación: Implementar búsqueda en campo 'name' que contiene datos completos\n";
        }

        file_put_contents($consolidatedReportFile, $consolidatedContent);
        
        $io->success("✅ Análisis completado. Reporte consolidado: " . basename($consolidatedReportFile));
        $io->table(
            ['Métrica', 'Valor'],
            [
                ['Total usuarios analizados', number_format($globalUsersWithLastname + $globalUsersWithoutLastname)],
                ['Usuarios CON apellido', "$globalUsersWithLastname (" . round(($globalUsersWithLastname / max($globalUsersWithLastname + $globalUsersWithoutLastname, 1)) * 100, 2) . "%)"],
                ['Usuarios SIN apellido', "$globalUsersWithoutLastname (" . round(($globalUsersWithoutLastname / max($globalUsersWithLastname + $globalUsersWithoutLastname, 1)) * 100, 2) . "%)"],
                ['---', '---'],
                ['Total tests', number_format($globalTotalTests)],
                ['✅ Pasados', number_format($globalTotalPassed) . " (" . round(($globalTotalPassed / max($globalTotalTests, 1)) * 100, 2) . "%)"],
                ['❌ Fallidos', number_format($globalTotalFailed) . " (" . round(($globalTotalFailed / max($globalTotalTests, 1)) * 100, 2) . "%)"],
            ]
        );
        
        $io->note("Reportes individuales por batch guardados en: $reportsDir");

        return Command::SUCCESS;
    }

    private function testUserSearchVariations($user, SymfonyStyle $io, &$totalTests, &$totalPassed, &$totalFailed, &$failuresByType): string
    {
        $name = $user->getName();
        $lastname = $user->getLastname();
        $hasLastname = !empty(trim($lastname));

        $report = "\n" . str_repeat('-', 80) . "\n";
        $report .= "USUARIO ID: {$user->getId()}\n";
        $report .= "Name: '{$name}'\n";
        $report .= "Lastname: '" . ($hasLastname ? $lastname : '[VACÍO]') . "'\n";
        $report .= "Phone: {$user->getPhone()}\n";
        $report .= "Email: {$user->getEmail()}\n";
        
        // Detectar si es nombre completo en campo name
        $nameParts = explode(' ', trim($name));
        if (count($nameParts) > 1 && !$hasLastname) {
            $report .= "⚠️  CASO ESPECIAL: Nombre completo en campo 'name' (sin apellido separado)\n";
            $report .= "   Partes detectadas: " . count($nameParts) . " palabras\n";
        }
        
        $report .= str_repeat('-', 80) . "\n";

        // Variaciones de búsqueda a probar
        $searchVariations = $this->generateSearchVariations($user);

        foreach ($searchVariations as $variation) {
            $totalTests++;
            $found = $this->performSearch($user, $variation);
            
            if ($found) {
                $totalPassed++;
                $status = "✅";
            } else {
                $totalFailed++;
                $status = "❌";
                
                // Clasificar tipo de fallo
                $failureType = "{$variation['field']} - {$variation['type']}";
                if (!$hasLastname && $variation['field'] === 'lastname') {
                    $failureType = "lastname VACÍO - búsqueda imposible";
                }
                $failuresByType[$failureType] = ($failuresByType[$failureType] ?? 0) + 1;
            }

            $note = $variation['note'] ?? '';
            $report .= sprintf(
                "%s | Campo: %-10s | Tipo: %-25s | Query: '%-30s' %s\n",
                $status,
                $variation['field'],
                $variation['type'],
                substr($variation['query'], 0, 30),
                $note ? "| $note" : ''
            );
        }

        return $report;
    }

    private function generateSearchVariations($user): array
    {
        $variations = [];
        $name = $user->getName();
        $lastname = $user->getLastname();
        $phone = $user->getPhone();
        $email = $user->getEmail();

        // BÚSQUEDAS POR NOMBRE
        if ($name) {
            // Nombre completo
            $variations[] = ['query' => $name, 'field' => 'name', 'type' => 'completo', 'note' => ''];
            $variations[] = ['query' => strtolower($name), 'field' => 'name', 'type' => 'minúsculas', 'note' => ''];
            $variations[] = ['query' => strtoupper($name), 'field' => 'name', 'type' => 'MAYÚSCULAS', 'note' => ''];
            
            // Nombre parcial (primeras 3 letras)
            if (strlen($name) >= 3) {
                $partial = substr($name, 0, 3);
                $variations[] = ['query' => $partial, 'field' => 'name', 'type' => 'parcial 3 letras', 'note' => ''];
            }

            // Nombre sin acentos (si aplica)
            $normalized = $this->removeAccents($name);
            if ($normalized !== $name) {
                $variations[] = ['query' => $normalized, 'field' => 'name', 'type' => 'sin acentos', 'note' => ''];
                $variations[] = ['query' => strtolower($normalized), 'field' => 'name', 'type' => 'sin acentos minúsculas', 'note' => ''];
            }

            // Si el nombre tiene múltiples palabras, buscar por la segunda palabra (posible apellido)
            $nameParts = explode(' ', trim($name));
            if (count($nameParts) > 1) {
                $possibleLastname = $nameParts[count($nameParts) - 1]; // Última palabra
                $variations[] = [
                    'query' => $possibleLastname, 
                    'field' => 'name', 
                    'type' => 'última palabra del name',
                    'note' => 'Posible apellido en name'
                ];
                
                // También la primera palabra (posible nombre real)
                $possibleFirstName = $nameParts[0];
                $variations[] = [
                    'query' => $possibleFirstName, 
                    'field' => 'name', 
                    'type' => 'primera palabra del name',
                    'note' => 'Posible nombre en name'
                ];
            }
        }

        // BÚSQUEDAS POR APELLIDO
        $hasLastname = !empty(trim($lastname));
        
        if ($hasLastname) {
            $variations[] = ['query' => $lastname, 'field' => 'lastname', 'type' => 'completo', 'note' => ''];
            $variations[] = ['query' => strtolower($lastname), 'field' => 'lastname', 'type' => 'minúsculas', 'note' => ''];
            $variations[] = ['query' => strtoupper($lastname), 'field' => 'lastname', 'type' => 'MAYÚSCULAS', 'note' => ''];

            if (strlen($lastname) >= 3) {
                $partial = substr($lastname, 0, 3);
                $variations[] = ['query' => $partial, 'field' => 'lastname', 'type' => 'parcial 3 letras', 'note' => ''];
            }

            $normalized = $this->removeAccents($lastname);
            if ($normalized !== $lastname) {
                $variations[] = ['query' => $normalized, 'field' => 'lastname', 'type' => 'sin acentos', 'note' => ''];
                $variations[] = ['query' => strtolower($normalized), 'field' => 'lastname', 'type' => 'sin acentos minúsculas', 'note' => ''];
            }
        } else {
            // NO HAY APELLIDO - Agregar tests que deberían fallar
            $variations[] = [
                'query' => 'NoExiste', 
                'field' => 'lastname', 
                'type' => 'campo vacío test',
                'note' => '⚠️ ESPERADO: Falla porque lastname está vacío'
            ];
        }

        // BÚSQUEDAS POR TELÉFONO
        if ($phone) {
            $variations[] = ['query' => $phone, 'field' => 'phone', 'type' => 'completo', 'note' => ''];
            
            // Teléfono parcial (últimos 7 dígitos)
            if (strlen($phone) >= 7) {
                $partial = substr($phone, -7);
                $variations[] = ['query' => $partial, 'field' => 'phone', 'type' => 'parcial últimos 7', 'note' => ''];
            }

            // Teléfono parcial (primeros 4 dígitos)
            if (strlen($phone) >= 4) {
                $partial = substr($phone, 0, 4);
                $variations[] = ['query' => $partial, 'field' => 'phone', 'type' => 'parcial primeros 4', 'note' => ''];
            }
        }

        // BÚSQUEDAS POR EMAIL
        if ($email) {
            $variations[] = ['query' => $email, 'field' => 'email', 'type' => 'completo', 'note' => ''];
            $variations[] = ['query' => strtolower($email), 'field' => 'email', 'type' => 'minúsculas', 'note' => ''];
            
            // Email parcial (antes del @)
            $emailParts = explode('@', $email);
            if (!empty($emailParts[0])) {
                $variations[] = ['query' => $emailParts[0], 'field' => 'email', 'type' => 'user antes de @', 'note' => ''];
            }

            // Email parcial (dominio)
            if (!empty($emailParts[1])) {
                $variations[] = ['query' => $emailParts[1], 'field' => 'email', 'type' => 'dominio después @', 'note' => ''];
            }
        }

        return $variations;
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

            // Verificar si el usuario actual está en los resultados
            foreach ($result as $foundUser) {
                if ($foundUser->getId() === $user->getId()) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Si hay error de collation u otro, considerar como no encontrado
            return false;
        }

        return false;
    }

    private function removeAccents(string $text): string
    {
        // Remover acentos usando iconv
        return iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    }
}
