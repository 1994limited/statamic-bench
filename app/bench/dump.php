<?php

/**
 * Print what the Stache holds, in a stable order, so two targets can be
 * compared: every entry and term with its URL, status, dates and a hash of
 * its data, and every nav and collection tree.
 *
 *   php bench/dump.php
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Term;

bench_boot();

$entries = [];
foreach (Entry::all() as $e) {
    $entries[$e->id()] = [
        'site' => $e->locale(),
        'collection' => $e->collectionHandle(),
        'slug' => $e->slug(),
        'uri' => $e->uri(),
        'url' => $e->url(),
        'absolute_url' => $e->absoluteUrl(),
        'published' => $e->published(),
        'status' => $e->status(),
        'date' => $e->hasDate() ? $e->date()->format('c') : null,
        'order' => $e->order(),
        'origin' => $e->origin()?->id(),
        'parent' => $e->parent()?->id(),
        'blueprint' => $e->blueprint()->handle(),
        'data' => md5(json_encode($e->data()->all())),
        'values' => md5(json_encode($e->values()->all())),
    ];
}
ksort($entries);

$terms = [];
foreach (Term::all() as $t) {
    $terms[$t->id()] = ['slug' => $t->slug(), 'url' => $t->url(), 'title' => $t->title(), 'entries' => $t->queryEntries()->count()];
}
ksort($terms);

$flatten = function ($pages) use (&$flatten) {
    $out = [];
    foreach ($pages as $page) {
        $out[] = [$page->id(), $page->url(), $page->title(), $flatten($page->pages()->all())];
    }

    return $out;
};
$trees = [];
foreach (Site::all() as $site) {
    foreach (Nav::all() as $nav) {
        if ($tree = $nav->in($site->handle())) {
            $trees["nav:{$nav->handle()}:{$site->handle()}"] = md5(json_encode($flatten($tree->pages()->all())));
        }
    }
    foreach (Collection::all() as $collection) {
        if ($collection->hasStructure() && ($tree = $collection->structure()->in($site->handle()))) {
            $trees["collection:{$collection->handle()}:{$site->handle()}"] = md5(json_encode($flatten($tree->pages()->all())));
        }
    }
}

bench_emit(['entries' => $entries, 'terms' => $terms, 'trees' => $trees]);
