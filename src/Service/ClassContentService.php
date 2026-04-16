<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PostRepository;

/**
 * Resuelve el contenido editable de la pagina /clases.
 *
 * Lee el Post con slug "clases-contenido" (tipo static) desde la BD.
 * El campo `content` almacena un JSON con la estructura:
 *
 * {
 *   "header": { "title": "...", "subtitle": "..." },
 *   "classes": {
 *     "pilates-reformer":  { <campos> },
 *     "beastformer":       { <campos> },
 *     "dual-pilates":      { <campos> },
 *     "clase-privada":     { <campos> }
 *   }
 * }
 *
 * Si el Post no existe o el JSON es invalido, los metodos devuelven null / el fallback sin cambios.
 */
class ClassContentService
{
    public const POST_SLUG = 'clases-contenido';

    /** Slugs canonicos editables (orden de aparicion en el editor). */
    public const KNOWN_SLUGS = [
        'pilates-reformer',
        'beastformer',
        'dual-pilates',
        'clase-privada',
    ];

    public const SCALAR_FIELDS = ['audience', 'duration', 'intensity', 'focus', 'summary', 'description'];
    public const ARRAY_FIELDS  = ['bestFor', 'keyPostures', 'guidedFlow', 'benefits', 'tips'];

    private const MAX_SCALAR_LENGTH = 400;
    private const MAX_ARRAY_ITEMS   = 8;

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
     * Combina el contenido de la BD con el fallback hardcoded para una clase concreta.
     *
     * @param array<string, mixed> $fallback     Datos del CLASS_CONTENT_MAP del controller.
     * @param string               $contentSlug  Slug canonico de la clase.
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

        foreach (self::ARRAY_FIELDS as $field) {
            $items = $classData[$field] ?? null;
            if (is_array($items)) {
                $clean = array_values(array_filter(
                    array_map(static fn ($v) => is_string($v) ? trim($v) : '', $items),
                    static fn ($v) => '' !== $v,
                ));
                if (count($clean) > 0) {
                    $merged[$field] = array_slice($clean, 0, self::MAX_ARRAY_ITEMS);
                }
            }
        }

        return $merged;
    }

    /**
     * Carga el payload completo de la BD para el editor del backend.
     * Retorna array vacio si no existe aun.
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
