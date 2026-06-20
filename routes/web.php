<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\AttachmentThumbnailController;
use App\Http\Controllers\AttachmentViewController;
use App\Http\Controllers\AvatarController;
use App\Livewire\Admin\UserManagement;
use App\Livewire\Board;
use App\Livewire\Dashboard;
use App\Livewire\Invitations\AcceptInvitation;
use App\Livewire\Invitations\InviteUser;
use App\Livewire\Notifications\ManageNotifications;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectList;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Stories\StoryView;
use App\Livewire\Tasks\TaskView;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', static function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

// Invitation acceptance is reachable by guests via a signed, temporary URL.
Route::livewire('/invitation/{invitation}/accept', AcceptInvitation::class)
    ->middleware('signed')
    ->name('invitation.accept');

Route::middleware(['auth', 'verified'])->group(static function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('projects', ProjectList::class)->name('projects.index');
    Route::livewire('board', Board::class)->name('board');
    Route::livewire('notifications', ManageNotifications::class)->name('notifications.index');
    Route::livewire('invite', InviteUser::class)->name('invitations.create');
    Route::livewire('admin/users', UserManagement::class)->name('admin.users');

    // Avatars are stored privately and streamed only to authenticated viewers.
    Route::get('users/{user}/avatar', AvatarController::class)->name('avatar');

    /*
     * Attachment delivery is scoped under the owning project's short name and
     * authorized against that project's access rules.
     */
    Route::get('{short_name}/attachments/{attachment}/download', AttachmentDownloadController::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('attachments.download');

    Route::get('{short_name}/attachments/{attachment}/thumbnail', AttachmentThumbnailController::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('attachments.thumbnail');

    Route::get('{short_name}/attachments/{attachment}/view', AttachmentViewController::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('attachments.view');

    /*
     * Scoped public references. Registered last and constrained to uppercase
     * short names (2-4 letters) so they never collide with the lowercase
     * reserved routes above (dashboard, projects, board, invite, settings, ...).
     */
    Route::livewire('/{short_name}/board', ProjectBoard::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.board');

    Route::livewire('/{short_name}-{task_number}', TaskView::class)
        ->where(['short_name' => '[A-Z]{2,4}', 'task_number' => '\d+'])
        ->name('task.show');

    Route::livewire('/{short_name}{story_number}', StoryView::class)
        ->where(['short_name' => '[A-Z]{2,4}', 'story_number' => '\d+'])
        ->name('story.show');

    Route::livewire('/{short_name}', ProjectShow::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.show');
});

require __DIR__.'/settings.php';
