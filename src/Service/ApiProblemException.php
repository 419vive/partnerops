<?php

declare(strict_types=1);

namespace App\Service;

final class ApiProblemException extends \RuntimeException
{
    /**
     * @param list<array{field:string, code:string, message:string}> $errors
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        string $detail,
        public readonly array $errors = [],
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }
}
