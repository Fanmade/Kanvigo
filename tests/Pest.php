<?php

use App\Authorization\ProjectRoleProvisioner;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Doc;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Facades\Audit;
use App\Support\InlineReferenceParser;
use Fanmade\DelegatedPermissions\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

// Browser tests run the app, its JS bundle (Livewire, Flux, Tiptap) and a real
// browser all in-process, and the suite runs `--parallel`. Under that CPU
// contention an occasional page load or Livewire render overshoots Playwright's
// 5s default and a wait spuriously times out (e.g. login → dashboard). Raising
// the ceiling costs passing tests nothing — waits resolve the moment their
// condition is met — and only absorbs those load spikes.
pest()->browser()->timeout(15_000);

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed a feed activity for a task/project through the real audit pipeline
 * (Audit::record() → ActivityLogSink) and return the Activity row it wrote —
 * the seeding equivalent of the removed recordActivity() model helper.
 */
function seedActivity(Task|Project $subject, string $action, ?string $field = null, ?string $oldValue = null, ?string $newValue = null): Activity
{
    Audit::record($subject->contentAuditEvent($action, $field, $oldValue, $newValue));

    return $subject->activities()->orderByDesc('id')->firstOrFail();
}

/**
 * Grant one or more users membership of a project with one or more package
 * roles (default: member). Mirrors how the app provisions members (KAN-243): a
 * project_user row plus a delegated-permissions role assignment, so the members
 * resolve real project access through the package. Pass an array of role names
 * to hold several at once (KAN-317).
 *
 * @param  User|int|array<int, User|int>  $users
 * @param  string|list<string>  $role
 */
function joinProject(Project $project, User|int|array $users, string|array $role = 'member'): void
{
    $provisioner = app(ProjectRoleProvisioner::class);
    $roles = array_values((array) $role);

    foreach (is_array($users) ? $users : [$users] as $user) {
        $user = $user instanceof User ? $user : User::findOrFail($user);
        $project->members()->syncWithoutDetaching([$user->id]);
        $provisioner->syncMember($project, $user, $roles[0]);

        foreach (array_slice($roles, 1) as $extraRole) {
            $provisioner->addRole($project, $user, $extraRole);
        }
    }
}

/**
 * A user holding the named base project role (owner|admin|member|viewer).
 */
function userWithRole(Project $project, string $role): User
{
    $user = User::factory()->create();
    joinProject($project, $user, $role);

    return $user;
}

/**
 * The stored markup of an inline #reference to a task or doc, as the rich-text
 * editor writes it — the shape {@see InlineReferenceParser} reads
 * back when it reconciles an item's links.
 */
function inlineReference(Task|Doc $item): string
{
    $type = $item instanceof Doc ? 'doc' : 'task';

    return '<a class="reference" data-type="reference" data-item-type="'.$type.'"'
        .' data-id="'.$item->getKey().'" data-label="'.$item->reference.'"'
        .' href="/'.$item->reference.'">'.$item->reference.'</a>';
}

/**
 * A user holding a fresh custom project role that grants exactly the given
 * permissions (plus view-project, so they can reach the project at all).
 *
 * @param  list<string>  $permissions
 */
function userWithPermissions(Project $project, array $permissions): User
{
    $owner = app(ProjectRoleProvisioner::class)->roleFor($project, 'owner');
    $role = app(RoleManager::class)->createRole(
        'Custom '.fake()->unique()->word(),
        $owner,
        array_values(array_unique(['view-project', ...$permissions])),
        $project,
    );

    return User::factory()->create()->assignRole($role);
}

/**
 * A stored PNG of the given size, attached to the task and embedded in its
 * description as an inline image — the shape the export's image handling reads.
 */
function attachInlineImage(Task $task, int $width = 40, int $height = 30, string $name = 'diagram.png'): Attachment
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $attachment = Attachment::factory()->inline()->create([
        'attachable_id' => $task->getKey(),
        'attachable_type' => $task->getMorphClass(),
        'name' => $name,
        'size' => strlen($bytes),
    ]);

    Storage::disk($attachment->disk)->put($attachment->path, $bytes);

    $task->update([
        'description' => '<p><img src="'.$attachment->thumbnailUrl(absolute: false).'" alt="Screenshot"></p>',
    ]);

    return $attachment;
}

/**
 * Raw bytes of a throwaway test image of the given dimensions, filled with a
 * coloured grid so the encoder cannot collapse it to a handful of bytes — tests
 * that assert an image got smaller need it to have had a size to begin with.
 *
 * The grid cell is 150px: a fine 8px grid produces a pattern that is *too*
 * regular — resampling it down to a smaller box aliases the tiles into
 * per-pixel noise, which compresses worse than the original despite being
 * visually smaller, defeating the very "materially smaller" tests this fixture
 * exists for. 150px cells survive a realistic downscale as a still-blocky,
 * still-compressible image.
 */
function imageFixture(int $width, int $height, string $format = 'png'): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x += 150) {
        for ($y = 0; $y < $height; $y += 150) {
            $colour = imagecolorallocate($image, ($x * 7) % 256, ($y * 13) % 256, (($x + $y) * 3) % 256);
            imagefilledrectangle($image, $x, $y, $x + 149, $y + 149, $colour);
        }
    }

    ob_start();

    match ($format) {
        'jpeg' => imagejpeg($image, null, 92),
        'webp' => imagewebp($image),
        default => imagepng($image),
    };

    return (string) ob_get_clean();
}

/**
 * Raw PNG bytes of a throwaway test image filled with per-pixel random noise.
 *
 * Unlike {@see imageFixture()}'s blocky grid, random noise defeats PNG's
 * run-length/filter compression almost entirely, so even a modest, well-inside
 * the 1568px vision-default edge bound image lands over a few hundred KiB —
 * useful for tests that need to trip a *byte-size* threshold independently of
 * pixel dimensions.
 */
function noisyImageFixture(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagesetpixel($image, $x, $y, $colour);
        }
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Raw bytes of a real, Imagick-rasterizable single-page PDF (`%PDF-1.4` header,
 * a filled rectangle so the rasterized page isn't blank).
 *
 * Distinct from a `'pdf-bytes'` string literal: Imagick decodes and rasterizes
 * this happily via its Ghostscript delegate, which is exactly the behaviour
 * tests guarding "PDFs must 422 on transform" need to exercise — undecodable
 * garbage would 422 for the wrong reason (it can't be read at all) and let a
 * missing MIME-type guard slip through unnoticed.
 */
function pdfFixture(int $width = 800, int $height = 1000): string
{
    $pdf = new Imagick;
    $pdf->newImage($width, $height, new ImagickPixel('skyblue'));
    $pdf->setImageFormat('pdf');
    $bytes = $pdf->getImageBlob();
    $pdf->clear();

    return $bytes;
}
