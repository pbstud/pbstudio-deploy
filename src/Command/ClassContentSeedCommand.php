<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Post;
use App\Repository\PostRepository;
use App\Service\ClassContentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:class-content:seed',
    description: 'Siembra el contenido inicial editable de la pagina de clases en la BD.',
)]
class ClassContentSeedCommand extends Command
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Sobreescribir si ya existe.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->postRepository->findOneBy(['slug' => ClassContentService::POST_SLUG]);

        if (null !== $existing && !$input->getOption('force')) {
            $io->warning(sprintf(
                'Ya existe un Post con slug "%s". Usa --force para sobreescribir.',
                ClassContentService::POST_SLUG,
            ));

            return Command::SUCCESS;
        }

        $json = (string) json_encode($this->buildPayload(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        if (null !== $existing) {
            $existing->setContent($json);
            $existing->setIsActive(true);
            $io->success('Contenido actualizado en el Post existente.');
        } else {
            $post = new Post();
            $post->setType(Post::TYPE_STATIC);
            $post->setTitle('Clases Contenido');
            $post->setContent($json);
            $post->setIsActive(true);
            $this->entityManager->persist($post);
            $io->success(sprintf('Post "%s" creado con contenido inicial.', ClassContentService::POST_SLUG));
        }

        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        return [
            'schemaVersion' => 1,
            'header'        => [
                'title'    => 'Nuestras clases',
                'subtitle' => 'Conoce los tipos de clases que ofrecemos y encuentra la que mejor se adapta a tus objetivos.',
            ],
            'classes' => [
                'pilates-reformer' => [
                    'audience'    => 'Todos los niveles',
                    'duration'    => '50 minutos',
                    'intensity'   => 'Media',
                    'focus'       => 'Fuerza funcional, postura y control del core',
                    'summary'     => 'Trabajo integral de fuerza, estabilidad y alineacion en equipo reformer.',
                    'description' => 'Sesión guiada en reformer para fortalecer sin impacto, mejorar alineación y ganar estabilidad. Se adapta con progresiones según tu nivel para que avances con técnica segura.',
                    'bestFor'     => [
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
                'beastformer' => [
                    'audience'    => 'Intermedio a avanzado',
                    'duration'    => '50 minutos',
                    'intensity'   => 'Alta',
                    'focus'       => 'Fuerza funcional de alta intensidad en reformer',
                    'summary'     => 'Entrenamiento funcional intenso que combina reformer y movimiento atletico.',
                    'description' => 'BeastFormer fusiona la resistencia del reformer con patrones de movimiento funcional para elevar fuerza, potencia y acondicionamiento fisico de forma progresiva y controlada.',
                    'bestFor'     => [
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
                'dual-pilates' => [
                    'audience'    => 'Todos los niveles (2 personas)',
                    'duration'    => '50 minutos',
                    'intensity'   => 'Media',
                    'focus'       => 'Trabajo en pareja en reformer con coordinacion compartida',
                    'summary'     => 'Sesion de pilates en duo para trabajar coordinacion, apoyo mutuo y tecnica.',
                    'description' => 'Clase diseñada para dos personas que trabajan juntas en el reformer, desarrollando coordinación, comunicación y técnica compartida con atención personalizada para cada integrante.',
                    'bestFor'     => [
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
                'clase-privada' => [
                    'audience'    => 'Plan personalizado',
                    'duration'    => '50 minutos',
                    'intensity'   => 'Variable',
                    'focus'       => 'Atencion 1 a 1 con objetivos especificos',
                    'summary'     => 'Programa personalizado segun objetivo, historial y nivel actual.',
                    'description' => 'Sesión personalizada para rehabilitación, acondicionamiento o perfeccionamiento técnico. El plan se adapta completamente a tu historial, ritmo y metas.',
                    'bestFor'     => [
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
            ],
        ];
    }
}
