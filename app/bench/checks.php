<?php

/**
 * Behaviour checks. Each one guards something a performance change has broken
 * before (or nearly did): faster code that gives a different answer is a bug.
 * They run against whatever statamic/cms the target has, on a warm Stache.
 *
 *   php bench/checks.php
 *
 * Prints one JSON line: {"checks":[{"name":"...","pass":true,"detail":""}, ...]}
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Support\Str;

bench_boot();
$m = bench_manifest();
$c = new BenchChecks;
$articleId = $m['article']['id'];

// ------------------------------------------------------------ change tracking

$c->check('entries read from the Stache are clean', function () {
    foreach (Entry::all() as $entry) {
        if ($entry->isDirty()) {
            return "{$entry->id()} ({$entry->collectionHandle()}) is dirty straight after being read";
        }
    }
});

$c->check('setting a value marks that field dirty, and only that field', function () use ($articleId) {
    $entry = Entry::find($articleId);
    $was = $entry->get('title');
    $entry->set('title', 'Changed');

    return bench_differs('isDirty()', true, $entry->isDirty())
        ?? bench_differs("isDirty('title')", true, $entry->isDirty('title'))
        ?? bench_differs("isDirty('excerpt')", false, $entry->isDirty('excerpt'))
        ?? bench_differs("isClean('excerpt')", true, $entry->isClean('excerpt'))
        ?? bench_differs("getOriginal('title')", $was, $entry->getOriginal('title'));
});
Blink::flush();

$c->check('changing slug, date or published state is tracked, and the original is kept', function () use ($articleId) {
    $entry = Entry::find($articleId);
    [$slug, $date, $published] = [$entry->slug(), $entry->date()->format('Y-m-d-Hi'), $entry->published()];
    $entry->slug('another-slug')->date('2031-02-03')->published(! $published);

    return bench_differs("isDirty('slug')", true, $entry->isDirty('slug'))
        ?? bench_differs("isDirty('date')", true, $entry->isDirty('date'))
        ?? bench_differs("isDirty('published')", true, $entry->isDirty('published'))
        ?? bench_differs("getOriginal('slug')", $slug, $entry->getOriginal('slug'))
        ?? bench_differs("getOriginal('date')", $date, $entry->getOriginal('date'))
        ?? bench_differs("getOriginal('published')", $published, $entry->getOriginal('published'));
});
Blink::flush();

$c->check('the original survives changes made to nested values and dates in place', function () use ($articleId) {
    $entry = Entry::find($articleId);
    $content = $entry->get('content');
    $original = $entry->getOriginal('content');
    $date = $entry->getOriginal('date');
    $content[0]['content'][0]['text'] = 'Rewritten heading';
    $entry->set('content', $content);
    $entry->date()->addYears(3);

    return bench_differs("getOriginal('content')", $original, $entry->getOriginal('content'))
        ?? bench_differs("getOriginal('date')", $date, $entry->getOriginal('date'))
        ?? bench_differs("isDirty('content')", true, $entry->isDirty('content'));
});
Blink::flush();

$c->check('saving makes an entry clean, and the Stache and its indexes hold the new value', function () use ($articleId) {
    $entry = Entry::find($articleId);
    $was = $entry->get('title');
    try {
        $entry->set('title', 'Saved by the bench checks')->save();
        Blink::flush();
        $again = Entry::find($articleId);

        return bench_differs('isDirty() after save', false, $entry->isDirty())
            ?? bench_differs('title read back', 'Saved by the bench checks', $again->get('title'))
            ?? bench_differs('re-read entry isDirty()', false, $again->isDirty())
            ?? bench_differs('found by new title', 1, Entry::query()->where('collection', 'articles')->where('title', 'Saved by the bench checks')->count())
            ?? bench_differs('found by old title', 0, Entry::query()->where('collection', 'articles')->where('title', $was)->count());
    } finally {
        Entry::find($articleId)->set('title', $was)->save();
        Blink::flush();
    }
});

$c->check('a save is seen by another process, and by this one after that process saves', function () use ($articleId) {
    $was = Entry::find($articleId)->get('title');
    try {
        $theirs = bench_child(['bench/edit.php', "--id=$articleId", '--title=Saved in another process']);
        Blink::flush();
        // a request in a long-lived worker starts with fresh request state but the same Stache object
        $mine = Entry::find($articleId)->get('title');

        return bench_differs('title in the other process', 'Saved in another process', $theirs['title'])
            ?? bench_differs('found by title in the other process', 1, $theirs['found_by_title'])
            ?? bench_differs('title here afterwards', 'Saved in another process', $mine);
    } finally {
        bench_child(['bench/edit.php', "--id=$articleId", '--restore']);
        Blink::flush();
    }
});

// --------------------------------------------------------- localised entries

$c->check('a localised entry inherits from its origin and keeps its own overrides', function () use ($m) {
    $origin = Entry::find($m['localised']['origin']);
    $french = Entry::find($m['localised']['localisation']);

    return bench_differs('locale', 'fr', $french->locale())
        ?? bench_differs('origin', $origin->id(), $french->origin()?->id())
        ?? bench_differs('own title', $origin->get('title').' (FR)', $french->value('title'))
        ?? bench_differs('inherited blocks', $origin->get('blocks'), $french->value('blocks'))
        ?? bench_differs('blocks stored on the localisation', null, $french->get('blocks'))
        ?? bench_differs('origin lists it as a descendant', true, $origin->descendants()->has('fr'))
        ?? bench_differs('url', '/fr/contact', $french->url());
});

$c->check('changing an entry\'s origin is tracked, and the original still points at the old origin', function () use ($m) {
    $french = Entry::find($m['localised']['localisation']);
    $other = Entry::find($m['localised']['other_origin']);
    $inherited = $french->value('blocks');
    $french->origin($other);

    return bench_differs("isDirty('origin')", true, $french->isDirty('origin'))
        ?? bench_differs("getOriginal('origin')", $m['localised']['origin'], $french->getOriginal('origin'))
        ?? bench_differs('origin()', $other->id(), $french->origin()->id())
        ?? bench_differs('values now come from the new origin', $other->get('blocks'), $french->value('blocks'))
        ?? ($inherited === $french->value('blocks') ? 'inherited blocks did not change with the origin' : null);
});
Blink::flush();

$c->check('an unsaved origin change does not leak into the next read of the entry', function () use ($m) {
    $french = Entry::find($m['localised']['localisation']);

    return bench_differs('origin', $m['localised']['origin'], $french->origin()?->id())
        ?? bench_differs('isDirty()', false, $french->isDirty());
});

// --------------------------------------------------------------------- URLs

$c->check('every entry\'s URL is what its route says, worked out by hand', function () {
    $tree = [];   // page id => uri, walked from the trees themselves
    foreach (['en' => '', 'fr' => '/fr'] as $site => $prefix) {
        $walk = function (array $branches, string $parent) use (&$walk, &$tree, $prefix) {
            foreach ($branches as $i => $branch) {
                $slug = Entry::find($branch['entry'])->slug();
                $uri = $parent === '' && $i === 0 && $slug === 'home' ? '/' : rtrim($parent, '/').'/'.$slug;
                $tree[$branch['entry']] = $prefix.($uri === '/' && $prefix ? '' : $uri);
                $walk($branch['children'] ?? [], $uri === '/' ? '' : $uri);
            }
        };
        $walk(Statamic\Facades\YAML::file(BENCH_ROOT."/content/trees/collections/$site/pages.yaml")->parse()['tree'], '');
    }
    $checked = 0;
    foreach (Entry::all() as $entry) {
        $expected = match ($entry->collectionHandle()) {
            'pages' => $tree[$entry->id()] ?: '/',
            'articles' => '/articles/'.$entry->slug(),
            'products' => '/products/'.$entry->slug(),
            'events' => '/events/'.$entry->date()->format('Y').'/'.$entry->date()->format('m').'/'.$entry->slug(),
            'people' => '/team/'.Str::slug($entry->get('role')).'/'.$entry->slug(),
        };
        if ($expected !== $entry->url()) {
            return "{$entry->collectionHandle()} {$entry->id()}: expected $expected, got ".json_encode($entry->url());
        }
        $checked++;
    }

    return $checked > 0 ? null : 'no entries checked';
});

bench_emit(['checks' => $c->results]);
