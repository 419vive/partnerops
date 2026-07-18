<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ServiceRequest;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, ServiceRequest> */
final class ServiceRequestVoter extends Voter
{
    public const VIEW = 'request_view';
    public const COMMENT = 'request_comment';
    public const MANAGE = 'request_manage';
    public const TRANSITION = 'request_transition';
    public const LOG_TIME = 'request_log_time';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ServiceRequest && \in_array($attribute, [
            self::VIEW,
            self::COMMENT,
            self::MANAGE,
            self::TRANSITION,
            self::LOG_TIME,
        ], true);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool
    {
        if (!$subject instanceof ServiceRequest) {
            return false;
        }
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        $isTeam = $user->canManageWork();
        $ownsRequest = $user->getClient() !== null && $user->getClient() === $subject->getClient();

        if ($attribute === self::VIEW) {
            return $isTeam || $ownsRequest;
        }

        if ($subject->getClient()->isArchived()) {
            return false;
        }

        return match ($attribute) {
            self::COMMENT => $isTeam || $ownsRequest,
            self::MANAGE, self::TRANSITION, self::LOG_TIME => $isTeam,
            default => false,
        };
    }
}
