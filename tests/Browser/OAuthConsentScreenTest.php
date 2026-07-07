<?php

use App\Models\Project;
use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Build the authorization URL Claude-style clients open, for a freshly
 * registered OAuth client.
 */
function consentUrl(Client $client): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'state-123',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', 'a-test-verifier-of-sufficient-length-1234567890', true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]);
}

it('renders the consent screen in dark mode without JS errors', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC', 'title' => 'Alpha']);

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude',
        redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
        enableDeviceFlow: false,
    );

    $this->actingAs($user);

    $page = visit(consentUrl($client))->inDarkMode();

    $page->assertVisible('@oauth-scope-all')
        ->assertVisible('@oauth-scope-selected');

    // The picker reveals its project checkboxes when "Selected projects" is chosen.
    $page->click('@oauth-scope-selected')
        ->assertVisible('@oauth-project-ABC');

//    $page->screenshot(false, 'oauth-consent-dark');

    $page->assertNoJavascriptErrors();
});

it('renders the consent screen in light mode without JS errors', function () {
    $user = User::factory()->create();
    Project::factory()->withMembers([$user])->create(['short_name' => 'ABC', 'title' => 'Alpha']);

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude',
        redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
        enableDeviceFlow: false,
    );

    $this->actingAs($user);

    $page = visit(consentUrl($client));

    $page->assertVisible('@oauth-scope-all');

//    $page->screenshot(false, 'oauth-consent-light');

    $page->assertNoJavascriptErrors();
});
