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
        if ($entry->get('redirect')) {
            continue;   // a redirecting entry's URL is where it redirects to; that has a check of its own
        }
        $expected = match ($entry->collectionHandle()) {
            'pages' => $tree[$entry->id()] ?: '/',
            'articles' => '/articles/'.$entry->slug(),
            'products' => '/products/'.$entry->slug(),
            'events' => '/events/'.$entry->date()->format('Y').'/'.$entry->date()->format('m').'/'.$entry->slug(),
            'people' => '/team/'.Str::slug($entry->get('role')).'/'.$entry->slug(),
            'notes' => ($entry->locale() === 'fr' ? '/fr' : '').'/notes/'.$entry->slug(),
            'notices' => '/notices/'.$entry->slug(),
            'faqs' => '/faqs/'.$entry->slug(),
            'snippets' => null,
        };
        if ($expected !== $entry->url()) {
            return "{$entry->collectionHandle()} {$entry->id()}: expected $expected, got ".json_encode($entry->url());
        }
        $checked++;
    }

    return $checked > 0 ? null : 'no entries checked';
});

// ------------------------------------------------------------- moved content

$c->check('renaming the page a collection is mounted on changes every mounted entry\'s URL, and back', function () use ($m) {
    $ids = array_slice($m['save']['update_ids'], 0, 3);
    $before = bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--read', '--urls='.implode(',', $ids)])['urls'];
    try {
        bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--slug=writing']);
        // a process that starts after the rename
        $during = bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--read', '--urls='.implode(',', $ids)])['urls'];
    } finally {
        bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--restore']);
    }
    $after = bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--read', '--urls='.implode(',', $ids)])['urls'];

    return bench_differs('urls while renamed', array_map(fn ($url) => str_replace('/articles/', '/writing/', $url), $before), $during)
        ?? bench_differs('urls after putting it back', $before, $after)
        ?? (str_starts_with(reset($before), '/articles/') ? null : 'the articles were not under /articles/ to begin with');
});
Blink::flush();

$c->check('moving a page under another changes its URL, its parent and its children\'s URLs, and back', function () use ($m) {
    $tree = Statamic\Facades\YAML::file(BENCH_ROOT.'/content/trees/collections/en/pages.yaml')->parse()['tree'];
    // the first generated branch that has children, moved under the contact page
    $branch = collect($tree)->first(fn ($b) => isset($b['children']));
    $target = $m['localised']['origin'];
    [$page, $child] = [$branch['entry'], $branch['children'][0]['entry']];
    $read = fn () => bench_child(['bench/edit.php', "--id=$page", '--read', "--urls=$page,$child"])['urls'];
    $before = $read();
    try {
        bench_child(['bench/edit.php', "--id=$page", "--move-under=$target"]);
        $during = $read();
    } finally {
        bench_child(['bench/edit.php', "--id=$page", '--restore-tree']);
    }
    $after = $read();

    return bench_differs('urls while moved', array_map(fn ($url) => '/contact'.$url, $before), $during)
        ?? bench_differs('urls after putting it back', $before, $after);
});
Blink::flush();

// ------------------------------------------------------------ awkward content
// Only for content generated with --awkward: see generate_awkward.php.

if ($a = $m['awkward'] ?? null) {
    $same = fn ($x, $y) => json_encode($x, JSON_PRESERVE_ZERO_FRACTION) === json_encode($y, JSON_PRESERVE_ZERO_FRACTION);

    $c->check('hand-written YAML is read as Symfony\'s parser reads it, whatever style it is in', function () use ($a, $same) {
        foreach ($a['notes'] as $id => $note) {
            $entry = Entry::find($id) ?? null;
            if (! $entry) {
                return "{$note['file']} is not in the Stache";
            }
            foreach ($note['expect'] as $key => $expected) {
                $got = $entry->get((string) $key);
                if (! $same($expected, is_string($got) && $key === 'content' ? rtrim($got) : $got)) {
                    return "{$note['file']}: $key should be ".substr(json_encode($expected), 0, 120).', is '.substr(json_encode($got), 0, 120);
                }
            }
        }

        return bench_differs('notes in the Stache', $a['note_count'], Entry::query()->where('collection', 'notes')->count());
    });

    $c->check('ids that are numbers or look like them are found, and keep their type', function () use ($a) {
        return bench_differs('integer id', $a['integer_id'], Entry::find($a['integer_id'])?->id())
            ?? bench_differs('string of digits', $a['numeric_string_id'], Entry::find($a['numeric_string_id'])?->id())
            ?? bench_differs('found by a query on the integer id', 1, Entry::query()->where('collection', 'notes')->where('id', $a['integer_id'])->count());
    });

    $c->check('one of two files with the same id is kept, and the other is recorded as a duplicate', function () use ($a) {
        $recorded = Statamic\Facades\Stache::duplicates()->all()['entries::notes'][$a['duplicate_id']] ?? [];

        return bench_differs('entries with that id', 1, Entry::query()->where('collection', 'notes')->where('title', 'like', 'Duplicate %')->count())
            ?? (count($recorded) > 0 ? null : 'nothing was recorded as a duplicate');
    });

    $c->check('files with the same name in different folders are separate entries', function () use ($a) {
        [$one, $two] = array_map(fn ($id) => Entry::find($id), $a['nested']);

        return bench_differs('slugs', ['nested', 'nested'], [$one?->slug(), $two?->slug()])
            ?? ($one->path() === $two->path() ? 'both have the same path' : null);
    });

    $c->check('a computed value that queries its own collection is indexed with the right values', function () use ($a) {
        $index = Statamic\Facades\Stache::store('entries')->store('articles')->index('same_author_count');
        foreach ($a['computed']['same_author_count'] as $id => $expected) {
            if (($problem = bench_differs("index value for $id", $expected, $index->get($id))) || ($problem = bench_differs("value on entry $id", $expected, Entry::find($id)->value('same_author_count')))) {
                return $problem;
            }
        }

        return bench_differs('articles whose author has that many or more', $a['computed']['at_or_over_threshold'],
            Entry::query()->where('collection', 'articles')->where('same_author_count', '>=', $a['computed']['threshold'])->count());
    });

    $c->check('drafts, scheduled entries and dates in file names come out as written', function () use ($a) {
        $status = fn ($kind) => array_map(fn ($id) => Entry::find($id)?->status(), $a['notices'][$kind]);
        $dates = array_map(fn ($id) => Entry::find($id)->date()->format('Y-m-d H:i:s'), $a['notices']['timed']);
        $listed = Entry::query()->where('collection', 'notices')->whereStatus('published')->get()->map->id()->all();

        return bench_differs('past', ['published', 'published', 'published'], $status('past'))
            ?? bench_differs('scheduled', ['scheduled', 'scheduled', 'scheduled'], $status('scheduled'))
            ?? bench_differs('drafts', ['draft', 'draft'], $status('draft'))
            ?? bench_differs('times from file names', ['2020-03-08 01:30:00', '2020-11-01 01:30:59'], $dates)
            ?? bench_differs('scheduled or draft entries among the published', [], array_values(array_intersect($listed, [...$a['notices']['scheduled'], ...$a['notices']['draft']])))
            ?? bench_differs('two entries with the same date and slug', 2, count(array_filter(array_map(fn ($id) => Entry::find($id), $a['notices']['colliding']))));
    });

    $c->check('a one-level orderable collection follows its tree, with an entry the tree leaves out at the end', function () use ($a) {
        $expected = [...$a['faqs']['order'], $a['faqs']['unlisted']];

        return bench_differs('order', $expected, Entry::query()->where('collection', 'faqs')->orderBy('order')->get()->map->id()->all())
            ?? bench_differs('order of the third', 3, Entry::find($expected[2])->order());
    });

    $c->check('entries without a route have no URL, and redirects give theirs', function () use ($a) {
        $url = fn ($kind) => Entry::find($a['redirects'][$kind])->url();

        return bench_differs('snippet url', null, Entry::find($a['snippets'][0])->url())
            ?? bench_differs('internal redirect', '/contact', $url('internal'))
            ?? bench_differs('external redirect', 'https://example.com/elsewhere', $url('external'))
            ?? bench_differs('uri of a redirecting entry', '/notes/redirect-internal', Entry::find($a['redirects']['internal'])->uri());
    });

    $c->check('a big taxonomy counts the entries on each term, including terms that have no file', function () use ($a) {
        foreach ($a['keywords']['busiest'] + $a['keywords']['implicit'] as $slug => $expected) {
            $term = Statamic\Facades\Term::find("keywords::$slug");
            if ($problem = bench_differs("entries on $slug", $expected, $term?->queryEntries()->count())) {
                return $problem;
            }
        }
        $first = array_key_first($a['keywords']['busiest']);

        return bench_differs('French title of a term', 'Mot-clé '.(int) substr($first, 3, 4), Statamic\Facades\Term::find("keywords::$first")->in('fr')->title());
    });

    $c->check('a relation across sites gives the entry\'s version in this site, and one to a missing entry is left out', function () use ($a) {
        $french = Entry::find($a['french_note']['id']);
        $broken = Entry::find($a['broken_relation']['id']);
        $resolved = fn ($entry) => $entry->augmentedValue('see_also')->value()->get()->map->id()->values()->all();

        return bench_differs('French note\'s relations (the French version of one, nothing for the one with no French version)', [$a['french_note']['see_also_resolves_to']], $resolved($french))
            ?? bench_differs('French note inherits its origin\'s intro', Entry::find($a['french_note']['origin'])->get('intro'), $french->value('intro'))
            ?? bench_differs('relation with a missing entry', [$a['broken_relation']['present']], $resolved($broken))
            ?? bench_differs('stored relation still lists both', 2, count($broken->get('see_also')));
    });

    $c->check('a six-level nav with entries, links, bare titles and a missing entry can be walked', function () use ($a) {
        $nodes = 0;
        $deepest = 0;
        $walk = function ($pages, $depth) use (&$walk, &$nodes, &$deepest) {
            foreach ($pages as $page) {
                $nodes++;
                $deepest = max($deepest, $depth);
                $page->url();
                $page->title();
                $walk($page->pages()->all(), $depth + 1);
            }
        };
        $walk(Statamic\Facades\Nav::find('deep')->in('en')->pages()->all(), 1);

        return bench_differs('nodes', $a['deep_nav_nodes'], $nodes) ?? bench_differs('levels', 6, $deepest);
    });

    $c->check('the deepest page\'s URL has every ancestor in it', function () use ($a) {
        return bench_differs('url', '/'.implode('/', array_map(fn ($i) => "deep-chain-level-$i", range(1, 6))), Entry::find(end($a['deep_chain']))->url())
            ?? bench_differs('parent', $a['deep_chain'][4], Entry::find($a['deep_chain'][5])->parent()?->id());
    });

    $c->check('every asset in the container is found, in its folder', function () use ($a) {
        $assets = Statamic\Facades\Asset::query()->where('container', 'assets');

        return bench_differs('assets', $a['assets'], $assets->count())
            ?? bench_differs('in one library folder', 50, Statamic\Facades\Asset::query()->where('container', 'assets')->where('folder', 'library/001')->count());
    });
}

bench_emit(['checks' => $c->results]);
