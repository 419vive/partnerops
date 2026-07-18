<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\RequestPriority;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, mixed>> */
final class RequestManageType extends AbstractType
{
    /** @param FormBuilderInterface<array<string, mixed>|null> $builder */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => '請求標題',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 3, max: 160),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => '需求說明',
                'attr' => ['rows' => 6],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 10, max: 10000),
                ],
            ])
            ->add('priority', EnumType::class, [
                'class' => RequestPriority::class,
                'choice_label' => static fn (RequestPriority $priority): string => $priority->label(),
                'label' => '優先級',
            ])
            ->add('assignee', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'displayName',
                'label' => '負責人',
                'placeholder' => '尚未指派',
                'required' => false,
                'query_builder' => static fn (UserRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('u')
                    ->andWhere('u.isActive = true')
                    ->andWhere('u.role IN (:roles)')
                    ->setParameter('roles', [UserRole::Admin->value, UserRole::Agent->value])
                    ->orderBy('u.displayName', 'ASC'),
            ])
            ->add('dueAt', DateTimeType::class, [
                'label' => '預計完成時間',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => 'Asia/Taipei',
            ])
            ->add('expectedVersion', HiddenType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: '缺少版本資訊，請重新載入後再試。'),
                    new Assert\Regex(pattern: '/^[1-9][0-9]*$/', message: '版本資訊無效，請重新載入後再試。'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => '儲存工作安排',
                'attr' => ['class' => 'button button--primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
