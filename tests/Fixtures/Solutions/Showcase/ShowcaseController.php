<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solutions\Showcase;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages of the fixture Solution. `foreign` renders a page of Core, which a Solution route must not.
 */
final class ShowcaseController
{
    public function board(): Response
    {
        return Inertia::render('showcase::Board/Index', ['title' => 'Board']);
    }

    public function desk(): Response
    {
        return Inertia::render('showcase::Board/Index', ['title' => 'Desk']);
    }

    public function foreign(): Response
    {
        return Inertia::render('Console/Dashboard');
    }

    /*
     * Route parameters reach an action by position: the assistant first, then the record.
     */
    public function contact(string $tenant, string $record): Response
    {
        return Inertia::render('showcase::Contact/Show', ['record' => $record, 'assistant' => $tenant]);
    }
}
