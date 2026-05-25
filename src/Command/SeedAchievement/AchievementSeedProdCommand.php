<?php

declare(strict_types=1);

namespace App\Command\SeedAchievement;

use App\Entity\Achievement;
use App\Repository\AchievementBadgeRepository;
use App\Repository\AchievementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Siembra un catálogo completo de logros de producción.
 *
 * Diferencias clave respecto al seed de desarrollo:
 *  - Nombres y descripciones en español correcto (con tildes).
 *  - Combina PERIOD_TYPE_NONE (hitos acumulados de por vida) con
 *    PERIOD_TYPE_DAYS (hábitos en ventana rodante).
 *  - showProgress = true en todos los logros donde el progreso es relevante.
 *  - notifySpecial = true a partir del nivel gold para hitos importantes.
 *  - difficulty configurado en los logros tipo reto (challenge_*).
 *  - conditionContext null donde los IDs reales deben configurarse en el panel
 *    (discipline_classes, multi_discipline_count, consecutive_same_time_slot).
 *
 * Uso:
 *   php bin/console app:achievement:seed-prod
 *   php bin/console app:achievement:seed-prod --force
 *   php bin/console app:achievement:seed-prod --category=habitos
 *   php bin/console app:achievement:seed-prod --category=racha --force
 */
#[AsCommand(
    name: 'app:achievement:seed-prod',
    description: 'Siembra un catálogo completo de logros de producción con nombres, descripciones y configuraciones reales.',
)]
class AchievementSeedProdCommand extends Command
{
    public function __construct(
        private readonly AchievementRepository      $achievementRepository,
        private readonly AchievementBadgeRepository $badgeRepository,
        private readonly EntityManagerInterface     $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Eliminar y recrear logros que ya existen con el mismo nombre.',
            )
            ->addOption(
                'category',
                null,
                InputOption::VALUE_REQUIRED,
                'Limitar el seed a una categoría (asistencia, habitos, racha, compras, antiguedad, disciplina, instructor, comunidad, calificacion). Vacío = todas.',
                '',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $category = trim((string) $input->getOption('category'));

        $io->title('Seed de logros — producción');

        $badgeIndex   = $this->badgeRepository->findAllActiveIndexed();
        $achievements = $this->buildAchievementList();

        if ($category !== '') {
            $achievements = array_values(
                array_filter($achievements, static fn(array $d): bool => $d['cat'] === $category)
            );
            $io->comment(sprintf("Filtrando por categoría: «%s» — %d logros.", $category, count($achievements)));
        }

        // Siempre limpia los logros existentes (del catálogo seed) antes de recrear,
        // para que la tabla quede sincronizada exactamente con este seed.
        // Los logros creados desde el panel (wizard) con nombre fuera del catálogo
        // no están en $achievements, por lo que no se ven afectados si no colisionan por nombre.
        $all = $this->achievementRepository->findAll();
        foreach ($all as $old) {
            $this->entityManager->remove($old);
        }
        $this->entityManager->flush();
        $io->comment(sprintf('%d logros eliminados. Recreando desde el seed…', count($all)));

        $created = 0;

        foreach ($achievements as $d) {
            $badgeLevel = $d['badge'];
            $badge      = $badgeIndex[$badgeLevel] ?? null;

            $ach = new Achievement();
            $ach->setName($d['name']);
            $ach->setDescription($d['desc']);
            $ach->setCategoryKey($d['cat']);
            $ach->setConditionKey($d['cond']);
            $ach->setConditionContext($d['ctx'] ?? null);
            $ach->setThresholdType($d['type']);
            $ach->setTargetValue((string) $d['target']);
            $ach->setComparisonOperator(Achievement::COMPARISON_OPERATOR_GTE);
            $ach->setRewardType(Achievement::REWARD_TYPE_BADGE);
            $ach->setBadgeLevel($badgeLevel);
            $ach->setBadgeColor($badge?->getColor() ?? Achievement::resolveBadgeHexColor($badgeLevel));
            $ach->setBadgeIcon($badge?->getIcon());
            $ach->setBadgeLabel($badge?->getName());
            $ach->setPeriodType($d['period'] ?? Achievement::PERIOD_TYPE_NONE);
            $ach->setPeriodDays(isset($d['days']) ? (int) $d['days'] : null);
            $ach->setVisibleProfile(true);
            $ach->setNotifyInApp(true);
            $ach->setNotifySpecial($d['special'] ?? false);
            $ach->setShowProgress($d['progress'] ?? false);
            $ach->setDifficulty($d['diff'] ?? null);
            $ach->setSortOrder((int) $d['sort']);
            $ach->setActive(true);

            $this->entityManager->persist($ach);
            $created++;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Completado: %d logros creados correctamente.', $created));

        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Catálogo completo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cada entrada es un array asociativo con los siguientes campos:
     *
     *   name     string        Nombre único del logro (visible en perfil).
     *   desc     string        Descripción visible al usuario.
     *   cat      string        categoryKey.
     *   cond     string        conditionKey.
     *   type     string        thresholdType: count | amount | days | months.
     *   target   int|float     Valor objetivo.
     *   badge    string        badgeLevel.
     *   sort     int           sortOrder.
     *   period   string        PERIOD_TYPE_NONE (default) | PERIOD_TYPE_DAYS.
     *   days     int|null      periodDays (sólo cuando period=days).
     *   progress bool          showProgress (default false).
     *   special  bool          notifySpecial (default false).
     *   diff     string|null   difficulty: easy | medium | hard | expert.
     *   ctx      array|null    conditionContext JSON (null = no aplica o pendiente de admin).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAchievementList(): array
    {
        return [

            // ── ASISTENCIA / attended_classes ─────────────────────────────────
            // Hitos acumulados de por vida. showProgress activo para que el usuario
            // sepa cuánto le falta. notifySpecial a partir del nivel gold.
            [
                'name' => 'Primeros pasos',
                'desc' => 'Asististe a tus primeras 10 clases. El inicio de un gran camino.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 10, 'badge' => 'bronze', 'sort' => 100,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'En el camino',
                'desc' => '25 clases completadas. Tu constancia ya empieza a notarse.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 25, 'badge' => 'silver', 'sort' => 110,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Medio centenar',
                'desc' => '50 clases. Llevas medio centenar de sesiones de pura dedicación.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 50, 'badge' => 'gold', 'sort' => 120,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Centurión del Studio',
                'desc' => '100 clases asistidas. Eres parte del corazón de este lugar.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 100, 'badge' => 'platinum', 'sort' => 130,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Guerrero del Studio',
                'desc' => '250 clases. Tu disciplina es un ejemplo para todos.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 250, 'badge' => 'diamond', 'sort' => 140,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Élite del Studio',
                'desc' => '500 clases. Has alcanzado un nivel de compromiso extraordinario.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 500, 'badge' => 'master', 'sort' => 150,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Inmortal del Studio',
                'desc' => '1 000 clases. Eres una leyenda viva de PB Studio.',
                'cat' => 'asistencia', 'cond' => 'attended_classes',
                'type' => 'count', 'target' => 1000, 'badge' => 'legend', 'sort' => 160,
                'progress' => true, 'special' => true,
            ],

            // ── ASISTENCIA / unique_days_attended ─────────────────────────────
            [
                'name' => 'Explorador activo',
                'desc' => 'Asististe en 10 días diferentes. El hábito de moverte está tomando forma.',
                'cat' => 'asistencia', 'cond' => 'unique_days_attended',
                'type' => 'count', 'target' => 10, 'badge' => 'bronze', 'sort' => 200,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Presencia constante',
                'desc' => '30 días distintos asistidos. Tu cuerpo ya siente la diferencia.',
                'cat' => 'asistencia', 'cond' => 'unique_days_attended',
                'type' => 'count', 'target' => 30, 'badge' => 'silver', 'sort' => 210,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Dedicación plena',
                'desc' => '60 días distintos de entrenamiento. Casi la mitad del año activo.',
                'cat' => 'asistencia', 'cond' => 'unique_days_attended',
                'type' => 'count', 'target' => 60, 'badge' => 'gold', 'sort' => 220,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Alma del Studio',
                'desc' => '120 días distintos. Casi uno de cada tres días del año, aquí contigo.',
                'cat' => 'asistencia', 'cond' => 'unique_days_attended',
                'type' => 'count', 'target' => 120, 'badge' => 'platinum', 'sort' => 230,
                'progress' => true, 'special' => true,
            ],

            // ── ASISTENCIA / weekly_frequency ─────────────────────────────────
            // Ventana rodante de 5 semanas (35 días). El resolver evalúa el mínimo semanal
            // alcanzado dentro de esa ventana, por lo que showProgress no aplica aquí.
            [
                'name' => 'Dos veces a la semana',
                'desc' => 'Asististe al menos 2 veces por semana durante 5 semanas consecutivas.',
                'cat' => 'asistencia', 'cond' => 'weekly_frequency',
                'type' => 'count', 'target' => 2, 'badge' => 'bronze', 'sort' => 300,
                'period' => 'days', 'days' => 35,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Tres veces a la semana',
                'desc' => 'Mínimo 3 clases semanales durante 5 semanas. Un ritmo sólido y sostenido.',
                'cat' => 'asistencia', 'cond' => 'weekly_frequency',
                'type' => 'count', 'target' => 3, 'badge' => 'silver', 'sort' => 310,
                'period' => 'days', 'days' => 35,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Cinco veces a la semana',
                'desc' => '5 clases semanales durante 5 semanas. Una dedicación que muy pocos alcanzan.',
                'cat' => 'asistencia', 'cond' => 'weekly_frequency',
                'type' => 'count', 'target' => 5, 'badge' => 'gold', 'sort' => 320,
                'period' => 'days', 'days' => 35,
                'progress' => false, 'special' => true,
            ],

            // ── RACHA / consecutive_days ──────────────────────────────────────
            // Retos con dificultad escalada. El evaluador busca la racha más larga
            // de por vida, por lo que showProgress es útil para motivar.
            [
                'name' => 'Semana perfecta',
                'desc' => '7 días consecutivos en el Studio. Una semana sin ninguna pausa.',
                'cat' => 'racha', 'cond' => 'consecutive_days',
                'type' => 'days', 'target' => 7, 'badge' => 'challenge_1', 'sort' => 400,
                'progress' => true, 'special' => false, 'diff' => 'easy',
            ],
            [
                'name' => 'Dos semanas de fuego',
                'desc' => '14 días seguidos. Dos semanas de puro fuego interior.',
                'cat' => 'racha', 'cond' => 'consecutive_days',
                'type' => 'days', 'target' => 14, 'badge' => 'challenge_2', 'sort' => 410,
                'progress' => true, 'special' => false, 'diff' => 'medium',
            ],
            [
                'name' => 'Mes sin pausa',
                'desc' => '30 días consecutivos de asistencia. Un mes entero sin detenerte.',
                'cat' => 'racha', 'cond' => 'consecutive_days',
                'type' => 'days', 'target' => 30, 'badge' => 'challenge_3', 'sort' => 420,
                'progress' => true, 'special' => true, 'diff' => 'hard',
            ],
            [
                'name' => 'Trimestre imparable',
                'desc' => '90 días consecutivos. Un trimestre de disciplina inquebrantable.',
                'cat' => 'racha', 'cond' => 'consecutive_days',
                'type' => 'days', 'target' => 90, 'badge' => 'challenge_4', 'sort' => 430,
                'progress' => true, 'special' => true, 'diff' => 'expert',
            ],

            // ── RACHA / consecutive_weeks ─────────────────────────────────────
            [
                'name' => 'Racha inicial',
                'desc' => '4 semanas seguidas asistiendo. El hábito está echando raíces.',
                'cat' => 'racha', 'cond' => 'consecutive_weeks',
                'type' => 'count', 'target' => 4, 'badge' => 'bronze', 'sort' => 500,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Racha sostenida',
                'desc' => '8 semanas consecutivas. Dos meses sin romper el ritmo.',
                'cat' => 'racha', 'cond' => 'consecutive_weeks',
                'type' => 'count', 'target' => 8, 'badge' => 'silver', 'sort' => 510,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Racha de fuego',
                'desc' => '12 semanas seguidas. Tres meses de constancia imparable.',
                'cat' => 'racha', 'cond' => 'consecutive_weeks',
                'type' => 'count', 'target' => 12, 'badge' => 'gold', 'sort' => 520,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Imparable',
                'desc' => '24 semanas consecutivas. Seis meses sin detenerte ni un solo día.',
                'cat' => 'racha', 'cond' => 'consecutive_weeks',
                'type' => 'count', 'target' => 24, 'badge' => 'platinum', 'sort' => 530,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Un año sin parar',
                'desc' => '52 semanas consecutivas. Un año entero de asistencia ininterrumpida.',
                'cat' => 'racha', 'cond' => 'consecutive_weeks',
                'type' => 'count', 'target' => 52, 'badge' => 'diamond', 'sort' => 540,
                'progress' => true, 'special' => true,
            ],

            // ── RACHA / no_show_free_days ─────────────────────────────────────
            [
                'name' => 'Sin faltas — 30 días',
                'desc' => '30 días de reservas cumplidas sin ninguna inasistencia. Compromiso total.',
                'cat' => 'racha', 'cond' => 'no_show_free_days',
                'type' => 'days', 'target' => 30, 'badge' => 'bronze', 'sort' => 600,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Sin faltas — 60 días',
                'desc' => '60 días de asistencia impecable. Tu disciplina habla por ti.',
                'cat' => 'racha', 'cond' => 'no_show_free_days',
                'type' => 'days', 'target' => 60, 'badge' => 'silver', 'sort' => 610,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Asistencia perfecta',
                'desc' => '90 días sin una sola falta. Un trimestre de excelencia.',
                'cat' => 'racha', 'cond' => 'no_show_free_days',
                'type' => 'days', 'target' => 90, 'badge' => 'gold', 'sort' => 620,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Compromiso total',
                'desc' => '180 días sin inasistencias. Medio año de presencia impecable.',
                'cat' => 'racha', 'cond' => 'no_show_free_days',
                'type' => 'days', 'target' => 180, 'badge' => 'platinum', 'sort' => 630,
                'progress' => true, 'special' => true,
            ],

            // ── HÁBITOS / checkin_morning_count ───────────────────────────────
            // Hitos de por vida. Recompensan el hábito de entrenar en las mañanas.
            [
                'name' => 'Madrugador',
                'desc' => '10 asistencias matutinas (antes de las 12:00). El mejor arranque del día.',
                'cat' => 'habitos', 'cond' => 'checkin_morning_count',
                'type' => 'count', 'target' => 10, 'badge' => 'bronze', 'sort' => 700,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Ave matutina',
                'desc' => '25 clases matutinas completadas. Las mañanas ya son tuyas.',
                'cat' => 'habitos', 'cond' => 'checkin_morning_count',
                'type' => 'count', 'target' => 25, 'badge' => 'silver', 'sort' => 710,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Guerrero del amanecer',
                'desc' => '50 asistencias matutinas. Eres dueño absoluto de tus mañanas.',
                'cat' => 'habitos', 'cond' => 'checkin_morning_count',
                'type' => 'count', 'target' => 50, 'badge' => 'gold', 'sort' => 720,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Leyenda matutina',
                'desc' => '100 clases en la mañana. Tu disciplina al despertar no tiene igual.',
                'cat' => 'habitos', 'cond' => 'checkin_morning_count',
                'type' => 'count', 'target' => 100, 'badge' => 'platinum', 'sort' => 730,
                'progress' => true, 'special' => true,
            ],

            // ── HÁBITOS / checkin_evening_count ───────────────────────────────
            [
                'name' => 'Dueño de la tarde',
                'desc' => '10 asistencias vespertinas (12:00 en adelante). La tarde también te pertenece.',
                'cat' => 'habitos', 'cond' => 'checkin_evening_count',
                'type' => 'count', 'target' => 10, 'badge' => 'bronze', 'sort' => 800,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Noctámbulo disciplinado',
                'desc' => '25 asistencias vespertinas. Tu productividad no conoce horarios.',
                'cat' => 'habitos', 'cond' => 'checkin_evening_count',
                'type' => 'count', 'target' => 25, 'badge' => 'silver', 'sort' => 810,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Rey de la tarde',
                'desc' => '50 clases vespertinas. Las tardes del Studio son tu territorio.',
                'cat' => 'habitos', 'cond' => 'checkin_evening_count',
                'type' => 'count', 'target' => 50, 'badge' => 'gold', 'sort' => 820,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Maestro vespertino',
                'desc' => '100 asistencias en la tarde. Tu energía al final del día no tiene rival.',
                'cat' => 'habitos', 'cond' => 'checkin_evening_count',
                'type' => 'count', 'target' => 100, 'badge' => 'platinum', 'sort' => 830,
                'progress' => true, 'special' => true,
            ],

            // ── HÁBITOS / weekend_attendance ──────────────────────────────────
            [
                'name' => 'Fin de semana activo',
                'desc' => '5 asistencias en sábado o domingo. Tu descanso también es movimiento.',
                'cat' => 'habitos', 'cond' => 'weekend_attendance',
                'type' => 'count', 'target' => 5, 'badge' => 'bronze', 'sort' => 900,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Weekend Warrior',
                'desc' => '15 asistencias en fines de semana. El sábado y domingo son completamente tuyos.',
                'cat' => 'habitos', 'cond' => 'weekend_attendance',
                'type' => 'count', 'target' => 15, 'badge' => 'silver', 'sort' => 910,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Campeón del fin de semana',
                'desc' => '30 fines de semana activos. Cada semana termina con toda la fuerza.',
                'cat' => 'habitos', 'cond' => 'weekend_attendance',
                'type' => 'count', 'target' => 30, 'badge' => 'gold', 'sort' => 920,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Guardián del fin de semana',
                'desc' => '50 asistencias en fin de semana. Tu compromiso no descansa ni el domingo.',
                'cat' => 'habitos', 'cond' => 'weekend_attendance',
                'type' => 'count', 'target' => 50, 'badge' => 'platinum', 'sort' => 930,
                'progress' => true, 'special' => true,
            ],

            // ── HÁBITOS / consecutive_same_time_slot ──────────────────────────
            // conditionContext debe completarse desde el panel con los timeSlotIds
            // correspondientes. Se crea con ctx=null para que el admin lo configure.
            [
                'name' => 'Horario fijo',
                'desc' => '10 clases en tu horario habitual. La constancia en el horario es poder.',
                'cat' => 'habitos', 'cond' => 'consecutive_same_time_slot',
                'type' => 'count', 'target' => 10, 'badge' => 'bronze', 'sort' => 1000,
                'progress' => true, 'special' => false, 'ctx' => null,
            ],
            [
                'name' => 'Disciplina horaria',
                'desc' => '25 clases en el mismo horario. Tu cuerpo ya sabe exactamente cuándo llegar.',
                'cat' => 'habitos', 'cond' => 'consecutive_same_time_slot',
                'type' => 'count', 'target' => 25, 'badge' => 'silver', 'sort' => 1010,
                'progress' => true, 'special' => false, 'ctx' => null,
            ],
            [
                'name' => 'Reloj de precisión',
                'desc' => '50 clases en horario fijo. Tu puntualidad es tu mayor fortaleza.',
                'cat' => 'habitos', 'cond' => 'consecutive_same_time_slot',
                'type' => 'count', 'target' => 50, 'badge' => 'gold', 'sort' => 1020,
                'progress' => true, 'special' => true, 'ctx' => null,
            ],
            [
                'name' => 'Maestro del horario',
                'desc' => '100 clases en tu horario. La consistencia inquebrantable es tu superpoder.',
                'cat' => 'habitos', 'cond' => 'consecutive_same_time_slot',
                'type' => 'count', 'target' => 100, 'badge' => 'platinum', 'sort' => 1030,
                'progress' => true, 'special' => true, 'ctx' => null,
            ],

            // ── COMPRAS / total_amount ─────────────────────────────────────────
            [
                'name' => 'Primer compromiso',
                'desc' => 'Acumulaste $5 000 en membresías. Tu inversión en salud comienza.',
                'cat' => 'compras', 'cond' => 'total_amount',
                'type' => 'amount', 'target' => 5000, 'badge' => 'bronze', 'sort' => 1100,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Cliente leal',
                'desc' => '$10 000 invertidos en tu bienestar. Gracias por confiar en nosotros.',
                'cat' => 'compras', 'cond' => 'total_amount',
                'type' => 'amount', 'target' => 10000, 'badge' => 'silver', 'sort' => 1110,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Inversor de salud',
                'desc' => '$25 000 acumulados. Tu salud es tu mejor inversión.',
                'cat' => 'compras', 'cond' => 'total_amount',
                'type' => 'amount', 'target' => 25000, 'badge' => 'gold', 'sort' => 1120,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Patrocinador VIP',
                'desc' => '$50 000 en membresías. Eres uno de nuestros pilares más importantes.',
                'cat' => 'compras', 'cond' => 'total_amount',
                'type' => 'amount', 'target' => 50000, 'badge' => 'platinum', 'sort' => 1130,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Mecenas Élite',
                'desc' => '$100 000 acumulados en membresías. Un nivel de fidelidad que muy pocos alcanzan.',
                'cat' => 'compras', 'cond' => 'total_amount',
                'type' => 'amount', 'target' => 100000, 'badge' => 'diamond', 'sort' => 1140,
                'progress' => true, 'special' => true,
            ],

            // ── COMPRAS / consecutive_paid_months ─────────────────────────────
            [
                'name' => 'Miembro activo',
                'desc' => '3 meses consecutivos con membresía activa. Vas muy por buen camino.',
                'cat' => 'compras', 'cond' => 'consecutive_paid_months',
                'type' => 'months', 'target' => 3, 'badge' => 'bronze', 'sort' => 1200,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Miembro constante',
                'desc' => '6 meses seguidos sin pausa. Medio año de compromiso real.',
                'cat' => 'compras', 'cond' => 'consecutive_paid_months',
                'type' => 'months', 'target' => 6, 'badge' => 'silver', 'sort' => 1210,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Miembro fiel',
                'desc' => '12 meses consecutivos. Un año entero de membresía activa.',
                'cat' => 'compras', 'cond' => 'consecutive_paid_months',
                'type' => 'months', 'target' => 12, 'badge' => 'gold', 'sort' => 1220,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Miembro de élite',
                'desc' => '24 meses continuos. Dos años de fidelidad inquebrantable.',
                'cat' => 'compras', 'cond' => 'consecutive_paid_months',
                'type' => 'months', 'target' => 24, 'badge' => 'platinum', 'sort' => 1230,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Miembro eterno',
                'desc' => '36 meses consecutivos. Eres parte de la historia de PB Studio.',
                'cat' => 'compras', 'cond' => 'consecutive_paid_months',
                'type' => 'months', 'target' => 36, 'badge' => 'diamond', 'sort' => 1240,
                'progress' => true, 'special' => true,
            ],

            // ── COMPRAS / total_transactions ──────────────────────────────────
            [
                'name' => 'Pago inicial',
                'desc' => '5 transacciones completadas. Los primeros pasos de una larga relación.',
                'cat' => 'compras', 'cond' => 'total_transactions',
                'type' => 'count', 'target' => 5, 'badge' => 'bronze', 'sort' => 1300,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Pago constante',
                'desc' => '12 transacciones completadas. Un año de compromisos cumplidos.',
                'cat' => 'compras', 'cond' => 'total_transactions',
                'type' => 'count', 'target' => 12, 'badge' => 'silver', 'sort' => 1310,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Inversor frecuente',
                'desc' => '24 transacciones pagadas. El bienestar es tu prioridad más preciada.',
                'cat' => 'compras', 'cond' => 'total_transactions',
                'type' => 'count', 'target' => 24, 'badge' => 'gold', 'sort' => 1320,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Socio fundacional',
                'desc' => '50 transacciones. Tu historial de inversión en salud es verdaderamente impresionante.',
                'cat' => 'compras', 'cond' => 'total_transactions',
                'type' => 'count', 'target' => 50, 'badge' => 'platinum', 'sort' => 1330,
                'progress' => true, 'special' => true,
            ],

            // ── COMPRAS / gift_cards_count ─────────────────────────────────────
            [
                'name' => 'Generoso',
                'desc' => 'Compraste tu primera tarjeta de regalo. Compartes lo que amas.',
                'cat' => 'compras', 'cond' => 'gift_cards_count',
                'type' => 'count', 'target' => 1, 'badge' => 'season_special', 'sort' => 1400,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Embajador del regalo',
                'desc' => '3 tarjetas de regalo entregadas. Eres el mejor promotor del Studio.',
                'cat' => 'compras', 'cond' => 'gift_cards_count',
                'type' => 'count', 'target' => 3, 'badge' => 'season_spring', 'sort' => 1410,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Filántropo del Studio',
                'desc' => '5 tarjetas de regalo. Tu generosidad construye comunidad.',
                'cat' => 'compras', 'cond' => 'gift_cards_count',
                'type' => 'count', 'target' => 5, 'badge' => 'challenge_1', 'sort' => 1420,
                'progress' => true, 'special' => false,
            ],

            // ── ANTIGÜEDAD / active_months ────────────────────────────────────
            [
                'name' => 'Recién llegado',
                'desc' => 'Llevas 3 meses con nosotros. Bienvenido a la familia de PB Studio.',
                'cat' => 'antiguedad', 'cond' => 'active_months',
                'type' => 'months', 'target' => 3, 'badge' => 'bronze', 'sort' => 1500,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Veterano novato',
                'desc' => '6 meses activo. Ya eres parte del ritmo del Studio.',
                'cat' => 'antiguedad', 'cond' => 'active_months',
                'type' => 'months', 'target' => 6, 'badge' => 'silver', 'sort' => 1510,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Cliente consolidado',
                'desc' => '12 meses activo. Un año entero de vida compartida con PB Studio.',
                'cat' => 'antiguedad', 'cond' => 'active_months',
                'type' => 'months', 'target' => 12, 'badge' => 'gold', 'sort' => 1520,
                'progress' => true, 'special' => true,
            ],

            // ── ANTIGÜEDAD / active_years ─────────────────────────────────────
            [
                'name' => 'Un año contigo',
                'desc' => '1 año activo. Tu primer aniversario en PB Studio.',
                'cat' => 'antiguedad', 'cond' => 'active_years',
                'type' => 'months', 'target' => 12, 'badge' => 'bronze', 'sort' => 1600,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Dos años contigo',
                'desc' => '2 años de historia compartida. Gracias por quedarte con nosotros.',
                'cat' => 'antiguedad', 'cond' => 'active_years',
                'type' => 'months', 'target' => 24, 'badge' => 'silver', 'sort' => 1610,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Tres años contigo',
                'desc' => '3 años activo. Tres años de transformación constante.',
                'cat' => 'antiguedad', 'cond' => 'active_years',
                'type' => 'months', 'target' => 36, 'badge' => 'gold', 'sort' => 1620,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Cinco años contigo',
                'desc' => '5 años siendo parte de este lugar. Un vínculo que muy pocos alcanzan.',
                'cat' => 'antiguedad', 'cond' => 'active_years',
                'type' => 'months', 'target' => 60, 'badge' => 'platinum', 'sort' => 1630,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Diez años contigo',
                'desc' => '10 años de historia compartida. Eres parte del alma fundacional de PB Studio.',
                'cat' => 'antiguedad', 'cond' => 'active_years',
                'type' => 'months', 'target' => 120, 'badge' => 'master', 'sort' => 1640,
                'progress' => true, 'special' => true,
            ],

            // ── DISCIPLINA / discipline_classes ───────────────────────────────
            // IDs fijos de las disciplinas activas: 5=BeastFormer, 6=Dual Pilates, 2=Pilates Reformer.
            // disciplineIds tiene exactamente UN id por logro (diseño del wizard).
            [
                'name' => 'Especialista · BeastFormer',
                'desc' => '20 clases de BeastFormer. Tu técnica ya se nota claramente.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 20, 'badge' => 'challenge_1', 'sort' => 1700,
                'progress' => true, 'special' => false, 'diff' => 'easy',
                'ctx' => ['disciplineIds' => [5], 'includeIndividual' => false],
            ],
            [
                'name' => 'Gran especialista · BeastFormer',
                'desc' => '50 clases de BeastFormer. Tu dominio es completamente admirable.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 50, 'badge' => 'challenge_2', 'sort' => 1701,
                'progress' => true, 'special' => false, 'diff' => 'medium',
                'ctx' => ['disciplineIds' => [5], 'includeIndividual' => false],
            ],
            [
                'name' => 'Maestro de disciplina · BeastFormer',
                'desc' => '100 clases de BeastFormer. Pocos alcanzan este nivel de especialización.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 100, 'badge' => 'challenge_3', 'sort' => 1702,
                'progress' => true, 'special' => true, 'diff' => 'hard',
                'ctx' => ['disciplineIds' => [5], 'includeIndividual' => false],
            ],
            [
                'name' => 'Leyenda de la disciplina · BeastFormer',
                'desc' => '200 clases de BeastFormer. Eres un referente indiscutible de esta disciplina.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 200, 'badge' => 'challenge_4', 'sort' => 1703,
                'progress' => true, 'special' => true, 'diff' => 'expert',
                'ctx' => ['disciplineIds' => [5], 'includeIndividual' => false],
            ],
            [
                'name' => 'Especialista · Dual Pilates',
                'desc' => '20 clases de Dual Pilates. Tu técnica ya se nota claramente.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 20, 'badge' => 'challenge_1', 'sort' => 1710,
                'progress' => true, 'special' => false, 'diff' => 'easy',
                'ctx' => ['disciplineIds' => [6], 'includeIndividual' => false],
            ],
            [
                'name' => 'Gran especialista · Dual Pilates',
                'desc' => '50 clases de Dual Pilates. Tu dominio es completamente admirable.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 50, 'badge' => 'challenge_2', 'sort' => 1711,
                'progress' => true, 'special' => false, 'diff' => 'medium',
                'ctx' => ['disciplineIds' => [6], 'includeIndividual' => false],
            ],
            [
                'name' => 'Maestro de disciplina · Dual Pilates',
                'desc' => '100 clases de Dual Pilates. Pocos alcanzan este nivel de especialización.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 100, 'badge' => 'challenge_3', 'sort' => 1712,
                'progress' => true, 'special' => true, 'diff' => 'hard',
                'ctx' => ['disciplineIds' => [6], 'includeIndividual' => false],
            ],
            [
                'name' => 'Leyenda de la disciplina · Dual Pilates',
                'desc' => '200 clases de Dual Pilates. Eres un referente indiscutible de esta disciplina.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 200, 'badge' => 'challenge_4', 'sort' => 1713,
                'progress' => true, 'special' => true, 'diff' => 'expert',
                'ctx' => ['disciplineIds' => [6], 'includeIndividual' => false],
            ],
            [
                'name' => 'Especialista · Pilates Reformer',
                'desc' => '20 clases de Pilates Reformer. Tu técnica ya se nota claramente.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 20, 'badge' => 'challenge_1', 'sort' => 1720,
                'progress' => true, 'special' => false, 'diff' => 'easy',
                'ctx' => ['disciplineIds' => [2], 'includeIndividual' => false],
            ],
            [
                'name' => 'Gran especialista · Pilates Reformer',
                'desc' => '50 clases de Pilates Reformer. Tu dominio es completamente admirable.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 50, 'badge' => 'challenge_2', 'sort' => 1721,
                'progress' => true, 'special' => false, 'diff' => 'medium',
                'ctx' => ['disciplineIds' => [2], 'includeIndividual' => false],
            ],
            [
                'name' => 'Maestro de disciplina · Pilates Reformer',
                'desc' => '100 clases de Pilates Reformer. Pocos alcanzan este nivel de especialización.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 100, 'badge' => 'challenge_3', 'sort' => 1722,
                'progress' => true, 'special' => true, 'diff' => 'hard',
                'ctx' => ['disciplineIds' => [2], 'includeIndividual' => false],
            ],
            [
                'name' => 'Leyenda de la disciplina · Pilates Reformer',
                'desc' => '200 clases de Pilates Reformer. Eres un referente indiscutible de esta disciplina.',
                'cat' => 'disciplina', 'cond' => 'discipline_classes',
                'type' => 'count', 'target' => 200, 'badge' => 'challenge_4', 'sort' => 1723,
                'progress' => true, 'special' => true, 'diff' => 'expert',
                'ctx' => ['disciplineIds' => [2], 'includeIndividual' => false],
            ],

            // ── DISCIPLINA / multi_discipline_count ───────────────────────────
            // disciplineIds.length == target (invariante del wizard).
            // scope='each': cada disciplina necesita >= attendancesRequired clases.
            // Polideportivo y Atleta total: ctx=null hasta que haya más disciplinas activas.
            [
                'name' => 'Bidisciplinado',
                'desc' => 'Practicas 2 disciplinas distintas. La variedad te hace más fuerte.',
                'cat' => 'disciplina', 'cond' => 'multi_discipline_count',
                'type' => 'count', 'target' => 2, 'badge' => 'bronze', 'sort' => 1800,
                'progress' => false, 'special' => false,
                'ctx' => ['disciplineIds' => [5, 2], 'attendancesScope' => 'each', 'attendancesRequired' => 1, 'includeIndividual' => false],
            ],
            [
                'name' => 'Polifacético',
                'desc' => 'Tres disciplinas diferentes dominadas. Tu versatilidad es única.',
                'cat' => 'disciplina', 'cond' => 'multi_discipline_count',
                'type' => 'count', 'target' => 3, 'badge' => 'silver', 'sort' => 1810,
                'progress' => false, 'special' => false,
                'ctx' => ['disciplineIds' => [5, 6, 2], 'attendancesScope' => 'each', 'attendancesRequired' => 1, 'includeIndividual' => false],
            ],
            [
                'name' => 'Polideportivo',
                'desc' => 'Cinco disciplinas distintas exploradas. Eres un atleta verdaderamente completo.',
                'cat' => 'disciplina', 'cond' => 'multi_discipline_count',
                'type' => 'count', 'target' => 5, 'badge' => 'gold', 'sort' => 1820,
                'progress' => false, 'special' => true,
                'ctx' => null,
            ],
            [
                'name' => 'Atleta total',
                'desc' => '10 disciplinas dominadas. No hay disciplina en el Studio que no conozcas.',
                'cat' => 'disciplina', 'cond' => 'multi_discipline_count',
                'type' => 'count', 'target' => 10, 'badge' => 'diamond', 'sort' => 1830,
                'progress' => false, 'special' => true,
                'ctx' => null,
            ],

            // ── INSTRUCTOR / unique_instructors_count ─────────────────────────
            [
                'name' => 'Curioso',
                'desc' => 'Tomaste clases con 3 instructores distintos. Cada uno tiene algo único que aportar.',
                'cat' => 'instructor', 'cond' => 'unique_instructors_count',
                'type' => 'count', 'target' => 3, 'badge' => 'bronze', 'sort' => 1900,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Explorador de talentos',
                'desc' => '5 instructores distintos. Abres tu mente a diferentes estilos de enseñanza.',
                'cat' => 'instructor', 'cond' => 'unique_instructors_count',
                'type' => 'count', 'target' => 5, 'badge' => 'silver', 'sort' => 1910,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Coleccionista de estilos',
                'desc' => '10 instructores diferentes. Has vivido la riqueza formativa completa del Studio.',
                'cat' => 'instructor', 'cond' => 'unique_instructors_count',
                'type' => 'count', 'target' => 10, 'badge' => 'gold', 'sort' => 1920,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Maestro de maestros',
                'desc' => '20 instructores distintos. Tu experiencia formativa no tiene absolutamente ningún límite.',
                'cat' => 'instructor', 'cond' => 'unique_instructors_count',
                'type' => 'count', 'target' => 20, 'badge' => 'platinum', 'sort' => 1930,
                'progress' => true, 'special' => true,
            ],

            // ── COMUNIDAD / challenges_completed ──────────────────────────────
            [
                'name' => 'Primer reto',
                'desc' => 'Completaste tu primer reto especial. El camino de los retados empieza aquí.',
                'cat' => 'comunidad', 'cond' => 'challenges_completed',
                'type' => 'count', 'target' => 1, 'badge' => 'challenge_1', 'sort' => 2000,
                'progress' => true, 'special' => false, 'diff' => 'easy',
            ],
            [
                'name' => 'Triple amenaza',
                'desc' => 'Tres retos superados. Tu determinación ya es completamente imparable.',
                'cat' => 'comunidad', 'cond' => 'challenges_completed',
                'type' => 'count', 'target' => 3, 'badge' => 'challenge_2', 'sort' => 2010,
                'progress' => true, 'special' => false, 'diff' => 'medium',
            ],
            [
                'name' => 'Cinco de fuego',
                'desc' => 'Cinco retos completados. El fuego de la competencia te define.',
                'cat' => 'comunidad', 'cond' => 'challenges_completed',
                'type' => 'count', 'target' => 5, 'badge' => 'challenge_3', 'sort' => 2020,
                'progress' => true, 'special' => false, 'diff' => 'hard',
            ],
            [
                'name' => 'Veterano de retos',
                'desc' => '10 retos en tu historial. Eres un warrior auténticamente probado en batalla.',
                'cat' => 'comunidad', 'cond' => 'challenges_completed',
                'type' => 'count', 'target' => 10, 'badge' => 'challenge_4', 'sort' => 2030,
                'progress' => true, 'special' => true, 'diff' => 'hard',
            ],
            [
                'name' => 'Leyenda de retos',
                'desc' => '20 retos superados. Tu escudo de honor no tiene rival en todo el Studio.',
                'cat' => 'comunidad', 'cond' => 'challenges_completed',
                'type' => 'count', 'target' => 20, 'badge' => 'challenge_5', 'sort' => 2040,
                'progress' => true, 'special' => true, 'diff' => 'expert',
            ],

            // ── COMUNIDAD / friend_joint_classes ──────────────────────────────
            [
                'name' => 'Compañero de Studio',
                'desc' => 'Asististe a 1 clase junto a un amigo. Juntos siempre es mucho mejor.',
                'cat' => 'comunidad', 'cond' => 'friend_joint_classes',
                'type' => 'count', 'target' => 1, 'badge' => 'season_special', 'sort' => 2100,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Squad del Studio',
                'desc' => '5 clases con amigos. Tu energía grupal es absolutamente contagiosa.',
                'cat' => 'comunidad', 'cond' => 'friend_joint_classes',
                'type' => 'count', 'target' => 5, 'badge' => 'bronze', 'sort' => 2110,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Alma de la pandilla',
                'desc' => '10 clases en grupo. Tú eres el motor que impulsa a todo tu equipo.',
                'cat' => 'comunidad', 'cond' => 'friend_joint_classes',
                'type' => 'count', 'target' => 10, 'badge' => 'silver', 'sort' => 2120,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Maestro del grupo',
                'desc' => '25 clases compartidas con amigos. Tu presencia inspira profundamente a todos.',
                'cat' => 'comunidad', 'cond' => 'friend_joint_classes',
                'type' => 'count', 'target' => 25, 'badge' => 'gold', 'sort' => 2130,
                'progress' => true, 'special' => true,
            ],

            // ── COMUNIDAD / referrals_count ────────────────────────────────────
            [
                'name' => 'Primer referido',
                'desc' => 'Recomendaste el Studio y alguien se unió. Tu influencia positiva crece.',
                'cat' => 'comunidad', 'cond' => 'referrals_count',
                'type' => 'count', 'target' => 1, 'badge' => 'season_spring', 'sort' => 2200,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Embajador activo',
                'desc' => '5 personas se unieron gracias a ti. Eres un embajador completamente nato.',
                'cat' => 'comunidad', 'cond' => 'referrals_count',
                'type' => 'count', 'target' => 5, 'badge' => 'silver', 'sort' => 2210,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Influencer del Studio',
                'desc' => '10 referidos efectivos. Tu comunidad es tu mayor y más preciado logro.',
                'cat' => 'comunidad', 'cond' => 'referrals_count',
                'type' => 'count', 'target' => 10, 'badge' => 'gold', 'sort' => 2220,
                'progress' => true, 'special' => true,
            ],

            // ── COMUNIDAD / social_shares_count ───────────────────────────────
            [
                'name' => 'Primera publicación',
                'desc' => 'Compartiste el Studio en redes por primera vez. Tu voz llega mucho más lejos.',
                'cat' => 'comunidad', 'cond' => 'social_shares_count',
                'type' => 'count', 'target' => 1, 'badge' => 'season_special', 'sort' => 2300,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Creador de comunidad',
                'desc' => '5 veces compartido en redes. Tu contenido inspira y motiva a otros.',
                'cat' => 'comunidad', 'cond' => 'social_shares_count',
                'type' => 'count', 'target' => 5, 'badge' => 'bronze', 'sort' => 2310,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Viral',
                'desc' => '25 publicaciones en redes. Tu energía se contagia a quien te sigue.',
                'cat' => 'comunidad', 'cond' => 'social_shares_count',
                'type' => 'count', 'target' => 25, 'badge' => 'challenge_1', 'sort' => 2320,
                'progress' => true, 'special' => false,
            ],

            // ── COMUNIDAD / event_attendance_count ────────────────────────────
            // Badges de temporada para dar un carácter especial a cada hito.
            [
                'name' => 'Estreno especial',
                'desc' => 'Asististe a tu primer evento del Studio. Bienvenido a algo mucho más grande.',
                'cat' => 'comunidad', 'cond' => 'event_attendance_count',
                'type' => 'count', 'target' => 1, 'badge' => 'season_special', 'sort' => 2400,
                'progress' => false, 'special' => false,
            ],
            [
                'name' => 'Asiduo de eventos',
                'desc' => '3 eventos especiales en tu historial. Siempre presente en lo que importa.',
                'cat' => 'comunidad', 'cond' => 'event_attendance_count',
                'type' => 'count', 'target' => 3, 'badge' => 'season_spring', 'sort' => 2410,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Protagonista del Studio',
                'desc' => '7 eventos asistidos. Eres el alma y motor de cada celebración especial.',
                'cat' => 'comunidad', 'cond' => 'event_attendance_count',
                'type' => 'count', 'target' => 7, 'badge' => 'season_summer', 'sort' => 2420,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Espíritu navideño',
                'desc' => '12 eventos en tu historial. Siempre en primera fila en todas las celebraciones.',
                'cat' => 'comunidad', 'cond' => 'event_attendance_count',
                'type' => 'count', 'target' => 12, 'badge' => 'season_xmas', 'sort' => 2430,
                'progress' => true, 'special' => true,
            ],

            // ── CALIFICACIÓN / rating_votes_min ───────────────────────────────
            [
                'name' => 'Evaluador activo',
                'desc' => '10 calificaciones registradas. Tu opinión ayuda a mejorar el Studio cada día.',
                'cat' => 'calificacion', 'cond' => 'rating_votes_min',
                'type' => 'count', 'target' => 10, 'badge' => 'bronze', 'sort' => 2500,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Voz autorizada',
                'desc' => '30 calificaciones dadas. Tu opinión construye y moldea nuestra comunidad.',
                'cat' => 'calificacion', 'cond' => 'rating_votes_min',
                'type' => 'count', 'target' => 30, 'badge' => 'silver', 'sort' => 2510,
                'progress' => true, 'special' => false,
            ],
            [
                'name' => 'Crítico de confianza',
                'desc' => '50 calificaciones registradas. Eres una referencia genuina de calidad.',
                'cat' => 'calificacion', 'cond' => 'rating_votes_min',
                'type' => 'count', 'target' => 50, 'badge' => 'gold', 'sort' => 2520,
                'progress' => true, 'special' => true,
            ],
            [
                'name' => 'Crítico experto',
                'desc' => '100 calificaciones registradas. Eres la voz más confiable de toda nuestra comunidad.',
                'cat' => 'calificacion', 'cond' => 'rating_votes_min',
                'type' => 'count', 'target' => 100, 'badge' => 'challenge_3', 'sort' => 2530,
                'progress' => true, 'special' => true,
            ],
        ];
    }
}
