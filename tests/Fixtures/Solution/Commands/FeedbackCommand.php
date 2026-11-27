<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution\Commands;

use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;

/**
 * The `/feedback` chat command of the fixture Solution: it thanks the contact through its own `feedback.thank` type.
 */
final class FeedbackCommand implements SolutionCommandInterface
{
    final public const string COMMAND = '/feedback';

    final public const string REPLY = 'Thanks for your feedback!';

    public function command(): string
    {
        return self::COMMAND;
    }

    public function actionType(): string
    {
        return FeedbackThankCommandType::TYPE;
    }

    public function config(): array
    {
        return ['text' => self::REPLY];
    }

    public function label(): string
    {
        return 'Leave feedback';
    }
}
