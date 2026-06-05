<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Discipline;
use App\Entity\ExerciseRoom;
use App\Entity\Package;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ExerciseRoomType.
 */
class ExerciseRoomType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'label.name',
            ])
            ->add('capacity', null, [
                'label' => 'label.capacity',
            ])
            ->add('discipline', EntityType::class, [
                'label' => 'label.discipline',
                'class' => Discipline::class,
                'placeholder' => '',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('d')
                        ->where('d.isActive = :active')
                        ->orderBy('d.name')
                        ->setParameter('active', true)
                    ;
                },
            ])
            ->add('branchOffice', null, [
                'label' => 'label.branch_office',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'label.type',
                'choices' => array_flip(Package::typeChoices()),
                'expanded' => true,
                'choice_attr' => function () {
                    return ['class' => 'flat'];
                },
            ])
            ->add('isActive', null, [
                'label' => 'label.is_active',
                'block_prefix' => 'switch',
            ])
            ->add('placesNotAvailable', TextType::class, [
                'label' => 'label.places_not_available',
                'required' => false,
                'attr' => [
                    'data-role' => 'tagsinput',
                ],
            ])
            ->add('seatLayout', HiddenType::class, [
                'required' => false,
            ])
        ;

        $builder->get('placesNotAvailable')
            ->addModelTransformer(new CallbackTransformer(
                static function ($arrayAsString) {
                    return implode(',', is_array($arrayAsString) ? $arrayAsString : []);
                },
                static function ($stringAsArray) {
                    if (empty($stringAsArray)) {
                        return [];
                    }

                    $tokens = preg_split('/[^0-9]+/', (string) $stringAsArray) ?: [];

                    return array_values(array_filter($tokens, static fn ($value) => '' !== $value));
                }
            ))
        ;

        $builder->get('seatLayout')
            ->addModelTransformer(new CallbackTransformer(
                static function ($layout): string {
                    return is_array($layout) ? (string) json_encode($layout) : '';
                },
                static function ($layout): ?array {
                    if (is_array($layout)) {
                        return $layout;
                    }

                    if (!is_string($layout) || '' === trim($layout)) {
                        return null;
                    }

                    $decoded = json_decode($layout, true);

                    return is_array($decoded) ? $decoded : null;
                }
            ))
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExerciseRoom::class,
        ]);
    }
}