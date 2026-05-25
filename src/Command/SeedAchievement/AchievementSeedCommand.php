<?php

declare(strict_types=1);

namespace App\Command\SeedAchievement;

use App\Command\SeedAchievement\AchievementSeedUserCommand;
use App\Entity\AchievementConditionCatalog;
use App\Entity\AchievementThresholdOption;
use App\Entity\AchievementBadge;
use App\Repository\AchievementConditionCatalogRepository;
use App\Repository\AchievementThresholdOptionRepository;
use App\Repository\AchievementBadgeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:achievement:seed',
    description: 'Siembra datos de condiciones, umbrales y logros de ejemplo en la BD.',
)]
class AchievementSeedCommand extends Command
{
    public function __construct(
        private readonly AchievementConditionCatalogRepository $conditionRepository,
        private readonly AchievementThresholdOptionRepository $thresholdRepository,
        private readonly AchievementBadgeRepository $badgeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Sobreescribir si ya existen los datos.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        try {
            if ($force) {
                $io->section('Limpiando catálogo existente (--force)...');

                $thresholds = $this->thresholdRepository->findAll();
                foreach ($thresholds as $thr) {
                    $this->entityManager->remove($thr);
                }
                $this->entityManager->flush();

                $conditions = $this->conditionRepository->findAll();
                foreach ($conditions as $cond) {
                    $this->entityManager->remove($cond);
                }
                $this->entityManager->flush();

                $badges = $this->badgeRepository->findAll();
                foreach ($badges as $b) {
                    $this->entityManager->remove($b);
                }
                $this->entityManager->flush();

                $io->info('✓ Catálogo limpio');
            }

            // 1. Badges
            $io->section('1. Insertando niveles de badge...');
            $badgeCount = $this->seedBadgeLevels($io, $force);
            $io->success("✓ $badgeCount badges procesados");

            // 2. Condiciones de catálogo
            $io->section('2. Insertando condiciones de catálogo...');
            $conditionCount = $this->seedConditions($io, $force);
            $io->success("✓ $conditionCount condiciones procesadas");

            // 3. Opciones de umbral
            $io->section('3. Insertando opciones de umbral...');
            $thresholdCount = $this->seedThresholds($io, $force);
            $io->success("✓ $thresholdCount opciones de umbral procesadas");

            $io->success('✅ Catálogo sembrado. Ejecuta app:achievement:seed-user para añadir logros y conquistas de prueba.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('❌ Error durante seed: '.$e->getMessage());
            return Command::FAILURE;
        }
    }

    private function seedBadgeLevels(SymfonyStyle $io, bool $force): int
    {
        $badges = [
            // Progresión estándar
            ['bronze',            '🥉', 'Bronce',          100,   '#8f5d43', 'Progresión estándar',   10],
            ['silver',            '🥈', 'Plata',           250,   '#8f9db1', 'Progresión estándar',   20],
            ['gold',              '🥇', 'Oro',             500,   '#c9a227', 'Progresión estándar',   30],
            ['platinum',          '💠', 'Platino',         1000,  '#3f7f93', 'Progresión estándar',   40],
            ['diamond',           '💎', 'Diamante',        2000,  '#3f78c8', 'Progresión estándar',   50],
            ['master',            '👑', 'Maestro',         5000,  '#7a42b5', 'Progresión estándar',   60],
            ['legend',            '🏆', 'Leyenda',         10000, '#bfa24e', 'Progresión estándar',   70],
            // Retos personalizados
            ['challenge_1',       '🎯', 'Reto 1',          0,     '#e05c2a', 'Retos personalizados',  110],
            ['challenge_2',       '⚡', 'Reto 2',          0,     '#d4287e', 'Retos personalizados',  120],
            ['challenge_3',       '🔥', 'Reto 3',          0,     '#1a7a6e', 'Retos personalizados',  130],
            ['challenge_4',       '💪', 'Reto 4',          0,     '#5b6e8c', 'Retos personalizados',  140],
            ['challenge_5',       '🛡️', 'Reto 5',         0,     '#2e3d6b', 'Retos personalizados',  150],
            // Temporadas
            ['season_spring',     '🌸', 'Primavera',       0,     '#4caf50', 'Temporadas',            210],
            ['season_summer',     '☀️', 'Verano',          0,     '#f9a825', 'Temporadas',            220],
            ['season_fall',       '🍂', 'Otoño',           0,     '#bf6c1a', 'Temporadas',            230],
            ['season_winter',     '❄️', 'Invierno',        0,     '#5b9bd5', 'Temporadas',            240],
            ['season_xmas',       '🎄', 'Navidad',         0,     '#c0392b', 'Temporadas',            250],
            ['season_special',    '✨', 'Especial',        0,     '#8e44ad', 'Temporadas',            260],
            // Animales / Poder
            ['animal_lion',       '🦁', 'León',            0,     '#c9830a', 'Animales / Poder',      310],
            ['animal_eagle',      '🦅', 'Águila',          0,     '#5b4a2e', 'Animales / Poder',      320],
            ['animal_wolf',       '🐺', 'Lobo',            0,     '#5a607a', 'Animales / Poder',      330],
            ['animal_bull',       '🐂', 'Toro',            0,     '#7a2e2e', 'Animales / Poder',      340],
            ['animal_shark',      '🦈', 'Tiburón',         0,     '#2e6b8a', 'Animales / Poder',      350],
            ['animal_dragon',     '🐉', 'Dragón',          0,     '#1a6b3a', 'Animales / Poder',      360],
            ['animal_panther',    '🐆', 'Pantera',         0,     '#3a2e1a', 'Animales / Poder',      370],
            ['animal_bear',       '🐻', 'Oso',             0,     '#7a5c3a', 'Animales / Poder',      380],
            // Naturaleza / Elementos
            ['element_water',     '🌊', 'Ola',             0,     '#1a78c2', 'Naturaleza / Elementos', 410],
            ['element_storm',     '🌪️', 'Tormenta',        0,     '#5a6a7a', 'Naturaleza / Elementos', 420],
            ['element_moon',      '🌙', 'Luna',            0,     '#4a3f7a', 'Naturaleza / Elementos', 430],
            ['element_volcano',   '🌋', 'Volcán',          0,     '#8a2e1a', 'Naturaleza / Elementos', 440],
            ['element_mountain',  '🗻', 'Montaña',         0,     '#4a5a6a', 'Naturaleza / Elementos', 450],
            ['element_leaf',      '🍃', 'Brisa',           0,     '#2e7a4a', 'Naturaleza / Elementos', 460],
            ['element_crystal',   '🔷', 'Cristal',         0,     '#1a6a8a', 'Naturaleza / Elementos', 470],
            ['element_drop',      '💧', 'Gota',            0,     '#2e8ab5', 'Naturaleza / Elementos', 480],
            // Fitness / Disciplinas
            ['sport_strength',    '🏋️', 'Fuerza',         0,     '#7a2e2e', 'Fitness / Disciplinas',  510],
            ['sport_zen',         '🧘', 'Zen',             0,     '#4a7a5a', 'Fitness / Disciplinas',  520],
            ['sport_run',         '🏃', 'Velocidad',       0,     '#2e5a8a', 'Fitness / Disciplinas',  530],
            ['sport_flex',        '🤸', 'Flexibilidad',    0,     '#8a5a2e', 'Fitness / Disciplinas',  540],
            ['sport_cardio',      '💗', 'Cardio',          0,     '#c0392b', 'Fitness / Disciplinas',  550],
            ['sport_boxing',      '🥊', 'Boxeo',           0,     '#8a2e5a', 'Fitness / Disciplinas',  560],
            ['sport_swim',        '🏊', 'Natación',        0,     '#1a6a9a', 'Fitness / Disciplinas',  570],
            ['sport_bike',        '🚴', 'Ciclismo',        0,     '#2e7a2e', 'Fitness / Disciplinas',  580],
            ['sport_rocket',      '🚀', 'Impulso',         0,     '#2e2e7a', 'Fitness / Disciplinas',  590],
            // Honor / Rango
            ['rank_sword',        '⚔️', 'Guerrero',        0,     '#7a3a1a', 'Honor / Rango',          610],
            ['rank_medal',        '🎖️', 'Condecoración',   0,     '#c9a227', 'Honor / Rango',          620],
            ['rank_captain',      '⚓', 'Capitán',         0,     '#1a3a7a', 'Honor / Rango',          630],
            ['rank_hero',         '🦸', 'Héroe',           0,     '#7a1a7a', 'Honor / Rango',          640],
            ['rank_archer',       '🏹', 'Arquero',         0,     '#3a5a2e', 'Honor / Rango',          650],
            ['rank_samurai',      '🗡️', 'Samurái',         0,     '#2e2e2e', 'Honor / Rango',          660],
            ['rank_ribbon',       '🎗️', 'Distinción',      0,     '#b54a8a', 'Honor / Rango',          670],
            ['rank_fortress',     '🏯', 'Fortaleza',       0,     '#5a4a3a', 'Honor / Rango',          680],
            // Comunidad / Social
            ['social_ambassador', '📢', 'Embajador',       0,     '#e05c2a', 'Comunidad / Social',     710],
            ['social_ally',       '🤝', 'Aliado',          0,     '#2e7a5a', 'Comunidad / Social',     720],
            ['social_vip',        '⭐', 'VIP',             0,     '#c9a227', 'Comunidad / Social',     730],
            ['social_loyal',      '❤️', 'Fiel',            0,     '#c0392b', 'Comunidad / Social',     740],
            ['social_spark',      '💫', 'Inspiración',     0,     '#8e44ad', 'Comunidad / Social',     750],
            ['social_crown',      '👸', 'Ícono',           0,     '#b5860a', 'Comunidad / Social',     760],
            ['social_megaphone',  '🗣️', 'Vocero',          0,     '#2e5a7a', 'Comunidad / Social',     770],
            ['social_referrer',   '🙌', 'Referidor',       0,     '#4a7a2e', 'Comunidad / Social',     780],
            // Cosmos / Universo
            ['cosmos_star',       '🌟', 'Supernova',       0,     '#c9a227', 'Cosmos / Universo',      810],
            ['cosmos_galaxy',     '🌌', 'Galaxia',         0,     '#1a1a5a', 'Cosmos / Universo',      820],
            ['cosmos_planet',     '🪐', 'Planeta',         0,     '#5a3a8a', 'Cosmos / Universo',      830],
            ['cosmos_meteor',     '🌠', 'Meteoro',         0,     '#2e4a7a', 'Cosmos / Universo',      840],
            ['cosmos_comet',      '☄️', 'Cometa',          0,     '#7a4a1a', 'Cosmos / Universo',      850],
            ['cosmos_astronaut',  '👨‍🚀', 'Explorador',    0,     '#1a5a7a', 'Cosmos / Universo',      860],
            ['cosmos_black_hole', '🕳️', 'Singularidad',    0,     '#1a1a1a', 'Cosmos / Universo',      870],
            ['cosmos_telescope',  '🔭', 'Visionario',      0,     '#3a3a6a', 'Cosmos / Universo',      880],
            // Magia / Misterio
            ['magic_orb',         '🔮', 'Oráculo',         0,     '#5a1a8a', 'Magia / Misterio',       910],
            ['magic_wand',        '🎩', 'Mago',            0,     '#7a2e7a', 'Magia / Misterio',       920],
            ['magic_spiral',      '🌀', 'Espiral',         0,     '#1a6a8a', 'Magia / Misterio',       930],
            ['magic_talisman',    '🧿', 'Talismán',        0,     '#1a5a3a', 'Magia / Misterio',       940],
            ['magic_key',         '🗝️', 'Llave Secreta',   0,     '#8a6a1a', 'Magia / Misterio',       950],
            ['magic_skull',       '💀', 'Oscuro',          0,     '#2e2e2e', 'Magia / Misterio',       960],
            ['magic_infinity',    '♾️', 'Infinito',        0,     '#3a3a7a', 'Magia / Misterio',       970],
            ['magic_phantom',     '👻', 'Fantasma',        0,     '#7a7a9a', 'Magia / Misterio',       980],
            // Evolución / Ciencia
            ['science_dna',       '🧬', 'ADN',             0,     '#1a7a4a', 'Evolución / Ciencia',   1010],
            ['science_lab',       '🧪', 'Laboratorio',     0,     '#4a1a7a', 'Evolución / Ciencia',   1020],
            ['science_brain',     '🧠', 'Mente',           0,     '#8a2e5a', 'Evolución / Ciencia',   1030],
            ['science_atom',      '⚛️', 'Átomo',           0,     '#1a4a8a', 'Evolución / Ciencia',   1040],
            ['science_robot',     '🤖', 'Máquina',         0,     '#3a3a5a', 'Evolución / Ciencia',   1050],
            ['science_gear',      '⚙️', 'Engranaje',       0,     '#5a5a5a', 'Evolución / Ciencia',   1060],
            ['science_bulb',      '💡', 'Innovador',       0,     '#c9a227', 'Evolución / Ciencia',   1070],
            ['science_micro',     '🔬', 'Microscopio',     0,     '#2e5a7a', 'Evolución / Ciencia',   1080],
            // Nutrición / Bienestar
            ['wellness_salad',    '🥗', 'Nutrido',         0,     '#2e7a2e', 'Nutrición / Bienestar', 1110],
            ['wellness_avocado',  '🥑', 'Saludable',       0,     '#3a7a1a', 'Nutrición / Bienestar', 1120],
            ['wellness_apple',    '🍏', 'Limpio',          0,     '#2e8a2e', 'Nutrición / Bienestar', 1130],
            ['wellness_water',    '🥤', 'Hidratado',       0,     '#1a7ab5', 'Nutrición / Bienestar', 1140],
            ['wellness_sleep',    '😴', 'Descanso',        0,     '#4a3a7a', 'Nutrición / Bienestar', 1150],
            ['wellness_meditate', '🌿', 'Equilibrio',      0,     '#2e6a4a', 'Nutrición / Bienestar', 1160],
            ['wellness_energy',   '🧃', 'Energizado',      0,     '#e07a1a', 'Nutrición / Bienestar', 1170],
            ['wellness_heart',    '💚', 'Bienestar',       0,     '#1a8a3a', 'Nutrición / Bienestar', 1180],
            // Arte / Creatividad
            ['art_palette',       '🎨', 'Artista',         0,     '#d4287e', 'Arte / Creatividad',    1210],
            ['art_music',         '🎵', 'Melodía',         0,     '#7a2e8a', 'Arte / Creatividad',    1220],
            ['art_film',          '🎬', 'Director',        0,     '#1a1a1a', 'Arte / Creatividad',    1230],
            ['art_theater',       '🎭', 'Teatro',          0,     '#8a2e2e', 'Arte / Creatividad',    1240],
            ['art_pencil',        '✏️', 'Creativo',        0,     '#5a4a2e', 'Arte / Creatividad',    1250],
            ['art_camera',        '📸', 'Fotógrafo',       0,     '#2e2e5a', 'Arte / Creatividad',    1260],
            ['art_guitar',        '🎸', 'Rock',            0,     '#5a1a1a', 'Arte / Creatividad',    1270],
            ['art_dance',         '💃', 'Danza',           0,     '#c0392b', 'Arte / Creatividad',    1280],
        ];

        $processed = 0;
        foreach ($badges as [$key, $icon, $name, $pts, $color, $group, $sort]) {
            $existing = $this->badgeRepository->findOneBy(['badgeKey' => $key]);

            if (!$existing || $force) {
                if ($existing && $force) {
                    $this->entityManager->remove($existing);
                    $this->entityManager->flush();
                }

                $badge = new AchievementBadge();
                $badge->setBadgeKey($key);
                $badge->setIcon($icon);
                $badge->setName($name);
                $badge->setDefaultPts($pts);
                $badge->setColor($color);
                $badge->setBadgeGroup($group);
                $badge->setSortOrder($sort);
                $badge->setIsActive(true);

                $this->entityManager->persist($badge);
                $processed++;
            }
        }

        $this->entityManager->flush();
        return $processed;
    }

    private function seedConditions(SymfonyStyle $io, bool $force): int
    {
        $conditions = [
            // Asistencia (3 condiciones)
            ['asistencia', 'attended_classes', 'Clases asistidas', 'count', 1, 1, 10000, 10],
            ['asistencia', 'unique_days_attended', 'Días distintos asistidos', 'count', 1, 1, 3650, 20],
            ['asistencia', 'weekly_frequency', 'Frecuencia semanal promedio', 'count', 1, 1, 14, 30],

            // Compras (5 condiciones)
            ['compras', 'total_amount', 'Monto acumulado', 'amount', 1, 1, 9999999, 20],
            ['compras', 'total_transactions', 'Transacciones pagadas', 'count', 1, 1, 10000, 30],
            ['compras', 'consecutive_paid_months', 'Membresía activa continua', 'months', 1, 1, 240, 40],
            ['compras', 'gift_cards_count', 'Tarjetas de regalo compradas', 'count', 1, 1, 1000, 50],
            ['compras', 'specific_package_count', 'Paquete específico comprado', 'count', 1, 1, 500, 55],

            // Antigüedad (3 condiciones)
            ['antiguedad', 'active_months', 'Meses activos', 'months', 1, 1, 600, 40],
            ['antiguedad', 'active_years', 'Años activos', 'months', 1, 12, 600, 50],
            ['antiguedad', 'consolidated_client', 'Cliente consolidado', 'months', 1, 6, 600, 60],

            // Racha (3 condiciones)
            ['racha', 'consecutive_days', 'Días consecutivos', 'days', 1, 1, 3650, 50],
            ['racha', 'consecutive_weeks', 'Semanas consecutivas', 'count', 1, 1, 520, 60],
            ['racha', 'no_show_free_days', 'Días sin inasistencia', 'days', 1, 1, 3650, 70],

            // Disciplina (2 condiciones — discipline_mastery_days eliminado)
            ['disciplina', 'discipline_classes', 'Clases por disciplina', 'count', 1, 1, 10000, 60],
            ['disciplina', 'multi_discipline_count', 'Cantidad de disciplinas', 'count', 1, 1, 50, 70],

            // Calificación (1 condición — avg_rating y five_star_count eliminados)
            ['calificacion', 'rating_votes_min', 'Mínimo de evaluaciones', 'count', 1, 1, 10000, 90],

            // Instructor (1 condición — top_instructor_classes e instructor_consistency eliminados)
            ['instructor', 'unique_instructors_count', 'Instructores distintos', 'count', 1, 1, 1000, 90],

            // Comunidad (5 condiciones)
            ['comunidad', 'challenges_completed', 'Retos completados', 'count', 1, 1, 2000, 90],
            ['comunidad', 'friend_joint_classes', 'Clases con amigos', 'count', 1, 1, 1000, 100],
            ['comunidad', 'referrals_count', 'Referidos efectivos', 'count', 1, 1, 500, 110],
            ['comunidad', 'social_shares_count', 'Compartidos en redes', 'count', 1, 1, 500, 120],
            ['comunidad', 'event_attendance_count', 'Eventos especiales asistidos', 'count', 1, 1, 500, 130],

            // Hábitos (4 condiciones)
            ['habitos', 'checkin_morning_count', 'Asistencias matutinas', 'count', 1, 1, 10000, 100],
            ['habitos', 'weekend_attendance', 'Asistencia fin de semana', 'count', 1, 1, 10000, 110],
            ['habitos', 'checkin_evening_count', 'Asistencias vespertinas', 'count', 1, 1, 10000, 120],
            ['habitos', 'consecutive_same_time_slot', 'Asistencias en horario fijo', 'count', 1, 1, 10000, 130],
        ];

        $processed = 0;
        foreach ($conditions as [$categoryKey, $conditionKey, $label, $thresholdType, $allowsCustom, $minVal, $maxVal, $sortOrder]) {
            $existing = $this->conditionRepository->findOneBy(['categoryKey' => $categoryKey, 'conditionKey' => $conditionKey]);

            if (!$existing) {
                $condition = new AchievementConditionCatalog();
                $condition->setCategoryKey($categoryKey);
                $condition->setConditionKey($conditionKey);
                $condition->setConditionLabel($label);
                $condition->setThresholdType($thresholdType);
                $condition->setAllowsCustomValue((bool) $allowsCustom);
                $condition->setMinValue((string) $minVal);
                $condition->setMaxValue((string) $maxVal);
                $condition->setActive(true);
                $condition->setSortOrder((int) $sortOrder);

                $this->entityManager->persist($condition);
                $processed++;
            } else {
                // Actualizar label y maxVal en condiciones existentes si cambiaron
                $existing->setConditionLabel($label);
                $existing->setMaxValue((string) $maxVal);
            }
        }

        $this->entityManager->flush();
        return $processed;
    }

    private function seedThresholds(SymfonyStyle $io, bool $force): int
    {
        // Limpiar umbrales obsoletos de consecutive_same_time_slot (semanas → clases)
        $timeslotCond = $this->conditionRepository->findOneBy([
            'categoryKey' => 'habitos',
            'conditionKey' => 'consecutive_same_time_slot',
        ]);
        if ($timeslotCond) {
            foreach ([4, 8, 12, 24] as $oldValue) {
                $oldThreshold = $this->thresholdRepository->findOneBy([
                    'condition' => $timeslotCond,
                    'optionValue' => (string) $oldValue,
                ]);
                if ($oldThreshold) {
                    $this->entityManager->remove($oldThreshold);
                }
            }
            $this->entityManager->flush();
        }

        $thresholds = [
            // Asistencia
            ['asistencia', 'attended_classes', 10, '10 clases', 10],
            ['asistencia', 'attended_classes', 25, '25 clases', 20],
            ['asistencia', 'attended_classes', 50, '50 clases', 30],
            ['asistencia', 'attended_classes', 100, '100 clases', 40],
            ['asistencia', 'unique_days_attended', 10, '10 días', 10],
            ['asistencia', 'unique_days_attended', 20, '20 días', 20],
            ['asistencia', 'unique_days_attended', 30, '30 días', 30],
            ['asistencia', 'weekly_frequency', 2, '2 veces/semana', 10],
            ['asistencia', 'weekly_frequency', 3, '3 veces/semana', 20],
            ['asistencia', 'weekly_frequency', 5, '5 veces/semana', 30],

            // Compras
            ['compras', 'total_amount', 5000, '$5,000', 10],
            ['compras', 'total_amount', 10000, '$10,000', 20],
            ['compras', 'total_amount', 25000, '$25,000', 30],
            ['compras', 'total_transactions', 5, '5 compras', 10],
            ['compras', 'total_transactions', 10, '10 compras', 20],
            ['compras', 'total_transactions', 12, '12 compras', 25],
            ['compras', 'total_transactions', 24, '24 compras', 28],
            ['compras', 'total_transactions', 25, '25 compras', 30],
            ['compras', 'consecutive_paid_months', 3, '3 meses', 10],
            ['compras', 'consecutive_paid_months', 6, '6 meses', 20],
            ['compras', 'consecutive_paid_months', 12, '12 meses', 30],
            ['compras', 'gift_cards_count', 1, '1 tarjeta', 10],
            ['compras', 'gift_cards_count', 3, '3 tarjetas', 20],
            ['compras', 'gift_cards_count', 5, '5 tarjetas', 30],
            ['compras', 'specific_package_count', 1, '1 vez', 10],
            ['compras', 'specific_package_count', 3, '3 veces', 20],
            ['compras', 'specific_package_count', 5, '5 veces', 30],
            ['compras', 'specific_package_count', 10, '10 veces', 40],

            // Antigüedad
            ['antiguedad', 'active_months', 3, '3 meses', 10],
            ['antiguedad', 'active_months', 6, '6 meses', 20],
            ['antiguedad', 'active_months', 12, '12 meses', 30],
            ['antiguedad', 'active_years', 12, '1 año', 10],
            ['antiguedad', 'active_years', 24, '2 años', 20],
            ['antiguedad', 'active_years', 36, '3 años', 30],
            ['antiguedad', 'active_years', 60, '5 años', 40],
            ['antiguedad', 'consolidated_client', 6, '6 meses', 10],
            ['antiguedad', 'consolidated_client', 12, '12 meses', 20],
            ['antiguedad', 'consolidated_client', 24, '24 meses', 30],

            // Racha
            ['racha', 'consecutive_days', 7, '7 días', 5],
            ['racha', 'consecutive_days', 14, '14 días', 8],
            ['racha', 'consecutive_days', 15, '15 días', 10],
            ['racha', 'consecutive_days', 30, '30 días', 20],
            ['racha', 'consecutive_days', 90, '90 días', 30],
            ['racha', 'consecutive_weeks', 4, '4 semanas', 10],
            ['racha', 'consecutive_weeks', 8, '8 semanas', 20],
            ['racha', 'consecutive_weeks', 12, '12 semanas', 30],
            ['racha', 'no_show_free_days', 30, '30 días', 10],
            ['racha', 'no_show_free_days', 60, '60 días', 20],
            ['racha', 'no_show_free_days', 90, '90 días', 30],

            // Disciplina
            ['disciplina', 'discipline_classes', 10, '10 clases', 10],
            ['disciplina', 'discipline_classes', 20, '20 clases', 15],
            ['disciplina', 'discipline_classes', 25, '25 clases', 20],
            ['disciplina', 'discipline_classes', 50, '50 clases', 30],
            ['disciplina', 'discipline_classes', 100, '100 clases', 40],
            ['disciplina', 'multi_discipline_count', 2, '2 disciplinas', 10],
            ['disciplina', 'multi_discipline_count', 3, '3 disciplinas', 20],
            ['disciplina', 'multi_discipline_count', 4, '4 disciplinas', 30],
            ['disciplina', 'multi_discipline_count', 5, '5 disciplinas', 40],
            ['disciplina', 'multi_discipline_count', 10, '10 disciplinas', 50],
            // Calificación (avg_rating y five_star_count eliminados)
            ['calificacion', 'rating_votes_min', 10, '10 evaluaciones', 10],
            ['calificacion', 'rating_votes_min', 30, '30 evaluaciones', 20],
            ['calificacion', 'rating_votes_min', 50, '50 evaluaciones', 30],
            ['calificacion', 'rating_votes_min', 100, '100 evaluaciones', 40],

            // Instructor
            // Instructor (top_instructor_classes e instructor_consistency eliminados)
            ['instructor', 'unique_instructors_count', 3, '3 instructores', 10],
            ['instructor', 'unique_instructors_count', 5, '5 instructores', 20],
            ['instructor', 'unique_instructors_count', 10, '10 instructores', 30],

            // Comunidad
            ['comunidad', 'referrals_count', 1, '1 referido', 10],
            ['comunidad', 'referrals_count', 3, '3 referidos', 20],
            ['comunidad', 'referrals_count', 5, '5 referidos', 30],
            ['comunidad', 'referrals_count', 10, '10 referidos', 40],
            ['comunidad', 'challenges_completed', 1, '1 reto', 5],
            ['comunidad', 'challenges_completed', 3, '3 retos', 10],
            ['comunidad', 'challenges_completed', 10, '10 retos', 20],
            ['comunidad', 'challenges_completed', 25, '25 retos', 30],
            ['comunidad', 'social_shares_count', 1, '1 compartido', 10],
            ['comunidad', 'social_shares_count', 3, '3 compartidos', 20],
            ['comunidad', 'social_shares_count', 5, '5 compartidos', 30],
            ['comunidad', 'social_shares_count', 10, '10 compartidos', 40],
            ['comunidad', 'social_shares_count', 25, '25 compartidos', 50],
            ['comunidad', 'event_attendance_count', 1, '1 evento', 10],
            ['comunidad', 'event_attendance_count', 3, '3 eventos', 20],
            ['comunidad', 'event_attendance_count', 5, '5 eventos', 30],
            ['comunidad', 'event_attendance_count', 7, '7 eventos', 35],
            ['comunidad', 'event_attendance_count', 10, '10 eventos', 40],
            ['comunidad', 'event_attendance_count', 12, '12 eventos', 45],
            ['comunidad', 'friend_joint_classes', 1, '1 clase', 10],
            ['comunidad', 'friend_joint_classes', 5, '5 clases', 20],
            ['comunidad', 'friend_joint_classes', 10, '10 clases', 30],
            ['comunidad', 'friend_joint_classes', 25, '25 clases', 40],

            // Hábitos
            ['habitos', 'checkin_morning_count', 10, '10 asistencias', 10],
            ['habitos', 'checkin_morning_count', 25, '25 asistencias', 20],
            ['habitos', 'checkin_morning_count', 50, '50 asistencias', 30],
            ['habitos', 'weekend_attendance', 5, '5 asistencias', 10],
            ['habitos', 'weekend_attendance', 10, '10 asistencias', 20],
            ['habitos', 'weekend_attendance', 25, '25 asistencias', 30],
            ['habitos', 'checkin_evening_count', 10, '10 asistencias', 10],
            ['habitos', 'checkin_evening_count', 25, '25 asistencias', 20],
            ['habitos', 'checkin_evening_count', 50, '50 asistencias', 30],
            ['habitos', 'consecutive_same_time_slot', 10, '10 clases', 10],
            ['habitos', 'consecutive_same_time_slot', 12, '12 clases', 15],
            ['habitos', 'consecutive_same_time_slot', 24, '24 clases', 18],
            ['habitos', 'consecutive_same_time_slot', 25, '25 clases', 20],
            ['habitos', 'consecutive_same_time_slot', 50, '50 clases', 30],
            ['habitos', 'consecutive_same_time_slot', 100, '100 clases', 40],
        ];

        $processed = 0;
        foreach ($thresholds as [$categoryKey, $conditionKey, $optionValue, $optionLabel, $sortOrder]) {
            $condition = $this->conditionRepository->findOneBy(['categoryKey' => $categoryKey, 'conditionKey' => $conditionKey]);

            if ($condition) {
                $existing = $this->thresholdRepository->findOneBy(['condition' => $condition, 'optionValue' => $optionValue]);

                if (!$existing || $force) {
                    if ($existing && $force) {
                        $this->entityManager->remove($existing);
                    }

                    $threshold = new AchievementThresholdOption();
                    $threshold->setCondition($condition);
                    $threshold->setOptionValue((string) $optionValue);
                    $threshold->setOptionLabel($optionLabel);
                    $threshold->setSortOrder((int) $sortOrder);
                    $threshold->setActive(true);

                    $this->entityManager->persist($threshold);
                    $processed++;
                }
            }
        }

        $this->entityManager->flush();
        return $processed;
    }
}
