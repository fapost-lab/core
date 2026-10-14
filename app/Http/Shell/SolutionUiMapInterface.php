<?php

declare(strict_types=1);

namespace App\Http\Shell;

/**
 * The prebuilt console UI of the Solutions active for the current tenant, as the console's root view lists it for
 * the page resolver (ADR solution-console-ui).
 *
 * Core renders {@see self::bundles()} into `#fapost-solutions` beside the import map ({@see SolutionImportMap}) and
 * appends {@see self::versionSuffix()} to the Inertia asset version, so activating, deactivating or updating a
 * Solution makes the next visit a full reload. Implemented by {@see SolutionUi}.
 *
 * @phpstan-type SolutionBundle array{pages: array<string, string>, components: array<string, string>, css: list<string>}
 */
interface SolutionUiMapInterface
{
    /**
     * Bundles by Solution `id`: page and component names (the `<Path>` of `<id>::<Path>`) to module URLs, and the
     * stylesheets to attach before mounting any of them.
     *
     * @return array<string, SolutionBundle>
     */
    public function bundles(): array;

    /**
     * What sets this tenant's set of Solution bundles apart from another's, appended to the Inertia asset version;
     * empty when there is none.
     */
    public function versionSuffix(): string;
}
