<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\StaffProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * InstructorProfileType.
 */
class InstructorProfileType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', null, [
                'label' => 'label.firstname',
            ])
            ->add('paternalSurname', null, [
                'label' => 'label.paternal_surname',
            ])
            ->add('maternalSurname', null, [
                'label' => 'label.maternal_surname',
            ])
            ->add('photoFile', VichImageType::class, [
                'label' => 'label.photo_file',
                'required' => false,
                'allow_delete' => true,
                'delete_label' => 'label.remove_photo',
                'download_uri' => false,
                'image_uri' => false,
                'attr' => [
                    'accept' => '.jpg,.jpeg,.png',
                ],
                'help' => 'help.instructor_photo',
                'help_html' => true,
                'help_translation_parameters' => [
                    '%max_size%' => StaffProfile::PHOTO_MAX_FILE_SIZE_LABEL,
                    '%min_width%' => (string) StaffProfile::PHOTO_MIN_WIDTH,
                    '%min_height%' => (string) StaffProfile::PHOTO_MIN_HEIGHT,
                    '%max_width%' => (string) StaffProfile::PHOTO_MAX_WIDTH,
                    '%max_height%' => (string) StaffProfile::PHOTO_MAX_HEIGHT,
                ],
            ])
            ->add('telephone', null, [
                'label' => 'label.telephone',
            ])
            ->add('admissionAt', DateType::class, [
                'label' => 'label.admission_at',
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'datepicker'],
                'block_prefix' => 'datepicker',
            ])
            ->add('description', null, [
                'label' => 'label.description',
            ])
            ->add('colorHex', ColorType::class, [
                'label' => 'Color del instructor',
                'required' => false,
                'attr' => [
                    'class' => 'instructor-color-input',
                    'style' => 'width: 42px; height: 42px; padding: 2px; border-radius: 6px; border: 1px solid #d9dee4;',
                ],
                'help' => 'Se usa para sombrear suavemente el instructor en la grilla.',
            ])
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StaffProfile::class]);
    }
}
