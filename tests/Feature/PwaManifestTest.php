<?php

use function Pest\Laravel\get;

it('ships a maskable PWA icon in the manifest', function (): void {
    $path = public_path('site.webmanifest');

    expect($path)->toBeFile();

    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $maskable = collect($manifest['icons'])
        ->firstWhere('purpose', 'maskable');

    expect($maskable)->not->toBeNull()
        ->and($maskable['src'])->toEndWith('maskable-icon-512x512.png');

    expect(public_path(ltrim($maskable['src'], '/')))->toBeFile();
});

it('exposes theme-color and apple web-app metas in the document head', function (): void {
    get('/login')
        ->assertOk()
        ->assertSee('name="theme-color"', false)
        ->assertSee('apple-mobile-web-app-capable', false)
        ->assertSee('apple-mobile-web-app-title', false);
});
