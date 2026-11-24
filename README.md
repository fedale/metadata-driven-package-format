# fedale/metadata-driven-package-format

The package format of [metadata-driven](../metadata-driven): what a package looks like, and the
tools to read, check, pack and list one. metadata-driven installs and upgrades packages with it;
the package catalog checks them with it on publish.

It is data only: a package holds objects, forms, views, translations, seeds and workflows, never
code.

Until 1.2 this library was `fedale/metadata-driven-template-format`, namespace
`Fedale\TemplateFormat`, and packages were called templates. 2.0 is the same library under its new
name: see [Format v2](#format-v2).

## What it gives you

| Class | What it does |
|---|---|
| `PackageDirectory` | Reads a package from a directory into a `Package` |
| `Archive` | Packs a package as a reproducible `.tar.gz`, and unpacks one safely (no absolute paths, no `..`, no links, size limits) |
| `PackageChecker` | The structural checks: every document against its JSON schema, unique ids, references, seeds, files. Returns a list of `Problem` |
| `DocumentCodec` | A document's YAML envelope (`format`, `kind`, `id`, `spec`) |
| `RepositoryIndex`, `IndexEntry` | The index of a package repository (`metadata-driven/index/v1`): versions, archive URLs, checksums, signatures, yanked versions |
| `Signature` | Ed25519 signatures of index entries (libsodium) |
| `SeedRecords` | The records of a seed, inline or from a CSV file |
| `FieldTypes\FieldTypeCatalog` | The field types of format v1 and their settings, so settings are checked without metadata-driven |
| `Constraint` | A version constraint of `spec.requires`: `^1.2`, `~1.2` or an exact `1.2.3` |
| `Versions\ItemStates`, `Versions\VersionCheck` | A package's items (each object, field, view, form, workflow, translation) in a form that compares equal when they mean the same, and whether a version's number fits what it changed against an earlier version: a patch changes labels, layouts, forms, translations and a workflow's description; a minor adds and changes settings and a workflow's definition; removals, renames (a workflow's too: it is called by its name) and migration steps need a major |

The schemas live in `src/Resources/schema/v2/`, the field types in
`src/Resources/field-types.v1.json`.

```php
use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\FieldTypes\FieldTypeCatalog;
use Fedale\PackageFormat\PackageChecker;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\SchemaValidator;
use Fedale\PackageFormat\SeedRecords;

$package = (new PackageDirectory(new DocumentCodec()))->read('/srv/packages/shop');
$problems = (new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()))->check($package);
```

### Packages requiring packages

A package may require others, with a constraint each:

```yaml
spec:
  requires:
    format: metadata-driven/v2
    acme/places: ^1.0
```

It then references their objects and fields by id, as its own (a relation's `target`, a form's
`object` and `via`, a seed's references by the key of the required seed), and only references
them: its ids differ from theirs, its views and seeds are of its own objects. Check it with the
required packages, as installed or published:

```php
$problems = $checker->check($people, [$places]);   // acme/places in a version ^1.0 allows
```

A requirement not given is a problem: the references to it cannot be checked.

Checking a new version's number against the previous one:

```php
use Fedale\PackageFormat\Versions\ItemStates;
use Fedale\PackageFormat\Versions\VersionCheck;

$states = new ItemStates(new FieldTypeCatalog());
$problems = VersionCheck::problems($states->of($previous), $states->of($next), '1.2.0', '1.3.0', migrates: false);
// e.g. "objects/product.yaml: field acme.product.photo: a removal needs a new major version, 1.3.0 is not one."
```

Seed records are not compared: a version that only changes records fits any number.

### Keys

Since 2.4, an object may say the type of its records' id, its key: `uuid` (made by
metadata-driven, the default when left out) or `integer` (numbered by the database). A version
changing an object's key needs a new major version, as a rename does: every table pointing at the
object holds keys of its type. A key left out and `uuid` are the same.

```yaml
format: metadata-driven/v2
kind: Object
id: acme.ticket
spec:
  name: ticket
  label: Ticket
  plural: Tickets
  key: integer
  fields: []
```

### Tags fields

Since 2.5, a field may be of type `tags`: a short list of free text labels on a record, stored
as one JSON array. Its settings are `options` (the labels the form suggests, a list of strings),
`restrict` (only those are allowed), `max` (the most labels, 1 to 100) and `maxLength` (the
longest label, 1 to 100 characters, 50 by default). A seed gives its value as a YAML list, or in
a CSV file as the labels separated by `|`. An instance on an earlier version refuses the package,
naming the type it doesn't know.

```yaml
format: metadata-driven/v2
kind: Object
id: acme.ticket
spec:
  name: ticket
  label: Ticket
  plural: Tickets
  fields:
    - { id: acme.ticket.labels, name: labels, label: Labels, type: tags, settings: { options: [urgent, later], max: 5 } }
```

### Workflows

Since 2.3, a package may carry workflows (`workflows/<name>.yaml`, kind `Workflow`): its name,
its description, and its definition as metadata-driven takes it, the objects of its trigger and
steps, and the workflow each `run_workflow` step calls, given as ids (fields by name, within
their object). The checker resolves those ids, in the package and the packages it requires; the
rest of the definition is metadata-driven's to check. The platform's events are not allowed:
they are the system admin's. metadata-driven installs a package's workflows as drafts.

```yaml
format: metadata-driven/v2
kind: Workflow
id: acme.workflow.restock
spec:
  name: Restock
  definition:
    trigger: { on: updated, object: acme.product, when: after, fields: [seats] }
    steps:
      - { key: empty, type: filter, when: { field: $trigger.record.seats, op: eq, value: 0 } }
      - { key: tag, type: create_record, object: acme.tag, values: { name: 'restock {{ $trigger.record.sku }}' } }
```

What only metadata-driven can tell (a form that does not fit its objects, a value that does not
fit its field) is checked there, at install.

## Format v2

Format v2 (`format: metadata-driven/v2`) renames what said "template" in v1:

| v1 | v2 |
|---|---|
| the manifest `template.yaml`, of kind `Template` | `package.yaml`, of kind `Package` |
| a view's `template` (its HTML layout or its grid YAML) | its `source` |
| `requires: { format: metadata-driven/v1 }` | `requires: { format: metadata-driven/v2 }` |

Everything else is the same. **v1 is still read**: `PackageDirectory` takes `template.yaml` when
there is no `package.yaml`, and `DocumentCodec` reads a v1 document as its v2 equivalent, so
nothing past the reader sees v1. Everything is written in v2.

The index (`metadata-driven/index/v1`) lists the packages under `packages`. Each version says the
package format it is written in (`requires.format`; none means v1): `RepositoryIndex::latest()`
picks among the versions of a format this library reads, v1 or v2.

## Requirements

PHP 8.4 with `sodium` and `zlib`; `symfony/yaml` and `opis/json-schema`. Nothing else: no
framework, no database.

## Development

```bash
composer install
composer qa    # php-cs-fixer (dry run), PHPStan at level max, PHPUnit
```

The format is documented in metadata-driven's `docs/templates.md`.
