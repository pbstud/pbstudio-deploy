<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Configuration;
use App\Model\ConfigurationFileModel;
use App\Repository\ConfigurationRepository;
use App\Repository\PackageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Handler\UploadHandler;

#[IsGranted('ROLE_ADMIN')]
#[Route('/backend/settings')]
class ConfigurationController extends AbstractController
{
    private UploadHandler $uploadHandler;

    private array $cast = [
        'sessions' => [
            'fitpass_user_id' => 'int',
        ],
        'stats' => [
            'start_date' => 'date',
        ],
    ];

    #[Route('/', name: 'backend_configuration', methods: ['GET'])]
    public function index(
        ConfigurationRepository $configurationRepository,
        PackageRepository $packageRepository,
    ): Response {
        $packages = $packageRepository->findBy([
            'isActive' => true,
        ]);

        /** @var Configuration $general */
        $general = $configurationRepository->findGeneral();

        if ($general) {
            $general = $general->getData();
        }

        /** @var Configuration $conekta */
        $conekta = $configurationRepository->findConekta();

        if ($conekta) {
            $conekta = $conekta->getData();
        }

        /** @var Configuration $sessions */
        $sessions = $configurationRepository->findSessions();

        if ($sessions) {
            $sessions = $sessions->getData();
        }

        /** @var Configuration $notice */
        $notice = $configurationRepository->findNotice();

        if ($notice) {
            $notice = $notice->getData();
            $this->getConfigFiles($notice, ['image']);
        }

        $stats = $this->reverseCast('stats', $configurationRepository->findStats()?->getData());

        return $this->render('backend/configuration/index.html.twig', [
            'packages' => $packages,
            'general' => $general,
            'conekta' => $conekta,
            'sessions' => $sessions,
            'notice' => $notice,
            'stats' => $stats,
        ]);
    }

    #[Route('/update', name: 'backend_configuration_update', methods: ['PUT'])]
    public function update(
        Request $request,
        UploadHandler $uploadHandler,
        ConfigurationRepository $configurationRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $this->uploadHandler = $uploadHandler;

        $data = $request->request->all();

        unset($data['_method']);

        $uploads = $this->uploadFiles($request->files->all(), $configurationRepository);
        $data = array_merge_recursive($data, $uploads);

        foreach ($data as $module => $values) {
            $entity = $configurationRepository->findOneByModule($module);

            if (!$entity) {
                $entity = new Configuration();
                $entity->setModule($module);
            }

            try {
                $values = $this->castValues($module, $values);
                $this->validateReferences($module, $values, $userRepository);
            } catch (\InvalidArgumentException $exception) {
                // Ensure pending managed entities are discarded for this request path.
                $em->clear();

                $this->addFlash('danger', sprintf(
                    'Fecha invalida detectada. No se guardaron cambios. %s',
                    $exception->getMessage(),
                ));

                return $this->redirectToRoute('backend_configuration');
            }

            $entity->setData($values);
            $em->persist($entity);
        }

        $em->flush();

        $this->addFlash('success', '¡La configuración ha sido actualizada!');

        return $this->redirectToRoute('backend_configuration');
    }

    private function uploadFiles(array $data, ConfigurationRepository $configurationRepository): array
    {
        $uploads = [];

        foreach ($data as $module => $files) {
            /** @var Configuration $config */
            $config = $configurationRepository->findOneByModule($module);
            $configData = $config ? $config->getData() : [];
            foreach ($files as $field => $file) {
                $value = $configData[$field] ?? null;

                if ($file instanceof UploadedFile) {
                    $configFile = new ConfigurationFileModel();
                    $configFile->setName($value);

                    $configFile->setFile($file);
                    $this->uploadHandler->upload($configFile, 'file');
                    $value = $configFile->getName();
                }

                $uploads[$module][$field] = $value;
            }
        }

        return $uploads;
    }

    /**
     * Config files.
     *
     * @param array $config
     * @param array $fields
     */
    private function getConfigFiles(array &$config, array $fields): void
    {
        foreach ($fields as $field) {
            if (!empty($config[$field])) {
                if ('image' === $field) {
                    $noticeImagePath = sprintf(
                        '%s/public/media/uploads/site/%s',
                        $this->getParameter('kernel.project_dir'),
                        $config[$field],
                    );

                    if (!is_file($noticeImagePath)) {
                        unset($config[$field]);
                        continue;
                    }
                }

                $configFile = new ConfigurationFileModel();
                $configFile->setName($config[$field]);
                $config[$field] = $configFile;
            }
        }
    }

    private function castValues(string $module, array $values): array
    {
        foreach ($values as $field => $value) {
            if (empty($value) || !isset($this->cast[$module][$field])) {
                continue;
            }

            $values[$field] = match ($this->cast[$module][$field]) {
                'date' => $this->castDateValue($module, $field, (string) $value),
                'int' => $this->castIntValue($module, $field, $value),
                default => $value,
            };
        }

        return $values;
    }

    private function castIntValue(string $module, string $field, mixed $value): ?int
    {
        $input = trim((string) $value);
        if ('' === $input) {
            return null;
        }

        if (!ctype_digit($input) || (int) $input <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'El valor para "%s.%s" es invalido. Debe ser un entero positivo.',
                $module,
                $field,
            ));
        }

        return (int) $input;
    }

    private function validateReferences(string $module, array $values, UserRepository $userRepository): void
    {
        if ('sessions' !== $module) {
            return;
        }

        $fitpassUserId = $values['fitpass_user_id'] ?? null;
        if (null === $fitpassUserId) {
            return;
        }

        if (null === $userRepository->find((int) $fitpassUserId)) {
            throw new \InvalidArgumentException(sprintf(
                'No existe un usuario con id %d para sessions.fitpass_user_id.',
                (int) $fitpassUserId,
            ));
        }
    }

    private function castDateValue(string $module, string $field, string $value): string
    {
        $input = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $input);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasParseErrors = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (!$date || $hasParseErrors || $date->format('d/m/Y') !== $input) {
            throw new \InvalidArgumentException(sprintf(
                'La fecha para "%s.%s" es invalida. Usa el formato d/m/Y.',
                $module,
                $field,
            ));
        }

        return $date->format('Y-m-d');
    }

    private function reverseCast(string $module, ?array $values): array
    {
        if (empty($values)) {
            return [];
        }

        foreach ($values as $field => $value) {
            if (empty($value) || !isset($this->cast[$module][$field])) {
                continue;
            }

            $values[$field] = match ($this->cast[$module][$field]) {
                'date' => $this->reverseCastDateValue((string) $value),
                default => $value,
            };
        }

        return $values;
    }

    private function reverseCastDateValue(string $value): string
    {
        $input = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasParseErrors = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (!$date || $hasParseErrors || $date->format('Y-m-d') !== $input) {
            return $value;
        }

        return $date->format('d/m/Y');
    }
}
