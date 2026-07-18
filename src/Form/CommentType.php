<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class CommentType extends AbstractType
{
    /** @param FormBuilderInterface<array<string, mixed>|null> $builder */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, [
            'label' => '留言',
            'attr' => ['rows' => 5, 'maxlength' => 5000],
            'constraints' => [
                new Assert\NotBlank(message: '請輸入留言。'),
                new Assert\Length(max: 5000, maxMessage: '留言不可超過 5,000 個字。'),
            ],
        ]);

        if ($options['allow_internal']) {
            $builder->add('isInternal', CheckboxType::class, [
                'label' => '僅團隊內部可見',
                'required' => false,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => '新增留言',
            'attr' => ['class' => 'button button--primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'allow_internal' => false,
        ]);
        $resolver->setAllowedTypes('allow_internal', 'bool');
    }
}
