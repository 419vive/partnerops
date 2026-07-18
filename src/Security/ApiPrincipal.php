<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiCredential;
use App\Entity\Client;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ApiPrincipal implements UserInterface
{
    public function __construct(private ApiCredential $credential)
    {
    }

    public function getCredential(): ApiCredential
    {
        return $this->credential;
    }

    public function getClient(): Client
    {
        return $this->credential->getClient();
    }

    public function getUserIdentifier(): string
    {
        return 'api:'.$this->credential->getPublicId();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_API_CLIENT'];
    }

    public function eraseCredentials(): void
    {
    }
}
