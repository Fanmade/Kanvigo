<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Apply dark mode immediately, honouring the appearance the user picked
         in the app (Flux persists it in localStorage) before falling back to
         the system preference. --}}
    <script>
        (function () {
            const appearance = localStorage.getItem('flux.appearance') || 'system';

            if (appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    <style>
        html {
            background-color: #F8FAFC; /* zinc-50 */
        }

        html.dark {
            background-color: #0a0a0a; /* zinc-950 */
        }
    </style>

    <title>{{ __('Authorize application') }} - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96"/>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg"/>
    <link rel="shortcut icon" href="/favicon.ico"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png"/>
    <meta name="apple-mobile-web-app-title" content="Authorize MCP"/>
    <link rel="manifest" href="/site.webmanifest"/>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet"/>

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
<div class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Card Container -->
        <div class="rounded-lg border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <!-- Header -->
            <div class="flex flex-col space-y-1.5 p-6">
                <div class="flex items-center justify-center mb-4">
                    <!-- Shield Icon -->
                    <svg class="h-12 w-12 text-brand-600 dark:text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                         xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                </div>

                <h3 class="text-2xl font-semibold leading-none tracking-tight text-center">
                    {{ __('Authorize :name', ['name' => $client->name]) }}
                </h3>

                <p class="text-sm text-zinc-500 dark:text-zinc-400 text-center">
                    {{ __('This application will be able to:') }}<br/>{{ __('Use available MCP functionality.') }}
                </p>
            </div>

            <!-- Content -->
            <div class="p-6 pt-0 space-y-4">
                <!-- User Info -->
                <div class="rounded-lg border border-zinc-200 p-4 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Logged in as:') }}</p>
                    <p class="font-medium">{{ $user->email }}</p>
                </div>

                <!-- Project scope -->
                @if($projects->isNotEmpty())
                    <div class="space-y-2">
                        <p class="text-sm font-medium">{{ __('Project access') }}</p>

                        <label class="flex items-start gap-2">
                            <input type="radio" name="project_scope" value="all" form="authorizeForm" class="mt-1 accent-brand-600 dark:accent-brand-500"
                                   @checked(! $grantRestricts) data-test="oauth-scope-all">
                            <span class="text-sm">
                                {{ __('All projects') }}
                                <span class="block text-zinc-500 dark:text-zinc-400">{{ __('Can access every project you are a member of') }}</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2">
                            <input type="radio" name="project_scope" value="selected" form="authorizeForm" class="mt-1 accent-brand-600 dark:accent-brand-500"
                                   @checked($grantRestricts) data-test="oauth-scope-selected">
                            <span class="text-sm">
                                {{ __('Selected projects') }}
                                <span class="block text-zinc-500 dark:text-zinc-400">{{ __('Can only access the projects picked below') }}</span>
                            </span>
                        </label>

                        <ul id="projectChoices" class="{{ $grantRestricts ? '' : 'hidden' }} max-h-40 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            @foreach($projects as $project)
                                <li>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="projects[]" value="{{ $project->id }}" form="authorizeForm"
                                               class="accent-brand-600 dark:accent-brand-500"
                                               @checked(in_array($project->id, $grantProjectIds, true))
                                               data-test="oauth-project-{{ $project->short_name }}">
                                        <span>{{ $project->title }}</span>
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $project->short_name }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>

                        @error('projects')
                            <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    {{-- No memberships to pick from — the connection covers all
                         (zero) projects; keep the approve payload valid. --}}
                    <input type="hidden" name="project_scope" value="all" form="authorizeForm">
                @endif

                <!-- Scopes / Permissions -->
                @if(count($scopes) > 0)
                    <div class="space-y-2">
                        <p class="text-sm font-medium">{{ __('Permissions:') }}</p>

                        <ul class="space-y-2">
                            @foreach($scopes as $scope)
                                <li class="flex items-start gap-2">
                                    <div class="rounded-full bg-brand-500/10 p-1 mt-0.5">
                                        <div class="h-1.5 w-1.5 rounded-full bg-brand-500"></div>
                                    </div>
                                    {{-- The description is registered in English by Laravel MCP's
                                         scope setup; translate it dynamically (de.json carries the
                                         "Use MCP server" key). --}}
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __($scope->description) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <!-- Footer With Buttons -->
            <div class="flex items-center p-6 pt-0 gap-3">
                <!-- Deny Form -->
                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit"
                            class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-50 border border-zinc-300 bg-white hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900 dark:hover:bg-zinc-800 h-10 px-4 py-2 w-full">
                        <svg class="mr-2 h-4 w-4" stroke="currentColor" viewBox="0 0 24 24"
                             xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        {{ __('Cancel') }}
                    </button>
                </form>

                <!-- Approve Form -->
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1"
                      id="authorizeForm">
                    @csrf
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit"
                            class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-zinc-900 disabled:pointer-events-none disabled:opacity-50 bg-brand-600 text-white hover:bg-brand-500 dark:bg-brand-500 dark:hover:bg-brand-400 h-10 px-4 py-2 w-full"
                            id="authorizeButton">
                        <span id="authorizeText">{{ __('Authorize') }}</span>

                        <svg id="loadingSpinner" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white hidden"
                             xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Reveal the project checkboxes only while "Selected projects" is chosen...
        const projectChoices = document.getElementById('projectChoices');
        if (projectChoices) {
            document.querySelectorAll('input[name="project_scope"]').forEach(function (radio) {
                radio.addEventListener('change', function () {
                    projectChoices.classList.toggle('hidden', radio.value !== 'selected' || !radio.checked);
                });
            });
        }

        const form = document.getElementById('authorizeForm');
        const button = document.getElementById('authorizeButton');
        const authorizeText = document.getElementById('authorizeText');
        const loadingSpinner = document.getElementById('loadingSpinner');

        form.addEventListener('submit', function (e) {
            // Show loading state...
            button.disabled = true;
            authorizeText.textContent = @js(__('Authorizing…'));
            loadingSpinner.classList.remove('hidden');

            // After form submission, watch for redirect and close window...
            setTimeout(function () {
                const checkRedirect = setInterval(function () {
                    // If URL changed or we have OAuth params, redirect happened...
                    if (!window.location.href.includes('/oauth/authorize') ||
                        window.location.search.includes('code=') ||
                        window.location.search.includes('error=')) {
                        clearInterval(checkRedirect);
                        window.close();
                    }
                }, 100);

                // Fallback: Close after five seconds...
                setTimeout(function () {
                    clearInterval(checkRedirect);
                    window.close();
                }, 5000);
            }, 200);
        });

        // Handle cancel button...
        const cancelForm = document.querySelector('form[method="POST"]:has(input[name="_method"][value="DELETE"])');
        if (cancelForm) {
            cancelForm.addEventListener('submit', function (e) {
                setTimeout(function () {
                    window.close();
                }, 200);
            });
        }
    });
</script>
</body>
</html>
