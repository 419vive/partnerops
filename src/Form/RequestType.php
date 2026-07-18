<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Client;
use App\Enum\RequestPriority;
use App\Repository\ClientRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class RequestType extends AbstractType
{
    /** @param FormBuilderInterface<array<string, mixed>|null> $builder */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['team_mode']) {
            $builder->add('client', EntityType::class, [
                'class' => Client::class,
                'choice_label' => 'name',
                'label' => '客戶',
                'placeholder' => '請選擇客戶',
                'query_builder' => static fn (ClientRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('c')
                    ->andWhere('c.isArchived = false')
                    ->orderBy('c.name', 'ASC'),
                'constraints' => [new Assert\NotNull(message: '請選擇客戶。')],
            ]);
        }

        $builder
            ->add('title', TextType::class, [
                'label' => '標題',
                'attr' => ['maxlength' => 160, 'autocomplete' => 'off'],
                'constraints' => [
                    new Assert\NotBlank(message: '請輸入標題。'),
                    new Assert\Length(min: 3, max: 160, minMessage: '標題至少需要 3 個字。', maxMessage: '標題不可超過 160 個字。'),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => '需求說明',
                'help' => '請提供背景、預期結果與可重現的線索。',
                'attr' => ['rows' => 8, 'maxlength' => 10000],
                'constraints' => [
                    new Assert\NotBlank(message: '請輸入需求說明。'),
                    new Assert\Length(min: 10, max: 10000, minMessage: '需求說明至少需要 10 個字。', maxMessage: '需求說明不可超過 10,000 個字。'),
                ],
            ])
            ->add('priority', EnumType::class, [
                'class' => RequestPriority::class,
                'choice_label' => static fn (RequestPriority $priority): string => $priority->label(),
                'label' => '優先級',
            ])
            ->add('submit', SubmitType::class, [
                'label' => '建立服務請求',
                'attr' => ['class' => 'button button--signal'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'team_mode' => false,
        ]);
        $resolver->setAllowedTypes('team_mode', 'bool');
    }
}
