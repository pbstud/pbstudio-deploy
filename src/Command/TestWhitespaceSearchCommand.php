<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test:whitespace-search',
    description: 'Valida filtros backend cuando el término de búsqueda tiene espacios al inicio/fin'
)]
class TestWhitespaceSearchCommand extends Command
{
    private UserRepository $userRepository;
    private array $batchResults = [];
    private int $batchTotalTests = 0;
    private int $batchPassedTests = 0;
    private int $batchFailedTests = 0;
    private int $globalTotalTests = 0;
    private int $globalPassedTests = 0;
    private int $globalFailedTests = 0;
    private array $globalByTest = [];

    public function __construct(UserRepository $userRepository)
    {
        parent::__construct();
        $this->userRepository = $userRepository;
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Usuarios por batch', 50)
            ->addOption('max-users', 'm', InputOption::VALUE_OPTIONAL, 'Máximo de usuarios a validar', 200)
            ->addOption('output-dir', 'o', InputOption::VALUE_OPTIONAL, 'Directorio de salida', 'var/test-reports/05_WHITESPACE_TESTS')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');
        $maxUsers = (int) $input->getOption('max-users');
        $outputDir = (string) $input->getOption('output-dir');

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $output->writeln('=== TEST DE VALIDACIÓN DE FILTROS BACKEND CON ESPACIOS ===');
        $output->writeln('Fecha: '.date('Y-m-d H:i:s'));
        $output->writeln('Objetivo: validar input de filtros con espacios externos (inicio/fin), NO calidad de datos en BD');
        $output->writeln('');

        $users = $this->getUsersForValidation($maxUsers);
        $totalUsers = count($users);

        if (0 === $totalUsers) {
            $output->writeln('⚠️ No se encontraron usuarios para validar filtros.');

            return Command::SUCCESS;
        }

        $output->writeln("Usuarios a validar: {$totalUsers}");
        $output->writeln("Batch size: {$batchSize}");
        $output->writeln('');

        $batches = array_chunk($users, $batchSize);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            $this->resetBatchCounters();

            $output->writeln("Procesando batch {$batchNum} de {$totalBatches}...");

            foreach ($batch as $user) {
                $this->testUserFilterWhitespace($user);
            }

            $this->saveBatchResults($batchNum, count($batch), $outputDir);

            $output->writeln("  ✅ Pasados: {$this->batchPassedTests}");
            $output->writeln("  ❌ Fallidos: {$this->batchFailedTests}");
            $output->writeln('');
        }

        $this->saveGlobalSummary($outputDir, $totalUsers);

        $output->writeln('=== TESTS COMPLETADOS ===');
        $output->writeln("Tests totales: {$this->globalTotalTests}");
        $output->writeln("✅ Pasados: {$this->globalPassedTests}");
        $output->writeln("❌ Fallidos: {$this->globalFailedTests}");

        return Command::SUCCESS;
    }

    /**
     * @return User[]
     */
    private function getUsersForValidation(int $maxUsers): array
    {
        $qb = $this->userRepository->createQueryBuilder('u');

        $qb
            ->where('u.name IS NOT NULL')
            ->andWhere('u.name <> :empty')
            ->andWhere('u.lastname IS NOT NULL')
            ->andWhere('u.lastname <> :empty')
            ->andWhere('u.email IS NOT NULL')
            ->andWhere('u.email <> :empty')
            ->setParameter('empty', '')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults($maxUsers)
        ;

        return $qb->getQuery()->getResult();
    }

    private function resetBatchCounters(): void
    {
        $this->batchResults = [];
        $this->batchTotalTests = 0;
        $this->batchPassedTests = 0;
        $this->batchFailedTests = 0;
    }

    private function testUserFilterWhitespace(User $user): void
    {
        $userId = (int) $user->getId();
        $name = trim((string) ($user->getName() ?? ''));
        $lastname = trim((string) ($user->getLastname() ?? ''));
        $email = trim((string) ($user->getEmail() ?? ''));
        $phone = trim((string) ($user->getPhone() ?? ''));

        $this->batchResults[] = [
            'type' => 'HEADER',
            'message' => "Usuario ID: {$userId} | Name: \"{$name}\" | Lastname: \"{$lastname}\" | Email: \"{$email}\" | Phone: \"{$phone}\"",
        ];

        $this->runFieldWhitespaceValidation('name', 'name', $name, $userId);
        $this->runFieldWhitespaceValidation('lastname', 'lastname', $lastname, $userId);
        $this->runFieldWhitespaceValidation('email', 'email', $email, $userId);

        if ('' !== $phone) {
            $this->batchResults[] = [
                'type' => 'NOTE',
                'message' => 'ℹ️ Campo phone no se valida aquí porque findWithFilters() no tiene filtro por phone.',
            ];
        }
    }

    private function runFieldWhitespaceValidation(string $label, string $field, string $baseValue, int $expectedUserId): void
    {
        if ('' === trim($baseValue)) {
            return;
        }

        $base = trim($baseValue);
        $withOuterSpaces = "  {$base}  ";

        // Control: búsqueda limpia
        $this->runSearchTest(
            strtoupper($label).' control (sin espacios)',
            [$field => $base],
            $expectedUserId,
            true
        );

        // Caso solicitado: filtro con espacios externos
        $this->runSearchTest(
            strtoupper($label).' filtro con espacios externos',
            [$field => $withOuterSpaces],
            $expectedUserId,
            true
        );

        // Comportamiento esperado en backend: trim previo a consulta
        $this->runSearchTest(
            strtoupper($label).' filtro trim backend',
            [$field => trim($withOuterSpaces)],
            $expectedUserId,
            true
        );
    }

    private function runSearchTest(string $testName, array $filters, int $expectedUserId, bool $shouldFind): void
    {
        ++$this->batchTotalTests;
        ++$this->globalTotalTests;

        $qb = $this->userRepository->findWithFilters($filters);
        $qb
            ->resetDQLPart('select')
            ->select('u.id')
            ->setMaxResults(500)
        ;

        $results = $qb->getQuery()->getScalarResult();
        $foundIds = array_map(static fn (array $row) => (int) $row['id'], $results);
        $found = in_array($expectedUserId, $foundIds, true);
        $passed = ($found === $shouldFind);

        if ($passed) {
            ++$this->batchPassedTests;
            ++$this->globalPassedTests;
            $status = '✅ PASS';
        } else {
            ++$this->batchFailedTests;
            ++$this->globalFailedTests;
            $status = '❌ FAIL';
        }

        if (!isset($this->globalByTest[$testName])) {
            $this->globalByTest[$testName] = ['total' => 0, 'passed' => 0, 'failed' => 0];
        }

        ++$this->globalByTest[$testName]['total'];
        if ($passed) {
            ++$this->globalByTest[$testName]['passed'];
        } else {
            ++$this->globalByTest[$testName]['failed'];
        }

        $this->batchResults[] = [
            'type' => 'TEST',
            'status' => $status,
            'name' => $testName,
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'expected' => $shouldFind ? 'debería encontrar' : 'NO debería encontrar',
            'actual' => $found ? 'ENCONTRÓ' : 'NO encontró',
            'found_count' => count($results),
        ];
    }

    private function saveBatchResults(int $batchNum, int $batchUsers, string $outputDir): void
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = sprintf('FILTER_WHITESPACE_batch_%03d_%s.txt', $batchNum, $timestamp);
        $filepath = $outputDir.'/'.$filename;

        $successRate = 0.0;
        if ($this->batchTotalTests > 0) {
            $successRate = ($this->batchPassedTests / $this->batchTotalTests) * 100;
        }

        $content = "=== VALIDACIÓN DE FILTROS BACKEND CON ESPACIOS - BATCH {$batchNum} ===\n";
        $content .= 'Fecha: '.date('Y-m-d H:i:s')."\n";
        $content .= "Usuarios en batch: {$batchUsers}\n";
        $content .= "Tests ejecutados: {$this->batchTotalTests}\n";
        $content .= "Tests pasados: {$this->batchPassedTests}\n";
        $content .= "Tests fallidos: {$this->batchFailedTests}\n";
        $content .= 'Tasa de éxito: '.number_format($successRate, 2)."%\n";
        $content .= str_repeat('=', 90)."\n\n";

        foreach ($this->batchResults as $result) {
            if ('HEADER' === $result['type']) {
                $content .= "\n{$result['message']}\n";
                $content .= str_repeat('-', 90)."\n";
                continue;
            }

            if ('NOTE' === $result['type']) {
                $content .= $result['message']."\n";
                continue;
            }

            $content .= sprintf(
                "%s | %-42s | Filtros: %s\n",
                $result['status'],
                $result['name'],
                $result['filters']
            );
            $content .= sprintf(
                "     Esperado: %s | Real: %s | Resultados: %d\n",
                $result['expected'],
                $result['actual'],
                $result['found_count']
            );
        }

        $content .= "\n".str_repeat('=', 90)."\n";
        $content .= "RESUMEN BATCH {$batchNum}\n";
        $content .= "Tests totales: {$this->batchTotalTests}\n";
        $content .= '✅ Pasados: '.$this->batchPassedTests.' ('.number_format($successRate, 2)."%)\n";
        $content .= '❌ Fallidos: '.$this->batchFailedTests.' ('.number_format(100 - $successRate, 2)."%)\n";

        file_put_contents($filepath, $content);
    }

    private function saveGlobalSummary(string $outputDir, int $totalUsers): void
    {
        $filepath = $outputDir.'/RESUMEN_FILTROS_BACKEND_WHITESPACE.txt';

        $successRate = 0.0;
        if ($this->globalTotalTests > 0) {
            $successRate = ($this->globalPassedTests / $this->globalTotalTests) * 100;
        }

        $content = "=================================================================================\n";
        $content .= "RESUMEN CONSOLIDADO: VALIDACIÓN DE FILTROS BACKEND CON ESPACIOS\n";
        $content .= "=================================================================================\n";
        $content .= 'Fecha: '.date('Y-m-d H:i:s')."\n";
        $content .= "Objetivo: validar filtros backend con espacios al inicio/fin del input.\n";
        $content .= "Enfoque: comportamiento del backend, no limpieza de datos en base de datos.\n\n";

        $content .= "Usuarios validados: {$totalUsers}\n";
        $content .= "Tests ejecutados: {$this->globalTotalTests}\n";
        $content .= "✅ Tests pasados: {$this->globalPassedTests}\n";
        $content .= "❌ Tests fallidos: {$this->globalFailedTests}\n";
        $content .= 'Tasa de éxito global: '.number_format($successRate, 2)."%\n\n";

        $content .= "---------------------------------------------------------------------------------\n";
        $content .= "DETALLE POR ESCENARIO\n";
        $content .= "---------------------------------------------------------------------------------\n";

        ksort($this->globalByTest);
        foreach ($this->globalByTest as $testName => $stats) {
            $rate = 0.0;
            if ($stats['total'] > 0) {
                $rate = ($stats['passed'] / $stats['total']) * 100;
            }

            $content .= sprintf(
                "%-45s | total: %4d | pass: %4d | fail: %4d | éxito: %6.2f%%\n",
                $testName,
                $stats['total'],
                $stats['passed'],
                $stats['failed'],
                $rate
            );
        }

        $content .= "\nConclusión:\n";
        if ($this->globalFailedTests > 0) {
            $content .= "- Se detectaron escenarios donde filtros con espacios externos no encuentran registros.\n";
            $content .= "- Recomendación: aplicar trim() al input de filtros en backend antes de construir la query.\n";
        } else {
            $content .= "- Los filtros backend soportan correctamente input con espacios externos en los campos validados.\n";
            $content .= "- No se requieren ajustes adicionales para el caso de espacios al inicio/fin del término buscado.\n";
        }

        file_put_contents($filepath, $content);
    }
}
