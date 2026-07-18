<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comment;
use App\Entity\ServiceRequest;
use App\Repository\CommentRepository;

final readonly class ApiRequestPresenter
{
    public function __construct(private CommentRepository $comments)
    {
    }

    /**
     * @param list<Comment>|null $comments
     * @return array<string, mixed>
     */
    public function present(ServiceRequest $request, ?array $comments = null, int $commentsPage = 1): array
    {
        if ($comments === null) {
            $commentsTotal = $this->comments->countClientVisible($request->getClient(), $request);
            $commentsPages = max(1, (int) ceil($commentsTotal / CommentRepository::PAGE_SIZE));
            $commentsPage = min(max(1, $commentsPage), $commentsPages);
            $comments = $this->comments->findClientVisible($request->getClient(), $request, $commentsPage);
        } else {
            $commentsTotal = count($comments);
            $commentsPages = 1;
            $commentsPage = 1;
        }

        return [
            'id' => $request->getPublicId(),
            'title' => $request->getTitle(),
            'description' => $request->getDescription(),
            'priority' => $request->getPriority()->value,
            'status' => $request->getStatus()->value,
            'dueAt' => $this->dateTime($request->getDueAt()),
            'assignee' => $request->getAssignee() === null
                ? null
                : ['name' => $request->getAssignee()->getDisplayName()],
            'createdAt' => $this->dateTime($request->getCreatedAt()),
            'updatedAt' => $this->dateTime($request->getUpdatedAt()),
            'comments' => array_map(
                fn (Comment $comment): array => [
                    'id' => $comment->getPublicId(),
                    'body' => $comment->getBody(),
                    'author' => ['name' => $comment->getAuthor()->getDisplayName()],
                    'createdAt' => $this->dateTime($comment->getCreatedAt()),
                ],
                $comments,
            ),
            'commentsPagination' => [
                'page' => $commentsPage,
                'perPage' => CommentRepository::PAGE_SIZE,
                'total' => $commentsTotal,
                'pages' => $commentsPages,
            ],
        ];
    }

    private function dateTime(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
