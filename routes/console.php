<?php

use App\Models\Notification;
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');

Schedule::command('attachments:prune-inline')->daily();
Schedule::command('tasks:auto-archive')->daily();
Schedule::command('audit:outbox:drain')->everyMinute();
Schedule::command('audit:outbox:prune')->daily();
Schedule::command('activity:prune')->daily();
Schedule::command('model:prune', ['--model' => [Notification::class]])->daily();
