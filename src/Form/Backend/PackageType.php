<?php

declare(strict_types=1);

namespace App\Form\Backend;

use App\Entity\Package;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Package::class,
        ]);
    }
}
