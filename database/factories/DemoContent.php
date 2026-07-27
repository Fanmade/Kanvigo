<?php

namespace Database\Factories;

/**
 * Themed, realistic filler for factories and the demo seeder.
 *
 * Faker's lorem ipsum makes every seeded board look like placeholder rubble,
 * which is useless for screenshots, demos and eyeballing a UI change. These
 * pools produce content that reads like a real software team's board instead,
 * combining a handful of fragments into a large enough space that a seeded
 * project rarely repeats itself.
 */
final class DemoContent
{
    /**
     * A product-style project name ("Northwind Ledger").
     */
    public static function projectTitle(): string
    {
        return self::pick(self::PROJECT_PREFIXES).' '.self::pick(self::PROJECT_NOUNS);
    }

    /**
     * A one-sentence project blurb, as the rich-text editor would store it.
     */
    public static function projectDescription(): string
    {
        return '<p>'.self::pick(self::PROJECT_BLURBS).'</p>';
    }

    /**
     * A task title that reads like real work ("Speed up the search index on mobile").
     */
    public static function taskTitle(): string
    {
        return self::pick(self::TASK_VERBS).' '.self::pick(self::TASK_OBJECTS).self::pick(self::TASK_QUALIFIERS);
    }

    /**
     * A short task description: where it came from, what is wrong, what is next.
     */
    public static function taskDescription(): string
    {
        return '<p>'.self::pick(self::TASK_CONTEXT).' '.self::pick(self::TASK_DETAIL).'</p>'
            .'<p>'.self::pick(self::TASK_NEXT_STEP).'</p>';
    }

    /**
     * A reference-doc title ("API conventions").
     */
    public static function docTitle(): string
    {
        return self::pick(self::DOC_TITLES);
    }

    /**
     * A reference-doc body, occasionally with a checklist.
     */
    public static function docBody(): string
    {
        return '<p>'.self::pick(self::DOC_INTROS).'</p>'
            .'<ul><li>'.implode('</li><li>', fake()->randomElements(self::DOC_POINTS, 3)).'</li></ul>';
    }

    /**
     * A personal-note title ("Ideas for the next retro").
     */
    public static function noteTitle(): string
    {
        return self::pick(self::NOTE_TITLES);
    }

    /**
     * A personal-note body — jotted down, not polished.
     */
    public static function noteBody(): string
    {
        return '<p>'.self::pick(self::NOTE_BODIES).'</p>';
    }

    /**
     * A comment as a teammate would leave it.
     */
    public static function commentBody(): string
    {
        return '<p>'.self::pick(self::COMMENTS).'</p>';
    }

    /**
     * @param  list<string>  $pool
     */
    private static function pick(array $pool): string
    {
        return fake()->randomElement($pool);
    }

    /** @var list<string> */
    private const PROJECT_PREFIXES = [
        'Northwind', 'Atlas', 'Harbour', 'Meridian', 'Bluefin', 'Cascade', 'Ironwood',
        'Lantern', 'Solstice', 'Kestrel', 'Foundry', 'Beacon', 'Thornbury', 'Windrose',
    ];

    /** @var list<string> */
    private const PROJECT_NOUNS = [
        'Platform', 'Ledger', 'Portal', 'Workbench', 'Dashboard', 'Toolkit', 'Marketplace',
        'Scheduler', 'Archive', 'Storefront', 'Console', 'Companion',
    ];

    /** @var list<string> */
    private const PROJECT_BLURBS = [
        'The customer-facing web application and everything that ships with it.',
        'Internal tooling for the support team, rebuilt one screen at a time.',
        'A small product with a long backlog and an even longer changelog.',
        'Everything the team maintains outside the main application.',
        'The next major release, tracked from spec to rollout.',
        'Migration work: moving the last few services off the old stack.',
    ];

    /** @var list<string> */
    private const TASK_VERBS = [
        'Add', 'Fix', 'Refactor', 'Document', 'Investigate', 'Speed up', 'Harden',
        'Migrate', 'Polish', 'Automate', 'Simplify', 'Rework',
    ];

    /** @var list<string> */
    private const TASK_OBJECTS = [
        'the checkout flow', 'the search index', 'the onboarding email', 'the invoice export',
        'the settings page', 'the audit trail', 'the import wizard', 'the CSV export',
        'the session handling', 'the rate limiter', 'the webhook retries', 'the avatar upload',
        'the password reset', 'the release pipeline', 'the staging deploy', 'the error pages',
        'the empty states', 'the mobile navigation', 'the keyboard shortcuts', 'the digest email',
        'the permission checks', 'the background queue', 'the file storage', 'the API pagination',
        'the date filters', 'the timezone handling', 'the print stylesheet', 'the changelog page',
        'the health check', 'the sign-up form',
    ];

    /** @var list<string> */
    private const TASK_QUALIFIERS = [
        '', '', '', ' on mobile', ' for large projects', ' before the beta',
        ' behind a feature flag', ' in the admin area',
    ];

    /** @var list<string> */
    private const TASK_CONTEXT = [
        'Reported by support twice this week.', 'Came up in the weekly review.',
        'Left over from the last release.', 'Noticed while pairing on something else.',
        'Follow-up from the incident on Tuesday.', 'Asked for by two customers now.',
        'Found during the accessibility pass.', 'Turned up in the error tracker.',
    ];

    /** @var list<string> */
    private const TASK_DETAIL = [
        'It only happens with more than a few hundred rows.',
        'The current behaviour is technically correct but nobody expects it.',
        'It works locally, which is exactly the problem.',
        'The old workaround has outlived the bug it was written for.',
        'Nothing breaks, it is just slower than it has any right to be.',
        'The copy is fine; the timing is not.',
        'It is a five-line change plus the tests nobody wrote.',
    ];

    /** @var list<string> */
    private const TASK_NEXT_STEP = [
        'Next step: reproduce it with a test before touching anything.',
        'Next step: agree on the wording, then ship it.',
        'Next step: measure first, optimise second.',
        'Next step: check whether the API needs the same fix.',
        'Next step: split this up once we know the shape of it.',
        'Next step: confirm with support that this is the case they meant.',
    ];

    /** @var list<string> */
    private const DOC_TITLES = [
        'API conventions', 'Release checklist', 'Definition of done', 'Branching model',
        'On-call handbook', 'Naming things', 'Support escalation path', 'Environment setup',
        'Data retention rules', 'Accessibility baseline', 'Incident review template',
    ];

    /** @var list<string> */
    private const DOC_INTROS = [
        'The short version, so nobody has to ask twice.',
        'Written down after the third time we discussed it.',
        'This is the current agreement — change it here, not in chat.',
        'Background for anyone joining the project mid-flight.',
    ];

    /** @var list<string> */
    private const DOC_POINTS = [
        'Keep the change small enough to review in one sitting',
        'Write the test before the fix',
        'Update the changelog in the same change',
        'Name things after what they do, not how they work',
        'Prefer boring solutions with obvious failure modes',
        'Anything user-facing needs both languages',
        'Leave the docs cleaner than you found them',
    ];

    /** @var list<string> */
    private const NOTE_TITLES = [
        'Ideas for the next retro', 'Things to check before Friday', 'Half-formed refactor',
        'Questions for the customer call', 'Reading list', 'Postponed until after the beta',
        'Snippets worth keeping', 'Follow-ups from standup',
    ];

    /** @var list<string> */
    private const NOTE_BODIES = [
        'Nothing urgent — parking this here so it stops rattling around.',
        'Rough sketch only. Revisit once the beta is out.',
        'Copied from chat before it scrolled away.',
        'If this is still relevant next week, it becomes a task.',
        'Two options, neither obviously better. Sleep on it.',
    ];

    /** @var list<string> */
    private const COMMENTS = [
        'Reproduced on staging — same stack trace.',
        'I can take this one tomorrow morning.',
        'Agreed, though I would keep the old behaviour behind a flag for a release.',
        'This is smaller than it looks: the hard part is already done elsewhere.',
        'Do we know how many customers are actually affected?',
        'Split out the migration so this can ship without waiting on it.',
        'Tested with a stupidly large project and it held up fine.',
        'Wording nit: "archive" reads better than "remove" here.',
        'Blocked on the API change, but ready otherwise.',
        'Nice — that shaved about a second off the first paint.',
    ];
}
