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

```bash
bin/bench generate stock --size=medium
bin/bench generate stock --size=small --articles=5000   # override any one count
```

Targets you compare must hold the same content; `compare`, `check` and `octane` refuse to run otherwise.

What's in it, and why:

- **Pages**: a structured collection with a page builder (a Replicator with 14 block types, one nested), in English and French. The French pages are localisations that override two fields and inherit the rest from their origin.
- **Articles**: dated, mounted on the `/articles` page, Bard content with four set types, an author, two taxonomies, related articles.
- **Products, events, people**: grids, assets, entry relationships. Events use `/events/{year}/{month}/{slug}`; people use `/team/{{ role | slugify }}/{{ slug }}`, a route that needs Antlers to build.
- **Navigation**: a three-level main nav (155 nodes) and a footer nav, with per-node fields. One branch follows a real page and its ancestors so `is_current` and `is_parent` have something to match.
- **Globals, 12 categories, tags, 40 assets** with metadata.
- **65 Antlers templates**, shaped like a real site's: a layout, partials, reusable components (some with slots), one partial per block and per Bard set, paginated listings, taxonomy pages.

## Scenarios

| scenario | what it does |
|---|---|
| `build` | Builds the Stache from nothing in a fresh process (`stache:warm` after a deploy) |
| `cold` | One request for the home page with nothing cached at all |
| `home`, `page_builder`, `page_simple`, `page_deep`, `page_french` | Pages from the structured collection |
| `article`, `articles_index`, `articles_page_3`, `category`, `tag` | An article, paginated listings, taxonomy term pages |
| `product`, `products_index`, `event`, `events_index`, `team_index`, `person` | The other collections |
| `not_found` | A 404 |
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

Renders every scenario page in both targets and compares the HTML byte for byte (after masking CSRF tokens), then compares what each Stache holds: every entry's URL, status, date, order, origin, parent and data, every term, and every nav and collection tree. Both versions are written to `results/check/`.

## Behaviour checks

```bash
bin/bench verify mine
```

These guard things that performance changes have actually broken, or nearly did:

- **Change tracking**: entries are clean when read; setting a value dirties that field only; `getOriginal()` survives changes to nested arrays and dates; saving cleans the entry and updates the indexes.
- **Other processes**: a save in another process is visible to this one, and the other way round.
- **Localisations**: a localised entry inherits from its origin; changing the origin is tracked and the original still points at the old one; an unsaved origin change doesn't leak into the next read.
- **URLs**: every entry's URL equals its route worked out by hand, including nested pages, date parts and the Antlers route.
- **Zero-downtime deploys**: pages render the same after the app's directory is moved, because the Stache was built under a path that no longer exists.

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
- an index first built **after** another process saved holds the saved value. This is the one that catches entries or snapshots cached on a long-lived object: the worker does the cold build, another process saves, then the worker needs an index it hasn't built yet.

Statamic 6.40.0 does not pass all of this on its own: its `Cascade` is a singleton created with the worker's boot request, so in a worker every page gets the home page's `is_homepage` and `segment_1`. That is why the two-target form exists. It only fails when your target does worse than the baseline: a page whose worker HTML differs from the baseline's, or a check the baseline passes and yours doesn't.

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
  bench/             generate.php, request.php, build.php, save.php, checks.php, worker.php, dump.php, edit.php
  resources/views/   the site's templates
  routes/web.php     two bench-only routes used by the Octane checks
tools/               the cachegrind summary script
targets.noindex/     the apps (not committed)
results/             JSON results, rendered HTML, profiles (not committed)
```

## Licence

MIT
