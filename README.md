# CakePHP to Static

A CakePHP 5 plugin for assessing and exporting public applications without a PHP
server or runtime database. **Early prototype: explicit approval and artifact review are required.**

## The approach

A small database is not, by itself, evidence that a site is static-compatible.
What matters is whether public responses can be generated ahead of time, without
visitor-specific state or runtime side effects.

There are two different deployment strategies:

1. **Pre-rendered HTML (preferred starting point):** run CakePHP and its database
   during a build, render public pages, then deploy HTML and assets. Existing ORM
   queries and templates can often stay unchanged. Rebuild when content changes.
2. **Public JSON plus browser rendering:** explicitly export approved fields and
   implement client-side rendering. JSON alone cannot run CakePHP controllers,
   PHP templates, authentication, or form handling in a browser.

Never export an entire database just because its rows are reachable by application
code. Public pages may deliberately omit email addresses, internal comments, or
other private fields. Database access happens only for explicitly configured JSON
datasets or in the host application's normal rendering; analysis never reads records.

## Requirements

- PHP 8.1+ and a compatible CakePHP 5 release
- Composer

## Installation

The repository is not automatically published to Packagist. Until a release is
submitted, add a Composer VCS repository in the host application:

```sh
composer config repositories.cakephp-to-static vcs https://github.com/umer936/cakephp-to-static.git
composer require --dev umer936/cakephp-to-static:dev-main
bin/cake plugin load CakephpToStatic
```

For unpublished local development, use a Composer `path` repository pointing to
your checkout instead of the VCS repository. Install this package as a development
dependency; the deployed static site will not need it.

## Usage

From a host CakePHP application with the plugin loaded:

```sh
bin/cake to_static analyze
bin/cake to_static analyze --format json
bin/cake to_static analyze --fail-on-review
bin/cake to_static analyze --root /path/to/another/app
```

On Windows, use `bin\cake.bat` instead of `bin/cake`.

The command inspects PHP files under `src/`, `templates/`, and `config/` using a
PHP syntax tree. Comments and string contents do not trigger rules. Reports
contain rule identifiers, relative filenames, source lines, and review guidance,
not snippets of source code or database contents.

- `info`: a database read or configuration write may be satisfied at build time.
- `review`: possible writes, request/session/cookie dependencies, forms, security
  middleware, dynamic calls, nondeterministic functions, or incomplete analysis.
- `no-obvious-blockers`: no review findings were found, **not certification**.
- `needs-review`: investigate the reported behavior before attempting export.

By default findings do not fail the command. `--fail-on-review` returns exit code
1 when review findings exist. Operational failures also return exit code 1.

## Setup wizard and static export

Run `bin/cake to_static setup` to approve page paths, webroot assets, and optional
JSON datasets interactively. The wizard writes `config/to_static.json` after
explicit confirmation. Review and commit that file; CI does not need a wizard.
It never selects dataset fields automatically. A dataset publishes **all rows**
returned by the selected model's default query, so choose only genuinely public
models; row-level authorization is not inferred.

An HTML-only configuration might be:

```json
{
    "pages": ["/", "/science", "/instrument", "/publications", "/teams"],
    "assets": ["css", "img", "favicon.ico", "files/public-paper.pdf"],
    "datasets": []
}
```

An optional dataset entry uses `{"table":"Team","fields":["first_name","country"],"path":"data/team.json"}`.
Only those literal columns are queried and projected into JSON; wildcards and SQL
expressions are rejected. This does **not** redact pages: queries and templates
must also restrict the fields rendered into HTML. No JSON or database dump is
needed when the existing templates already render all approved content.

Start a non-debug build instance with its database available, then export:

```sh
bin/cake server -H 127.0.0.1 -p 8765
# In another terminal (or a later CI step):
bin/cake to_static export --base-url http://127.0.0.1:8765 --output dist
```

Output must be a **new directory outside webroot**, with an existing parent. No
existing directory is overwritten. Failed exports remove their partial output.
Deploy **only its contents**, never the project directory. Extensionless pages
become `path/index.html`; configure static hosting to serve directory indexes.
Rebuild when source or content changes. Browser-side libraries such as DataTables
continue to work with pre-rendered rows and approved JavaScript assets.

Export refuses unsuccessful responses, redirects, cookies, private/no-store
caching, and cookie/authorization-dependent `Vary`. Each page is fetched with a
fresh anonymous client. Missing local HTML links/assets, query-dependent local
links, base elements, output collisions, traversal paths, hidden files, SQL/PHP
assets, symlinks, and build-host URLs in HTML URL attributes fail the export.
Only approved static file extensions are copied; broad directories still need
human review (a public-looking image or PDF can contain sensitive data).

The first exporter intentionally supports only root-mounted sites, extensionless
page paths, explicit URL lists, and UTF-8/ordinary HTML. It does not crawl, rewrite
absolute production URLs, reproduce HTTP headers/redirects, check remote CDNs,
execute JavaScript, or validate CSS URLs, `srcset`, inline scripts/styles, or
JavaScript-generated URLs. Review those separately. A database write triggered by
a GET request is still a side effect: audit the application before exporting.

### Limits

This is a heuristic source scan, not whole-program or security analysis. Method
names are matched without resolving receiver types, so false positives are
expected. Custom abstractions and indirect behavior can be missed. It does not
execute requests, enumerate concrete routes, inspect JavaScript, or analyze code
inside dependencies, external plugins, or symlinked directories.

Conventional CLI-only locations (`src/Console`, `src/Command`, `config/Migrations`,
`config/Seeds`, and `config/bootstrap_cli.php`) are excluded. If web code invokes
code from these locations, review it separately. Calls named `Configure::write`
are classified as informational, without resolving the class to its full type.

The host application's normal CakePHP bootstrap still runs before its command;
the analyzer itself does not execute the files it inspects. `--root` changes the
source directory, not the bootstrapped application.

Manual review must cover authorization and private data, parameterized routes,
query strings, redirects and HTTP headers, form/API services, absolute URLs,
CSS/JS/images/downloads, deployment base paths, and content freshness. A form
backed by an external service can coexist with a static site; a public-looking
page dependent on a visitor's session cannot simply be pre-rendered once.

## Planned next steps

- Discover public concrete URLs using an explicit allowlist plus crawl seeds;
  require values for parameterized routes rather than guessing.
- Extend response/asset validation to CSS URLs, `srcset`, and browser behavior.
- Add reviewed row filters and build manifests/content-change detection.
- Test exported artifacts without PHP or a database in deployment pipelines.

## Development

```sh
composer install
composer check
```

Tests use temporary source/export fixtures and require no database or host app.

## Publishing

The package has CakePHP plugin metadata, PSR-4 autoloading, and an Unlicense
license matching this repository. Before a public release, review the API and
limitations, test supported PHP/CakePHP versions, push changes, submit the GitHub
repository to Packagist, and tag a semantic version. This checkout does not
publish or tag anything automatically.
