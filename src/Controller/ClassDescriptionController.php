<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DisciplineRepository;
use App\Service\ClassContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ClassDescriptionController extends AbstractController
{
    private const PRIVATE_CLASS_SLUG = 'clase-privada';

    public function __construct(private readonly ClassContentService $classContentService)
    {
    }

    /**
     * Contenido base (fallback) cuando la BD no tiene el campo.
     * Slugs reales generados por AsciiSlugger sobre los nombres de las disciplinas activas.
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
            'slug' => 'beastformer',
            'audience' => 'Intermedio a avanzado',
            'duration' => '50 minutos',
            'intensity' => 'Alta',
            'focus' => 'Fuerza funcional de alta intensidad en reformer',
            'summary' => 'Entrenamiento funcional intenso que combina reformer y movimiento atletico.',
            'description' => 'BeastFormer fusiona la resistencia del reformer con patrones de movimiento funcional para elevar fuerza, potencia y acondicionamiento fisico de forma progresiva y controlada.',
            'bestFor' => [
                'Mejorar rendimiento fisico y fuerza explosiva.',
                'Trabajar resistencia cardiovascular con control tecnico.',
                'Superar mesetas de entrenamiento con estimulos variados.',
            ],
            'keyPostures' => [
                'Jumping y box springs para potencia y pliometria.',
                'Plank row y rotaciones con carro para core y traccion.',
                'Squat jumps y lunges controlados en reformer.',
            ],
            'guidedFlow' => [
                'Activacion cardiovascular y movilidad articular.',
                'Bloque principal de potencia y fuerza funcional.',
                'Descarga muscular y estiramiento integrado.',
            ],
            'benefits' => [
                'Mayor fuerza funcional y explosividad.',
                'Mejor capacidad cardiovascular y resistencia.',
                'Cuerpo trabajado de forma integral y progresiva.',
            ],
            'tips' => [
                'Informa tu nivel de experiencia en reformer al instructor.',
                'Mantener estabilidad del core en movimientos explosivos.',
                'Hidratarse bien antes y despues de la sesion.',
            ],
        ],
        [
            'slug' => 'dual-pilates',
            'audience' => 'Todos los niveles (2 personas)',
            'duration' => '50 minutos',
            'intensity' => 'Media',
            'focus' => 'Trabajo en pareja en reformer con coordinacion compartida',
            'summary' => 'Sesion de pilates en duo para trabajar coordinacion, apoyo mutuo y tecnica.',
            'description' => 'Clase diseñada para dos personas que trabajan juntas en el reformer, desarrollando coordinación, comunicación y técnica compartida con atención personalizada para cada integrante.',
            'bestFor' => [
                'Entrenar en pareja con un objetivo comun.',
                'Mejorar coordinacion y trabajo colaborativo.',
                'Iniciar o progresar juntos con guia tecnica cercana.',
            ],
            'keyPostures' => [
                'Footwork sincronizado en dos reformers.',
                'Rowing y pulling cooperativo con elasticos compartidos.',
                'Stretching asistido entre companeros.',
            ],
            'guidedFlow' => [
                'Activacion y familiarizacion en pareja.',
                'Bloque de trabajo coordinado por series.',
                'Estiramiento asistido y cierre integrado.',
            ],
            'benefits' => [
                'Mayor motivacion y adherencia por trabajo en pareja.',
                'Tecnica mejorada con retroalimentacion mutua.',
                'Experiencia personalizada para ambos participantes.',
            ],
            'tips' => [
                'Coordinar nivel de resistencia entre ambos antes de iniciar.',
                'Comunicar molestias en todo momento al instructor.',
                'Mantener ritmo compartido sin adelantar al companero.',
            ],
        ],
        [
            'slug' => 'clase-privada',
            'audience' => 'Plan personalizado',
            'duration' => '50 minutos',
            'intensity' => 'Variable',
            'focus' => 'Atencion 1 a 1 con objetivos especificos',
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
        $header = $this->classContentService->getHeader();

        return $this->render('class_description/index.html.twig', [
            'classDescriptions' => $classDescriptions,
            'pageHeader'        => $header,
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

                return $this->classContentService->mergeClassContent($classContent, $contentSlug);
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
            return 'clase-privada';
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
