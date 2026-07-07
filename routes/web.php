<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\AttachmentThumbnailController;
use App\Http\Controllers\AttachmentViewController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\MentionSuggestionsController;
use App\Http\Controllers\NoteAttachmentController;
use App\Http\Controllers\OAuth\ApproveMcpAuthorizationController;
use App\Http\Controllers\TaskPreviewController;
use App\Http\Controllers\UserPreviewController;
use App\Livewire\Admin\UserManagement;
use App\Livewire\Board;
use App\Livewire\Dashboard;
use App\Livewire\Invitations\AcceptInvitation;
use App\Livewire\Invitations\InviteUser;
use App\Livewire\Notes\NoteList;
use App\Livewire\Notifications\ManageNotifications;
use App\Livewire\Projects\ProjectBoard;
use App\Livewire\Projects\ProjectList;
use App\Livewire\Projects\ProjectShow;
use App\Livewire\Projects\ProjectTags;
use App\Livewire\Projects\ProjectTaskTypes;
use App\Livewire\Tasks\TaskView;
use App\Livewire\Users\UserProfile;
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

// Replace Passport's OAuth approve endpoint (same method, URI and name, so
// this registration supersedes the package's) with one that also records the
// project scope picked on the MCP consent screen.
Route::post('/oauth/authorize', [ApproveMcpAuthorizationController::class, 'approve'])
    ->middleware('auth')
    ->name('passport.authorizations.approve');

Route::middleware(['auth', 'verified'])->group(static function () {
    Route::livewire('dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('projects', ProjectList::class)->name('projects.index');
    Route::livewire('board', Board::class)->name('board');
    Route::livewire('notes', NoteList::class)->name('notes.index');
    Route::livewire('notifications', ManageNotifications::class)->name('notifications.index');
    Route::livewire('invite', InviteUser::class)->name('invitations.create');
    Route::livewire('admin/users', UserManagement::class)->name('admin.users');

    // Avatars are stored privately and streamed only to authenticated viewers.
    Route::get('users/{user}/avatar', AvatarController::class)->name('avatar');

    // A user's profile page, visible to members who share a project with them.
    Route::livewire('users/{user}', UserProfile::class)->name('users.show');

    // Compact user preview for the @mention hovercard (mirrors task.preview).
    Route::get('users/{user}/preview', UserPreviewController::class)->name('users.preview');

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
     * Note attachments are projectless, so they are served without a project
     * short name. The same controllers handle both; authorization cascades to
     * the note via the attachment policy.
     */
    Route::get('notes/attachments/{attachment}/download', [NoteAttachmentController::class, 'download'])->name('notes.attachments.download');
    Route::get('notes/attachments/{attachment}/thumbnail', [NoteAttachmentController::class, 'thumbnail'])->name('notes.attachments.thumbnail');
    Route::get('notes/attachments/{attachment}/view', [NoteAttachmentController::class, 'view'])->name('notes.attachments.view');

    /*
     * Scoped public references. Registered last and constrained to uppercase
     * short names (2-4 letters) so they never collide with the lowercase
     * reserved routes above (dashboard, projects, board, invite, settings, ...).
     */
    Route::livewire('/{short_name}/board', ProjectBoard::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.board');

    Route::livewire('/{short_name}/tags', ProjectTags::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.tags');

    Route::livewire('/{short_name}/task-types', ProjectTaskTypes::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.task-types');

    Route::get('/{short_name}/mentionables', MentionSuggestionsController::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.mentionables');

    Route::get('/{short_name}-{task_number}/preview', TaskPreviewController::class)
        ->where(['short_name' => '[A-Z]{2,4}', 'task_number' => '\d+'])
        ->name('task.preview');

    Route::livewire('/{short_name}-{task_number}', TaskView::class)
        ->where(['short_name' => '[A-Z]{2,4}', 'task_number' => '\d+'])
        ->name('task.show');

    Route::livewire('/{short_name}', ProjectShow::class)
        ->where('short_name', '[A-Z]{2,4}')
        ->name('project.show');
});

require __DIR__.'/settings.php';
