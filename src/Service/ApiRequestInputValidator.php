<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\RequestPriority;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiRequestInputValidator
{
    /**
     * @return array{title:string, description:string, priority:RequestPriority, dueAt:?\DateTimeImmutable}
     */
    public function validate(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'json') {
            throw new ApiProblemException(
                Response::HTTP_BAD_REQUEST,
                'invalid_content_type',
                'Bad Request',
                'Content-Type must be application/json.',
            );
        }

        try {
            $decoded = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiProblemException(
                Response::HTTP_BAD_REQUEST,
                'invalid_json',
                'Bad Request',
                'The request body must contain valid JSON.',
                previous: $exception,
            );
        }

        if (!$decoded instanceof \stdClass) {
            throw new ApiProblemException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validation_failed',
                'Validation Failed',
                'The request body must be a JSON object.',
                [['field' => '$', 'code' => 'type', 'message' => 'Expected a JSON object.']],
            );
        }

        /** @var array<string, mixed> $input */
        $input = (array) $decoded;
        $errors = [];

        foreach (array_diff(array_keys($input), ['title', 'description', 'priority', 'dueAt']) as $field) {
            $errors[] = ['field' => $field, 'code' => 'additional_property', 'message' => 'This field is not allowed.'];
        }

        $title = $this->stringField($input, 'title', 3, 160, $errors);
        $description = $this->stringField($input, 'description', 10, 10000, $errors);

        $priority = null;
        if (!array_key_exists('priority', $input)) {
            $errors[] = ['field' => 'priority', 'code' => 'required', 'message' => 'This field is required.'];
        } elseif (!is_string($input['priority']) || null === $priority = RequestPriority::tryFrom($input['priority'])) {
            $errors[] = ['field' => 'priority', 'code' => 'choice', 'message' => 'Choose low, normal, high, or urgent.'];
        }

        $dueAt = null;
        if (array_key_exists('dueAt', $input) && $input['dueAt'] !== null) {
            if (!is_string($input['dueAt'])) {
                $errors[] = ['field' => 'dueAt', 'code' => 'type', 'message' => 'Expected an RFC 3339 date-time or null.'];
            } else {
                $dueAt = $this->dateTime($input['dueAt']);
                if ($dueAt === null) {
                    $errors[] = ['field' => 'dueAt', 'code' => 'format', 'message' => 'Expected an RFC 3339 date-time.'];
                }
            }
        }

        if ($errors !== []) {
            throw new ApiProblemException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validation_failed',
                'Validation Failed',
                'One or more fields are invalid.',
                $errors,
            );
        }
        if (!$priority instanceof RequestPriority) {
            throw new \LogicException('Validated API priority is unavailable.');
        }

        return [
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'dueAt' => $dueAt?->setTimezone(new \DateTimeZone('UTC')),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array{field:string, code:string, message:string}> $errors
     */
    private function stringField(array $input, string $field, int $min, int $max, array &$errors): string
    {
        if (!array_key_exists($field, $input)) {
            $errors[] = ['field' => $field, 'code' => 'required', 'message' => 'This field is required.'];

            return '';
        }
        if (!is_string($input[$field])) {
            $errors[] = ['field' => $field, 'code' => 'type', 'message' => 'Expected a string.'];

            return '';
        }

        $value = trim($input[$field]);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $errors[] = [
                'field' => $field,
                'code' => 'length',
                'message' => sprintf('Length must be between %d and %d characters.', $min, $max),
            ];
        }

        return $value;
    }

    private function dateTime(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) {
            return null;
        }

        $parsed = date_parse($value);
        if ($parsed['warning_count'] > 0 || $parsed['error_count'] > 0) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}
