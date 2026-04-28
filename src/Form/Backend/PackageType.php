<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Package;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * PackageType.
 */
class PackageType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('totalClasses', null, [
                'label' => 'label.total_classes',
            ])
            ->add('amount', null, [
                'label' => 'label.amount',
                'block_prefix' => 'money_int',
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'label.modality',
                'choices' => array_flip(Package::typeChoices()),
                'expanded' => true,
                'choice_attr' => function () {
                    return ['class' => 'flat'];
                },
            ])
            ->add('daysExpiry', null, [
                'label' => 'label.days_expiry',
            ])
            ->add('hasRestrictions', null, [
                'label' => 'Restricciones activas',
                'block_prefix' => 'switch',
                'required' => false,
            ])
            ->add('restrictionHoursSelection', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Hora de la clase',
                'choices' => $options['restriction_hours_choices'],
                'data' => $options['restriction_hours_selected'],
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'restriction-select-field'],
            ])
            ->add('restrictionDaysSelection', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Dia de la semana',
                'choices' => $options['restriction_days_choices'],
                'data' => $options['restriction_days_selected'],
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'restriction-select-field'],
            ])
            ->add('restrictionInstructorIdsSelection', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Instructores',
                'choices' => $options['restriction_instructor_choices'],
                'data' => $options['restriction_instructor_selected'],
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'restriction-select-field'],
            ])
            ->add('restrictionDisciplineIdsSelection', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Disciplinas',
                'choices' => $options['restriction_discipline_choices'],
                'data' => $options['restriction_discipline_selected'],
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'restriction-select-field'],
            ])
            ->add('restrictionBranchIdsSelection', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Sucursales',
                'choices' => $options['restriction_branch_choices'],
                'data' => $options['restriction_branch_selected'],
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'restriction-select-field'],
            ])
            ->add('isUnlimited', null, [
                'label' => 'label.is_unlimited',
                'block_prefix' => 'switch',
            ])
            ->add('newUser', null, [
                'label' => 'label.new_user',
                'block_prefix' => 'switch',
            ])
            ->add('altText', null, [
                'label' => 'label.alt_text',
            ])
            ->add('isActive', null, [
                'label' => 'label.is_active',
                'block_prefix' => 'switch',
            ])
            ->add('public', null, [
                'label' => 'label.public',
                'block_prefix' => 'switch',
            ])
            ->add('specialPrice', null, [
                'label' => 'label.special_price',
                'block_prefix' => 'money_int',
            ])
            ->add('discountInfo', null, [
                'label' => 'label.discount_info',
            ])
            ->add('specialPriceDateStart', DateType::class, [
                'label' => 'Fecha inicio descuento',
                'required' => false,
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'block_prefix' => 'datepicker',
                'attr' => ['placeholder' => 'dd/mm/aaaa'],
            ])
            ->add('specialPriceDateEnd', DateType::class, [
                'label' => 'Fecha final descuento',
                'required' => false,
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'block_prefix' => 'datepicker',
                'attr' => ['placeholder' => 'dd/mm/aaaa'],
            ])
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Package::class,
            'restriction_hours_choices' => [],
            'restriction_days_choices' => [],
            'restriction_instructor_choices' => [],
            'restriction_discipline_choices' => [],
            'restriction_branch_choices' => [],
            'restriction_hours_selected' => [],
            'restriction_days_selected' => [],
            'restriction_instructor_selected' => [],
            'restriction_discipline_selected' => [],
            'restriction_branch_selected' => [],
        ]);
    }
}
