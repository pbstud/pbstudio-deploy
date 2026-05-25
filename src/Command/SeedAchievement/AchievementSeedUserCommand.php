<?php

declare(strict_types=1);

namespace App\Command\SeedAchievement;

use App\Entity\Achievement;
use App\Repository\AchievementBadgeRepository;
use App\Repository\AchievementRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Siembra logros ganados de forma progresiva.
 *
 * Modos de uso:
 *
 *  Modo single (un usuario específico):
 *    php bin/console app:achievement:seed-user --user-id=14567 --max-level=gold
 *
 *  Modo bulk (N usuarios con distribución realista de niveles):
 *    php bin/console app:achievement:seed-user --bulk --limit=1000 --force
 *
 * Distribución bulk (sobre el total procesado):
 *   40 % → bronze   (usuarios nuevos / poco activos)
 *   30 % → silver
 *   20 % → gold
 *    7 % → platinum
 *    3 % → diamond o superior
 *
 * Reglas de asignación:
 *  - Logros agrupados por categoryKey + conditionKey, ordenados por nivel.
 *  - Cada categoría arranca en fecha base escalonada (no todos bronze el mismo día).
 *  - Niveles separados 60 días dentro del mismo grupo.
 *  - Usa addEarnedAchievement() de la entidad, previene duplicados.
 */
#[AsCommand(
    name: 'app:achievement:seed-user',
    description: 'Siembra logros ganados de forma progresiva en uno o varios usuarios.',
)]
class AchievementSeedUserCommand extends Command
{
    private const LEVEL_ORDER = [
        'bronze'          => 1,
        'silver'          => 2,
        'gold'            => 3,
        'platinum'        => 4,
        'diamond'         => 5,
        'master'          => 6,
        'legend'          => 7,
        // Retos aislados (escala propia: challenge_1=bronce .. challenge_5=platino)
        'challenge_1'     => 1,
        'challenge_2'     => 2,
        'challenge_3'     => 3,
        'challenge_4'     => 4,
        'challenge_5'     => 5,
        // Temporadas/eventos (escala propia: special=bronce .. xmas/winter=platino)
        'season_special'  => 1,
        'season_spring'   => 2,
        'season_summer'   => 3,
        'season_fall'     => 4,
        'season_winter'   => 5,
        'season_xmas'     => 5,
    ];

    /**
     * Distribución de niveles máximos en modo bulk.
     * cumulative = porcentaje acumulado hasta ese tramo.
     */
    private const BULK_DISTRIBUTION = [
        ['level' => 'bronze',   'cumulative' => 40],
        ['level' => 'silver',   'cumulative' => 70],
        ['level' => 'gold',     'cumulative' => 90],
        ['level' => 'platinum', 'cumulative' => 97],
        ['level' => 'legend',   'cumulative' => 100],
    ];

    private const CATEGORY_MONTHS_AGO = [
        'asistencia'   => 18,
        'antiguedad'   => 16,
        'compras'      => 14,
        'racha'        => 12,
        'habitos'      => 10,
        'calificacion' => 8,
        'instructor'   => 6,
        'comunidad'    => 4,
        'disciplina'   => 5,
        'retencion'    => 7,
    ];

    private const DAYS_PER_LEVEL = 60;

    public function __construct(
        private readonly AchievementRepository      $achievementRepository,
        private readonly AchievementBadgeRepository $badgeRepository,
        private readonly UserRepository             $userRepository,
        private readonly EntityManagerInterface     $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id',   null, InputOption::VALUE_REQUIRED, 'ID del usuario objetivo (modo single).',                                      '14567')
            ->addOption('max-level', null, InputOption::VALUE_REQUIRED, 'Nivel maximo (bronze|silver|gold|platinum|diamond|master|legend).',            'gold')
            ->addOption('groups',    null, InputOption::VALUE_REQUIRED, 'Categorias a incluir, separadas por coma. Vacio = todas.',                     '')
            ->addOption('force',     null, InputOption::VALUE_NONE,     'Vaciar earned_achievements antes de sembrar.')
            ->addOption('bulk',      null, InputOption::VALUE_NONE,     'Modo bulk: procesa multiples usuarios con distribucion de niveles realista.')
            ->addOption('limit',     null, InputOption::VALUE_REQUIRED, 'Cantidad maxima de usuarios a procesar en modo bulk.',                         '1000')
            ->addOption('offset',    null, InputOption::VALUE_REQUIRED, 'Saltar los primeros N usuarios en modo bulk.',                                 '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        // 1. Seed logros de ejemplo (catálogo de achievements)
        $io->section('1. Insertando logros de ejemplo...');
        $achievementCount = $this->seedAchievements($io, $force);
        $io->success("✓ $achievementCount logros procesados");

        // 2. Seed conquistas de usuarios
        // Modo single SOLO cuando --user-id se pasa explícitamente en la línea de comandos.
        // Por defecto (incluyendo --force sin más flags) corre bulk (N usuarios) + demo user.
        if ($input->hasParameterOption('--user-id')) {
            return $this->executeSingle($input, $io);
        }

        return $this->executeBulk($input, $io);
    }

    // ── Modo single ───────────────────────────────────────────────────────────

    private function executeSingle(InputInterface $input, SymfonyStyle $io): int
    {
        $userId   = (int) $input->getOption('user-id');
        $maxLevel = strtolower(trim((string) $input->getOption('max-level')));
        $groups   = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('groups')))));
        $force    = (bool) $input->getOption('force');

        if (!isset(self::LEVEL_ORDER[$maxLevel])) {
            $io->error("Nivel '$maxLevel' no valido. Opciones: " . implode(', ', array_keys(self::LEVEL_ORDER)));
            return Command::INVALID;
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            $io->error("Usuario con id=$userId no encontrado.");
            return Command::FAILURE;
        }

        $io->title("Seed single — usuario #{$userId} — {$user->getName()} {$user->getLastname()}");

        if ($force) {
            $user->setEarnedAchievements([]);
            $io->comment('--force: earned_achievements vaciado.');
        }

        $grouped = $this->buildGroupedAchievements($groups);
        [$assigned, $skipped, $rows] = $this->seedUser($user, $grouped, $maxLevel);

        $this->entityManager->flush();

        $io->table(['Logro', 'Categoria', 'Nivel', 'Fecha ganada'], $rows);
        $total = count($user->getEarnedAchievements());
        $io->success("Asignados: {$assigned} nuevos | Omitidos/ya tenia: {$skipped} | Total en usuario: {$total}");

        return Command::SUCCESS;
    }

    // ── Modo bulk ─────────────────────────────────────────────────────────────

    private function executeBulk(InputInterface $input, SymfonyStyle $io): int
    {
        $limit  = max(1, (int) $input->getOption('limit'));
        $offset = max(0, (int) $input->getOption('offset'));
        $force  = (bool) $input->getOption('force');

        $io->title("Seed bulk — hasta {$limit} usuarios (offset={$offset})");

        $batchSize     = 100;
        $totalDone     = 0;
        $totalAssigned = 0;
        $totalSkipped  = 0;

        $grouped = $this->buildGroupedAchievements([]);

        $progressBar = $io->createProgressBar($limit);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $progressBar->setMessage('iniciando...');
        $progressBar->start();

        for ($page = 0; $totalDone < $limit; $page++) {
            $batchLimit  = min($batchSize, $limit - $totalDone);
            $batchOffset = $offset + ($page * $batchSize);

            $users = $this->userRepository->findBy(
                ['enabled' => true],
                ['id' => 'ASC'],
                $batchLimit,
                $batchOffset,
            );

            if (!$users) {
                break;
            }

            foreach ($users as $user) {
                $maxLevel = $this->pickLevel($totalDone, $limit);

                if ($force) {
                    $user->setEarnedAchievements([]);
                }

                [$assigned, $skipped] = $this->seedUser($user, $grouped, $maxLevel);
                $totalAssigned += $assigned;
                $totalSkipped  += $skipped;
                $totalDone++;

                $progressBar->setMessage("#{$user->getId()} max={$maxLevel} +{$assigned}");
                $progressBar->advance();
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            // Recargar logros del catálogo tras clear() porque las entidades se detacharon
            $grouped = $this->buildGroupedAchievements([]);
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success("Bulk completado: {$totalDone} usuarios | {$totalAssigned} logros asignados | {$totalSkipped} omitidos");

        // ── Usuario de demo/test (14567): siempre legend, independiente del batch ──
        $this->seedDemoUser($io, $force);

        return Command::SUCCESS;
    }

    /**
     * Plan de logros para el usuario de demo (id=14567).
     * Simula un usuario activo de 2+ años con progreso desigual por categoría:
     * muy fuerte en asistencia/antigüedad, moderado en compras/racha, básico en el resto.
     *
     * Formato: 'categoryKey|conditionKey' => 'maxLevel'
     */
    private const DEMO_USER_PLAN = [
        'asistencia|attended_classes'        => 'gold',      // 100 clases — usuario activo pero no elite
        'asistencia|unique_days_attended'    => 'silver',    // 30 días distintos
        'compras|total_amount'               => 'silver',    // $10k acumulados
        'compras|consecutive_paid_months'    => 'gold',      // 12 meses consecutivos
        'antiguedad|active_months'           => 'silver',    // 6 meses
        'antiguedad|active_years'            => 'gold',      // 1 año activo
        'racha|consecutive_weeks'            => 'silver',    // 8 semanas seguidas
        'racha|no_show_free_days'            => 'bronze',    // empezando con rachas perfectas
        'habitos|early_bird_classes'         => 'bronze',    // apenas explorando mañanas
        'calificacion|avg_rating_given'      => 'silver',    // calificador moderado
        'disciplina|disciplines_tried'       => 'silver',    // probó varias disciplinas
    ];

    /**
     * Siembra el usuario de demo (id=14567) con un plan de logros realista:
     * cada grupo de condition_key tiene su propio nivel máximo, simulando
     * una evolución natural — no todos los logros al mismo nivel.
     * Se ejecuta siempre al final del modo bulk porque el ID está fuera del
     * primer batch de 1000 (ordena por id ASC).
     */
    private function seedDemoUser(SymfonyStyle $io, bool $force): void
    {
        $demoUserId = 14567;
        $user = $this->userRepository->find($demoUserId);

        if (!$user) {
            $io->warning("Usuario demo (id={$demoUserId}) no encontrado — omitido.");
            return;
        }

        if ($force) {
            $user->setEarnedAchievements([]);
        }

        // Cargar todos los logros activos agrupados
        $grouped = $this->buildGroupedAchievements([]);

        $totalAssigned = 0;
        $totalSkipped  = 0;

        foreach ($grouped as $groupKey => $achList) {
            // Usar el nivel del plan si está definido; si no está en el plan, omitir el grupo
            if (!isset(self::DEMO_USER_PLAN[$groupKey])) {
                continue;
            }

            $maxLevel = self::DEMO_USER_PLAN[$groupKey];
            [$assigned, $skipped] = $this->seedUser($user, [$groupKey => $achList], $maxLevel);
            $totalAssigned += $assigned;
            $totalSkipped  += $skipped;
        }

        $this->entityManager->flush();

        $io->comment(sprintf(
            'Demo user #%d (%s): +%d nuevos, %d omitidos — total %d logros.',
            $demoUserId,
            $user->getName(),
            $totalAssigned,
            $totalSkipped,
            count($user->getEarnedAchievements()),
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Determina el nivel máximo del usuario N-ésimo según la distribución bulk.
     * El reparto es determinista por posición (no aleatorio) para reproducibilidad.
     */
    private function pickLevel(int $index, int $total): string
    {
        $pct = $total > 1 ? (int) round(($index / ($total - 1)) * 100) : 50;

        foreach (self::BULK_DISTRIBUTION as $band) {
            if ($pct <= $band['cumulative']) {
                return $band['level'];
            }
        }

        return 'legend';
    }

    /**
     * Carga y agrupa los logros activos del catálogo.
     *
     * @param string[] $groups
     * @return array<string, Achievement[]>
     */
    private function buildGroupedAchievements(array $groups): array
    {
        /** @var Achievement[] $all */
        $all = $this->achievementRepository->findBy(['active' => true]);

        $grouped = [];
        foreach ($all as $ach) {
            if ($groups && !in_array($ach->getCategoryKey(), $groups, true)) {
                continue;
            }
            $key = $ach->getCategoryKey() . '|' . $ach->getConditionKey();
            $grouped[$key][] = $ach;
        }

        foreach ($grouped as &$list) {
            usort($list, fn (Achievement $a, Achievement $b) =>
                (self::LEVEL_ORDER[$a->getBadgeLevel() ?? 'bronze'] ?? 99)
                <=>
                (self::LEVEL_ORDER[$b->getBadgeLevel() ?? 'bronze'] ?? 99)
            );
        }
        unset($list);

        return $grouped;
    }

    /**
     * Asigna logros a un usuario respetando el nivel máximo y fechas escalonadas.
     *
     * @param array<string, Achievement[]> $grouped
     * @return array{0: int, 1: int, 2: list<array{string, string, string, string}>}
     */
    private function seedUser(mixed $user, array $grouped, string $maxLevel): array
    {
        $maxLevelOrder = self::LEVEL_ORDER[$maxLevel] ?? 1;
        $now           = new \DateTimeImmutable();
        $assigned      = 0;
        $skipped       = 0;
        $rows          = [];

        foreach ($grouped as $groupKey => $achList) {
            [$categoryKey] = explode('|', $groupKey);
            $monthsAgo     = self::CATEGORY_MONTHS_AGO[$categoryKey] ?? 6;
            $groupBase     = $now->modify("-{$monthsAgo} months");

            foreach ($achList as $index => $ach) {
                $level      = $ach->getBadgeLevel() ?? 'bronze';
                $levelOrder = self::LEVEL_ORDER[$level] ?? 99;

                if ($levelOrder > $maxLevelOrder) {
                    $skipped++;
                    continue;
                }

                $earnedAt = $groupBase->modify('+' . ($index * self::DAYS_PER_LEVEL) . ' days');
                if ($earnedAt > $now) {
                    $earnedAt = $now->modify('-1 day');
                }

                $before = count($user->getEarnedAchievements());
                $user->addEarnedAchievement(
                    $ach->getId(),
                    $ach->getName(),
                    $level,
                    $ach->getBadgeColor() ?? '#888888',
                    $categoryKey,
                    $earnedAt,
                );
                $after = count($user->getEarnedAchievements());

                if ($after > $before) {
                    $assigned++;
                    $rows[] = [$ach->getName(), $categoryKey, $level, $earnedAt->format('d/m/Y')];
                } else {
                    $skipped++;
                }
            }
        }

        return [$assigned, $skipped, $rows];
    }

    // ── Catálogo de logros de prueba ──────────────────────────────────────────

    private function seedAchievements(SymfonyStyle $io, bool $force): int
    {
        // [name, description, category_key, condition_key, threshold_type, target_value, badge_level, sort_order, ?difficulty, ?conditionContext]
        $achievements = [
            // ASISTENCIA: attended_classes (7 niveles)
            ['Primeros pasos',           'Asististe a tus primeras 10 clases. El inicio de un gran camino.',                     'asistencia', 'attended_classes',        'count',  10,    'bronze',   10],
            ['En el camino',             '25 clases completadas. Tu constancia ya se nota.',                                     'asistencia', 'attended_classes',        'count',  25,    'silver',   20],
            ['Medio centenar',           '50 clases. Llevas medio centenar de sesiones de pura dedicacion.',                     'asistencia', 'attended_classes',        'count',  50,    'gold',     30],
            ['Centurion del Studio',     '100 clases asistidas. Eres parte del corazon de este lugar.',                          'asistencia', 'attended_classes',        'count',  100,   'platinum', 40],
            ['Guerrero del Studio',      '250 clases. Tu disciplina es un ejemplo para todos.',                                  'asistencia', 'attended_classes',        'count',  250,   'diamond',  50],
            ['Elite del Studio',         '500 clases. Has alcanzado un nivel de compromiso extraordinario.',                     'asistencia', 'attended_classes',        'count',  500,   'master',   60],
            ['Inmortal del Studio',      '1000 clases. Eres una leyenda viva de PB Studio.',                                     'asistencia', 'attended_classes',        'count',  1000,  'legend',   70],

            // ASISTENCIA: unique_days_attended (4 niveles)
            ['Explorador activo',        'Asististe en 10 dias diferentes. Ya tienes el habito de moverte.',                     'asistencia', 'unique_days_attended',    'count',  10,    'bronze',   80],
            ['Presencia constante',      '30 dias distintos asistidos. Tu cuerpo ya siente la diferencia.',                      'asistencia', 'unique_days_attended',    'count',  30,    'silver',   90],
            ['Dedicacion plena',         '60 dias distintos de entrenamiento. Mitad del anio activo.',                           'asistencia', 'unique_days_attended',    'count',  60,    'gold',     100],
            ['Alma del Studio',          '120 dias distintos. Casi cada tercer dia del anio aqui contigo.',                      'asistencia', 'unique_days_attended',    'count',  120,   'platinum', 110],

            // COMPRAS: total_amount (5 niveles)
            ['Primer compromiso',        'Acumulaste $5,000 en membresias. Tu inversion en salud comienza.',                     'compras',    'total_amount',            'amount', 5000,  'bronze',   120],
            ['Cliente leal',             '$10,000 invertidos en tu bienestar. Gracias por confiar en nosotros.',                 'compras',    'total_amount',            'amount', 10000, 'silver',   130],
            ['Inversor de salud',        '$25,000 acumulados. Tu salud es tu mejor inversion.',                                  'compras',    'total_amount',            'amount', 25000, 'gold',     140],
            ['Patrocinador VIP',         '$50,000 en membresias. Eres uno de nuestros pilares mas importantes.',                 'compras',    'total_amount',            'amount', 50000, 'platinum', 150],
            ['Mecenas Elite',            '$100,000 acumulados. Un nivel de fidelidad que pocos alcanzan.',                       'compras',    'total_amount',            'amount', 100000,'diamond',  160],

            // COMPRAS: consecutive_paid_months (5 niveles)
            ['Miembro activo',           '3 meses consecutivos con membresia activa. Vas por buen camino.',                     'compras',    'consecutive_paid_months', 'months', 3,     'bronze',   170],
            ['Miembro constante',        '6 meses seguidos sin pausa. Medio anio de compromiso real.',                          'compras',    'consecutive_paid_months', 'months', 6,     'silver',   180],
            ['Miembro fiel',             '12 meses consecutivos. Un anio entero de membresia activa.',                          'compras',    'consecutive_paid_months', 'months', 12,    'gold',     190],
            ['Miembro de elite',         '24 meses continuos. Dos anios de fidelidad inquebrantable.',                          'compras',    'consecutive_paid_months', 'months', 24,    'platinum', 200],
            ['Miembro eterno',           '36 meses consecutivos. Eres parte de la historia de PB Studio.',                      'compras',    'consecutive_paid_months', 'months', 36,    'diamond',  210],

            // ANTIGUEDAD: active_months / active_years (6 niveles)
            ['Recien llegado',           'Llevas 3 meses con nosotros. Bienvenido a la familia.',                                'antiguedad', 'active_months',           'months', 3,     'bronze',   220],
            ['Veterano novato',          '6 meses activo. Ya eres parte del ritmo del Studio.',                                  'antiguedad', 'active_months',           'months', 6,     'silver',   230],
            ['Un año contigo',           '1 año activo. Tu primer aniversario en PB Studio.',                                   'antiguedad', 'active_years',            'months', 12,    'gold',     240],
            ['Dos años contigo',         '2 años de historia compartida. Gracias por quedarte.',                                'antiguedad', 'active_years',            'months', 24,    'platinum', 250],
            ['Tres años contigo',        '3 años activo. Tres años de transformacion constante.',                               'antiguedad', 'active_years',            'months', 36,    'diamond',  260],
            ['Cinco años contigo',       '5 años siendo parte de este lugar. Un vinculo que pocos alcanzan.',                   'antiguedad', 'active_years',            'months', 60,    'master',   270],

            // RACHA: consecutive_weeks (4 niveles)
            ['Racha inicial',            '4 semanas seguidas asistiendo. El habito esta echando raices.',                        'racha',      'consecutive_weeks',       'count',  4,     'bronze',   280],
            ['Racha sostenida',          '8 semanas consecutivas. Dos meses sin romper el ritmo.',                               'racha',      'consecutive_weeks',       'count',  8,     'silver',   290],
            ['Racha de fuego',           '12 semanas seguidas. Tres meses de constancia imparable.',                             'racha',      'consecutive_weeks',       'count',  12,    'gold',     300],
            ['Imparable',                '24 semanas consecutivas. Seis meses sin detenerte.',                                   'racha',      'consecutive_weeks',       'count',  24,    'platinum', 310],

            // RACHA: no_show_free_days (3 niveles)
            ['Sin faltas 30 dias',       '30 dias sin ninguna inasistencia registrada. Compromiso total.',                       'racha',      'no_show_free_days',       'days',   30,    'bronze',   320],
            ['Sin faltas 60 dias',       '60 dias de asistencia impecable. Tu disciplina habla por ti.',                         'racha',      'no_show_free_days',       'days',   60,    'silver',   330],
            ['Asistencia perfecta',      '90 dias sin una sola falta. Un trimestre de excelencia.',                              'racha',      'no_show_free_days',       'days',   90,    'gold',     340],

            // HABITOS: checkin_morning_count (4 niveles)
            ['Madrugador',               'Asististe 10 veces en horario matutino. El mejor arranque del dia.',                   'habitos',    'checkin_morning_count',   'count',  10,    'bronze',   350],
            ['Ave matutina',             '25 clases matutinas completadas. Las mananas son tuyas.',                              'habitos',    'checkin_morning_count',   'count',  25,    'silver',   360],
            ['Guerrero del amanecer',    '50 asistencias matutinas. Eres dueno de tus mananas.',                                 'habitos',    'checkin_morning_count',   'count',  50,    'gold',     370],
            ['Leyenda matutina',         '100 clases en la manana. Tu disciplina al despertar no tiene igual.',                  'habitos',    'checkin_morning_count',   'count',  100,   'platinum', 380],

            // HABITOS: weekend_attendance (3 niveles)
            ['Fin de semana activo',     'Asististe 5 veces en fin de semana. Tu descanso tambien es movimiento.',               'habitos',    'weekend_attendance',      'count',  5,     'bronze',   390],
            ['Weekend Warrior',          '15 asistencias en fines de semana. El sabado y domingo son tuyos.',                    'habitos',    'weekend_attendance',      'count',  15,    'silver',   400],
            ['Campeon de fin de semana', '30 fines de semana activos. Cada semana termina con fuerza.',                          'habitos',    'weekend_attendance',      'count',  30,    'gold',     410],

            // INSTRUCTOR: unique_instructors_count (3 niveles)
            ['Curioso',                  'Tomaste clases con 3 instructores distintos. Cada uno tiene algo unico.',              'instructor', 'unique_instructors_count','count',  3,     'bronze',   450],
            ['Explorador de talentos',   '5 instructores distintos. Abres tu mente a diferentes estilos.',                      'instructor', 'unique_instructors_count','count',  5,     'silver',   460],
            ['Coleccionista de estilos', '10 instructores diferentes. Has experimentado la riqueza completa del Studio.',        'instructor', 'unique_instructors_count','count',  10,    'gold',     470],

            // RETOS AISLADOS: challenges_completed (5 niveles)
            ['Primer reto',              'Completaste tu primer reto especial. El camino de los retados empieza.',               'comunidad',  'challenges_completed',    'count',  1,     'challenge_1', 480],
            ['Triple amenaza',           'Tres retos superados. Tu determinacion es imparable.',                                 'comunidad',  'challenges_completed',    'count',  3,     'challenge_2', 490],
            ['Cinco de fuego',           'Cinco retos completados. El fuego de la competencia te define.',                       'comunidad',  'challenges_completed',    'count',  5,     'challenge_3', 500],
            ['Veterano de retos',        'Diez retos en tu historial. Eres un warrior probado en batalla.',                      'comunidad',  'challenges_completed',    'count',  10,    'challenge_4', 510],
            ['Leyenda de retos',         'Veinte retos superados. Tu escudo de honor no tiene rival.',                           'comunidad',  'challenges_completed',    'count',  20,    'challenge_5', 520],

            // EVENTOS / TEMPORADAS: event_attendance_count (4 niveles)
            ['Estreno especial',         'Asististe a tu primer evento del Studio. Parte de algo mas grande.',                   'comunidad',  'event_attendance_count',  'count',  1,     'season_special', 530],
            ['Tres temporadas vividas',  'Tres eventos especiales en tu historial. Siempre presente en lo importante.',          'comunidad',  'event_attendance_count',  'count',  3,     'season_spring',  540],
            ['Verano de eventos',        'Siete eventos asistidos. Eres el alma de cada celebracion.',                           'comunidad',  'event_attendance_count',  'count',  7,     'season_summer',  550],
            ['Espiritu navideno',        'Doce eventos en tu historial. Siempre en primera fila en las fiestas del Studio.',     'comunidad',  'event_attendance_count',  'count',  12,    'season_xmas',    560],

            // DISCIPLINAS ESPECIFICAS: discipline_classes (3 niveles — retos)
            // disciplineIds = [] en conditionContext activa el modo "mejor disciplina":
            // el resolver retorna el conteo maximo del usuario en cualquier disciplina de grupo.
            ['Especialista',             '20 clases en tu disciplina favorita. Tu tecnica ya se nota.',                          'disciplina', 'discipline_classes',      'count',  20,    'challenge_1', 570, 'easy',   ['disciplineIds' => [], 'includeIndividual' => false]],
            ['Gran especialista',        '50 clases en la misma disciplina. Tu dominio es admirable.',                           'disciplina', 'discipline_classes',      'count',  50,    'challenge_2', 580, 'medium', ['disciplineIds' => [], 'includeIndividual' => false]],
            ['Maestro de disciplina',    '100 clases en disciplina. Pocos alcanzan este nivel de especializacion.',              'disciplina', 'discipline_classes',      'count',  100,   'challenge_3', 590, 'hard',   ['disciplineIds' => [], 'includeIndividual' => false]],

            // VARIEDAD: multi_discipline_count (3 niveles)
            // disciplineIds = [] activa el modo "cualquier disciplina":
            // el resolver cuenta cuantas disciplinas distintas ha frecuentado el usuario.
            ['Bidisciplinado',           'Practicas 2 disciplinas distintas. La variedad te fortalece.',                         'disciplina', 'multi_discipline_count',  'count',  2,     'season_fall',  600, null,     ['disciplineIds' => [], 'attendancesRequired' => 0]],
            ['Polideportivo',            'Cinco disciplinas distintas exploradas. Eres un atleta completo.',                     'disciplina', 'multi_discipline_count',  'count',  5,     'challenge_4',  610, 'hard',   ['disciplineIds' => [], 'attendancesRequired' => 0]],
            ['Atleta total',             'Dominaste 10 disciplinas. No hay disciplina que no conozcas.',                         'disciplina', 'multi_discipline_count',  'count',  10,    'challenge_5',  620, 'expert', ['disciplineIds' => [], 'attendancesRequired' => 0]],

            // COMUNIDAD: referrals_count (3 niveles)
            ['Primer referido',          'Recomendaste el Studio y alguien se unio. Tu influencia crece.',                       'comunidad',  'referrals_count',         'count',  1,     'season_spring', 630],
            ['Embajador activo',         'Cinco personas se unieron gracias a ti. Eres un embajador nato.',                      'comunidad',  'referrals_count',         'count',  5,     'challenge_2',   640],
            ['Influencer del Studio',    'Diez referidos efectivos. Tu comunidad es tu mayor logro.',                            'comunidad',  'referrals_count',         'count',  10,    'challenge_3',   650],

            // COMUNIDAD: social_shares_count (2 niveles)
            ['Primera publicacion',      'Compartiste el Studio en redes por primera vez. Tu voz llega lejos.',                  'comunidad',  'social_shares_count',     'count',  1,     'season_special', 660],
            ['Viral',                    'Veinticinco compartidos en redes. Tu energia se contagia.',                            'comunidad',  'social_shares_count',     'count',  25,    'challenge_1',    670],

            // HABITOS: checkin_evening_count (2 niveles)
            ['Dueno de la tarde',        '10 asistencias en horario vespertino. La tarde tambien te pertenece.',                 'habitos',    'checkin_evening_count',   'count',  10,    'season_fall', 680],
            ['Noctambulo disciplinado',  '25 asistencias vespertinas. Tu productividad no conoce horarios.',                     'habitos',    'checkin_evening_count',   'count',  25,    'silver',      690],

            // HABITOS: consecutive_same_time_slot (2 niveles)
            ['Horario de hierro',        '12 semanas seguidas en el mismo horario. Tu cuerpo ya sabe cuando llegar.',            'habitos',    'consecutive_same_time_slot','count', 12,    'challenge_2', 700],
            ['Maquina de precision',     '24 semanas con el mismo horario. La consistencia es tu superpoder.',                   'habitos',    'consecutive_same_time_slot','count', 24,    'challenge_3', 710],

            // COMPRAS: gift_cards_count (1 nivel)
            ['Generoso',                 'Compraste tu primera tarjeta de regalo. Compartes lo que amas.',                       'compras',    'gift_cards_count',        'count',  1,     'season_special', 760],

            // COMPRAS: total_transactions (2 niveles)
            ['Pago constante',           '12 transacciones completadas. Un anio de compromisos cumplidos.',                      'compras',    'total_transactions',      'count',  12,    'season_fall',  770],
            ['Inversor frecuente',       '24 transacciones pagadas. El bienestar es tu prioridad mas preciada.',                 'compras',    'total_transactions',      'count',  24,    'challenge_1',  780],

            // RACHA: consecutive_days (2 niveles — retos)
            ['Semana perfecta',          'Siete dias consecutivos en el Studio. Una semana sin pausa.',                          'racha',      'consecutive_days',        'days',   7,     'challenge_1', 790, 'easy'],
            ['Dos semanas de fuego',     'Catorce dias seguidos. Dos semanas de fuego puro.',                                    'racha',      'consecutive_days',        'days',   14,    'challenge_2', 800, 'medium'],

            // CALIFICACION: rating_votes_min (2 niveles)
            ['Voz autorizada',           '30 calificaciones dadas. Tu opinion construye la comunidad.',                          'calificacion','rating_votes_min',       'count',  30,    'season_spring', 810],
            ['Critico experto',          '100 calificaciones registradas. Eres la voz de nuestra comunidad.',                    'calificacion','rating_votes_min',       'count',  100,   'challenge_3',   820],
        ];

        $processed = 0;
        foreach ($achievements as $data) {
            [$name, $description, $categoryKey, $conditionKey, $thresholdType, $targetValue, $badgeLevel, $sortOrder] = $data;
            $difficulty       = $data[8] ?? null;
            $conditionContext  = $data[9] ?? null;

            $existing = $this->achievementRepository->findOneBy(['name' => $name]);

            if (!$existing || $force) {
                if ($existing && $force) {
                    $this->entityManager->remove($existing);
                    $this->entityManager->flush();
                }

                $achievement = new Achievement();
                $achievement->setName($name);
                $achievement->setDescription($description);
                $achievement->setCategoryKey($categoryKey);
                $achievement->setConditionKey($conditionKey);
                $achievement->setThresholdType($thresholdType);
                $achievement->setTargetValue((string) $targetValue);
                $achievement->setComparisonOperator(Achievement::COMPARISON_OPERATOR_GTE);
                $achievement->setRewardType(Achievement::REWARD_TYPE_BADGE);
                $badge = ($badgeIndex ?? ($badgeIndex = $this->badgeRepository->findAllActiveIndexed()))[$badgeLevel] ?? null;
                $achievement->setBadgeLevel($badgeLevel);
                $achievement->setBadgeColor($badge?->getColor() ?? Achievement::resolveBadgeHexColor($badgeLevel));
                $achievement->setBadgeIcon($badge?->getIcon());
                $achievement->setBadgeLabel($badge?->getName());
                $achievement->setPeriodType(Achievement::PERIOD_TYPE_NONE);
                $achievement->setVisibleProfile(true);
                $achievement->setNotifyInApp(true);
                $achievement->setSortOrder($sortOrder);
                $achievement->setDifficulty($difficulty);
                $achievement->setConditionContext($conditionContext);
                $achievement->setActive(true);

                $this->entityManager->persist($achievement);
                $processed++;
            }
        }

        $this->entityManager->flush();
        return $processed;
    }
}
