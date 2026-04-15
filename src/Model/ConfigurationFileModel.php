<?php

declare(strict_types=1);

namespace App\Model;

use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[Vich\Uploadable]
class ConfigurationFileModel
{
    /**
     * @var string|null
     */
    private ?string $name = null;

    /**
     * @var File|null
     */
    #[Vich\UploadableField(mapping: 'config', fileNameProperty: 'name')]
    private ?File $file = null;

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string|null $name
     *
     * @return ConfigurationFileModel
     */
    public function setName(?string $name): ConfigurationFileModel
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return File|null
     */
    public function getFile(): ?File
    {
        return $this->file;
    }

    /**
     * @param File|null $file
     *
     * @return ConfigurationFileModel
     */
    public function setFile(?File $file): ConfigurationFileModel
    {
        $this->file = $file;

        return $this;
    }
}
