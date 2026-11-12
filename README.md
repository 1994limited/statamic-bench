# statamic-bench

A test bench for Statamic performance work. It builds a real Statamic app around a generated content site, then lets you:

- **time** the Stache build, page renders and entry saves
- **compare** two versions of `statamic/cms` (a release against your branch) with alternating runs
- **check** that both versions produce the same HTML and the same Stache contents
- **verify** behaviour that faster code has broken before
- run the pages through a long-lived **Laravel Octane** worker and look for stale or leaked state
- **profile** any scenario with Xdebug and see where the time goes

It exists because a performance change needs three answers: is it faster, is the output still identical, and does it still behave when the process lives longer than one request.

## Requirements

PHP 8.3 or newer with the extensions Statamic needs, Composer, `rsync` and `patch`. `profile` also needs Xdebug and Python 3. Developed on macOS; nothing in it is macOS-only.

## Quick start

```bash
# a baseline: the released version
bin/bench setup stock --cms=6.40.0

# your change: the same release with a local statamic/cms checkout copied over it
bin/bench setup mine --cms=6.40.0 --src=~/code/cms

bin/bench check stock mine      # same HTML? same Stache?
bin/bench verify mine           # behaviour checks
bin/bench compare stock mine    # is it faster?
bin/bench octane stock mine     # does it behave in a long-lived worker?
bin/bench profile mine article  # where does the time go?
```

Each target is a complete app in `targets.noindex/<name>`. After changing the code in your checkout, run `bin/bench sync mine` to copy it over again.

Other ways to point a target at code:

```bash
bin/bench setup pr --cms=dev-my-branch --repo=https://github.com/you/cms   # a fork's branch
bin/bench setup patched --cms=6.40.0 --patch=fix.patch                     # a patch file
```

`--src` copies `src`, `config`, `routes`, `lang` and the views from your checkout over the installed package and leaves the built control panel assets alone. It does not install your branch's `composer.json` requirements, so base the target on the release your branch started from.

## The site it generates

A two-language marketing and content site, written straight to disk as YAML and Markdown by a seeded generator (`app/bench/generate.php`). The same seed and size always give the same bytes, and the generator never goes through Statamic's API, so the code under test cannot change its own fixture.

| size | articles | products | events | people | pages | tags |
|---|---|---|---|---|---|---|
| `micro` | 50 | 15 | 10 | 6 | 16 | 12 |
| `small` (default) | 200 | 60 | 40 | 12 | 23 | 30 |
| `medium` | 2,000 | 300 | 200 | 30 | 85 | 80 |
| `large` | 9,000 | 1,500 | 600 | 60 | 247 | 200 |
| `huge` | 45,000 | 3,000 | 1,500 | 120 | 247 | 400 |

`huge` is about 50,000 entries. It takes minutes to build, so it's for seeing how a cost grows rather than for everyday comparisons.

```bash
bin/bench generate stock --size=medium
bin/bench generate stock --size=small --articles=5000   # override any one count
bin/bench generate stock --size=small --awkward          # add the awkward content (below)
bin/bench generate stock --size=small --assets=20000    # more assets in the container
```

`compare`, `check` and `octane` also say when two targets' `config/` files differ, which happens when they were set up from different versions of the skeleton, and `run` and `compare` say when the machine is too busy for small differences to mean anything.

Targets you compare must hold the same content; `compare`, `check` and `octane` refuse to run otherwise. That's checked against a fingerprint of the files the generator wrote, not only against what it was asked for.

The checks change content, because that is what they test, and put it back through Statamic, which may write the same data in another order or be stopped half way. Statamic also lists files by modified time, so every content file is given the same time: otherwise two targets generated a minute apart return unordered queries in different orders, which looks exactly like a change in the code under test. So each target keeps a copy of what the generator wrote (`bench/pristine`), and every command that reads the content starts and ends by making sure the files are that, byte for byte, putting them back if they aren't.

What's in it, and why:

- **Pages**: a structured collection with a page builder (a Replicator with 14 block types, one nested), in English and French. The French pages are localisations that override two fields and inherit the rest from their origin.
- **Articles**: dated, mounted on the `/articles` page, Bard content with four set types, an author, two taxonomies, related articles.
- **Products, events, people**: grids, assets, entry relationships. Events use `/events/{year}/{month}/{slug}`; people use `/team/{{ role | slugify }}/{{ slug }}`, a route that needs Antlers to build.
- **Navigation**: a three-level main nav (155 nodes) and a footer nav, with per-node fields. One branch follows a real page and its ancestors so `is_current` and `is_parent` have something to match.
- **Globals, 12 categories, tags, 40 assets** with metadata.
- **65 Antlers templates**, shaped like a real site's: a layout, partials, reusable components (some with slots), one partial per block and per Bard set, paginated listings, taxonomy pages.

### Awkward content

A tidy generated site hides most of what breaks a performance change. `--awkward` adds the content real sites have, on top of any size. Each item is there because something like it got past this bench once, or would have:

- **Hand-written YAML** (the `notes` collection): comments, Windows line endings, anchors and merge keys, flow collections over several lines, block scalars with every chomping indicator, escapes in double quotes, keys that aren't plain identifiers, empty and null values, every scalar type, Unicode. Statamic's own dumper writes none of these, so a parser that's only been fed generated content has never seen them. What each file should hold is worked out with Symfony's parser when the content is generated.
- **Ids that are numbers** or strings of digits, **two files with the same id**, files with the **same name in different folders**, and **one entry of a megabyte or more**.
- **Computed values that query their own collection**, declared as indexes the way a site does for a field it filters by. One is on a mounted collection and one on a collection with no mount or structure, because what goes wrong differs.
- **Drafts, scheduled entries**, dates to the minute and to the second in file names, and two entries that want the same file name.
- **A one-level orderable collection** with an entry its tree leaves out, **a collection with no route**, and entries that **redirect**.
- **A taxonomy of a thousand terms** that entries really use, some with no term file, with French titles.
- **Relations** from a French entry to English ones, and to an entry that doesn't exist.
- **A six-level chain of pages** and a six-level nav with entries, links, bare titles and a missing entry.
- **2,000 assets** in folders (`--assets=N` for more). Entries only point at the first 40; the rest are there to be listed, counted and built.
- **A field holding every type of value** (null, false, true, 0, "0", 1, strings, an empty string), for filters and sorting to disagree about.

Everything in it is something stock Statamic builds without complaint, and nothing in it is rewritten when Statamic reads it, so the fixture stays the same bytes.

## Scenarios

| scenario | what it does |
|---|---|
| `build` | Builds the Stache from nothing in a fresh process (`stache:warm` after a deploy) |
| `parallel_build` | The same build with Statamic's parallel warming switched on. Reported when it falls back to the ordinary build, which Statamic does quietly if a worker fails |
| `cold_query` | The first query on a Stache with nothing built, timed alone: a listing of twelve articles by title. A change can make the full build faster and this slower |
| `cold` | One request for the home page with nothing cached at all |
| `home`, `page_builder`, `page_simple`, `page_deep`, `page_french` | Pages from the structured collection |
| `article`, `articles_index`, `articles_page_3`, `category`, `tag` | An article, paginated listings, taxonomy term pages |
| `product`, `products_index`, `event`, `events_index`, `team_index`, `person` | The other collections |
| `not_found` | A 404 |
| `cp_collections`, `cp_listing`, `cp_listing_search`, `cp_edit_form`, `cp_edit_page_builder`, `cp_page_tree`, `cp_assets` | Control Panel requests through the real controllers, signed in as a generated super user: listings as the panel asks for them (JSON), edit forms, the asset browser |
| `note`, `note_big`, `note_french`, `faq`, `notice_past`, `notice_scheduled`, `notice_draft`, `cp_assets_folder`, `cp_listing_mixed` | With `--awkward` content only |
| `save` | Updates 5 articles and creates 5 through the API |

Page scenarios run with everything warm, **one PHP process per request**, the way PHP-FPM serves them: statics, singletons and the Stache's in-memory indexes start empty every time, and only what's on disk carries over. Compiled code comes from an opcache file cache, standing in for FPM's shared memory. Booting the app and handling the request are timed separately (`boot ms` and `median ms`).

```
scenario         median ms    min    max  boot ms  peak MB
---------------  ---------  -----  -----  -------  -------
build                1,044  1,044  1,182              73.2
cold                 1,572  1,549  1,595    148.8     77.4
home                 348.5  342.4  362.7     65.0     28.3
page_builder         384.8  373.8  391.3     65.9     31.3
article              278.8  275.9  298.3     65.4     30.2
articles_index       323.2  312.5  326.5     64.3     28.2
save                 170.3  167.6  211.4               8.4
```

(Statamic 6.40.0, `small`, PHP 8.5, an M-series Mac. Your numbers will differ; only compare numbers taken on the same machine in the same session.)

Useful flags: `--only=home,article`, `--skip=build,cold,save`, `--r=20` for more samples.

Two more that apply to every command:

- `--memory-limit=512M` runs every PHP process under that limit, the workers of a parallel build included. Without it the limit is 8 GB, which no server has. A change that only works with unlimited memory looks fine until it's deployed.
- `--timeout=300` is how long any one process the bench starts may run, in seconds (900 by default), Composer and the generator included. A process that's still going is stopped, along with anything it started, the content is put back, and the run fails, saying so. Performance changes have made Statamic loop before; a bench that hangs has told nobody anything.

The Control Panel is switched off for every scenario but its own, and its licence check is answered locally, so no bench process makes a request to anywhere.

## Comparing two versions

```bash
bin/bench compare stock mine --rounds=3 --r=10
```

Each round runs every scenario on one target, then the other, swapping the order each round, so a machine that warms up or gets busy halfway through affects both. The table shows the median of every sample, the difference, and a noise figure (median absolute deviation of both targets' samples). A difference smaller than twice the noise, or smaller than 2%, is reported as `same`. Peak memory is compared too.

Close other work first. A loaded machine can easily move a scenario by 10%.

## Checking the output

```bash
bin/bench check stock mine
```

Renders every scenario page and every Control Panel scenario in both targets and compares them byte for byte (after masking CSRF and other per-request tokens), then compares what each Stache holds: every entry's URL, status, date, order, origin, parent and data, every term, and every nav and collection tree. Both versions are written to `results/check/`.

It also compares:

- **a fixed list of indexes** for every collection, by value. Which indexes exist depends on what's been queried, so the same ones are asked for on both sides;
- **the ids a spread of queries return, in order**: filters on a field that holds every type of value, sorts with ties, statuses, taxonomies, date ranges, pagination, terms and assets. A query that throws is recorded as the exception, so the two versions have to agree on that too.

## Behaviour checks

```bash
bin/bench verify mine
```

These guard things that performance changes have actually broken, or nearly did:

- **Change tracking**: entries are clean when read; setting a value dirties that field only; `getOriginal()` survives changes to nested arrays and dates; saving cleans the entry and updates the indexes.
- **Other processes**: a save in another process is visible to this one, and the other way round.
- **Localisations**: a localised entry inherits from its origin; changing the origin is tracked and the original still points at the old one; an unsaved origin change doesn't leak into the next read.
- **URLs**: every entry's URL equals its route worked out by hand, including nested pages, date parts and the Antlers route.
- **Moved content**: renaming the page a collection is mounted on changes the URL of every entry in that collection, and moving a page changes its children's URLs; both go back when it's undone.
- **Queue workers**: a long-lived process run the way `queue:work` is, so Statamic's reset between jobs happens. Between jobs another process saves, creates, renames and deletes entries and renames a mount page, and the next job has to see each.
- **Zero-downtime deploys**: pages render the same after the app's directory is moved, because the Stache was built under a path that no longer exists.
- **Awkward content** (with `--awkward`): every hand-written file holds what Symfony's parser says it should; number ids are found and keep their type; one duplicate is kept and the other recorded; computed indexes hold the right values, and still do when a query on one is the first thing to touch an unbuilt Stache; statuses, dates, orders, redirects, term counts and relations are what the generator says they are.

Any check that takes ten seconds or more says how long it took.

## Octane

```bash
bin/bench octane mine          # one target against fresh processes
bin/bench octane stock mine    # and against another target's worker
```

This drives `Laravel\Octane\Worker` itself, in process, with a client that hands it requests and keeps the responses. The per-request lifecycle is Octane's own (a sandboxed copy of the app, its listeners, its clean-up) without needing Swoole, RoadRunner or FrankenPHP. It does not cover concurrency. `laravel/octane` is installed into the target the first time you run this.

It reports the time per request in the worker next to a fresh process, memory after the second round and after the last, whether each page's HTML matches a fresh process, and these checks:

- a title saved by another process shows on the worker's next request
- a date changed by another process reorders the worker's listing
- after that process puts things back, the worker gives the HTML it gave at the start
- request state doesn't carry over (each page knows its own URL)
- an entry **created** by another process has a page on the worker's next request, one **renamed** is at its new URL and gone from the old, and one **deleted** is gone
- after another process renames the page a collection is mounted on, its entries are at the new URLs
- an index first built **after** another process saved holds the saved value. This is the one that catches entries or snapshots cached on a long-lived object: the worker does the cold build, another process saves, then the worker needs an index it hasn't built yet.

Statamic 6.41.0 does not pass all of this on its own. An entry created by another process is a 404 in a worker that was already running, because a store keeps the list of paths it read first ([statamic/cms#15857](https://github.com/statamic/cms/issues/15857)). And its `Cascade` is a singleton created with the worker's boot request, so in a worker every page gets the home page's `is_homepage` and `segment_1`. That is why the two-target form exists. It only fails when your target does worse than the baseline: a page whose worker HTML differs from the baseline's, or a check the baseline passes and yours doesn't.

## Profiling

```bash
bin/bench profile mine article --top=40
bin/bench profile mine build
bin/bench profile mine article --callees='Structure->toArray'
bin/bench profile mine article --keep     # keep the raw cachegrind file for KCachegrind
```

Runs the scenario under Xdebug's profiler and prints the functions with the most self time, the Statamic functions with the most inclusive time, and the heaviest call path. Xdebug is found through `php -m`, Laravel Herd's bundled copy, or `XDEBUG_SO=/path/to/xdebug.so`.

Xdebug slows everything down many times over and charges function calls more than they really cost, so small functions called often look worse than they are. Use the profile to find candidates, then measure the change with `compare`.

## How it keeps runs fair

- If PHP can run the app with no `php.ini` at all (`php -n`), it does, so nothing in your local config (Xdebug, a prepend file, `pcre.jit=0`) can skew a run. Otherwise it uses your config with the settings that matter overridden.
- Every target gets the same settings: opcache on, JIT off, `APP_DEBUG=false`, static caching off, Stache watcher off, no dev packages.
- Each timed request boots with its own URL bound as the request, as a web server would, because Statamic reads the request while booting.
- App directories end in `.noindex` so Spotlight doesn't index them mid-run.

Set `BENCH_PHP=/path/to/php` to choose the PHP binary.

## Layout

```
bin/bench            the command
app/                 copied into every target
  bench/             generate.php, generate_awkward.php, fingerprint.php, request.php, build.php, query.php, save.php, checks.php, worker.php, queue.php, dump.php, edit.php
  resources/views/   the site's templates
  routes/web.php     two bench-only routes used by the Octane checks, and the awkward content's computed values
tools/               the cachegrind summary script
targets.noindex/     the apps (not committed)
results/             JSON results, rendered HTML, profiles (not committed)
```

## Licence

MIT
