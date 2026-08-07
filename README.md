# Standard Notes to a clean Obsidian vault

This small command-line tool converts a **decrypted Standard Notes backup** into an Obsidian-friendly folder of ordinary Markdown files.

Each generated `.md` file contains only note content. Ordinary text and Markdown are written byte-for-byte unchanged. Recognized Super Notes and structured task documents are translated from their editor data into readable Markdown. Migration metadata is kept separately in `_standardnotes-export-manifest.json`.

> [!WARNING]
> A decrypted Standard Notes backup and the generated Obsidian vault contain private plaintext. Never commit either one to Git, attach one to an issue, paste one into a chat, or upload one to an untrusted service. Keep them only in locations you trust.

## What the conversion produces

The output is a new directory that can be opened directly as an Obsidian vault. A fictional example might look like this:

```text
My Obsidian Vault/
├── Projects/
│   └── Research/
│       └── Experiment.md
├── _Archived/
│   └── Projects/
│       └── Earlier Draft.md
├── _Trashed/
│   └── Temporary/
│       └── Discarded Idea.md
├── Unfiled/
│   └── Untitled.md
└── _standardnotes-export-manifest.json
```

Trashed notes are omitted by default, so `_Trashed/` only appears when `--include-trashed` is used.

## Clean note files are absolute

The exporter never prepends or appends migration metadata. It does not add:

- YAML frontmatter or `---` delimiters;
- Obsidian properties;
- a title heading;
- dates, UUIDs, tags, status, or pinned information;
- Zettelkasten IDs;
- comments, migration notices, headers, footers, or extra blank lines.

For an ordinary text or Markdown note, the first byte written is the first byte of the decoded Standard Notes body. Markdown, whitespace, line endings represented in the JSON string, Unicode, emoji, code blocks, links, wiki links, and empty bodies are left unchanged.

Recognized Lexical/Super Note and structured task bodies are editor-state JSON rather than readable note text. For those formats only, the exporter writes the user-visible content as Markdown. It still adds no title, frontmatter, dates, identifiers, notices, or other exporter text. The conversion and any limitations are recorded only in the manifest.

Standard Notes stores `content.text` as a JSON string. JSON escaping must be decoded to recover that string. Invalid UTF-8 cannot be represented by valid JSON and is rejected before any vault is written.

## Requirements

- PHP 8.1 or newer with the JSON extension, which is normally included with PHP.
- `mbstring` is recommended for full Unicode case folding when detecting portable filename collisions.
- `intl` is recommended for Unicode NFC normalization.

The exporter still preserves UTF-8 without the optional extensions. On systems without them, unusual filenames that differ only by non-ASCII capitalization or Unicode composition may not receive the same cross-platform collision treatment.

Check your PHP version with:

```bash
php --version
```

## Obtain a decrypted Standard Notes backup

In the Standard Notes desktop or web app:

1. Open **Preferences**.
2. Select **Backups**.
3. In **Data Backups**, choose a **Decrypted** backup.
4. Extract the downloaded ZIP file.
5. Locate the file named similarly to `Standard Notes Backup and Import File.txt`.

Despite its `.txt` name, the import file contains JSON. Standard Notes documents the current backup process in [How do I create and import backups of my Standard Notes data?](https://standardnotes.com/help/14/how-do-i-create-and-import-backups-of-my-standard-notes-data).

Treat the extracted backup as sensitive plaintext. Do not put it inside this repository.

## Convert the backup

Run the command from the directory in which you want relative paths to be resolved:

```bash
php standard-notes-to-markdown.php INPUT_FILE OUTPUT_DIRECTORY
```

For example:

```bash
php standard-notes-to-markdown.php \
  "/trusted/private/location/Standard Notes Backup and Import File.txt" \
  "/trusted/private/location/My Obsidian Vault"
```

Both paths are required. The output directory must not already exist. The exporter will not merge into or partially overwrite an existing vault.

## Command-line options

```text
--dry-run
--include-trashed
--exclude-archived
--folder-separator=.
--standalone-tags=folders
--standalone-tags=metadata
--verbose
--help
```

- `--dry-run` parses the backup, resolves folders and collisions, and validates the complete plan without creating files.
- `--include-trashed` exports trashed notes beneath `_Trashed/`.
- `--exclude-archived` omits archived notes. Archived notes are otherwise included beneath `_Archived/`.
- `--folder-separator=.` changes the separator used for legacy hierarchical tag names. A period is the default.
- `--standalone-tags=folders` treats standalone tags as top-level folder candidates. This is the default.
- `--standalone-tags=metadata` keeps standalone tags only in the manifest. It is the stricter alternative for people who used standalone tags only as keywords.
- `--verbose` prints additional record-number progress. It still never prints note bodies, titles, tags, or UUIDs.
- `--help` displays built-in usage instructions.

Options can be placed before the two paths. An invalid option or value returns a nonzero exit code with a short explanation.

## How folders are reconstructed

Standard Notes represents its hierarchy using tags. Modern exports can contain explicit tag-to-parent-tag references. Older versions represented nested folders using a separator in the tag title, such as `Personal.Financial.Retirement`.

The exporter applies these rules:

1. Explicit `TagToParentTag` relationships are authoritative.
2. A tag that is a parent or child in that relationship becomes a folder path.
3. A legacy separated tag becomes nested directories, such as `Personal/Financial/Retirement/`.
4. By default, a remaining standalone tag becomes a top-level folder candidate.
5. If a note has several candidates, the deepest valid path wins.
6. Equal-depth candidates are resolved by the alphabetically first normalized path.
7. A note with no usable candidate goes into `Unfiled/`.

Every candidate and every original referenced tag remains in the manifest, including candidates that were not selected.

### The unavoidable standalone-tag ambiguity

The Standard Notes export format does not label a standalone tag as either an ordinary keyword or a top-level folder. This fork defaults to `--standalone-tags=folders` because preserving top-level organization is its primary migration goal. That means an ordinary standalone keyword can become a directory.

Use the following when ordinary standalone tags should remain metadata only:

```bash
php standard-notes-to-markdown.php \
  --standalone-tags=metadata \
  INPUT_FILE OUTPUT_DIRECTORY
```

Explicit parent hierarchies and legacy separated tags continue to create folders in strict metadata mode.

Malformed segments, missing parents, cycles, and nonexistent references are recorded as issues without printing private values. Path separators, controls, traversal components, and unsafe names cannot escape the vault.

## Filenames and collisions

The sanitized Standard Notes title becomes the filename:

```text
My Note.md
```

The exporter removes or replaces cross-platform-invalid characters, null bytes, controls, forward and backward slashes, trailing spaces and periods, and Windows reserved names such as `CON`, `AUX`, `COM1`, and `LPT1`. Long names are shortened at a UTF-8 character boundary.

### Blank and Untitled source titles

When the original Standard Notes title is blank after trimming, or is exactly `Untitled` case-insensitively after trimming, the exporter derives the filename from the first meaningful line of the final note body. This happens after Lexical or structured-task conversion, so serialized editor JSON is never used as a filename.

For filename derivation only, the exporter:

- skips blank lines, horizontal rules, code-fence delimiters, table delimiter rows, and punctuation-only structural lines;
- removes Markdown heading, blockquote, bullet, numbered-list, and checklist prefixes;
- removes formatting uses of emphasis, strikethrough, and inline-code markers;
- decodes common HTML entities, removes HTML tags, and collapses internal whitespace;
- then sends the result through the same filename sanitization and length limits as every other title.

The scan is deliberately bounded to the first 100 lines or 65,536 bytes, whichever comes first. If no meaningful line is found within that limit, the filename remains `Untitled`.

This inspection never removes or edits the selected line. Ordinary note bodies remain byte-for-byte unchanged, and converted bodies remain exactly as produced by their content converter. A meaningful original Standard Notes title is never replaced.

There are no creation-date suffixes, timestamps, UUIDs, tags, folder names, or Zettelkasten IDs in a filename.

Collisions are resolved deterministically within a directory:

```text
My Note.md
My Note (2).md
My Note (3).md
```

The same filename can be used in different directories. The exporter validates all final paths before writing and never overwrites one planned note with another.

The summary separately reports filenames derived from body content, notes that still required the `Untitled` fallback, and additional filename collisions introduced by derived titles.

## Archived, trashed, and pinned notes

- Active notes use their selected reconstructed folder.
- Archived notes are included by default beneath `_Archived/`, retaining the reconstructed hierarchy.
- Trashed notes are excluded by default. `--include-trashed` places them beneath `_Trashed/`, retaining the hierarchy.
- If a corrupted record is both archived and trashed, trashed takes precedence.
- Pinned status is stored only in the manifest.

The final report counts active, archived, and trashed records found and exported. It never prints their titles, bodies, tags, or UUIDs.

## Note formats and conversion

The exporter separates formats conservatively. Ordinary text and Markdown are never passed through a formatter. Text-like task notes, HTML-like editor text, malformed JSON, and JSON that does not match a recognized editor schema also remain byte-for-byte unchanged.

### Lexical and Super Notes

A JSON body is treated as Lexical only when it has a valid Lexical editor-state root with a root node, integer version, and ordered child-node array. Recognized documents are converted to GitHub Flavored Markdown using these mappings:

- paragraphs and headings become Markdown paragraphs and headings;
- bold, italic, strikethrough, inline code, underline, subscript, superscript, and highlight are retained; highlight uses `<mark>`;
- links, automatic links, block quotes, fenced code, horizontal rules, bulleted lists, numbered lists, and checklists are retained;
- nested lists retain their order and indentation;
- tables become GFM tables;
- a file node that cannot be resolved from the backup becomes `[Embedded file unavailable from this backup]`. The placeholder contains no file UUID.

Left and start alignment are rendered as ordinary paragraphs. Other block alignment, including centered text, is omitted because portable Markdown has no clean equivalent. No `<div>` or aligned paragraph wrapper is added. The manifest records `block_alignment_omitted`.

GFM requires a table header row. If Lexical does not designate one, the exporter uses the first row as the Markdown header and records `table_header_synthesized_from_first_row`. Column and row spans are approximated because GFM does not represent them; column width, cell background color, and other layout-only details are omitted. Each limitation produces a manifest warning.

Unknown Lexical containers retain any understood child content. A neutral placeholder is used when no content can be understood. Unsupported node types and warnings are recorded without copying body text into the manifest.

### Structured task editor

Task JSON is converted only when both of these checks pass:

1. the note identifies the official task note type and a recognized Standard Notes task-editor identifier;
2. the decoded body has the validated `schemaVersion`, `groups`, and `defaultSections` structure.

Groups become level-two headings and sections become level-three headings. Tasks remain in their source array order and become `- [ ]` or `- [x]` checklist items. Multiline descriptions use Markdown line breaks. A saved group draft is user-authored content, so it becomes an unchecked item immediately after its group heading and before the sections. Empty groups and sections are retained as headings.

The known `1.0.0` schema is fully mapped. A recognized task document with a different or damaged internal structure is never emitted as raw JSON: understood user-visible content is preserved, neutral placeholders are used where necessary, and privacy-safe warnings identify the unsupported structure. A JSON-looking ordinary note without the required task-editor evidence is not treated as a task document.

### Formats left unchanged

- ordinary text and Markdown, including a user body beginning with `---`;
- task-editor notes whose bodies are already text-like;
- HTML-like or other editor-specific text that is not a recognized Lexical document;
- malformed JSON;
- valid JSON that does not match a recognized Lexical or structured-task schema.

The final report provides only aggregate detection, conversion, unsupported-structure, and warning counts. It never prints affected bodies, titles, tags, or UUIDs.

## Metadata manifest

The vault root contains:

```text
_standardnotes-export-manifest.json
```

The pretty-printed UTF-8 JSON document has `schema_version: 2`. Each exported note entry contains:

- Standard Notes UUID, when present;
- original title;
- filename-title source: `standard_notes_title`, `first_meaningful_body_line`, or `fallback_untitled`;
- final relative Markdown path;
- creation and selected modification timestamps;
- filesystem timestamp source;
- every original referenced Standard Notes tag;
- every detected folder candidate and how it was detected;
- selected folder path;
- archived, trashed, and pinned status;
- collision-adjusted filename, when applicable;
- `duplicate_of`, repeated-record count, and detected issues;
- available editor and note-type information;
- detected source format and whether conversion was performed;
- Lexical node types, unsupported node types, and conversion warnings;
- structured-task schema version, group and task counts, unsupported structures, and conversion warnings.

The manifest also documents the folder separator, standalone-tag mode and ambiguity, selection rule, content-conversion policy, bounded filename-title derivation policy, and unresolved global reference counts. It never stores the derived source line in a separate field. The final relative path necessarily contains the selected filename. The manifest contains no note body text or exporter-generated timestamp, so equivalent input and options produce deterministic content.

## Filesystem timestamps

When possible, each Markdown file receives the Standard Notes modification time using this order:

1. valid `content.appData["org.standardnotes.sn"].client_updated_at`;
2. valid top-level `updated_at`;
3. valid top-level `updated_at_timestamp`;
4. a valid creation timestamp.

Creation time is preserved in the manifest. Most portable filesystems do not provide a reliable way for PHP to set creation time.

Malformed or missing dates produce warnings. Failure to set a filesystem modification time does not fail the whole export, because the verified note body and timestamp metadata remain available.

## Duplicate and damaged records

The previous exporter silently replaced earlier records when a UUID appeared more than once. This version uses a data-preserving policy:

- byte-for-byte-equivalent decoded records with the same UUID are exported once and record their source count;
- differing records with the same UUID are all preserved with collision-safe filenames;
- a `duplicate_of` relationship is recorded but does not cause either note to be discarded;
- differing or inconsistent update records are preserved;
- missing or literal `Untitled` titles use a meaningful initial body line when available, otherwise `Untitled`;
- a missing text field becomes an empty file with a manifest warning;
- empty text remains a valid zero-byte Markdown file;
- missing tag/note targets, malformed dates, and broken folder relationships produce issue codes and warning counts;
- an encrypted or otherwise non-object Note payload stops the export, because silently writing it as an empty note could hide data loss.

No warning includes a private body, title, tag, or UUID.

## Output safety

Before writing, the exporter validates the input structure, status rules, folder candidates, sanitized segments, complete relative paths, and filename collisions.

For a real conversion it then:

1. creates a private, randomly named staging directory beside the requested vault;
2. writes each body and verifies its SHA-256 in memory against the written bytes;
3. attempts to set modification times;
4. writes and parses the manifest;
5. renames the complete staging directory to the requested final path.

If an error occurs, only the staging directory created by that run is cleaned up. Existing files and directories are never deleted. Temporary staging names are excluded by `.gitignore`.

## Test the exporter

The tests use only fictional notes, titles, tags, UUIDs, and bodies:

```bash
php -l standard-notes-to-markdown.php
php -l tests/run.php
php tests/run.php
```

The suite checks clean byte-identical ordinary bodies, Unicode, empty content, filename-title derivation, structural-line filtering, derived collisions, unsafe derived names, both standalone-tag modes, modern and legacy folders, statuses, Lexical conversion, structured tasks, task drafts, table and alignment limitations, text-like task preservation, timestamps, corruption, determinism, traversal protection, manifest privacy, and overwrite refusal.

For a manual sanitized dry run:

```bash
php standard-notes-to-markdown.php \
  --dry-run \
  tests/fixtures/comprehensive-export.json \
  test-output/sample-vault
```

For a manual sanitized conversion:

```bash
php standard-notes-to-markdown.php \
  tests/fixtures/comprehensive-export.json \
  test-output/sample-vault
```

`test-output/` is ignored by Git.

## Open the result in Obsidian

After a successful conversion:

1. Start Obsidian.
2. Choose **Open folder as vault**.
3. Select the generated output directory.
4. Review the folder organization, `Unfiled/`, archived notes, and any conversion warnings counted in the report.

Keep the original decrypted backup until the generated files and manifest have been reviewed and backed up securely.

## Known format limitations

- Standalone tags do not reveal whether they were intended as keywords or top-level folders. The CLI option controls the deterministic policy.
- Older dotted tags can be ambiguous when a literal tag name contains the selected separator.
- Missing or cyclic parent references cannot reconstruct a complete authoritative path.
- Lexical conversion preserves understood content but cannot reproduce layout details that portable Markdown does not support. The manifest identifies approximations and omissions.
- Structured-task conversion is based on the known `1.0.0` task schema. Recognized future or damaged structures use a content-preserving fallback and require review.
- Editor formats that are neither recognized Lexical nor recognized structured-task JSON remain unchanged. Format detection is necessarily incomplete.
- Filename derivation examines only the initial 100 lines or 65,536 bytes. A meaningful title beyond that boundary intentionally falls back to `Untitled`.
- Markdown and HTML removal is intentionally conservative and is used only for filenames. Unusual markup can produce a less polished filename, but never changes the note body.
- Only note data stored in a Note item's `content.text` is exported. An unresolved embedded-file node receives a neutral placeholder; the exporter does not extract Standard Notes file payloads or attachments.
- Filesystem creation time is not portably settable.
- Without PHP `mbstring` and `intl`, rare Unicode-only case or composition collisions receive more limited normalization.

These limitations are reported or documented rather than hidden behind invented behavior.

## Provenance and licensing

This repository is a substantially modified fork of [hozza/standardnotes-to-markdown-yaml-export](https://github.com/hozza/standardnotes-to-markdown-yaml-export).

The upstream repository did not include a software license. This fork does not add a license or grant additional rights to the upstream code.
