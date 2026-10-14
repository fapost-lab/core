<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Staff\Models\User;

/**
 * The search palette's groups for the applications themselves: the applications the user may open, by name, and the
 * pages of each, from its menu (so a page appears exactly when the menu shows it). Records come after them, from
 * {@see AdminSearch}.
 */
final readonly class ApplicationSearch
{
    /**
     * Hits per group.
     */
    public const int LIMIT = 8;

    public function __construct(
        private ApplicationRegistry $registry,
    ) {
    }

    /**
     * @param  list<string|null>  $wishedAssistantIds  Where the Console opens, best first (see {@see ApplicationRegistry::for()}).
     *
     * @return list<array{key: string, items: list<array{id: string, title: string, subtitle: string|null, url: string, external: bool}>}>
     */
    public function search(User $user, string $text, array $wishedAssistantIds = []): array
    {
        $text = mb_trim($text);

        if (mb_strlen($text) < AdminSearch::MIN_LENGTH) {
            return [];
        }

        $applications = $this->registry->for($user, null, $wishedAssistantIds);
        $found        = [];
        $pages        = [];

        foreach ($applications as $application) {
            if ($this->matches($application->label, $text)) {
                $found[] = $this->hit($application->key, $application->label, $application->scope, $application->href, $application->external);
            }

            foreach ($this->registry->menu($user, $application) as $group) {
                foreach ($group['items'] as $item) {
                    if ($this->matches($item['label'], $text)) {
                        $pages[] = $this->hit($item['key'], $item['label'], $application->label, $item['href'], $item['external']);
                    }
                }
            }
        }

        $groups = [];

        foreach (['applications' => $found, 'pages' => $pages] as $key => $items) {
            if ([] !== $items) {
                $groups[] = ['key' => $key, 'items' => array_slice($items, 0, self::LIMIT)];
            }
        }

        return $groups;
    }

    private function matches(string $label, string $text): bool
    {
        return false !== mb_stripos($label, $text);
    }

    /**
     * @return array{id: string, title: string, subtitle: string|null, url: string, external: bool}
     */
    private function hit(string $id, string $title, ?string $subtitle, string $url, bool $external): array
    {
        return ['id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url, 'external' => $external];
    }
}
