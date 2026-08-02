<?php

namespace App\Support;

use Illuminate\Support\Facades\Blade;

/**
 * The icons offered in the icon pickers (task types and tags): every icon Flux
 * can actually render, discovered from the anonymous-component paths Flux
 * registers under the `flux` namespace — its bundled Heroicon set plus any icon
 * pulled into the app with `php artisan flux:icon`. Nothing to curate: an icon
 * added to the app becomes selectable on the next request.
 */
class IconCatalog
{
    /**
     * The discovered icon names, memoised for the request — every picker render
     * and every validation pass would otherwise re-scan the icon directories.
     *
     * @var list<string>|null
     */
    protected static ?array $cached = null;

    /**
     * Every icon a task type or tag may carry, alphabetically.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        return self::$cached ??= self::discover();
    }

    /**
     * The icons whose name contains the query, capped at $limit so the picker
     * never renders hundreds of buttons. A blank query returns the first $limit
     * icons, giving the picker something to show before anything is typed.
     *
     * @return list<string>
     */
    public static function search(?string $query, int $limit = 60): array
    {
        $needle = mb_strtolower(trim((string) $query));

        $matches = $needle === ''
            ? self::available()
            : array_values(array_filter(
                self::available(),
                static fn (string $icon): bool => str_contains($icon, $needle),
            ));

        return array_slice($matches, 0, $limit);
    }

    /**
     * Return the icon only when Flux can render it, otherwise null — so a
     * preview/badge never tries to render a blank or stale icon value.
     */
    public static function validOrNull(?string $icon): ?string
    {
        return $icon !== null && in_array($icon, self::available(), true) ? $icon : null;
    }

    /**
     * Forget the memoised list. Only needed when the icon directories change
     * within a single process (tests publishing a custom icon).
     */
    public static function flush(): void
    {
        self::$cached = null;
    }

    /**
     * Collect the icon component names from every registered `flux` component
     * path. Later paths do not shadow earlier ones here — an app-published icon
     * overriding a bundled one is the same name either way, so the list is
     * simply deduped.
     *
     * @return list<string>
     */
    protected static function discover(): array
    {
        $icons = [];

        foreach (self::iconDirectories() as $directory) {
            foreach (glob($directory.'/*.blade.php') ?: [] as $file) {
                $icons[] = basename($file, '.blade.php');
            }
        }

        $icons = array_values(array_unique($icons));
        sort($icons);

        return $icons;
    }

    /**
     * The `icon` subdirectory of each anonymous-component path Flux registers
     * under the `flux` prefix (the app's `resources/views/flux` and the
     * package's bundled stubs).
     *
     * @return list<string>
     */
    protected static function iconDirectories(): array
    {
        $directories = [];

        foreach (Blade::getAnonymousComponentPaths() as $path) {
            if (($path['prefix'] ?? null) !== 'flux') {
                continue;
            }

            $directory = $path['path'].'/icon';

            if (is_dir($directory)) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }
}
