<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PostRepository;

/**
 * Resuelve el contenido editable de la pagina /clases y del panel lateral del home.
 *
 * Lee el Post con slug "clases-contenido" (tipo static) desde la BD.
 * El campo `content` almacena un JSON con la estructura:
 *
 * {
 *   "header": { "title": "...", "subtitle": "..." },
 *   "classes": {
 *     "<discipline-slug>":  {
 *       "queEs":       "...",
 *       "paraQuienEs": "...",
 *       "duration":    "...",
 *       "panelImage":  "...",  // filename opcional, override de imagen de disciplina
 *       "benefits":    ["...", "..."],
 *       "sections":    [{ "title": "...", "items": ["..."] }]  // secciones adicionales
 *     }
 *   }
 * }
 */
class ClassContentService
{
    public const POST_SLUG = 'clases-contenido';

    public const SCALAR_FIELDS = [
        'queEs', 'paraQuienEs', 'duration', 'panelImage',
        'queEsIcon', 'queEsLabel',
        'benefitsIcon', 'benefitsLabel',
        'paraQuienEsIcon', 'paraQuienEsLabel',
        'durationIcon', 'durationLabel',
    ];

    private const MAX_SCALAR_LENGTH = 400;

    public function __construct(private readonly PostRepository $postRepository)
    {
    }

    /**
     * Devuelve el encabezado de pagina desde la BD, o null si no existe / esta vacio.
     *
     * @return array{title: string, subtitle: string}|null
     */
    public function getHeader(): ?array
    {
        $payload = $this->loadPayload();
        if (null === $payload) {
            return null;
        }

        $header = $payload['header'] ?? null;
        if (!is_array($header)) {
            return null;
        }

        $title    = isset($header['title'])    && is_string($header['title'])    ? trim($header['title'])    : '';
        $subtitle = isset($header['subtitle']) && is_string($header['subtitle']) ? trim($header['subtitle']) : '';

        if ('' === $title && '' === $subtitle) {
            return null;
        }

        return ['title' => $title, 'subtitle' => $subtitle];
    }

    /**
     * Devuelve las dos cajas de información del panel (globales, mismas para todas las clases).
     *
     * @return list<array{icon: string, title: string, text: string}>
     */
    public function getInfoBoxes(): array
    {
        $defaults = [
            ['icon' => 'bi-people-fill',    'title' => 'Clase Grupal',    'text' => 'Grupos reducidos para garantizar atención personalizada.'],
            ['icon' => 'bi-calendar-check', 'title' => 'Reserva tu clase', 'text' => 'Consulta horarios disponibles y reserva en línea.'],
        ];

        $payload = $this->loadPayload();
        if (null === $payload) {
            return $defaults;
        }

        $result = [];
        foreach (['infoBox1', 'infoBox2'] as $i => $key) {
            $box = $payload[$key] ?? null;
            $def = $defaults[$i];

            if (is_array($box)) {
                $result[] = [
                    'icon'  => trim((string) ($box['icon']  ?? $def['icon'])),
                    'title' => trim((string) ($box['title'] ?? $def['title'])),
                    'text'  => trim((string) ($box['text']  ?? $def['text'])),
                ];
            } else {
                $result[] = $def;
            }
        }

        return $result;
    }

    /**
     * Devuelve los datos del panel para todas las clases, indexados por slug.
     * Usado por HomeController para embeber en el template como objeto JS.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllPanelData(): array
    {
        $payload = $this->loadPayload();
        if (null === $payload || !isset($payload['classes']) || !is_array($payload['classes'])) {
            return [];
        }

        $result = [];

        foreach ($payload['classes'] as $slug => $classData) {
            if (!is_array($classData)) {
                continue;
            }

            $entry = [];

            foreach (self::SCALAR_FIELDS as $field) {
                $val = $classData[$field] ?? null;
                $entry[$field] = is_string($val) ? trim($val) : '';
            }

            $entry['benefits'] = $this->normalizeBenefits($classData['benefits'] ?? null);
            $entry['sections'] = $this->normalizeSections($classData['sections'] ?? null);

            $result[(string) $slug] = $entry;
        }

        return $result;
    }

    /**
     * Combina el contenido de la BD con el fallback para la pagina /clases.
     *
     * @param array<string, mixed> $fallback
     * @param string               $contentSlug
     *
     * @return array<string, mixed>
     */
    public function mergeClassContent(array $fallback, string $contentSlug): array
    {
        $payload = $this->loadPayload();
        if (null === $payload) {
            return $fallback;
        }

        $classData = ($payload['classes'] ?? [])[$contentSlug] ?? null;
        if (!is_array($classData)) {
            return $fallback;
        }

        $merged = $fallback;

        foreach (self::SCALAR_FIELDS as $field) {
            $val = $classData[$field] ?? null;
            if (is_string($val)) {
                $val = trim($val);
                if ('' !== $val && mb_strlen($val) <= self::MAX_SCALAR_LENGTH) {
                    $merged[$field] = $val;
                }
            }
        }

        $benefits = $this->normalizeBenefits($classData['benefits'] ?? null);
        if ([] !== $benefits) {
            $merged['benefits'] = $benefits;
        }

        $sections = $this->normalizeSections($classData['sections'] ?? null);
        if ([] !== $sections) {
            $merged['sections'] = $sections;
        }

        return $merged;
    }

    /**
     * Normaliza sections para el editor (sin forzar sección vacía — el usuario las agrega con el botón).
     *
     * @param array<string, mixed> $classData
     *
     * @return array<int, array{icon: string, title: string, text: string}>
     */
    public function normalizeSectionsForEditor(array $classData): array
    {
        return $this->normalizeSections($classData['sections'] ?? null);
    }

    /**
     * Normaliza benefits para el editor (garantiza al menos una línea vacía).
     *
     * @param array<string, mixed> $classData
     *
     * @return list<string>
     */
    public function normalizeBenefitsForEditor(array $classData): array
    {
        $benefits = $this->normalizeBenefits($classData['benefits'] ?? null);

        return [] !== $benefits ? $benefits : [''];
    }

    /**
     * @param mixed $rawBenefits
     *
     * @return list<string>
     */
    public function normalizeBenefits(mixed $rawBenefits): array
    {
        if (!is_array($rawBenefits)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $rawBenefits),
            static fn ($v) => '' !== $v,
        ));
    }

    /**
     * @param mixed $rawSections
     *
     * @return array<int, array{icon: string, title: string, text: string}>
     */
    private function normalizeSections(mixed $rawSections): array
    {
        if (!is_array($rawSections)) {
            return [];
        }

        $sections = [];

        foreach ($rawSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? ''));
            if (mb_strlen($title) > self::MAX_SCALAR_LENGTH) {
                $title = mb_substr($title, 0, self::MAX_SCALAR_LENGTH);
            }

            $icon = trim((string) ($section['icon'] ?? ''));

            $text = trim((string) ($section['text'] ?? ''));
            if (mb_strlen($text) > self::MAX_SCALAR_LENGTH) {
                $text = mb_substr($text, 0, self::MAX_SCALAR_LENGTH);
            }

            // Migración: si viene en formato antiguo (items[]) y no hay text, unir con salto de línea
            if ('' === $text && isset($section['items']) && is_array($section['items'])) {
                $text = implode("\n", array_values(array_filter(
                    array_map(static fn ($v) => is_string($v) ? trim($v) : '', $section['items']),
                    static fn ($v) => '' !== $v,
                )));
            }

            if ('' !== $title || '' !== $text) {
                $sections[] = [
                    'icon'  => $icon,
                    'title' => $title,
                    'text'  => $text,
                ];
            }
        }

        return $sections;
    }

    /**
     * Carga el payload completo de la BD para el editor del backend.
     *
     * @return array<string, mixed>
     */
    public function loadEditorPayload(): array
    {
        return $this->loadPayload() ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPayload(): ?array
    {
        $post = $this->postRepository->findOneBy([
            'slug'     => self::POST_SLUG,
            'isActive' => true,
        ]);

        if (null === $post) {
            return null;
        }

        $content = $post->getContent();
        if (null === $content || '' === $content) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
