<?php

/**
 * The --awkward part of the fixture: content shaped like the things that have
 * caught performance changes out, none of which a tidy generated site has.
 *
 * Runs inside generate.php, in its scope. Sets $awkward (what the checks need
 * to know) and $awkwardPages (extra page scenarios).
 *
 * Everything here has to be something stock Statamic builds without complaint,
 * and nothing here may be rewritten by Statamic when it's read (a file with no
 * id would be), or the fixture would stop being the same bytes on every run.
 */

use Symfony\Component\Yaml\Yaml;

$raw = function (string $path, string $contents) use ($root): void {
    @mkdir(dirname("$root/$path"), 0777, true);
    file_put_contents("$root/$path", $contents);
};
// what Symfony's parser makes of a note's front matter is what Statamic has to hold, however it got there
$expect = fn (string $frontMatter): array => array_diff_key(Yaml::parse($frontMatter), ['id' => 1, 'blueprint' => 1, 'published' => 1, 'origin' => 1]);

// ---------------------------------------------------------------- notes
// An unstructured collection of hand-written files, in every style of YAML a
// person or another tool writes and Statamic's own dumper never does.

writeYaml("$root/content/collections/notes.yaml", ['title' => 'Notes', 'route' => '/notes/{slug}', 'template' => 'default', 'sites' => ['en', 'fr'], 'sort_dir' => 'asc']);
writeYaml("$root/resources/blueprints/collections/notes/note.yaml", ['title' => 'Note', 'tabs' => tab(
    field('title', ['type' => 'text', 'required' => true]),
    field('intro', ['type' => 'textarea']),
    field('flag', ['type' => 'text']),
    field('weight', ['type' => 'integer']),
    field('see_also', ['type' => 'entries', 'collections' => ['notes', 'pages']]),
    field('content', ['type' => 'markdown']),
    field('slug', ['type' => 'slug']),
)]);

$notes = [];
$note = function (string $file, string $frontMatter, ?string $body = null, string $site = 'en') use (&$notes, $raw, $expect, $g): string {
    $id = $g->uuid();
    $frontMatter = "id: $id\n".$frontMatter;
    $raw("content/collections/notes/$site/$file", "---\n$frontMatter---\n".($body ?? ''));
    $notes[$id] = ['file' => "$site/$file", 'expect' => $expect($frontMatter) + ($body !== null ? ['content' => rtrim($body)] : [])];

    return $id;
};

$note('comments.md', <<<'YAML'
# a comment before anything
title: 'Comments in the front matter'   # and one after a value
intro: "A hash # inside quotes is not a comment"
# a comment between keys
weight: 3
tags_inline: [one, two]   # trailing
YAML."\n");

$crlf = $g->uuid();
$raw('content/collections/notes/en/crlf.md', str_replace("\n", "\r\n", "---\nid: $crlf\ntitle: 'Windows line endings'\nintro: Saved on Windows\nweight: 4\nlist:\n  - one\n  - two\n---\nA body with\ntwo lines.\n"));
$notes[$crlf] = ['file' => 'en/crlf.md', 'expect' => ['title' => 'Windows line endings', 'intro' => 'Saved on Windows', 'weight' => 4, 'list' => ['one', 'two']]];

$note('anchors.md', <<<'YAML'
title: 'Anchors and aliases'
defaults: &defaults
  colour: blue
  size: 12
first: *defaults
second:
  <<: *defaults
  size: 14
weight: 5
YAML."\n");

$note('flow.md', <<<'YAML'
title: 'Flow collections over several lines'
matrix: [
  [1, 2, 3],
  [4, 5, 6]
]
options: { colour: red, sizes: [s, m, l],
  nested: { deep: true } }
empty_map: {  }
empty_list: []
weight: 6
YAML."\n");

$note('block.md', <<<'YAML'
title: 'Block scalars'
literal: |
  Line one
  Line two

  After a blank line
literal_strip: |-
  No newline at the end
literal_keep: |+
  Keeps its trailing newlines


folded: >
  Folded text
  becomes one line.

  A new paragraph.
folded_strip: >-
  Folded and
  stripped
weight: 7
YAML."\n");

$quoted = $note('quoted.md', <<<'YAML'
title: "Escapes in \"double\" quotes"
intro: "Line one\nLine two\tTabbed é \\ backslash"
single: 'It''s a single-quoted ''string'''
looks_like_a_number: "007"
looks_like_a_bool: 'true'
colon: "Key: value inside a string"
weight: 8
YAML."\n");

$note('keys.md', <<<'YAML'
title: 'Keys that are not plain identifiers'
1_simple_content: starts with a digit
'New Zealand': quoted with a space
brand-reverse: has a hyphen
"quoted.key": has a dot
ünïcödé: accented
42: an integer key
weight: 9
YAML."\n");

$note('empties.md', <<<'YAML'
title: 'Empty and null values'
tilde: ~
explicit_null: null
empty_string: ''
empty_double: ""
nothing_after_colon:
weight: 10
YAML."\n");

$note('unicode.md', <<<'YAML'
title: 'Ünïcödé — “quotes”, 日本語 and 🎉'
intro: 'Zero-width joiner: 👩‍👩‍👧, right-to-left: שלום'
weight: 11
YAML."\n");

$note('types.md', <<<'YAML'
title: 'Scalar types'
int: 42
negative: -7
float: 3.14
exponent: 1.5e3
zero: 0
string_zero: '0'
bool_true: true
bool_false: false
hex: 0x1F
octal: 0o17
leading_zero: 0123
version: 1.10
plus: +5
dot_start: .5
yes_is_a_string: yes
weight: 12
YAML."\n");

$note('with-body.md', "title: 'A body after the front matter'\nweight: 13\n", "# A heading\n\nSome **markdown** with a --- rule in it:\n\n---\n\nAnd text after.\n");

// ids and slugs that look like numbers
$note('2024.md', "title: 'A numeric slug'\nweight: 14\n");
$numericId = '12345';
$raw('content/collections/notes/en/numeric-string-id.md', "---\nid: '12345'\ntitle: 'An id that is a string of digits'\nweight: 15\n---\n");
$notes[$numericId] = ['file' => 'en/numeric-string-id.md', 'expect' => ['title' => 'An id that is a string of digits', 'weight' => 15]];
$raw('content/collections/notes/en/integer-id.md', "---\nid: 4242\ntitle: 'An id that is an integer'\nweight: 16\n---\n");

// the same file name in different folders, at different depths
$nestedA = $note('deep/er/nested.md', "title: 'Nested two folders down'\nweight: 17\n");
$nestedB = $note('other/nested.md', "title: 'Nested in another folder'\nweight: 18\n");

// two files with the same id: Statamic keeps one and records the other as a duplicate
$duplicate = $g->uuid();
$raw('content/collections/notes/en/duplicate-a.md', "---\nid: $duplicate\ntitle: 'Duplicate A'\nweight: 19\n---\n");
$raw('content/collections/notes/en/duplicate-b.md', "---\nid: $duplicate\ntitle: 'Duplicate B'\nweight: 19\n---\n");

// one entry far bigger than the rest
$big = $g->uuid();
writeEntry("$root/content/collections/notes/en/big.md", [
    'id' => $big,
    'title' => 'One very large entry',
    'weight' => 20,
    'blob' => array_map(fn ($i) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $g->sentence(70)]]], range(1, $size === 'micro' ? 600 : 3000)),
]);

// relations: across sites, to an entry that doesn't exist, and a localisation
$french[$quoted] = $g->uuid();
// from a French entry, a relation gives the French version of what it points at, and nothing where there isn't one
writeEntry("$root/content/collections/notes/fr/quoted.md", ['id' => $french[$quoted], 'origin' => $quoted, 'title' => 'Échappements (FR)', 'see_also' => [$nestedA, $fixed['contact']]]);
$missing = $g->uuid();
$broken = $note('broken-relation.md', "title: 'Points at an entry that is not there'\nsee_also:\n  - $missing\n  - $nestedB\nweight: 21\n");

// redirects, which take their own branch when a URL is worked out
$redirects = [
    'internal' => $note('redirect-internal.md', "title: 'Redirects to a page'\nredirect: /contact\nweight: 22\n"),
    'external' => $note('redirect-external.md', "title: 'Redirects elsewhere'\nredirect: 'https://example.com/elsewhere'\nweight: 23\n"),
    'not_found' => $note('redirect-404.md', "title: 'Redirects to a 404'\nredirect: '404'\nweight: 24\n"),
];

// a field holding every kind of value, for filters and sorting to disagree about
$flags = [null, false, true, 0, '0', 1, 'a', 'B', '', ['x', 'y']];
$mixed = [];
for ($i = 0; $i < 40; $i++) {
    $mixed[] = $id = $g->uuid();
    writeEntry("$root/content/collections/notes/en/".sprintf('mixed-%02d', $i).'.md', array_merge(
        ['id' => $id, 'title' => 'Mixed '.$g->word(), 'weight' => $i % 7],   // weights tie, so sorting has to be stable
        $i % 11 === 10 ? [] : ['flag' => $flags[$i % count($flags)]],
    ));
}

// ---------------------------------------------------------------- notices
// Dated, with future entries private: drafts, scheduled entries, every form of
// date a file name can carry, and two entries that want the same file name.

writeYaml("$root/content/collections/notices.yaml", ['title' => 'Notices', 'route' => '/notices/{slug}', 'template' => 'default', 'date' => true, 'sites' => ['en'], 'date_behavior' => ['past' => 'public', 'future' => 'private']]);
writeYaml("$root/resources/blueprints/collections/notices/notice.yaml", ['title' => 'Notice', 'tabs' => tab(field('title', ['type' => 'text']), field('slug', ['type' => 'slug']))]);
$notices = [];
$notice = function (string $kind, string $file, array $data = []) use (&$notices, $root, $g): string {
    $notices[$kind][] = $id = $g->uuid();
    writeEntry("$root/content/collections/notices/en/$file.md", ['id' => $id, 'title' => ucfirst(str_replace('_', ' ', $kind)).' notice'] + $data);

    return $id;
};
foreach ([1, 2, 3] as $i) {
    $notice('past', "2001-0$i-15.past-$i");
    $notice('scheduled', "2099-0$i-15.scheduled-$i");
}
$notice('draft', '2001-06-01.draft-1', ['published' => false]);
$notice('draft', '2099-06-01.draft-2', ['published' => false]);
$notice('timed', '2020-03-08-0130.to-the-minute');
$notice('timed', '2020-11-01-013059.to-the-second');
$notice('colliding', '2021-05-05.same-slug');
$notice('colliding', '2021-05-05.same-slug.1');

// ---------------------------------------------------------------- faqs
// One level deep and orderable: the tree gives the order and nothing else.
// One entry is missing from the tree, as when a file is added by hand.

writeYaml("$root/content/collections/faqs.yaml", ['title' => 'FAQs', 'route' => '/faqs/{slug}', 'template' => 'default', 'structure' => ['max_depth' => 1], 'sites' => ['en']]);
writeYaml("$root/resources/blueprints/collections/faqs/faq.yaml", ['title' => 'FAQ', 'tabs' => tab(field('title', ['type' => 'text']), field('slug', ['type' => 'slug']))]);
$faqs = [];
foreach (range(1, 12) as $i) {
    $faqs[] = $id = $g->uuid();
    writeEntry("$root/content/collections/faqs/en/".sprintf('question-%02d', $i).'.md', ['id' => $id, 'title' => "Question $i"]);
}
$unlisted = array_pop($faqs);
// the order in the tree is not the order of the files
$faqOrder = [...array_reverse(array_slice($faqs, 0, 5)), ...array_slice($faqs, 5)];
writeYaml("$root/content/trees/collections/en/faqs.yaml", ['tree' => array_map(fn ($id) => ['entry' => $id], $faqOrder)]);

// ---------------------------------------------------------------- snippets
// No route, so no URLs at all.

writeYaml("$root/content/collections/snippets.yaml", ['title' => 'Snippets', 'sites' => ['en']]);
writeYaml("$root/resources/blueprints/collections/snippets/snippet.yaml", ['title' => 'Snippet', 'tabs' => tab(field('title', ['type' => 'text']), field('text', ['type' => 'textarea']))]);
$snippets = [];
foreach (range(1, 6) as $i) {
    $snippets[] = $id = $g->uuid();
    writeEntry("$root/content/collections/snippets/en/snippet-$i.md", ['id' => $id, 'title' => "Snippet $i", 'text' => $g->sentence(12)]);
}

// ---------------------------------------------------------------- a deep nav
// Six levels, and every kind of node: an entry, a URL, a title with nothing
// behind it, and a reference to an entry that no longer exists.

$deepNav = null;
$deepNavNodes = 0;
foreach (array_reverse($chainIds) as $level => $id) {
    $deepNavNodes += 4;
    $deepNav = [
        ['id' => $g->uuid(), 'entry' => $id] + ($deepNav ? ['children' => $deepNav] : []),
        ['id' => $g->uuid(), 'url' => 'https://example.com/level-'.$level, 'title' => 'An outside link'],
        ['id' => $g->uuid(), 'title' => 'A title with no link'],
        ['id' => $g->uuid(), 'entry' => $missing],
    ];
}
writeYaml("$root/content/navigation/deep.yaml", ['title' => 'Deep', 'collections' => ['pages'], 'max_depth' => 6]);
writeYaml("$root/content/trees/navigation/en/deep.yaml", ['tree' => $deepNav]);

// ---------------------------------------------------------------- what the checks need

// computed values (registered in routes/web.php): how many articles share this one's author
$perAuthor = array_count_values($articleAuthors);
$sample = array_map(fn ($k) => ($k * 13 + 3) % $cfg['articles'], range(0, 7));
$threshold = (int) ceil($cfg['articles'] / $cfg['people']);

$keywordUse = [];
foreach ($articleKeywords as $articleKeywordSlugs) {
    foreach ($articleKeywordSlugs as $slug) {
        $keywordUse[$slug] = ($keywordUse[$slug] ?? 0) + 1;
    }
}
arsort($keywordUse);
$usedImplicit = array_values(array_intersect($implicitKeywords, array_keys($keywordUse)));

$awkward = [
    'notes' => $notes,
    'integer_id' => 4242,
    'numeric_string_id' => $numericId,
    'duplicate_id' => $duplicate,
    'big_id' => $big,
    'nested' => [$nestedA, $nestedB],
    'french_note' => ['id' => $french[$quoted], 'origin' => $quoted, 'see_also_resolves_to' => $french[$fixed['contact']]],
    'broken_relation' => ['id' => $broken, 'missing' => $missing, 'present' => $nestedB],
    'redirects' => $redirects,
    'mixed' => $mixed,
    'notices' => $notices,
    'faqs' => ['order' => $faqOrder, 'unlisted' => $unlisted],
    'snippets' => $snippets,
    'deep_chain' => $chainIds,
    'deep_nav_nodes' => $deepNavNodes,
    'computed' => [
        'same_author_count' => array_combine(array_map(fn ($i) => $ids['articles'][$i], $sample), array_map(fn ($i) => $perAuthor[$articleAuthors[$i]], $sample)),
        'threshold' => $threshold,
        'at_or_over_threshold' => array_sum(array_filter($perAuthor, fn ($n) => $n >= $threshold)),
    ],
    'keywords' => [
        'terms' => count($keywords),
        'busiest' => array_slice($keywordUse, 0, 3, true),
        'implicit' => $usedImplicit ? [$usedImplicit[0] => $keywordUse[$usedImplicit[0]]] : [],
    ],
    'assets' => $cfg['assets'],
];
// the notes with expectations, the mixed ones, and four more: the integer id, one of the duplicates, the big one, the French one
$awkward['note_count'] = count($notes) + count($mixed) + 4;

$awkwardPages = [
    'note' => ['/notes/quoted', 200],
    'note_big' => ['/notes/big', 200],
    'note_french' => ['/fr/notes/quoted', 200],
    'faq' => ['/faqs/question-03', 200],
    'notice_past' => ['/notices/past-1', 200],
    'notice_scheduled' => ['/notices/scheduled-1', 404],
    'notice_draft' => ['/notices/draft-1', 404],
];
