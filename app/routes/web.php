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

// Bench only, and only for content generated with --awkward: computed values,
// registered the way a site's service provider would. One asks a question of
// its own collection, which is what catches code that indexes an entry before
// the rest of the collection exists.
if (is_file($manifest = base_path('bench/manifest.json')) && (json_decode(file_get_contents($manifest), true)['awkward'] ?? null)) {
    Statamic\Facades\Collection::computed('articles', 'same_author_count', function ($entry) {
        return Entry::query()->where('collection', 'articles')->where('author', $entry->get('author'))->count();
    });
    Statamic\Facades\Collection::computed('notes', 'title_length', fn ($entry) => mb_strlen((string) $entry->get('title')));
}
