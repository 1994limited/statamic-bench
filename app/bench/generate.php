<?php

/**
 * Seeded fixture generator: a two-language content site with pages (English
 * and French), articles, products, events, people, two taxonomies, navs,
 * globals and assets.
 *
 * Everything is written straight to disk as YAML and Markdown, never through
 * Statamic's own API, so the code under test cannot change the fixture: the
 * same seed and size always produce the same bytes.
 *
 *   php bench/generate.php [--size=micro|small|medium|large] [--seed=1001]
 *       [--articles=N] [--products=N] [--events=N] [--people=N] [--tags=N]
 *
 * Writes bench/manifest.json: the URLs each scenario requests and the entries
 * the save scenario touches.
 */

require __DIR__.'/../vendor/autoload.php';

use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Yaml\Yaml;

const SIZES = [
    'micro' => ['articles' => 50, 'products' => 15, 'events' => 10, 'people' => 6, 'tags' => 12, 'tree' => [3, 2]],
    'small' => ['articles' => 200, 'products' => 60, 'events' => 40, 'people' => 12, 'tags' => 30, 'tree' => [4, 3]],
    'medium' => ['articles' => 2000, 'products' => 300, 'events' => 200, 'people' => 30, 'tags' => 80, 'tree' => [6, 4, 2]],
    'large' => ['articles' => 9000, 'products' => 1500, 'events' => 600, 'people' => 60, 'tags' => 200, 'tree' => [6, 4, 3, 2]],
];

$opts = getopt('', ['size:', 'seed:', 'articles:', 'products:', 'events:', 'people:', 'tags:']);
$size = $opts['size'] ?? 'small';
isset(SIZES[$size]) || exit("generate: unknown size $size\n");
$cfg = ['size' => $size, 'seed' => (int) ($opts['seed'] ?? 1001)] + SIZES[$size];
foreach (['articles', 'products', 'events', 'people', 'tags'] as $k) {
    if (isset($opts[$k])) {
        $cfg[$k] = max(4, (int) $opts[$k]);
    }
}

$root = dirname(__DIR__);
$g = new Gen($cfg['seed']);

foreach (['content', 'resources/blueprints', 'resources/fieldsets', 'resources/forms', 'public/assets', 'users', 'storage/framework/cache/data', 'storage/statamic'] as $dir) {
    rrmdir("$root/$dir");
}

const WORDS = ['alpine', 'amber', 'anchor', 'arcade', 'atlas', 'aurora', 'basalt', 'beacon', 'birch', 'bramble',
    'canyon', 'cedar', 'cinder', 'cobalt', 'comet', 'copper', 'coral', 'crest', 'delta', 'dune', 'ember', 'falcon',
    'fern', 'fjord', 'flint', 'forge', 'garnet', 'glacier', 'granite', 'harbor', 'hazel', 'heron', 'indigo', 'iris',
    'jasper', 'juniper', 'kestrel', 'lagoon', 'lantern', 'larch', 'lumen', 'maple', 'marble', 'meadow', 'mesa',
    'mistral', 'nectar', 'nimbus', 'oasis', 'onyx', 'orchid', 'pebble', 'pine', 'prairie', 'quartz', 'quill',
    'raven', 'reef', 'ridge', 'saffron', 'sage', 'sierra', 'slate', 'sparrow', 'spruce', 'summit', 'tamarind',
    'thistle', 'tide', 'timber', 'topaz', 'tundra', 'umber', 'valley', 'vesper', 'willow', 'wren', 'yarrow', 'zephyr'];

const T0 = 1700000000;

// ---------------------------------------------------------------- sites

writeYaml("$root/resources/sites.yaml", [
    'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
    'fr' => ['name' => 'French', 'url' => '/fr/', 'locale' => 'fr_FR'],
]);

// ---------------------------------------------------------------- assets

$assets = [];
for ($i = 0; $i < 40; $i++) {
    [$w, $h] = [16 + $i % 9, 9 + ($i * 3) % 7];
    $name = sprintf('img-%02d-%s.png', $i, $g->word());
    $png = png($w, $h, $g->int(0, 255), $g->int(0, 255), $g->int(0, 255));
    @mkdir("$root/public/assets/.meta", 0777, true);
    file_put_contents("$root/public/assets/$name", $png);
    writeYaml("$root/public/assets/.meta/$name.yaml", [
        'data' => ['alt' => ucfirst($g->words(3))],
        'size' => strlen($png),
        'last_modified' => T0 + $i,
        'width' => $w,
        'height' => $h,
        'mime_type' => 'image/png',
        'duration' => null,
    ]);
    $assets[] = $name;
}
writeYaml("$root/content/assets/assets.yaml", ['title' => 'Assets', 'disk' => 'assets']);
writeYaml("$root/resources/blueprints/assets/assets.yaml", ['title' => 'Asset', 'fields' => [field('alt', ['type' => 'text'])]]);

// ---------------------------------------------------------------- taxonomies

$categories = [];
foreach (['news', 'guides', 'reviews', 'opinion', 'releases', 'tutorials', 'case-studies', 'company', 'events', 'research', 'culture', 'interviews'] as $i => $slug) {
    $categories[] = $slug;
    writeYaml("$root/content/taxonomies/categories/$slug.yaml", [
        'title' => ucwords(str_replace('-', ' ', $slug)),
        'description' => $g->sentence(18),
        'id' => $g->uuid(),
        'updated_at' => T0,
    ]);
}
$tags = [];
for ($i = 0; $i < $cfg['tags']; $i++) {
    $slug = WORDS[$i % count(WORDS)].'-'.$i;
    $tags[] = $slug;
    writeYaml("$root/content/taxonomies/tags/$slug.yaml", ['title' => ucfirst(WORDS[$i % count(WORDS)]).' '.$i, 'id' => $g->uuid(), 'updated_at' => T0]);
}
writeYaml("$root/content/taxonomies/categories.yaml", ['title' => 'Categories', 'sites' => ['en', 'fr']]);
writeYaml("$root/content/taxonomies/tags.yaml", ['title' => 'Tags', 'sites' => ['en', 'fr']]);
writeYaml("$root/resources/blueprints/taxonomies/categories/category.yaml", ['title' => 'Category', 'tabs' => tab(
    field('title', ['type' => 'text']),
    field('description', ['type' => 'textarea']),
)]);
writeYaml("$root/resources/blueprints/taxonomies/tags/tag.yaml", ['title' => 'Tag', 'tabs' => tab(field('title', ['type' => 'text']))]);

// ---------------------------------------------------------------- blueprints

$image = fn (string $handle) => field($handle, ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]);
$seo = [
    field('seo_title', ['type' => 'text']),
    field('seo_description', ['type' => 'textarea']),
    $image('og_image'),
];

$bardSets = ['main' => ['display' => 'Main', 'sets' => [
    'image' => ['display' => 'Image', 'fields' => [$image('image'), field('caption', ['type' => 'text'])]],
    'quote' => ['display' => 'Quote', 'fields' => [field('quote', ['type' => 'textarea']), field('cite', ['type' => 'text'])]],
    'callout' => ['display' => 'Callout', 'fields' => [
        field('label', ['type' => 'text']),
        field('text', ['type' => 'textarea']),
        field('tone', ['type' => 'select', 'options' => ['info' => 'Info', 'warn' => 'Warn', 'tip' => 'Tip']]),
    ]],
    'button' => ['display' => 'Button', 'fields' => [
        field('label', ['type' => 'text']),
        field('link', ['type' => 'entries', 'collections' => ['pages', 'articles'], 'max_items' => 1]),
    ]],
]]];

$blockSets = [
    'hero' => [field('heading', ['type' => 'text']), field('text', ['type' => 'textarea']), $image('image'), field('buttons', ['type' => 'grid', 'fields' => [
        field('label', ['type' => 'text']),
        field('link', ['type' => 'entries', 'collections' => ['pages'], 'max_items' => 1]),
        field('style', ['type' => 'select', 'options' => ['primary' => 'Primary', 'secondary' => 'Secondary']]),
    ]])],
    'text' => [field('body', ['type' => 'bard', 'save_html' => false])],
    'text_image' => [field('heading', ['type' => 'text']), field('body', ['type' => 'markdown']), $image('image'), field('flip', ['type' => 'toggle'])],
    'cards' => [field('heading', ['type' => 'text']), field('items', ['type' => 'entries', 'collections' => ['articles']])],
    'latest_articles' => [field('heading', ['type' => 'text']), field('count', ['type' => 'integer']), field('category', ['type' => 'terms', 'taxonomies' => ['categories'], 'max_items' => 1])],
    'products' => [field('heading', ['type' => 'text']), field('items', ['type' => 'entries', 'collections' => ['products']])],
    'quote' => [field('quote', ['type' => 'textarea']), field('person', ['type' => 'entries', 'collections' => ['people'], 'max_items' => 1])],
    'stats' => [field('heading', ['type' => 'text']), field('stats', ['type' => 'grid', 'fields' => [field('label', ['type' => 'text']), field('value', ['type' => 'text'])]])],
    'faq' => [field('heading', ['type' => 'text']), field('questions', ['type' => 'grid', 'fields' => [field('question', ['type' => 'text']), field('answer', ['type' => 'markdown'])]])],
    'cta' => [field('heading', ['type' => 'text']), field('text', ['type' => 'textarea']), field('button_label', ['type' => 'text']), field('button_link', ['type' => 'entries', 'collections' => ['pages'], 'max_items' => 1])],
    'gallery' => [field('heading', ['type' => 'text']), field('images', ['type' => 'assets', 'container' => 'assets'])],
    'logos' => [field('heading', ['type' => 'text']), field('logos', ['type' => 'assets', 'container' => 'assets'])],
    'team' => [field('heading', ['type' => 'text']), field('members', ['type' => 'entries', 'collections' => ['people']])],
    'features' => [field('heading', ['type' => 'text']), field('items', ['type' => 'replicator', 'sets' => ['main' => ['display' => 'Main', 'sets' => [
        'feature' => ['display' => 'Feature', 'fields' => [field('icon', ['type' => 'text']), field('title', ['type' => 'text']), field('text', ['type' => 'textarea'])]],
    ]]]])],
];
$sets = [];
foreach ($blockSets as $handle => $fields) {
    $sets[$handle] = ['display' => ucwords(str_replace('_', ' ', $handle)), 'fields' => $fields];
}
$blocks = field('blocks', ['type' => 'replicator', 'sets' => ['main' => ['display' => 'Main', 'sets' => $sets]]]);

$blueprints = [
    'pages/page' => [field('title', ['type' => 'text', 'required' => true]), field('intro', ['type' => 'textarea']), $blocks, field('slug', ['type' => 'slug']), ...$seo],
    'articles/article' => [
        field('title', ['type' => 'text', 'required' => true]),
        field('excerpt', ['type' => 'textarea']),
        $image('hero_image'),
        field('content', ['type' => 'bard', 'sets' => $bardSets, 'save_html' => false]),
        field('author', ['type' => 'entries', 'collections' => ['people'], 'max_items' => 1]),
        field('categories', ['type' => 'terms', 'taxonomies' => ['categories']]),
        field('tags', ['type' => 'terms', 'taxonomies' => ['tags']]),
        field('related', ['type' => 'entries', 'collections' => ['articles']]),
        field('reading_time', ['type' => 'integer']),
        field('featured', ['type' => 'toggle']),
        field('slug', ['type' => 'slug']),
        ...$seo,
    ],
    'products/product' => [
        field('title', ['type' => 'text', 'required' => true]),
        field('sku', ['type' => 'text']),
        field('price', ['type' => 'float']),
        field('summary', ['type' => 'textarea']),
        field('description', ['type' => 'markdown']),
        field('gallery', ['type' => 'assets', 'container' => 'assets']),
        field('specs', ['type' => 'grid', 'fields' => [field('label', ['type' => 'text']), field('value', ['type' => 'text']), field('highlight', ['type' => 'toggle'])]]),
        field('categories', ['type' => 'terms', 'taxonomies' => ['categories']]),
        field('related', ['type' => 'entries', 'collections' => ['products']]),
        field('in_stock', ['type' => 'toggle']),
        field('slug', ['type' => 'slug']),
        ...$seo,
    ],
    'events/event' => [
        field('title', ['type' => 'text', 'required' => true]),
        field('venue', ['type' => 'text']),
        field('summary', ['type' => 'textarea']),
        field('body', ['type' => 'markdown']),
        field('speakers', ['type' => 'entries', 'collections' => ['people']]),
        field('slug', ['type' => 'slug']),
    ],
    'people/person' => [
        field('title', ['type' => 'text', 'required' => true]),
        field('role', ['type' => 'text']),
        field('bio', ['type' => 'markdown']),
        $image('photo'),
        field('socials', ['type' => 'grid', 'fields' => [field('network', ['type' => 'text']), field('url', ['type' => 'text'])]]),
        field('slug', ['type' => 'slug']),
    ],
];
foreach ($blueprints as $path => $fields) {
    writeYaml("$root/resources/blueprints/collections/$path.yaml", ['title' => ucfirst(basename($path)), 'tabs' => tab(...$fields)]);
}

// ---------------------------------------------------------------- people

$ids = ['people' => [], 'articles' => [], 'products' => [], 'events' => []];
$slugs = $ids;
$roles = [];
$eventUrls = [];

writeYaml("$root/content/collections/people.yaml", ['title' => 'People', 'route' => '/team/{{ role | slugify }}/{{ slug }}', 'template' => 'team/show', 'sort_dir' => 'asc', 'sites' => ['en']]);
for ($i = 0; $i < $cfg['people']; $i++) {
    $ids['people'][] = $id = $g->uuid();
    $slugs['people'][] = $slug = sprintf('%s-%s-%02d', $g->word(), $g->word(), $i);
    writeEntry("$root/content/collections/people/en/$slug.md", [
        'id' => $id,
        'blueprint' => 'person',
        'title' => ucwords($g->words(2)),
        'role' => $roles[] = ucwords($g->words(2)),
        'bio' => $g->sentence(24)."\n\n".$g->sentence(16),
        'photo' => $g->pick($assets),
        'socials' => array_map(fn ($n) => ['id' => $g->setId(), 'network' => $n, 'url' => "https://example.com/$n/".$g->word()], $g->pickMany(['mastodon', 'bluesky', 'github', 'linkedin'], 3)),
        'updated_at' => T0 + $i,
    ]);
}

// ---------------------------------------------------------------- products

writeYaml("$root/content/collections/products.yaml", ['title' => 'Products', 'route' => '/products/{slug}', 'template' => 'products/show', 'taxonomies' => ['categories'], 'sort_dir' => 'asc', 'sites' => ['en']]);
for ($i = 0; $i < $cfg['products']; $i++) {
    $ids['products'][] = $g->uuid();
    $slugs['products'][] = sprintf('%s-%s-%04d', $g->word(), $g->word(), $i);
}
for ($i = 0; $i < $cfg['products']; $i++) {
    writeEntry("$root/content/collections/products/en/{$slugs['products'][$i]}.md", [
        'id' => $ids['products'][$i],
        'blueprint' => 'product',
        'title' => ucwords($g->words(3)),
        'sku' => strtoupper(substr($g->word(), 0, 3)).'-'.(1000 + $i),
        'price' => $g->int(500, 49900) / 100,
        'summary' => $g->sentence(18),
        'description' => '## '.ucfirst($g->words(3))."\n\n".$g->sentence(30)."\n\n- ".$g->words(4)."\n- ".$g->words(4)."\n- ".$g->words(3),
        'gallery' => $g->pickMany($assets, $g->int(2, 5)),
        'specs' => array_map(fn () => ['id' => $g->setId(), 'label' => ucfirst($g->word()), 'value' => $g->words(2), 'highlight' => $g->chance(0.2)], range(1, $g->int(4, 9))),
        'categories' => $g->pickMany($categories, $g->int(1, 2)),
        'related' => $g->pickMany($ids['products'], $g->int(2, 4)),
        'in_stock' => $g->chance(0.8),
        'seo_description' => $g->sentence(14),
        'updated_at' => T0 + $i,
    ]);
}

// ---------------------------------------------------------------- events

writeYaml("$root/content/collections/events.yaml", ['title' => 'Events', 'route' => '/events/{year}/{month}/{slug}', 'template' => 'events/show', 'date' => true, 'sites' => ['en'], 'sort_dir' => 'asc', 'date_behavior' => ['past' => 'public', 'future' => 'public']]);
for ($i = 0; $i < $cfg['events']; $i++) {
    $ids['events'][] = $id = $g->uuid();
    $slug = sprintf('%s-%s-%03d', $g->word(), $g->word(), $i);
    $date = sprintf('%04d-%02d-%02d', 2024 + $i % 3, $g->int(1, 12), $g->int(1, 28));
    $slugs['events'][] = $slug;
    $eventUrls[] = '/events/'.substr($date, 0, 4).'/'.substr($date, 5, 2).'/'.$slug;
    writeEntry("$root/content/collections/events/en/$date.$slug.md", [
        'id' => $id,
        'blueprint' => 'event',
        'title' => ucwords($g->words(3)),
        'venue' => ucwords($g->words(2)).' Hall',
        'summary' => $g->sentence(16),
        'body' => $g->sentence(28)."\n\n".$g->sentence(20),
        'speakers' => $g->pickMany($ids['people'], $g->int(1, 3)),
        'updated_at' => T0 + $i,
    ]);
}

// ---------------------------------------------------------------- articles

for ($i = 0; $i < $cfg['articles']; $i++) {
    $ids['articles'][] = $g->uuid();
    $slugs['articles'][] = sprintf('%s-%s-%s-%05d', $g->word(), $g->word(), $g->word(), $i);
}
$articleCategories = [];
for ($i = 0; $i < $cfg['articles']; $i++) {
    $date = date('Y-m-d', strtotime('2022-01-01') + ($i * 1087 % 1400) * 86400);
    $articleCategories[$i] = $g->pickMany($categories, $g->int(1, 2));
    writeEntry("$root/content/collections/articles/en/$date.{$slugs['articles'][$i]}.md", [
        'id' => $ids['articles'][$i],
        'blueprint' => 'article',
        'title' => ucfirst($g->words($g->int(4, 8))),
        'excerpt' => $g->sentence(26),
        'hero_image' => $g->pick($assets),
        'content' => articleBody($g, $assets, $ids),
        'author' => $g->pick($ids['people']),
        'categories' => $articleCategories[$i],
        'tags' => $g->pickMany($tags, $g->int(2, 5)),
        'related' => $g->pickMany($ids['articles'], 3),
        'reading_time' => $g->int(2, 14),
        'featured' => $g->chance(0.1),
        'seo_title' => $g->chance(0.4) ? ucfirst($g->words(5)) : null,
        'seo_description' => $g->sentence(16),
        'updated_at' => T0 + $i,
    ]);
}

// ---------------------------------------------------------------- pages

$pageIds = [];
$pagePaths = [];
$french = []; // English page id => its French localisation's id
$page = function (string $slug, array $data) use ($root, $g, &$pageIds, &$french) {
    $pageIds[$slug] = $id = $g->uuid();
    writeEntry("$root/content/collections/pages/en/$slug.md", ['id' => $id, 'blueprint' => 'page'] + $data + ['updated_at' => T0]);
    // the French page overrides the title and intro and inherits the rest
    $french[$id] = $g->uuid();
    writeEntry("$root/content/collections/pages/fr/$slug.md", ['id' => $french[$id], 'origin' => $id, 'title' => $data['title'].' (FR)', 'intro' => $g->sentence(12), 'updated_at' => T0]);

    return $id;
};

$fixed = [];
foreach (['articles' => 'articles/index', 'products' => 'products/index', 'events' => 'events/index', 'team' => 'team/index'] as $slug => $template) {
    $fixed[$slug] = $page($slug, ['title' => ucfirst($slug), 'intro' => $g->sentence(18), 'template' => $template, 'seo_description' => $g->sentence(14)]);
}
$fixed['contact'] = $page('contact', ['title' => 'Contact', 'intro' => $g->sentence(20), 'blocks' => [block($g, 'text', $assets, $ids, $categories, $pageIds)]]);

$tree = [];
$build = function (int $level, array $ancestors) use (&$build, $cfg, $g, $page, $assets, $ids, $categories, &$pageIds, &$pagePaths) {
    $branches = [];
    for ($i = 0; $i < $cfg['tree'][$level]; $i++) {
        $slug = sprintf('%s-%s-l%d-%03d', $g->word(), $g->word(), $level + 1, count($pageIds));
        $types = $g->pickMany(array_keys($GLOBALS['blockSets']), $g->int(3, 6));
        $id = $page($slug, [
            'title' => ucwords($g->words(2)),
            'intro' => $g->sentence(16),
            'blocks' => array_map(fn ($t) => block($g, $t, $assets, $ids, $categories, $pageIds), $types),
            'seo_description' => $g->sentence(12),
        ]);
        $path = [...$ancestors, $slug];
        $pagePaths[] = $path;
        $branch = ['entry' => $id];
        if ($level + 1 < count($cfg['tree'])) {
            $branch['children'] = $build($level + 1, $path);
        }
        $branches[] = $branch;
    }

    return $branches;
};
$generated = $build(0, []);

// the two showcase pages come last so their blocks can link to any page
$fixed['about'] = $page('about', ['title' => 'About', 'intro' => $g->sentence(22), 'blocks' => array_map(
    fn ($t) => block($g, $t, $assets, $ids, $categories, $pageIds), array_keys($blockSets)
), 'seo_title' => 'About us']);
$home = $page('home', ['title' => 'Home', 'intro' => $g->sentence(14), 'blocks' => array_map(
    fn ($t) => block($g, $t, $assets, $ids, $categories, $pageIds),
    ['hero', 'logos', 'features', 'latest_articles', 'stats', 'products', 'quote', 'cards', 'team', 'faq', 'cta']
)]);

writeYaml("$root/content/collections/pages.yaml", ['title' => 'Pages', 'route' => '{parent_uri}/{slug}', 'template' => 'default', 'structure' => ['root' => true], 'sites' => ['en', 'fr'], 'propagate' => false]);
$pageTree = [
    ['entry' => $home],
    ['entry' => $fixed['about']],
    ['entry' => $fixed['articles']],
    ['entry' => $fixed['products']],
    ['entry' => $fixed['events']],
    ['entry' => $fixed['team']],
    ...$generated,
    ['entry' => $fixed['contact']],
];
$toFrench = function (array $branches) use (&$toFrench, $french) {
    return array_map(fn ($b) => ['entry' => $french[$b['entry']]] + (isset($b['children']) ? ['children' => $toFrench($b['children'])] : []), $branches);
};
writeYaml("$root/content/trees/collections/en/pages.yaml", ['tree' => $pageTree]);
writeYaml("$root/content/trees/collections/fr/pages.yaml", ['tree' => $toFrench($pageTree)]);

// articles are mounted on the /articles page
writeYaml("$root/content/collections/articles.yaml", [
    'title' => 'Articles',
    'route' => '{mount}/{slug}',
    'mount' => $fixed['articles'],
    'template' => 'articles/show',
    'date' => true,
    'sort_dir' => 'desc',
    'taxonomies' => ['categories', 'tags'],
    'sites' => ['en'],
]);

// ---------------------------------------------------------------- navs

$navFields = [field('icon', ['type' => 'text']), field('badge', ['type' => 'select', 'options' => ['new' => 'New', 'hot' => 'Hot']])];
$linkable = [...array_values($pageIds), ...array_slice($ids['articles'], 0, 200), ...array_slice($ids['products'], 0, 100)];
$deepest = end($pagePaths);
foreach ($pagePaths as $p) {
    if (count($p) > count($deepest)) {
        $deepest = $p;
    }
}
$chain = array_map(fn ($slug) => $pageIds[$slug], $deepest);
$counts = [];
foreach (['main' => [5, 3], 'footer' => [4, 2]] as $handle => [$breadth, $depth]) {
    $count = 0;
    writeYaml("$root/content/navigation/$handle.yaml", ['title' => ucfirst($handle), 'collections' => ['pages', 'articles', 'products'], 'max_depth' => $depth]);
    writeYaml("$root/resources/blueprints/navigation/$handle.yaml", ['title' => 'Nav', 'tabs' => tab(...$navFields)]);
    writeYaml("$root/content/trees/navigation/en/$handle.yaml", ['tree' => navBranches($g, $breadth, $depth, 1, $linkable, $handle === 'main' ? $chain : [], $count)]);
    $counts[$handle] = $count;
}

// ---------------------------------------------------------------- globals

writeYaml("$root/content/globals/settings.yaml", ['title' => 'Settings', 'sites' => ['en' => null, 'fr' => 'en']]);
writeYaml("$root/content/globals/fr/settings.yaml", ['tagline' => $g->sentence(6)]);
writeYaml("$root/content/globals/en/settings.yaml", [
    'site_name' => 'Bench & Co',
    'tagline' => $g->sentence(8),
    'announcement' => $g->sentence(10),
    'announcement_link' => $fixed['about'],
    'footer_text' => $g->sentence(20),
    'contact_email' => 'hello@example.com',
    'address' => "1 Bench Street\nBenchtown\nBT1 1AA",
    'social' => array_map(fn ($n) => ['id' => $g->setId(), 'network' => $n, 'url' => "https://example.com/$n"], ['mastodon', 'bluesky', 'github', 'linkedin', 'youtube']),
]);
writeYaml("$root/resources/blueprints/globals/settings.yaml", ['title' => 'Settings', 'tabs' => tab(
    field('site_name', ['type' => 'text']),
    field('tagline', ['type' => 'text']),
    field('announcement', ['type' => 'text']),
    field('announcement_link', ['type' => 'entries', 'collections' => ['pages'], 'max_items' => 1]),
    field('footer_text', ['type' => 'textarea']),
    field('contact_email', ['type' => 'text']),
    field('address', ['type' => 'textarea']),
    field('social', ['type' => 'grid', 'fields' => [field('network', ['type' => 'text']), field('url', ['type' => 'text'])]]),
)]);

// ---------------------------------------------------------------- manifest

$at = fn (string $c, float $f) => $slugs[$c][(int) ($f * (count($slugs[$c]) - 1))];
$person = intdiv($cfg['people'], 2);
$personUrl = '/team/'.str_replace(' ', '-', strtolower($roles[$person])).'/'.$slugs['people'][$person];
$eventUrl = $eventUrls[intdiv($cfg['events'], 2)];
$manifest = [
    'config' => $cfg,
    'counts' => [
        'articles' => $cfg['articles'], 'products' => $cfg['products'], 'events' => $cfg['events'], 'people' => $cfg['people'],
        'pages' => count($pageIds), 'categories' => count($categories), 'tags' => count($tags), 'nav_nodes' => $counts,
    ],
    // scenario => [url, expected status]
    'pages' => [
        'home' => ['/', 200],
        'page_builder' => ['/about', 200],
        'page_simple' => ['/contact', 200],
        'page_deep' => ['/'.implode('/', $deepest), 200],
        'page_french' => ['/fr/about', 200],
        'article' => ['/articles/'.$at('articles', 0.37), 200],
        'articles_index' => ['/articles', 200],
        'articles_page_3' => ['/articles?page=3', 200],
        'category' => ['/categories/'.$categories[2], 200],
        'tag' => ['/tags/'.$tags[3], 200],
        'product' => ['/products/'.$at('products', 0.5), 200],
        'event' => [$eventUrl, 200],
        'products_index' => ['/products', 200],
        'events_index' => ['/events', 200],
        'team_index' => ['/team', 200],
        'person' => [$personUrl, 200],
        'not_found' => ['/no-such-page', 404],
    ],
    'save' => [
        'update_ids' => array_map(fn ($k) => $ids['articles'][($k * 7 + 1) % $cfg['articles']], range(0, 19)),
        'template_id' => $ids['articles'][0],
    ],
    // an English page and its French localisation, for the origin checks
    'article' => ['id' => $ids['articles'][(int) (0.37 * ($cfg['articles'] - 1))], 'newest_date' => '2026-01-01'],
    'localised' => ['origin' => $fixed['contact'], 'localisation' => $french[$fixed['contact']], 'other_origin' => $fixed['about']],
];
file_put_contents("$root/bench/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDERR, sprintf("generated %s (seed %d): %s\n", $size, $cfg['seed'], json_encode($manifest['counts'])));

// ================================================================= helpers

final class Gen
{
    private Randomizer $r;

    public function __construct(int $seed)
    {
        $this->r = new Randomizer(new Mt19937($seed));
    }

    public function int(int $a, int $b): int
    {
        return $this->r->getInt($a, $b);
    }

    public function chance(float $p): bool
    {
        return $this->r->getInt(0, 9999) < $p * 10000;
    }

    public function pick(array $xs)
    {
        return $xs[$this->r->getInt(0, count($xs) - 1)];
    }

    public function pickMany(array $xs, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->pick($xs);
        }

        return array_values(array_unique($out));
    }

    public function word(): string
    {
        return $this->pick(WORDS);
    }

    public function words(int $n): string
    {
        return implode(' ', array_map(fn () => $this->word(), range(1, $n)));
    }

    public function sentence(int $n): string
    {
        return ucfirst($this->words($n)).'.';
    }

    public function uuid(): string
    {
        $h = bin2hex($this->r->getBytes(16));

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    public function setId(): string
    {
        return bin2hex($this->r->getBytes(4));
    }
}

function field(string $handle, array $config): array
{
    return ['handle' => $handle, 'field' => $config];
}

function tab(array ...$fields): array
{
    return ['main' => ['display' => 'Main', 'sections' => [['fields' => $fields]]]];
}

function prose(Gen $g, int $paragraphs): array
{
    $nodes = [];
    for ($p = 0; $p < $paragraphs; $p++) {
        $content = [['type' => 'text', 'text' => $g->sentence($g->int(12, 30)).' ']];
        if ($g->chance(0.5)) {
            $content[] = ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => $g->words(2)];
            $content[] = ['type' => 'text', 'text' => ' '.$g->sentence(8)];
        }
        if ($g->chance(0.3)) {
            $content[] = ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/'.$g->word(), 'target' => null]]], 'text' => $g->words(2)];
        }
        $nodes[] = ['type' => 'paragraph', 'content' => $content];
    }

    return $nodes;
}

function articleBody(Gen $g, array $assets, array $ids): array
{
    $content = [];
    for ($k = 0, $sections = $g->int(3, 5); $k < $sections; $k++) {
        $content[] = ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => ucfirst($g->words(4))]]];
        array_push($content, ...prose($g, $g->int(2, 3)));
        if ($g->chance(0.4)) {
            $content[] = ['type' => 'bulletList', 'content' => array_map(fn () => ['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => ucfirst($g->words(5))]]],
            ]], range(1, $g->int(3, 5)))];
        }
        $type = $g->pick(['image', 'quote', 'callout', 'button']);
        $content[] = ['type' => 'set', 'attrs' => ['id' => $g->setId(), 'values' => ['type' => $type] + match ($type) {
            'image' => ['image' => $g->pick($assets), 'caption' => $g->sentence(6)],
            'quote' => ['quote' => $g->sentence(16), 'cite' => ucwords($g->words(2))],
            'callout' => ['label' => ucfirst($g->words(3)), 'text' => $g->sentence(14), 'tone' => $g->pick(['info', 'warn', 'tip'])],
            'button' => ['label' => ucfirst($g->words(2)), 'link' => $g->pick($ids['articles'])],
        }]];
    }

    return $content;
}

function block(Gen $g, string $type, array $assets, array $ids, array $categories, array $pageIds): array
{
    $anyPage = fn () => $pageIds ? $g->pick(array_values($pageIds)) : null;
    $grid = fn (int $min, int $max, callable $row) => array_map(fn () => ['id' => $g->setId()] + $row(), range(1, $g->int($min, $max)));

    return ['id' => $g->setId(), 'type' => $type, 'enabled' => true] + match ($type) {
        'hero' => ['heading' => ucfirst($g->words(5)), 'text' => $g->sentence(18), 'image' => $g->pick($assets), 'buttons' => $grid(1, 2, fn () => ['label' => ucfirst($g->words(2)), 'link' => $anyPage(), 'style' => $g->pick(['primary', 'secondary'])])],
        'text' => ['body' => prose($g, $g->int(2, 4))],
        'text_image' => ['heading' => ucfirst($g->words(4)), 'body' => $g->sentence(30)."\n\n".$g->sentence(18), 'image' => $g->pick($assets), 'flip' => $g->chance(0.5)],
        'cards' => ['heading' => ucfirst($g->words(3)), 'items' => $g->pickMany($ids['articles'], 6)],
        'latest_articles' => ['heading' => ucfirst($g->words(3)), 'count' => $g->pick([3, 6]), 'category' => $g->chance(0.5) ? $g->pick($categories) : null],
        'products' => ['heading' => ucfirst($g->words(3)), 'items' => $g->pickMany($ids['products'], 4)],
        'quote' => ['quote' => $g->sentence(20), 'person' => $g->pick($ids['people'])],
        'stats' => ['heading' => ucfirst($g->words(3)), 'stats' => $grid(3, 4, fn () => ['label' => ucfirst($g->words(2)), 'value' => (string) $g->int(10, 9000)])],
        'faq' => ['heading' => ucfirst($g->words(3)), 'questions' => $grid(4, 7, fn () => ['question' => ucfirst($g->words(6)).'?', 'answer' => $g->sentence(24)])],
        'cta' => ['heading' => ucfirst($g->words(4)), 'text' => $g->sentence(14), 'button_label' => ucfirst($g->words(2)), 'button_link' => $anyPage()],
        'gallery' => ['heading' => ucfirst($g->words(2)), 'images' => $g->pickMany($assets, 8)],
        'logos' => ['heading' => ucfirst($g->words(3)), 'logos' => $g->pickMany($assets, 6)],
        'team' => ['heading' => ucfirst($g->words(3)), 'members' => $g->pickMany($ids['people'], 4)],
        'features' => ['heading' => ucfirst($g->words(3)), 'items' => array_map(fn () => ['id' => $g->setId(), 'type' => 'feature', 'enabled' => true, 'icon' => $g->word(), 'title' => ucfirst($g->words(3)), 'text' => $g->sentence(14)], range(1, $g->int(3, 6)))],
    };
}

/**
 * A breadth x depth nav tree: about 70% entry links, 30% plain URLs. The first
 * branch at each level follows $chain (a real page and its ancestors), so
 * is_current and is_parent have something to match.
 */
function navBranches(Gen $g, int $breadth, int $depth, int $level, array $linkable, array $chain, int &$count): array
{
    $branches = [];
    for ($i = 0; $i < $breadth; $i++) {
        $count++;
        $branch = ['id' => $g->uuid()];
        if ($i === 0 && isset($chain[$level - 1])) {
            $branch['entry'] = $chain[$level - 1];
        } elseif ($g->chance(0.7)) {
            $branch['entry'] = $g->pick($linkable);
        } else {
            $branch['url'] = 'https://example.com/'.$g->word();
            $branch['title'] = ucwords($g->words(2));
        }
        $branch['data'] = array_filter(['icon' => $g->word(), 'badge' => $g->chance(0.2) ? $g->pick(['new', 'hot']) : null]);
        if ($level < $depth) {
            $branch['children'] = navBranches($g, $breadth, $depth, $level + 1, $linkable, $i === 0 ? $chain : [], $count);
        }
        $branches[] = $branch;
    }

    return $branches;
}

/** A solid-colour PNG, built by hand so the generator doesn't need ext-gd. */
function png(int $w, int $h, int $r, int $g, int $b): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $row = "\0".str_repeat(chr($r).chr($g).chr($b), $w);

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress(str_repeat($row, $h)))
        .$chunk('IEND', '');
}

function dump(array $data): string
{
    return Yaml::dump(array_filter($data, fn ($v) => $v !== null), 100, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
}

function writeYaml(string $path, array $data): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, dump($data));
}

function writeEntry(string $path, array $data): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "---\n".dump($data)."---\n");
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
