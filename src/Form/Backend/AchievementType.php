<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Achievement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AchievementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'label.name',
            ])
            ->add('description', null, [
                'label' => 'label.description',
                'required' => false,
            ])
            ->add('categoryKey', ChoiceType::class, [
                'label' => 'Categoria',
                'choices' => array_flip(Achievement::categoryChoices()),
            ])
            ->add('conditionKey', null, [
                'label' => 'Condicion',
            ])
            ->add('conditionContext', HiddenType::class, [
                'required' => false,
                'empty_data' => '',
                'mapped' => false,
            ])
            ->add('thresholdType', ChoiceType::class, [
                'label' => 'Tipo de umbral',
                'choices' => array_flip(Achievement::thresholdTypeChoices()),
            ])
            ->add('targetValue', null, [
                'label' => 'Valor objetivo',
            ])
            ->add('comparisonOperator', ChoiceType::class, [
                'label' => 'Operador',
                'choices' => array_flip(Achievement::comparisonOperatorChoices()),
            ])
            ->add('rewardType', ChoiceType::class, [
                'label' => 'Tipo de recompensa',
                'choices' => array_flip(Achievement::rewardTypeChoices()),
            ])
            ->add('rewardValue', null, [
                'label' => 'Valor recompensa',
                'required' => false,
            ])
            ->add('badgeLevel', ChoiceType::class, [
                'label' => 'Nivel badge',
                'required' => false,
                'choices' => array_flip(Achievement::badgeLevelChoices()),
            ])
            ->add('badgeColor', null, [
                'label' => 'Color badge',
                'required' => false,
            ])
            ->add('badgeIcon', null, [
                'label' => 'Icono badge',
                'required' => false,
            ])
            ->add('badgeLabel', null, [
                'label' => 'Nombre nivel badge',
                'required' => false,
            ])
            ->add('periodType', ChoiceType::class, [
                'label' => 'Tipo de periodo',
                'choices' => array_flip(Achievement::periodTypeChoices()),
            ])
            ->add('periodDays', null, [
                'label' => 'Dias de periodo',
                'required' => false,
            ])
            ->add('periodDeadline', DateType::class, [
                'label' => 'Fecha limite / Cierre ventana',
                'required' => false,
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'block_prefix' => 'datepicker',
            ])
            ->add('periodWindowStart', DateType::class, [
                'label' => 'Inicio ventana',
                'required' => false,
                'format' => 'dd/MM/yyyy',
                'html5' => false,
                'widget' => 'single_text',
                'block_prefix' => 'datepicker',
            ])
            ->add('sortOrder', null, [
                'label' => 'Orden',
            ])
            ->add('visibleProfile', null, [
                'label' => 'Visible en perfil',
                'block_prefix' => 'switch',
            ])
            ->add('notifyInApp', null, [
                'label' => 'Notificacion in-app',
                'block_prefix' => 'switch',
            ])
            ->add('difficulty', ChoiceType::class, [
                'label'    => 'Dificultad (reto)',
                'required' => false,
                'placeholder' => '-- Sin dificultad (logro normal) --',
                'choices'  => array_flip(Achievement::DIFFICULTIES),
            ])
            ->add('active', null, [
                'label' => 'label.active',
                'block_prefix' => 'switch',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Achievement::class,
        ]);
    }
}
