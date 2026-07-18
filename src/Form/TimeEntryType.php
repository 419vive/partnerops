<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class TimeEntryType extends AbstractType
{
    /** @param FormBuilderInterface<array<string, mixed>|null> $builder */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workDate', DateType::class, [
                'label' => '工作日期',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: '請選擇工作日期。')],
            ])
            ->add('minutes', IntegerType::class, [
                'label' => '工作分鐘',
                'attr' => ['min' => 1, 'max' => 1440, 'inputmode' => 'numeric'],
                'constraints' => [
                    new Assert\NotBlank(message: '請輸入工作分鐘。'),
                    new Assert\Range(notInRangeMessage: '工作分鐘必須介於 1 至 1,440 之間。', min: 1, max: 1440),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => '工作摘要',
                'attr' => ['rows' => 4, 'maxlength' => 500],
                'constraints' => [
                    new Assert\NotBlank(message: '請輸入工作摘要。'),
                    new Assert\Length(min: 3, max: 500, minMessage: '工作摘要至少需要 3 個字。', maxMessage: '工作摘要不可超過 500 個字。'),
                ],
            ])
            ->add('isClientVisible', CheckboxType::class, [
                'label' => '客戶可看到這筆工時說明',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => '記錄工時',
                'attr' => ['class' => 'button button--primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
