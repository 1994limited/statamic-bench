<?php

use Illuminate\Support\Facades\Route;
use Statamic\Facades\Entry;

// Bench only: lets a check run an entry query inside a real request (so inside
// an Octane worker's request lifecycle) and see which ids come back.
Route::get('/__bench/query', function () {
    $value = request('value');

    return Entry::query()
        ->where('collection', request('collection', 'articles'))
        ->where(request('field'), is_numeric($value) ? (int) $value : $value)
        ->get()->map->id()->values()->all();
});

// Bench only: empty the Stache from inside a request, so the worker that
// handles the next request is the one that rebuilds it.
Route::get('/__bench/clear-stache', function () {
    Statamic\Facades\Stache::clear();

    return ['cleared' => true];
});
