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

// Saving through the API writes an entry's fields back in blueprint order, so
// the order of the top-level keys says nothing about what the entry holds.
$hash = function (array $data) {
    ksort($data);

    return md5(json_encode($data));
};

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
        'data' => $hash($e->data()->all()),
        'values' => $hash($e->values()->all()),
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

// ------------------------------------------------------------------ indexes
// Which indexes exist depends on what has been queried, so a fixed list is
// asked for: both targets then hold the same ones, and their contents can be
// compared as values rather than as whatever each version happens to cache.

$canonical = function ($value) use (&$canonical) {
    if ($value instanceof DateTimeInterface) {
        return $value->format('c');
    }
    if (is_object($value)) {
        return method_exists($value, 'id') ? get_class($value).':'.$value->id() : get_class($value);
    }

    return is_array($value) ? array_map($canonical, $value) : $value;
};
$catalogue = [
    '*' => ['id', 'slug', 'uri', 'title', 'published', 'site', 'origin', 'parent', 'order', 'date', 'collectionHandle', 'status', 'blueprint'],
    'articles' => ['author', 'featured', 'reading_time', 'categories', 'tags', 'keywords', 'same_author_count'],
    'products' => ['price', 'in_stock', 'categories', 'sku', 'stock_peers'],
    'notes' => ['flag', 'weight', 'labels', 'title_length', 'redirect', 'see_also'],
];
$indexes = [];
foreach (Collection::all() as $collection) {
    $store = Statamic\Facades\Stache::store('entries')->store($collection->handle());
    foreach ([...$catalogue['*'], ...($catalogue[$collection->handle()] ?? [])] as $name) {
        try {
            $items = $canonical($store->index($name)->items()->all());
            // which entry comes first is the queries' business, below; here it's what each entry's value is
            ksort($items);
            $indexes["{$collection->handle()}/$name"] = [count($items), md5(json_encode($items, JSON_PRESERVE_ZERO_FRACTION))];
        } catch (Throwable $e) {
            $indexes["{$collection->handle()}/$name"] = get_class($e);
        }
    }
}
foreach (Statamic\Facades\Taxonomy::all() as $taxonomy) {
    $associations = Statamic\Facades\Stache::store('terms')->store($taxonomy->handle())->index('associations')->items()->all();
    // the order they're held in isn't part of what they mean
    usort($associations, fn ($x, $y) => [$x['entry'], $x['slug'], $x['site']] <=> [$y['entry'], $y['slug'], $y['site']]);
    $indexes["terms/{$taxonomy->handle()}/associations"] = [count($associations), md5(json_encode($canonical($associations)))];
}

// ------------------------------------------------------------------ queries
// The ids a spread of queries return, in order: filters on a field that holds
// every type of value, sorts with ties, statuses, taxonomies, dates, limits.
// A query that throws is recorded as the exception, so both targets have to agree on that too.

$ids = function (callable $query) {
    try {
        $result = $query();

        return is_int($result) ? $result : collect($result)->map(fn ($item) => (string) (method_exists($item, 'id') ? $item->id() : $item))->values()->all();
    } catch (Throwable $e) {
        return get_class($e);
    }
};
$in = fn (string $collection) => Entry::query()->where('collection', $collection);
$queries = [
    'articles by title, page 2' => fn () => $in('articles')->orderBy('title')->paginate(10, ['*'], 'page', 2)->getCollection(),
    'articles newest first, 5' => fn () => $in('articles')->orderBy('date', 'desc')->limit(5)->get(),
    'articles featured' => fn () => $in('articles')->where('featured', true)->orderBy('id')->get(),
    'articles reading time 3 to 5' => fn () => $in('articles')->whereBetween('reading_time', [3, 5])->orderBy('reading_time')->orderBy('id')->get(),
    'articles in a category' => fn () => $in('articles')->whereTaxonomy('categories::news')->orderBy('id')->get(),
    'articles in either of two categories' => fn () => $in('articles')->whereTaxonomyIn(['categories::news', 'categories::guides'])->orderBy('id')->get(),
    'articles in 2023' => fn () => $in('articles')->whereYear('date', 2023)->orderBy('date')->orderBy('id')->get(),
    'articles title contains a word' => fn () => $in('articles')->where('title', 'like', '%amber%')->orderBy('id')->get(),
    'articles count' => fn () => $in('articles')->count(),
    'products in stock by price' => fn () => $in('products')->where('in_stock', true)->orderBy('price')->orderBy('id')->get(),
    'products price over 250' => fn () => $in('products')->where('price', '>', 250)->orderBy('id')->get(),
    'pages in French' => fn () => $in('pages')->where('site', 'fr')->orderBy('id')->get(),
    'pages with no parent' => fn () => $in('pages')->where('site', 'en')->whereNull('parent')->orderBy('id')->get(),
    'every published entry, by id' => fn () => Entry::query()->whereStatus('published')->orderBy('id')->get(),
    'entries across two collections by title' => fn () => Entry::query()->whereIn('collection', ['products', 'events'])->orderBy('title')->orderBy('id')->limit(30)->get(),
    'terms in tags by title' => fn () => Term::query()->where('taxonomy', 'tags')->where('site', 'en')->orderBy('title')->get(),
    'terms used by articles' => fn () => Term::query()->where('taxonomy', 'categories')->where('collection', 'articles')->where('site', 'en')->orderBy('slug')->get(),
    'assets count' => fn () => Statamic\Facades\Asset::query()->where('container', 'assets')->count(),
    'assets in the root folder' => fn () => Statamic\Facades\Asset::query()->where('container', 'assets')->where('folder', '/')->orderBy('path')->get(),
];
if (Collection::find('notes')) {
    $queries += [
        'notes flag is true' => fn () => $in('notes')->where('flag', true)->orderBy('id')->get(),
        'notes flag is false' => fn () => $in('notes')->where('flag', false)->orderBy('id')->get(),
        'notes flag is the string 0' => fn () => $in('notes')->where('flag', '0')->orderBy('id')->get(),
        'notes flag is the number 0' => fn () => $in('notes')->where('flag', 0)->orderBy('id')->get(),
        'notes flag is an empty string' => fn () => $in('notes')->where('flag', '')->orderBy('id')->get(),
        'notes flag is null' => fn () => $in('notes')->whereNull('flag')->orderBy('id')->get(),
        'notes flag is not null' => fn () => $in('notes')->whereNotNull('flag')->orderBy('id')->get(),
        'notes flag in a list' => fn () => $in('notes')->whereIn('flag', ['a', 'B', 1])->orderBy('id')->get(),
        'notes flag is not a' => fn () => $in('notes')->where('flag', '!=', 'a')->orderBy('id')->get(),
        'notes by flag' => fn () => $in('notes')->orderBy('flag')->orderBy('id')->get(),
        'notes with a label in their list' => fn () => $in('notes')->whereJsonContains('labels', 'x')->orderBy('id')->get(),
        'notes by weight, which ties' => fn () => $in('notes')->orderBy('weight')->get(),
        'notes by weight descending then title' => fn () => $in('notes')->orderBy('weight', 'desc')->orderBy('title')->get(),
        'notes by computed title length' => fn () => $in('notes')->orderBy('title_length')->orderBy('id')->get(),
        'notes in French' => fn () => $in('notes')->where('site', 'fr')->get(),
        'notes with a redirect' => fn () => $in('notes')->whereNotNull('redirect')->orderBy('id')->get(),
        'notices published' => fn () => $in('notices')->whereStatus('published')->orderBy('date')->orderBy('id')->get(),
        'notices scheduled' => fn () => $in('notices')->whereStatus('scheduled')->orderBy('id')->get(),
        'notices drafts' => fn () => $in('notices')->whereStatus('draft')->orderBy('id')->get(),
        'notices before 2010' => fn () => $in('notices')->where('date', '<', '2010-01-01')->orderBy('id')->get(),
        'faqs in order' => fn () => $in('faqs')->orderBy('order')->get(),
        'articles on a busy keyword' => fn () => $in('articles')->whereTaxonomy('keywords::kw-0001-amber')->orderBy('id')->get(),
        'articles by computed author count' => fn () => $in('articles')->orderBy('same_author_count', 'desc')->orderBy('id')->limit(20)->get(),
        'keywords used by articles' => fn () => Term::query()->where('taxonomy', 'keywords')->where('collection', 'articles')->where('site', 'en')->orderBy('slug')->get(),
        'assets in a library folder' => fn () => Statamic\Facades\Asset::query()->where('container', 'assets')->where('folder', 'library/002')->orderBy('path')->get(),
        'duplicate ids recorded' => fn () => count(Statamic\Facades\Stache::duplicates()->all()['entries::notes'] ?? []),
    ];
}
$results = [];
foreach ($queries as $name => $query) {
    $result = $ids($query);
    $results[$name] = is_array($result) ? [count($result), md5(json_encode($result)), array_slice($result, 0, 3)] : $result;
}

// collections and taxonomies come in whatever order the disk lists their files
ksort($trees);
ksort($indexes);

bench_emit(['entries' => $entries, 'terms' => $terms, 'trees' => $trees, 'indexes' => $indexes, 'queries' => $results]);
