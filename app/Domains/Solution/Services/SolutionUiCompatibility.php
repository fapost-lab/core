<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\SolutionUiBundle;
use App\Domains\Solution\DTO\UiRangeMismatch;
use Composer\Semver\Semver;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Compares the kit, Vue and Inertia ranges a Solution's console UI bundle declares with the versions this Core ships
 * (`config/solutions.php`, `console_runtime`), as `adr-20261014-solution-console-ui` (Stability) decides: the kit range is
 * checked with `composer/semver`, so it uses the syntax that understands (`^`, `~`, `>=1.0,<2.0`, `||`), and Vue and
 * Inertia are checked by major, that is: does the range admit any version of the major Core ships. Read only; a
 * mismatch is what the activation screen refuses on and `platform:update` warns about.
 *
 * A range the library cannot read counts as a mismatch (`unreadable`), never as a fit. A bundle published before its
 * ranges were kept (an old compiled cache) declares none here and reports none.
 */
final readonly class SolutionUiCompatibility
{
    /** The largest minor and patch tried when asking whether a range admits some version of a major. */
    private const int CEILING = 999_999;

    /**
     * @param  array<string, string>  $runtime  version Core ships by component: `kit`, `vue`, `inertia`
     */
    public function __construct(private array $runtime)
    {
    }

    /**
     * @return list<UiRangeMismatch> in the order kit, vue, inertia; empty when the bundle fits
     */
    public function mismatches(SolutionUiBundle $bundle): array
    {
        $mismatches = [];

        foreach (['kit' => $bundle->kit, 'vue' => $bundle->vue, 'inertia' => $bundle->inertia] as $component => $required) {
            $provided = $this->runtime[$component] ?? null;

            if (null === $required || null === $provided) {
                continue;
            }

            $fits = 'kit' === $component ? $this->satisfies($provided, $required) : $this->admitsMajor($provided, $required);

            if (true !== $fits) {
                $mismatches[] = new UiRangeMismatch($component, $required, $provided, null === $fits);
            }
        }

        return $mismatches;
    }

    /**
     * @return bool|null null when the range or the version cannot be read
     */
    private function satisfies(string $version, string $range): ?bool
    {
        try {
            return Semver::satisfies($version, $range);
        } catch (UnexpectedValueException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Whether the range admits some version of the major the platform ships: the ends of that major, and each version
     * the range itself names inside it (`>=3.6,<3.7` admits nothing at the ends of 3.x).
     *
     * @return bool|null null when the range or the version cannot be read
     */
    private function admitsMajor(string $version, string $range): ?bool
    {
        if (1 !== preg_match('/^v?(\d+)\./', $version, $found)) {
            return null;
        }

        $major      = (int) $found[1];
        $candidates = [$major . '.0.0', sprintf('%d.%d.%d', $major, self::CEILING, self::CEILING)];

        if (false !== preg_match_all('/(?<![\d.])v?' . $major . '(?:\.(\d+))?(?:\.(\d+))?(?![\d.])/', $range, $named, PREG_SET_ORDER)) {
            foreach ($named as $match) {
                $candidates[] = sprintf('%d.%d.%d', $major, (int) ($match[1] ?? 0), (int) ($match[2] ?? 0));
            }
        }

        $readable = false;

        foreach (array_unique($candidates) as $candidate) {
            $fits = $this->satisfies($candidate, $range);

            if (true === $fits) {
                return true;
            }

            $readable = $readable || null !== $fits;
        }

        return $readable ? false : null;
    }
}
