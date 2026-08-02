<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\DependencyController;
use App\Http\Controllers\Api\V1\DocController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskTypeController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VariableController;
use App\Http\Middleware\SetAuditSource;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public REST API — version 1
|--------------------------------------------------------------------------
|
| Authenticated with Sanctum personal access tokens (the same read/write
| abilities the MCP server uses). Every response is scoped to the token
| owner's projects, exactly like the rest of the app — references that exist
| but belong to another user's projects 404 rather than leaking their
| existence. Mutating endpoints (added under KAN-45 / KAN-29) additionally
| require the `write` token ability.
|
*/

Route::middleware(['auth:sanctum', 'throttle:api', SetAuditSource::class.':api'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(static function (): void {
        // The instance-wide audit event stream. Unlike every other endpoint it
        // is not scoped to the token owner's projects, so it carries its own
        // gate: the manage-users permission plus a dedicated `audit` token
        // ability (see EnsureTokenCanReadAudit).
        Route::middleware('token.audit')
            ->get('audit-events', [AuditEventController::class, 'index'])
            ->name('audit-events.index');

        Route::get('user', [UserController::class, 'current'])->name('user');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{short_name}', [ProjectController::class, 'show'])->name('projects.show');
        Route::get('projects/{short_name}/tasks', [TaskController::class, 'index'])->name('projects.tasks.index');
        Route::get('projects/{short_name}/task-types', [TaskTypeController::class, 'index'])->name('projects.task-types.index');
        Route::get('projects/{short_name}/tags', [TagController::class, 'index'])->name('projects.tags.index');
        Route::get('projects/{short_name}/variables', [VariableController::class, 'index'])->name('projects.variables.index');

        Route::get('tasks/{reference}', [TaskController::class, 'show'])->name('tasks.show');
        Route::get('tasks/{reference}/export', [ExportController::class, 'task'])->name('tasks.export');

        Route::get('projects/{short_name}/docs', [DocController::class, 'index'])->name('projects.docs.index');
        Route::get('docs/{reference}', [DocController::class, 'show'])->name('docs.show');
        Route::get('docs/{reference}/export', [ExportController::class, 'doc'])->name('docs.export');

        Route::get('notes', [NoteController::class, 'index'])->name('notes.index');
        Route::get('notes/{note}', [NoteController::class, 'show'])->whereNumber('note')->name('notes.show');

        Route::get('projects/{short_name}/comments', [CommentController::class, 'indexForProject'])->name('projects.comments.index');
        Route::get('tasks/{reference}/comments', [CommentController::class, 'indexForTask'])->name('tasks.comments.index');

        Route::get('projects/{short_name}/attachments', [AttachmentController::class, 'indexForProject'])->name('projects.attachments.index');
        Route::get('tasks/{reference}/attachments', [AttachmentController::class, 'indexForTask'])->name('tasks.attachments.index');
        Route::get('attachments/{attachment}', [AttachmentController::class, 'download'])->whereNumber('attachment')->name('attachments.download');

        // Mutations additionally require a token with the `write` ability.
        Route::middleware('token.write')->group(static function (): void {
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::patch('projects/{short_name}', [ProjectController::class, 'update'])->name('projects.update');

            Route::post('projects/{short_name}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
            Route::patch('tasks/{reference}', [TaskController::class, 'update'])->name('tasks.update');
            Route::post('tasks/{reference}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
            Route::post('tasks/{reference}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
            Route::put('tasks/{reference}/assignees', [TaskController::class, 'setAssignees'])->name('tasks.assignees.update');

            Route::post('tasks/{reference}/dependencies', [DependencyController::class, 'store'])->name('tasks.dependencies.store');
            Route::delete('tasks/{reference}/dependencies/{related}', [DependencyController::class, 'destroy'])->name('tasks.dependencies.destroy');

            Route::post('projects/{short_name}/docs', [DocController::class, 'store'])->name('projects.docs.store');
            Route::patch('docs/{reference}', [DocController::class, 'update'])->name('docs.update');
            Route::delete('docs/{reference}', [DocController::class, 'destroy'])->name('docs.destroy');

            /*
             * Cross-references are the same endpoint from either end: both paths
             * resolve whichever item — task or doc — the reference names.
             */
            Route::post('tasks/{reference}/references', [ReferenceController::class, 'store'])->name('tasks.references.store');
            Route::delete('tasks/{reference}/references/{related}', [ReferenceController::class, 'destroy'])->name('tasks.references.destroy');
            Route::post('docs/{reference}/references', [ReferenceController::class, 'store'])->name('docs.references.store');
            Route::delete('docs/{reference}/references/{related}', [ReferenceController::class, 'destroy'])->name('docs.references.destroy');

            Route::post('projects/{short_name}/comments', [CommentController::class, 'storeOnProject'])->name('projects.comments.store');
            Route::post('tasks/{reference}/comments', [CommentController::class, 'storeOnTask'])->name('tasks.comments.store');
            Route::patch('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
            Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');

            Route::post('projects/{short_name}/attachments', [AttachmentController::class, 'storeOnProject'])->name('projects.attachments.store');
            Route::post('tasks/{reference}/attachments', [AttachmentController::class, 'storeOnTask'])->name('tasks.attachments.store');
            Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment')->name('attachments.destroy');

            Route::post('notes', [NoteController::class, 'store'])->name('notes.store');
            Route::patch('notes/{note}', [NoteController::class, 'update'])->whereNumber('note')->name('notes.update');
            Route::delete('notes/{note}', [NoteController::class, 'destroy'])->whereNumber('note')->name('notes.destroy');
            Route::post('notes/{note}/convert', [NoteController::class, 'convert'])->whereNumber('note')->name('notes.convert');
        });
    });
