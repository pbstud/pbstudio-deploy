<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DisciplineRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ClassDescriptionController extends AbstractController
{
    private const PRIVATE_CLASS_SLUG = 'clase-privada';

    /**
     * Contenido base para primera version (sin edicion desde staff).
     */
    private const CLASS_CONTENT_MAP = [
        [
            'slug' => 'pilates-reformer',
            'audience' => 'Todos los niveles',
            'duration' => '50 minutos',
            'intensity' => 'Media',
            'focus' => 'Fuerza funcional, postura y control del core',
            'summary' => 'Trabajo integral de fuerza, estabilidad y alineacion en equipo reformer.',
            'description' => 'Sesión guiada en reformer para fortalecer sin impacto, mejorar alineación y ganar estabilidad. Se adapta con progresiones según tu nivel para que avances con técnica segura.',
            'bestFor' => [
                'Mejorar postura y control del abdomen profundo.',
                'Fortalecer piernas y espalda sin sobrecargar articulaciones.',
                'Retomar entrenamiento con una progresion tecnica segura.',
            ],
            'keyPostures' => [
                'The Hundred en reformer (activacion de core y respiracion).',
                'Footwork series (fuerza de tren inferior y alineacion).',
                'Elephant y Long Stretch (control escapular y cadena posterior).',
            ],
            'guidedFlow' => [
                'Movilidad inicial y respiracion lateral toracica.',
                'Bloque principal de control del centro y estabilidad.',
                'Trabajo de potencia controlada y estiramiento final.',
            ],
            'benefits' => [
                'Mejor conciencia corporal en actividades cotidianas.',
                'Mayor estabilidad lumbopelvica y control postural.',
                'Mejora progresiva de fuerza funcional y equilibrio.',
            ],
            'tips' => [
                'Llega 10 minutos antes para calibrar resistencias.',
                'Informa cualquier molestia lumbar, cervical o de rodilla.',
                'Prioriza calidad de movimiento sobre cantidad de repeticiones.',
            ],
        ],
        [
            'slug' => 'barra-funcional',
            'audience' => 'Intermedio a avanzado',
            'duration' => '50 minutos',
            'intensity' => 'Media alta',
            'focus' => 'Resistencia, tonificación y equilibrio corporal',
            'summary' => 'Secuencias dinamicas en barra con enfoque en resistencia muscular.',
            'description' => 'Entrenamiento dinámico de bajo impacto con secuencias en barra y trabajo funcional. Activa piernas, glúteos y abdomen para mejorar fuerza-resistencia y coordinación.',
            'bestFor' => [
                'Tonificar piernas y gluteos con trabajo continuo.',
                'Mejorar coordinacion, control y resistencia muscular.',
                'Complementar rutinas de fuerza y movilidad.',
            ],
            'keyPostures' => [
                'Pliés y relevés controlados en barra.',
                'Pulses isometricos para gluteo medio y aductores.',
                'Bloques de core antirotacional y estabilidad unilateral.',
            ],
            'guidedFlow' => [
                'Activacion de cadera y tobillo.',
                'Secuencia principal de barra por bloques de resistencia.',
                'Cierre con movilidad de cadena posterior y liberacion.',
            ],
            'benefits' => [
                'Definicion muscular sin impacto excesivo.',
                'Incremento de resistencia local y control motor fino.',
                'Mayor estabilidad y propiocepcion en tren inferior.',
            ],
            'tips' => [
                'Mantener rodillas alineadas con direccion de pies.',
                'Respirar en cada transicion para sostener tecnica.',
                'Usar rango controlado antes de buscar velocidad.',
            ],
        ],
        [
            'slug' => 'bsc',
            'audience' => 'Intermedio a avanzado',
            'duration' => '50 minutos',
            'intensity' => 'Media alta',
            'focus' => 'Resistencia, tonificacion y estabilidad global',
            'summary' => 'Clase dinamica tipo barre con enfoque en control, fuerza y resistencia.',
            'description' => 'BSC combina patrones de barra y trabajo funcional para elevar resistencia muscular, equilibrio y control postural con bajo impacto articular.',
            'bestFor' => [
                'Tonificar tren inferior y abdomen con continuidad de esfuerzo.',
                'Mejorar estabilidad y coordinacion en movimientos de precision.',
                'Incrementar resistencia sin carga articular excesiva.',
            ],
            'keyPostures' => [
                'Pliés y relevés con control de alineacion.',
                'Pulses isometricos para gluteo y aductores.',
                'Bloques de core antirotacional y estabilidad unilateral.',
            ],
            'guidedFlow' => [
                'Activacion de cadera, tobillo y centro.',
                'Bloques de resistencia por segmentos musculares.',
                'Cierre con movilidad y descarga de cadenas activadas.',
            ],
            'benefits' => [
                'Mayor resistencia local y control motor fino.',
                'Mejor postura dinamica y equilibrio.',
                'Definicion muscular progresiva.',
            ],
            'tips' => [
                'Mantener alineacion de rodilla con punta del pie.',
                'Controlar respiracion en cada cambio de bloque.',
                'Priorizar tecnica antes de subir ritmo.',
            ],
        ],
        [
            'slug' => 'pilates-suelo',
            'audience' => 'Principiante a intermedio',
            'duration' => '50 minutos',
            'intensity' => 'Suave media',
            'focus' => 'Movilidad, respiración y control consciente del movimiento',
            'summary' => 'Base tecnica de pilates para mejorar movilidad y estabilidad central.',
            'description' => 'Clase en mat enfocada en técnica, movilidad y respiración. Ideal para construir una base sólida, mejorar flexibilidad y reducir tensión muscular en la vida diaria.',
            'bestFor' => [
                'Iniciar en pilates con base tecnica clara.',
                'Recuperar movilidad y reducir tension corporal.',
                'Desarrollar respiracion y control del core.',
            ],
            'keyPostures' => [
                'Roll Up y Spine Stretch Forward.',
                'Single Leg Stretch y Dead Bug controlado.',
                'Bridge articulado y Side Kick Series.',
            ],
            'guidedFlow' => [
                'Respiracion guiada y activacion profunda.',
                'Secuencia de control segmentario de columna.',
                'Fortalecimiento de centro y estiramientos activos.',
            ],
            'benefits' => [
                'Mejor postura al sentarte, caminar y entrenar.',
                'Disminucion de rigidez en espalda y cadera.',
                'Mayor control corporal y eficiencia de movimiento.',
            ],
            'tips' => [
                'Mantener respiracion fluida durante todo el bloque.',
                'Evitar compensar con cuello y hombros.',
                'Aumentar rango gradualmente, sin dolor.',
            ],
        ],
        [
            'slug' => 'clase-individual',
            'audience' => 'Plan personalizado',
            'duration' => '50 minutos',
            'intensity' => 'Variable',
            'focus' => 'Atención 1 a 1 con objetivos específicos',
            'summary' => 'Programa personalizado segun objetivo, historial y nivel actual.',
            'description' => 'Sesión personalizada para rehabilitación, acondicionamiento o perfeccionamiento técnico. El plan se adapta completamente a tu historial, ritmo y metas.',
            'bestFor' => [
                'Objetivos especificos de rendimiento o rehabilitacion.',
                'Seguimiento tecnico cercano y ajustes en tiempo real.',
                'Casos con lesiones previas o necesidades particulares.',
            ],
            'keyPostures' => [
                'Evaluacion postural dinamica inicial.',
                'Patrones de control motor adaptados al objetivo.',
                'Trabajo correctivo especifico por cadena muscular.',
            ],
            'guidedFlow' => [
                'Diagnostico funcional breve y establecimiento de foco.',
                'Bloque principal con ejercicios individualizados.',
                'Cierre con plan de continuidad y recomendaciones.',
            ],
            'benefits' => [
                'Progreso mas rapido por personalizacion completa.',
                'Prevencion de sobrecargas y correccion tecnica precisa.',
                'Mejor adherencia por objetivos claros y medibles.',
            ],
            'tips' => [
                'Compartir historial de lesiones o molestias recientes.',
                'Definir un objetivo principal por ciclo de 4 a 6 semanas.',
                'Mantener constancia para consolidar resultados.',
            ],
        ],
    ];

    #[Route('/clases', name: 'class_descriptions_index', methods: ['GET'])]
    public function index(DisciplineRepository $disciplineRepository): Response
    {
        $classDescriptions = $this->buildClassDescriptions($disciplineRepository);

        return $this->render('class_description/index.html.twig', [
            'classDescriptions' => $classDescriptions,
        ]);
    }

    #[Route('/clases/{slug}', name: 'class_descriptions_show', methods: ['GET'])]
    public function show(string $slug, DisciplineRepository $disciplineRepository): Response
    {
        $classDescription = $this->findClassDescription($slug, $disciplineRepository);

        if (null === $classDescription) {
            throw $this->createNotFoundException('No se encontro la clase solicitada.');
        }

        return $this->render('class_description/modal.html.twig', [
            'classDescription' => $classDescription,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildClassDescriptions(DisciplineRepository $disciplineRepository): array
    {
        $disciplines = $disciplineRepository->getAllActives();
        $slugger = new AsciiSlugger();
        $classDescriptions = [];

        foreach ($disciplines as $discipline) {
            $name = (string) $discipline;
            $slug = strtolower((string) $slugger->slug($name));
            $classDescriptions[] = $this->buildClassDescription($name, $slug);
        }

        if (!$this->hasPrivateClass($classDescriptions)) {
            $classDescriptions[] = $this->buildClassDescription('Clase privada', self::PRIVATE_CLASS_SLUG);
        }

        return $classDescriptions;
    }

    private function findClassDescription(string $slug, DisciplineRepository $disciplineRepository): ?array
    {
        foreach ($this->buildClassDescriptions($disciplineRepository) as $classDescription) {
            if ($classDescription['slug'] === $slug) {
                return $classDescription;
            }
        }

        return null;
    }

    private function buildClassDescription(string $name, string $slug): array
    {
        $contentSlug = $this->resolveContentSlug($slug);

        foreach (self::CLASS_CONTENT_MAP as $classContent) {
            if ($classContent['slug'] === $contentSlug) {
                $classContent['name'] = $name;
                $classContent['slug'] = $slug;

                return $classContent;
            }
        }

        $nameLower = strtolower($name);

        return [
            'slug' => $slug,
            'name' => $name,
            'audience' => 'Todos los niveles',
            'duration' => '50 minutos',
            'intensity' => 'Media',
            'focus' => sprintf('Trabajo tecnico y progresivo de %s.', $nameLower),
            'summary' => sprintf('Sesion guiada de %s con enfoque en tecnica, control y progresion segura.', $nameLower),
            'description' => sprintf('La clase de %s combina alineacion, movilidad y fuerza funcional para que avances con una ejecucion consciente y objetivos claros por bloque de trabajo.', $nameLower),
            'bestFor' => [
                sprintf('Perfeccionar la tecnica base de %s.', $nameLower),
                'Mejorar control corporal y calidad de movimiento.',
                'Entrenar con una progresion segura segun tu nivel.',
            ],
            'keyPostures' => [
                'Activaciones de centro y control respiratorio.',
                'Secuencias tecnicas guiadas por bloques.',
                'Trabajo de estabilidad y movilidad integrada.',
            ],
            'guidedFlow' => [
                'Activacion inicial y movilidad articular.',
                'Bloque principal por objetivos tecnicos.',
                'Integracion final y vuelta a la calma.',
            ],
            'benefits' => [
                'Mayor estabilidad y eficiencia de movimiento.',
                'Mejor postura y control neuromuscular.',
                'Progreso sostenido sin sobrecarga innecesaria.',
            ],
            'tips' => [
                'Informar molestias o lesiones antes de iniciar.',
                'Mantener respiracion fluida durante la sesion.',
                'Priorizar tecnica sobre velocidad o volumen.',
            ],
        ];
    }

    private function resolveContentSlug(string $slug): string
    {
        if (str_contains($slug, 'privad') || str_contains($slug, 'individual')) {
            return 'clase-individual';
        }

        if ('barra' === $slug || 'barre' === $slug) {
            return 'bsc';
        }

        return $slug;
    }

    /**
     * @param array<int, array<string, mixed>> $classDescriptions
     */
    private function hasPrivateClass(array $classDescriptions): bool
    {
        foreach ($classDescriptions as $classDescription) {
            $slug = (string) ($classDescription['slug'] ?? '');
            $name = strtolower((string) ($classDescription['name'] ?? ''));

            if (str_contains($slug, 'privad') || str_contains($slug, 'individual')
                || str_contains($name, 'privad') || str_contains($name, 'individual')) {
                return true;
            }
        }

        return false;
    }
}
