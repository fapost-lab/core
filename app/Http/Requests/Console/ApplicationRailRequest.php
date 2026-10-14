<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Staff\Models\User;
use App\Http\Shell\ApplicationRegistry;
use App\Http\Shell\ShellApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new order of the user's own application rail: distinct keys, each of an application the user may open now. The
 * rail is the user's own preference, so any staff user who may use the console may change it.
 */
final class ApplicationRailRequest extends FormRequest
{
    /**
     * @var list<ShellApplication>|null
     */
    private ?array $applications = null;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canAccessConsole();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $keys = array_map(static fn (ShellApplication $application): string => $application->key, $this->applications());

        return [
            'applications'   => ['present', 'array', 'list', 'max:' . max(1, count($keys))],
            'applications.*' => ['required', 'string', 'distinct', Rule::in($keys)],
        ];
    }

    /**
     * The applications the user may open, the ones the rail may hold.
     *
     * @return list<ShellApplication>
     */
    public function applications(): array
    {
        if (null === $this->applications) {
            $user = $this->user();

            $this->applications = $user instanceof User ? $this->container->make(ApplicationRegistry::class)->for($user) : [];
        }

        return $this->applications;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        /** @var list<string> $keys */
        $keys = array_values($this->validated('applications', []));

        return $keys;
    }
}
