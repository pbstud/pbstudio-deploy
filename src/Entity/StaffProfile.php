<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StaffProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Contract\Entity\TimestampableInterface;
use Knp\DoctrineBehaviors\Model\Timestampable\TimestampableTrait;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: StaffProfileRepository::class)]
#[Vich\Uploadable]
class StaffProfile implements TimestampableInterface
{
    use TimestampableTrait;

    public const PHOTO_MAX_FILE_SIZE = '5M';
    public const PHOTO_MAX_FILE_SIZE_LABEL = '5 MB';
    public const PHOTO_MIN_WIDTH = 249;
    public const PHOTO_MIN_HEIGHT = 265;
    public const PHOTO_MAX_WIDTH = 1200;
    public const PHOTO_MAX_HEIGHT = 1200;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'profile')]
    #[ORM\JoinColumn(name: 'staff_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Staff $staff = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\NotBlank]
    private ?string $firstname = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\NotBlank]
    private ?string $paternalSurname = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\NotBlank]
    private ?string $maternalSurname = null;

    #[Vich\UploadableField(mapping: 'staff_profile', fileNameProperty: 'photo')]
    #[Assert\Image(
        maxSize: self::PHOTO_MAX_FILE_SIZE,
        maxSizeMessage: 'La imagen pesa demasiado (máximo {{ limit }} {{ suffix }}).',
        mimeTypes: ['image/jpeg', 'image/png'],
        mimeTypesMessage: 'Formato no permitido. Usa JPG o PNG.',
        minWidth: self::PHOTO_MIN_WIDTH,
        minHeight: self::PHOTO_MIN_HEIGHT,
        minWidthMessage: 'La imagen debe tener al menos {{ min_width }} px de ancho.',
        minHeightMessage: 'La imagen debe tener al menos {{ min_height }} px de alto.',
        maxWidth: self::PHOTO_MAX_WIDTH,
        maxHeight: self::PHOTO_MAX_HEIGHT,
        maxWidthMessage: 'La imagen no debe exceder {{ max_width }} px de ancho.',
        maxHeightMessage: 'La imagen no debe exceder {{ max_height }} px de alto.'
    )]
    private ?File $photoFile = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\NotBlank]
    private ?string $telephone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(
        pattern: '/^#[0-9A-Fa-f]{6}$/',
        message: 'El color debe estar en formato HEX, por ejemplo #1A2B3C.'
    )]
    private ?string $colorHex = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $admissionAt = null;

    public function __toString(): string
    {
        return sprintf('%s %s %s', $this->firstname, $this->paternalSurname, $this->maternalSurname);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStaff(): ?Staff
    {
        return $this->staff;
    }

    public function setStaff(?Staff $staff): static
    {
        $this->staff = $staff;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getPaternalSurname(): ?string
    {
        return $this->paternalSurname;
    }

    public function setPaternalSurname(?string $paternalSurname): static
    {
        $this->paternalSurname = $paternalSurname;

        return $this;
    }

    public function getMaternalSurname(): ?string
    {
        return $this->maternalSurname;
    }

    public function setMaternalSurname(?string $maternalSurname): static
    {
        $this->maternalSurname = $maternalSurname;

        return $this;
    }

    public function getPhotoFile(): ?File
    {
        return $this->photoFile;
    }

    public function setPhotoFile(?File $photoFile = null): static
    {
        $this->photoFile = $photoFile;

        if (null !== $photoFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getColorHex(): ?string
    {
        return $this->colorHex;
    }

    public function setColorHex(?string $colorHex): static
    {
        $this->colorHex = $colorHex;

        return $this;
    }

    public function getAdmissionAt(): ?\DateTimeInterface
    {
        return $this->admissionAt;
    }

    public function setAdmissionAt(?\DateTimeInterface $admissionAt): static
    {
        $this->admissionAt = $admissionAt;

        return $this;
    }
}
