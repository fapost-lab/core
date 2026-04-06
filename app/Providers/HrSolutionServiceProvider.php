<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Stub provider for the future HR Solution package (task 23).
 *
 * Contact extensions (relations and computed attributes) will be registered here.
 */
final class HrSolutionServiceProvider extends ServiceProvider
{
    /**
     * Register HR Solution bindings (currently a stub).
     *
     * The real HR solution will register model relations/computed attributes
     * for {@see \App\Domains\Contact\Models\Contact} through Core extension points.
     */
    public function register(): void
    {

    }

    /**
     * Bootstrap HR extensions on Contact.
     *
     * Intentionally empty until the HR Solution package is integrated.
     */
    public function boot(): void
    {
        // Task 23: register HR extensions on Contact.
        //
        // Contact::resolveRelationUsing(
        //     'employee',
        //     fn (Contact $contact) => $contact->hasOne(Employee::class, 'contact_id'),
        // );
        //
        // app(ModelAttributeRegistry::class)->register(
        //     Contact::class,
        //     'department_name',
        //     fn (Contact $contact) => $contact->employee?->department,
        //     append: false,
        // );
    }
}
