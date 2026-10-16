<?php

declare(strict_types=1);

namespace Fapost\Testing;

use PHPUnit\Framework\Assert;

/**
 * The outcome of {@see SolutionTestCase::callAction()}: what the call node's handler transport answered.
 *
 * @experimental may change in minor versions before 1.0
 */
final readonly class ActionCallResult
{
    public function __construct(
        public bool $success,
        public mixed $body,
        public ?string $errorCode,
    ) {
    }

    /**
     * @internal the kit builds the result; a test reads it
     *
     * @param  array<string, mixed>  $result  the array Core's call tester returns
     */
    public static function fromArray(array $result): self
    {
        return new self(
            success: true === ($result['success'] ?? false),
            body: $result['body'] ?? null,
            errorCode: is_string($result['error_code'] ?? null) ? $result['error_code'] : null,
        );
    }

    public function assertSucceeded(): static
    {
        Assert::assertTrue(
            $this->success,
            sprintf(
                'Expected the action call to succeed, it was refused with "%s"%s.',
                $this->errorCode ?? 'no error code',
                // A refused call carries its explanation, when it has one, in the body.
                is_string($this->body) && '' !== $this->body ? ': ' . $this->body : '',
            ),
        );

        return $this;
    }

    public function assertRefused(string $errorCode): static
    {
        Assert::assertFalse($this->success, 'Expected the action call to be refused, it succeeded.');
        Assert::assertSame($errorCode, $this->errorCode, 'The action call was refused with another error code.');

        return $this;
    }
}
