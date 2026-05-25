#!/usr/bin/env php
<?php

require_once dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$realKernel = new \App\Kernel($_ENV['APP_ENV'] ?? 'dev', (bool) ($_ENV['APP_DEBUG'] ?? true));
$realKernel->boot();
$container  = $realKernel->getContainer();
$connection = $container->get('doctrine.dbal.default_connection');

echo "Iniciando seed de datos de Achievement...\n\n";

try {
    // 0. Seed achievement_badge
    echo "0. Insertando badges...\n";

    $connection->executeStatement('DELETE FROM achievement_badge');

    $badges = [
        // Progresión estándar
        ['bronze',            '🥉', 'Bronce',        100,   '#8f5d43', 'Progresión estándar',   10],
        ['silver',            '🥈', 'Plata',          250,   '#8f9db1', 'Progresión estándar',   20],
        ['gold',              '🥇', 'Oro',            500,   '#c9a227', 'Progresión estándar',   30],
        ['platinum',          '💠', 'Platino',        1000,  '#3f7f93', 'Progresión estándar',   40],
        ['diamond',           '💎', 'Diamante',       2000,  '#3f78c8', 'Progresión estándar',   50],
        ['master',            '👑', 'Maestro',        5000,  '#7a42b5', 'Progresión estándar',   60],
        ['legend',            '🏆', 'Leyenda',        10000, '#bfa24e', 'Progresión estándar',   70],
        // Retos personalizados
        ['challenge_1',       '🎯', 'Reto 1',         0, '#e05c2a', 'Retos personalizados',    110],
        ['challenge_2',       '⚡', 'Reto 2',         0, '#d4287e', 'Retos personalizados',    120],
        ['challenge_3',       '🔥', 'Reto 3',         0, '#1a7a6e', 'Retos personalizados',    130],
        ['challenge_4',       '💪', 'Reto 4',         0, '#5b6e8c', 'Retos personalizados',    140],
        ['challenge_5',       '🛡️', 'Reto 5',        0, '#2e3d6b', 'Retos personalizados',    150],
        // Temporadas
        ['season_spring',     '🌸', 'Primavera',      0, '#4caf50', 'Temporadas',              210],
        ['season_summer',     '☀️', 'Verano',         0, '#f9a825', 'Temporadas',              220],
        ['season_fall',       '🍂', 'Otoño',          0, '#bf6c1a', 'Temporadas',              230],
        ['season_winter',     '❄️', 'Invierno',       0, '#5b9bd5', 'Temporadas',              240],
        ['season_xmas',       '🎄', 'Navidad',        0, '#c0392b', 'Temporadas',              250],
        ['season_special',    '✨', 'Especial',       0, '#8e44ad', 'Temporadas',              260],
        // Animales / Poder
        ['animal_lion',       '🦁', 'León',           0, '#c9830a', 'Animales / Poder',        310],
        ['animal_eagle',      '🦅', 'Águila',         0, '#5b4a2e', 'Animales / Poder',        320],
        ['animal_wolf',       '🐺', 'Lobo',           0, '#5a607a', 'Animales / Poder',        330],
        ['animal_bull',       '🐂', 'Toro',           0, '#7a2e2e', 'Animales / Poder',        340],
        ['animal_shark',      '🦈', 'Tiburón',        0, '#2e6b8a', 'Animales / Poder',        350],
        ['animal_dragon',     '🐉', 'Dragón',         0, '#1a6b3a', 'Animales / Poder',        360],
        ['animal_panther',    '🐆', 'Pantera',        0, '#3a2e1a', 'Animales / Poder',        370],
        ['animal_bear',       '🐻', 'Oso',            0, '#7a5c3a', 'Animales / Poder',        380],
        // Naturaleza / Elementos
        ['element_water',     '🌊', 'Ola',            0, '#1a78c2', 'Naturaleza / Elementos',  410],
        ['element_storm',     '🌪️', 'Tormenta',       0, '#5a6a7a', 'Naturaleza / Elementos',  420],
        ['element_moon',      '🌙', 'Luna',           0, '#4a3f7a', 'Naturaleza / Elementos',  430],
        ['element_volcano',   '🌋', 'Volcán',         0, '#8a2e1a', 'Naturaleza / Elementos',  440],
        ['element_mountain',  '🗻', 'Montaña',        0, '#4a5a6a', 'Naturaleza / Elementos',  450],
        ['element_leaf',      '🍃', 'Brisa',          0, '#2e7a4a', 'Naturaleza / Elementos',  460],
        ['element_crystal',   '🔷', 'Cristal',        0, '#1a6a8a', 'Naturaleza / Elementos',  470],
        ['element_drop',      '💧', 'Gota',           0, '#2e8ab5', 'Naturaleza / Elementos',  480],
        // Fitness / Disciplinas
        ['sport_strength',    '🏋️', 'Fuerza',        0, '#7a2e2e', 'Fitness / Disciplinas',   510],
        ['sport_zen',         '🧘', 'Zen',            0, '#4a7a5a', 'Fitness / Disciplinas',   520],
        ['sport_run',         '🏃', 'Velocidad',      0, '#2e5a8a', 'Fitness / Disciplinas',   530],
        ['sport_flex',        '🤸', 'Flexibilidad',   0, '#8a5a2e', 'Fitness / Disciplinas',   540],
        ['sport_cardio',      '💗', 'Cardio',         0, '#c0392b', 'Fitness / Disciplinas',   550],
        ['sport_boxing',      '🥊', 'Boxeo',          0, '#8a2e5a', 'Fitness / Disciplinas',   560],
        ['sport_swim',        '🏊', 'Natación',       0, '#1a6a9a', 'Fitness / Disciplinas',   570],
        ['sport_bike',        '🚴', 'Ciclismo',       0, '#2e7a2e', 'Fitness / Disciplinas',   580],
        ['sport_rocket',      '🚀', 'Impulso',        0, '#2e2e7a', 'Fitness / Disciplinas',   590],
        // Honor / Rango
        ['rank_sword',        '⚔️', 'Guerrero',       0, '#7a3a1a', 'Honor / Rango',           610],
        ['rank_medal',        '🎖️', 'Condecoración',  0, '#c9a227', 'Honor / Rango',           620],
        ['rank_captain',      '⚓', 'Capitán',        0, '#1a3a7a', 'Honor / Rango',           630],
        ['rank_hero',         '🦸', 'Héroe',          0, '#7a1a7a', 'Honor / Rango',           640],
        ['rank_archer',       '🏹', 'Arquero',        0, '#3a5a2e', 'Honor / Rango',           650],
        ['rank_samurai',      '🗡️', 'Samurái',        0, '#2e2e2e', 'Honor / Rango',           660],
        ['rank_ribbon',       '🎗️', 'Distinción',     0, '#b54a8a', 'Honor / Rango',           670],
        ['rank_fortress',     '🏯', 'Fortaleza',      0, '#5a4a3a', 'Honor / Rango',           680],
        // Comunidad / Social
        ['social_ambassador', '📢', 'Embajador',      0, '#e05c2a', 'Comunidad / Social',      710],
        ['social_ally',       '🤝', 'Aliado',         0, '#2e7a5a', 'Comunidad / Social',      720],
        ['social_vip',        '⭐', 'VIP',            0, '#c9a227', 'Comunidad / Social',      730],
        ['social_loyal',      '❤️', 'Fiel',           0, '#c0392b', 'Comunidad / Social',      740],
        ['social_spark',      '💫', 'Inspiración',    0, '#8e44ad', 'Comunidad / Social',      750],
        ['social_crown',      '👸', 'Ícono',          0, '#b5860a', 'Comunidad / Social',      760],
        ['social_megaphone',  '🗣️', 'Vocero',         0, '#2e5a7a', 'Comunidad / Social',      770],
        ['social_referrer',   '🙌', 'Referidor',      0, '#4a7a2e', 'Comunidad / Social',      780],
        // Cosmos / Universo
        ['cosmos_star',       '🌟', 'Supernova',      0, '#c9a227', 'Cosmos / Universo',       810],
        ['cosmos_galaxy',     '🌌', 'Galaxia',        0, '#1a1a5a', 'Cosmos / Universo',       820],
        ['cosmos_planet',     '🪐', 'Planeta',        0, '#5a3a8a', 'Cosmos / Universo',       830],
        ['cosmos_meteor',     '🌠', 'Meteoro',        0, '#2e4a7a', 'Cosmos / Universo',       840],
        ['cosmos_comet',      '☄️', 'Cometa',         0, '#7a4a1a', 'Cosmos / Universo',       850],
        ['cosmos_astronaut',  '👨‍🚀', 'Explorador',   0, '#1a5a7a', 'Cosmos / Universo',       860],
        ['cosmos_black_hole', '🕳️', 'Singularidad',   0, '#1a1a1a', 'Cosmos / Universo',       870],
        ['cosmos_telescope',  '🔭', 'Visionario',     0, '#3a3a6a', 'Cosmos / Universo',       880],
        // Magia / Misterio
        ['magic_orb',         '🔮', 'Oráculo',        0, '#5a1a8a', 'Magia / Misterio',        910],
        ['magic_wand',        '🎩', 'Mago',           0, '#7a2e7a', 'Magia / Misterio',        920],
        ['magic_spiral',      '🌀', 'Espiral',        0, '#1a6a8a', 'Magia / Misterio',        930],
        ['magic_talisman',    '🧿', 'Talismán',       0, '#1a5a3a', 'Magia / Misterio',        940],
        ['magic_key',         '🗝️', 'Llave Secreta',  0, '#8a6a1a', 'Magia / Misterio',        950],
        ['magic_skull',       '💀', 'Oscuro',         0, '#2e2e2e', 'Magia / Misterio',        960],
        ['magic_infinity',    '♾️', 'Infinito',       0, '#3a3a7a', 'Magia / Misterio',        970],
        ['magic_phantom',     '👻', 'Fantasma',       0, '#7a7a9a', 'Magia / Misterio',        980],
        // Evolución / Ciencia
        ['science_dna',       '🧬', 'ADN',            0, '#1a7a4a', 'Evolución / Ciencia',    1010],
        ['science_lab',       '🧪', 'Laboratorio',    0, '#4a1a7a', 'Evolución / Ciencia',    1020],
        ['science_brain',     '🧠', 'Mente',          0, '#8a2e5a', 'Evolución / Ciencia',    1030],
        ['science_atom',      '⚛️', 'Átomo',          0, '#1a4a8a', 'Evolución / Ciencia',    1040],
        ['science_robot',     '🤖', 'Máquina',        0, '#3a3a5a', 'Evolución / Ciencia',    1050],
        ['science_gear',      '⚙️', 'Engranaje',      0, '#5a5a5a', 'Evolución / Ciencia',    1060],
        ['science_bulb',      '💡', 'Innovador',      0, '#c9a227', 'Evolución / Ciencia',    1070],
        ['science_micro',     '🔬', 'Microscopio',    0, '#2e5a7a', 'Evolución / Ciencia',    1080],
        // Nutrición / Bienestar
        ['wellness_salad',    '🥗', 'Nutrido',        0, '#2e7a2e', 'Nutrición / Bienestar',  1110],
        ['wellness_avocado',  '🥑', 'Saludable',      0, '#3a7a1a', 'Nutrición / Bienestar',  1120],
        ['wellness_apple',    '🍏', 'Limpio',         0, '#2e8a2e', 'Nutrición / Bienestar',  1130],
        ['wellness_water',    '🥤', 'Hidratado',      0, '#1a7ab5', 'Nutrición / Bienestar',  1140],
        ['wellness_sleep',    '😴', 'Descanso',       0, '#4a3a7a', 'Nutrición / Bienestar',  1150],
        ['wellness_meditate', '🌿', 'Equilibrio',     0, '#2e6a4a', 'Nutrición / Bienestar',  1160],
        ['wellness_energy',   '🧃', 'Energizado',     0, '#e07a1a', 'Nutrición / Bienestar',  1170],
        ['wellness_heart',    '💚', 'Bienestar',      0, '#1a8a3a', 'Nutrición / Bienestar',  1180],
        // Arte / Creatividad
        ['art_palette',       '🎨', 'Artista',        0, '#d4287e', 'Arte / Creatividad',     1210],
        ['art_music',         '🎵', 'Melodía',        0, '#7a2e8a', 'Arte / Creatividad',     1220],
        ['art_film',          '🎬', 'Director',       0, '#1a1a1a', 'Arte / Creatividad',     1230],
        ['art_theater',       '🎭', 'Teatro',         0, '#8a2e2e', 'Arte / Creatividad',     1240],
        ['art_pencil',        '✏️', 'Creativo',       0, '#5a4a2e', 'Arte / Creatividad',     1250],
        ['art_camera',        '📸', 'Fotógrafo',      0, '#2e2e5a', 'Arte / Creatividad',     1260],
        ['art_guitar',        '🎸', 'Rock',           0, '#5a1a1a', 'Arte / Creatividad',     1270],
        ['art_dance',         '💃', 'Danza',          0, '#c0392b', 'Arte / Creatividad',     1280],
    ];

    foreach ($badges as [$key, $icon, $name, $pts, $color, $group, $sort]) {
        $connection->insert('achievement_badge', [
            'badge_key'   => $key,
            'icon'        => $icon,
            'name'        => $name,
            'default_pts' => $pts,
            'color'       => $color,
            'badge_group' => $group,
            'sort_order'  => $sort,
            'is_active'   => 1,
        ]);
    }
    echo "   ✓ " . count($badges) . " badges insertados\n\n";

    // 1. Seed achievement_condition_catalog
    echo "1. Insertando condiciones de catálogo...\n";

    // Limpieza total: se borra todo y se recrea desde cero
    $connection->executeStatement('DELETE FROM achievement_threshold_option');
    $connection->executeStatement('DELETE FROM achievement_condition_catalog');
    echo "   ✓ Tablas de condiciones y umbrales vaciadas\n";

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
        ['compras', 'specific_package_count', 'Paquete específico comprado', 'count', 1, 1, 10000, 55],

        // Antigüedad (3 condiciones)
        ['antiguedad', 'active_months', 'Meses activos', 'months', 1, 1, 600, 40],
        ['antiguedad', 'active_years', 'Años activos', 'months', 1, 12, 600, 50],
        ['antiguedad', 'consolidated_client', 'Cliente consolidado', 'months', 1, 6, 600, 60],

        // Racha (3 condiciones)
        ['racha', 'consecutive_days', 'Días consecutivos', 'days', 1, 1, 3650, 50],
        ['racha', 'consecutive_weeks', 'Semanas consecutivas', 'count', 1, 1, 520, 60],
        ['racha', 'no_show_free_days', 'Días sin inasistencia', 'days', 1, 1, 3650, 70],

        // Disciplina (2 condiciones)
        ['disciplina', 'discipline_classes', 'Clases por disciplina', 'count', 1, 1, 10000, 60],
        ['disciplina', 'multi_discipline_count', 'Cantidad de disciplinas', 'count', 1, 1, 50, 70],

        // Calificación (3 condiciones)
        ['calificacion', 'rating_streak_classes', 'Racha de clases calificadas', 'count', 1, 1, 10000, 70],
        ['calificacion', 'rating_rate_pct', 'Porcentaje de clases calificadas', 'percent', 1, 50, 100, 80],
        ['calificacion', 'rating_votes_min', 'Mínimo de evaluaciones', 'count', 1, 1, 10000, 90],

        // Comunidad (5 condiciones)
        ['comunidad', 'referrals_count', 'Referidos efectivos', 'count', 1, 1, 500, 90],
        ['comunidad', 'challenges_completed', 'Retos completados', 'count', 1, 1, 2000, 100],
        ['comunidad', 'social_shares_count', 'Compartidos en redes', 'count', 1, 1, 10000, 110],
        ['comunidad', 'event_attendance_count', 'Asistencia a eventos', 'count', 1, 1, 1000, 120],
        ['comunidad', 'friend_joint_classes', 'Clases con amigos', 'count', 1, 1, 10000, 130],

        // Instructor (1 condición)
        ['instructor', 'distinct_instructors_count', 'Instructores distintos', 'count', 1, 1, 1000, 95],

        // Hábitos (4 condiciones)
        ['habitos', 'checkin_morning_count', 'Asistencias matutinas', 'count', 1, 1, 10000, 100],
        ['habitos', 'weekend_attendance', 'Asistencia fin de semana', 'count', 1, 1, 10000, 110],
        ['habitos', 'checkin_evening_count', 'Asistencias nocturnas', 'count', 1, 1, 10000, 120],
        ['habitos', 'consecutive_same_time_slot', 'Misma franja horaria consecutiva', 'count', 1, 1, 10000, 130],
    ];

    $inserted = 0;
    foreach ($conditions as [$categoryKey, $conditionKey, $label, $thresholdType, $allowsCustom, $minVal, $maxVal, $sortOrder]) {
        $connection->insert('achievement_condition_catalog', [
            'category_key'       => $categoryKey,
            'condition_key'      => $conditionKey,
            'condition_label'    => $label,
            'threshold_type'     => $thresholdType,
            'allows_custom_value'=> $allowsCustom,
            'min_value'          => $minVal,
            'max_value'          => $maxVal,
            'active'             => 1,
            'sort_order'         => $sortOrder,
            'created_at'         => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
        $inserted++;
    }
    echo "   ✓ $inserted condiciones insertadas\n\n";

    // 2. Seed achievement_threshold_option
    echo "2. Insertando opciones de umbral...\n";
    
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
        ['antiguedad', 'consolidated_client', 6, '6 meses', 10],
        ['antiguedad', 'consolidated_client', 12, '12 meses', 20],
        ['antiguedad', 'consolidated_client', 24, '24 meses', 30],
        
        // Racha
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
        ['disciplina', 'discipline_classes', 25, '25 clases', 20],
        ['disciplina', 'discipline_classes', 50, '50 clases', 30],
        ['disciplina', 'multi_discipline_count', 2, '2 disciplinas', 10],
        ['disciplina', 'multi_discipline_count', 3, '3 disciplinas', 20],
        ['disciplina', 'multi_discipline_count', 4, '4 disciplinas', 30],
        
        // Calificación
        ['calificacion', 'rating_streak_classes', 10, '10 seguidas', 10],
        ['calificacion', 'rating_streak_classes', 25, '25 seguidas', 20],
        ['calificacion', 'rating_streak_classes', 50, '50 seguidas', 30],
        ['calificacion', 'rating_rate_pct', 80, '80% calificadas', 10],
        ['calificacion', 'rating_rate_pct', 90, '90% calificadas', 20],
        ['calificacion', 'rating_rate_pct', 100, '100% calificadas', 30],
        ['calificacion', 'rating_votes_min', 10, '10 evaluaciones', 10],
        ['calificacion', 'rating_votes_min', 30, '30 evaluaciones', 20],
        ['calificacion', 'rating_votes_min', 50, '50 evaluaciones', 30],
        
        // Comunidad
        ['comunidad', 'referrals_count', 1, '1 referido', 10],
        ['comunidad', 'referrals_count', 3, '3 referidos', 20],
        ['comunidad', 'referrals_count', 5, '5 referidos', 30],
        ['comunidad', 'referrals_count', 10, '10 referidos', 40],
        ['comunidad', 'challenges_completed', 1, '1 reto', 10],
        ['comunidad', 'challenges_completed', 3, '3 retos', 20],
        ['comunidad', 'challenges_completed', 10, '10 retos', 30],
        ['comunidad', 'challenges_completed', 25, '25 retos', 40],
        ['comunidad', 'social_shares_count', 1, '1 compartido', 10],
        ['comunidad', 'social_shares_count', 3, '3 compartidos', 20],
        ['comunidad', 'social_shares_count', 5, '5 compartidos', 30],
        ['comunidad', 'social_shares_count', 10, '10 compartidos', 40],
        ['comunidad', 'social_shares_count', 25, '25 compartidos', 50],
        ['comunidad', 'event_attendance_count', 1, '1 evento', 10],
        ['comunidad', 'event_attendance_count', 3, '3 eventos', 20],
        ['comunidad', 'event_attendance_count', 5, '5 eventos', 30],
        ['comunidad', 'event_attendance_count', 10, '10 eventos', 40],
        ['comunidad', 'friend_joint_classes', 1, '1 clase', 10],
        ['comunidad', 'friend_joint_classes', 5, '5 clases', 20],
        ['comunidad', 'friend_joint_classes', 10, '10 clases', 30],
        ['comunidad', 'friend_joint_classes', 25, '25 clases', 40],

        // Instructor
        ['instructor', 'distinct_instructors_count', 2, '2 instructores', 10],
        ['instructor', 'distinct_instructors_count', 5, '5 instructores', 20],
        ['instructor', 'distinct_instructors_count', 10, '10 instructores', 30],

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
        ['habitos', 'consecutive_same_time_slot', 25, '25 clases', 20],
        ['habitos', 'consecutive_same_time_slot', 50, '50 clases', 30],
    ];

    $inserted = 0;
    foreach ($thresholds as [$categoryKey, $conditionKey, $optionValue, $optionLabel, $sortOrder]) {
        $conditionId = $connection->fetchOne(
            'SELECT id FROM achievement_condition_catalog WHERE category_key = ? AND condition_key = ?',
            [$categoryKey, $conditionKey]
        );
        if ($conditionId) {
            $connection->insert('achievement_threshold_option', [
                'condition_id' => $conditionId,
                'option_value' => $optionValue,
                'option_label' => $optionLabel,
                'sort_order'   => $sortOrder,
                'active'       => 1,
                'created_at'   => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $inserted++;
        }
    }
    echo "   ✓ $inserted opciones de umbral insertadas\n\n";

    echo "✅ Seed completado exitosamente!\n";

} catch (\Exception $e) {
    echo "❌ Error durante seed: " . $e->getMessage() . "\n";
    exit(1);
}
