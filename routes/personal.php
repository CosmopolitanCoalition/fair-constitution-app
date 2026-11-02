<?php

use App\Http\Controllers\Personal\Api\CalendarApiController;
use App\Http\Controllers\Personal\Api\CrmApiController;
use App\Http\Controllers\Personal\Api\MailApiController;
use App\Http\Controllers\Personal\Api\TasksApiController;
use Illuminate\Support\Facades\Route;

/*
| Personal workspace API, prefix /api/v1, stateless. Each route needs a bearer API key
| that carries the named scope. The key's owner is the only data any route can touch.
*/

Route::get('/ping', fn () => ['ok' => true, 'user' => request()->user()?->id])->middleware('api.key:calendar:read');

Route::middleware('api.key:calendar:read')->group(function () {
    Route::get('/calendars', [CalendarApiController::class, 'calendars']);
    Route::get('/events', [CalendarApiController::class, 'events']);
    Route::get('/availability', [CalendarApiController::class, 'availability']);
    Route::get('/slots', [CalendarApiController::class, 'slots']);
});
Route::middleware('api.key:calendar:write')->group(function () {
    Route::post('/events', [CalendarApiController::class, 'book']);
    Route::patch('/events/{id}', [CalendarApiController::class, 'change']);
    Route::post('/events/{id}/cancel', [CalendarApiController::class, 'cancel']);
    Route::put('/availability', [CalendarApiController::class, 'setAvailability']);
    Route::post('/events/sync', [CalendarApiController::class, 'sync']);
});

Route::middleware('api.key:tasks:read')->group(function () {
    Route::get('/projects', [TasksApiController::class, 'projects']);
    Route::get('/tasks', [TasksApiController::class, 'index']);
});
Route::middleware('api.key:tasks:write')->group(function () {
    Route::post('/projects', [TasksApiController::class, 'createProject']);
    Route::post('/tasks', [TasksApiController::class, 'store']);
    Route::patch('/tasks/{id}', [TasksApiController::class, 'update']);
    Route::delete('/tasks/{id}', [TasksApiController::class, 'destroy']);
    Route::post('/tasks/sync', [TasksApiController::class, 'sync']);
});

Route::middleware('api.key:crm:read')->group(function () {
    Route::get('/contacts', [CrmApiController::class, 'contacts']);
    Route::get('/contacts/identify', [CrmApiController::class, 'identify']);
    Route::get('/contacts/{id}', [CrmApiController::class, 'show']);
    Route::get('/followups', [CrmApiController::class, 'followups']);
});
Route::middleware('api.key:crm:write')->group(function () {
    Route::post('/contacts', [CrmApiController::class, 'store']);
    Route::post('/contacts/sync', [CrmApiController::class, 'sync']);
    Route::patch('/contacts/{id}', [CrmApiController::class, 'update']);
    Route::post('/contacts/{id}/notes', [CrmApiController::class, 'addNote']);
    Route::post('/contacts/{id}/followups', [CrmApiController::class, 'addFollowup']);
    Route::post('/followups/{id}/complete', [CrmApiController::class, 'completeFollowup']);
});

Route::middleware('api.key:mail:read')->group(function () {
    Route::get('/mail/messages', [MailApiController::class, 'messages']);
    Route::get('/mail/messages/{id}', [MailApiController::class, 'show']);
    Route::get('/mail/counts', [MailApiController::class, 'counts']);
    Route::get('/mail/drafts', [MailApiController::class, 'drafts']);
});
Route::middleware('api.key:mail:write')->group(function () {
    Route::post('/mail/sync', [MailApiController::class, 'sync']);
    Route::patch('/mail/messages/{id}', [MailApiController::class, 'flag']);
    Route::post('/mail/drafts', [MailApiController::class, 'createDraft']);
    Route::post('/mail/drafts/{id}/request-send', [MailApiController::class, 'requestSend']);
});
Route::middleware('api.key:mail:send')->group(function () {
    Route::get('/mail/approved', [MailApiController::class, 'approved']);
    Route::post('/mail/drafts/{id}/sent', [MailApiController::class, 'markSent']);
});
