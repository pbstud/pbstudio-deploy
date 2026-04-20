<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HomeContentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: HomeContentRepository::class)]
#[Vich\Uploadable]
class HomeContent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // --- Banner ---

    #[Vich\UploadableField(mapping: 'config', fileNameProperty: 'bannerDesktop')]
    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Solo JPG, PNG o WebP.'
    )]
    private ?File $bannerDesktopFile = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $bannerDesktop = null;

    #[Vich\UploadableField(mapping: 'config', fileNameProperty: 'bannerMobile')]
    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Solo JPG, PNG o WebP.'
    )]
    private ?File $bannerMobileFile = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $bannerMobile = null;

    // --- Caja 1 ---

    #[Vich\UploadableField(mapping: 'config', fileNameProperty: 'box1Image')]
    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Solo JPG, PNG o WebP.'
    )]
    private ?File $box1ImageFile = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $box1Image = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $box1Title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $box1Description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $box1Url = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $box1LinkLabel = null;

    // --- Caja 2 ---

    #[Vich\UploadableField(mapping: 'config', fileNameProperty: 'box2Image')]
    #[Assert\Image(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Solo JPG, PNG o WebP.'
    )]
    private ?File $box2ImageFile = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $box2Image = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $box2Title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $box2Description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $box2Url = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $box2LinkLabel = null;

    // --- Contacto ---

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactFacebook = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactInstagram = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactWhatsapp = null;

    // --- Timestamps (requeridos por VichUploader para el trigger de update) ---

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // -------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    // Banner desktop

    public function getBannerDesktopFile(): ?File
    {
        return $this->bannerDesktopFile;
    }

    public function setBannerDesktopFile(?File $bannerDesktopFile = null): static
    {
        $this->bannerDesktopFile = $bannerDesktopFile;
        if (null !== $bannerDesktopFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getBannerDesktop(): ?string
    {
        return $this->bannerDesktop;
    }

    public function setBannerDesktop(?string $bannerDesktop): static
    {
        $this->bannerDesktop = $bannerDesktop;

        return $this;
    }

    // Banner mobile

    public function getBannerMobileFile(): ?File
    {
        return $this->bannerMobileFile;
    }

    public function setBannerMobileFile(?File $bannerMobileFile = null): static
    {
        $this->bannerMobileFile = $bannerMobileFile;
        if (null !== $bannerMobileFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getBannerMobile(): ?string
    {
        return $this->bannerMobile;
    }

    public function setBannerMobile(?string $bannerMobile): static
    {
        $this->bannerMobile = $bannerMobile;

        return $this;
    }

    // Caja 1

    public function getBox1ImageFile(): ?File
    {
        return $this->box1ImageFile;
    }

    public function setBox1ImageFile(?File $box1ImageFile = null): static
    {
        $this->box1ImageFile = $box1ImageFile;
        if (null !== $box1ImageFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getBox1Image(): ?string
    {
        return $this->box1Image;
    }

    public function setBox1Image(?string $box1Image): static
    {
        $this->box1Image = $box1Image;

        return $this;
    }

    public function getBox1Title(): ?string
    {
        return $this->box1Title;
    }

    public function setBox1Title(?string $box1Title): static
    {
        $this->box1Title = $box1Title;

        return $this;
    }

    public function getBox1Description(): ?string
    {
        return $this->box1Description;
    }

    public function setBox1Description(?string $box1Description): static
    {
        $this->box1Description = $box1Description;

        return $this;
    }

    public function getBox1Url(): ?string
    {
        return $this->box1Url;
    }

    public function setBox1Url(?string $box1Url): static
    {
        $this->box1Url = $box1Url;

        return $this;
    }

    public function getBox1LinkLabel(): ?string
    {
        return $this->box1LinkLabel;
    }

    public function setBox1LinkLabel(?string $box1LinkLabel): static
    {
        $this->box1LinkLabel = $box1LinkLabel;

        return $this;
    }

    // Caja 2

    public function getBox2ImageFile(): ?File
    {
        return $this->box2ImageFile;
    }

    public function setBox2ImageFile(?File $box2ImageFile = null): static
    {
        $this->box2ImageFile = $box2ImageFile;
        if (null !== $box2ImageFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getBox2Image(): ?string
    {
        return $this->box2Image;
    }

    public function setBox2Image(?string $box2Image): static
    {
        $this->box2Image = $box2Image;

        return $this;
    }

    public function getBox2Title(): ?string
    {
        return $this->box2Title;
    }

    public function setBox2Title(?string $box2Title): static
    {
        $this->box2Title = $box2Title;

        return $this;
    }

    public function getBox2Description(): ?string
    {
        return $this->box2Description;
    }

    public function setBox2Description(?string $box2Description): static
    {
        $this->box2Description = $box2Description;

        return $this;
    }

    public function getBox2Url(): ?string
    {
        return $this->box2Url;
    }

    public function setBox2Url(?string $box2Url): static
    {
        $this->box2Url = $box2Url;

        return $this;
    }

    public function getBox2LinkLabel(): ?string
    {
        return $this->box2LinkLabel;
    }

    public function setBox2LinkLabel(?string $box2LinkLabel): static
    {
        $this->box2LinkLabel = $box2LinkLabel;

        return $this;
    }

    // Contacto

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $this->contactEmail = $contactEmail;

        return $this;
    }

    public function getContactFacebook(): ?string
    {
        return $this->contactFacebook;
    }

    public function setContactFacebook(?string $contactFacebook): static
    {
        $this->contactFacebook = $contactFacebook;

        return $this;
    }

    public function getContactInstagram(): ?string
    {
        return $this->contactInstagram;
    }

    public function setContactInstagram(?string $contactInstagram): static
    {
        $this->contactInstagram = $contactInstagram;

        return $this;
    }

    public function getContactWhatsapp(): ?string
    {
        return $this->contactWhatsapp;
    }

    public function setContactWhatsapp(?string $contactWhatsapp): static
    {
        $this->contactWhatsapp = $contactWhatsapp;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
