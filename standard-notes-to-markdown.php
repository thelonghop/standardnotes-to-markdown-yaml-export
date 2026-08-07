<?php

declare(strict_types=1);

const MANIFEST_FILENAME = '_standardnotes-export-manifest.json';
const MANIFEST_SCHEMA_VERSION = 2;
const MAX_FILENAME_BASE_BYTES = 120;
const MAX_FOLDER_SEGMENT_BYTES = 80;
const MAX_RELATIVE_PATH_BYTES = 240;
const MAX_FOLDER_DEPTH = 64;
const MAX_TITLE_DERIVATION_LINES = 100;
const MAX_TITLE_DERIVATION_BYTES = 65536;

final class ExportFailure extends RuntimeException
{
}

function printHelp(): void
{
    $help = <<<'HELP'
Standard Notes to clean Obsidian Markdown

Usage:
  php standard-notes-to-markdown.php [OPTIONS] INPUT_FILE OUTPUT_DIRECTORY

The input must be a decrypted Standard Notes backup JSON file. The output
directory must not already exist.

Ordinary text and Markdown are written byte-for-byte unchanged. Recognized
Lexical/Super Note and structured-task editor data is converted to readable
Markdown. Conversion metadata and privacy-safe warnings go only in the JSON
manifest at the vault root.

Options:
  --dry-run
      Parse and validate the complete export plan without writing anything.

  --include-trashed
      Export trashed notes under _Trashed/. Trashed notes are only counted by
      default and are not written.

  --exclude-archived
      Count archived notes but do not export them. Archived notes are included
      under _Archived/ by default.

  --folder-separator=.
      Character or string used to split legacy dotted folder tags. The default
      is a period.

  --standalone-tags=folders
      Treat standalone tags as top-level folder candidates. This is the
      default and best preserves Standard Notes top-level organization.

  --standalone-tags=metadata
      Keep standalone tags only in the manifest. Notes that have no other
      usable folder candidate are placed in Unfiled/.

  --verbose
      Print privacy-safe diagnostics using record numbers and issue codes.
      Note bodies, titles, tags, and UUIDs are never printed.

  --help
      Show this help.
HELP;

    fwrite(STDOUT, $help . PHP_EOL);
}

/** @return array{options: array<string, mixed>, input: string, output: string, help: bool} */
function parseArguments(array $argv): array
{
    $options = [
        'dry_run' => false,
        'include_trashed' => false,
        'exclude_archived' => false,
        'folder_separator' => '.',
        'standalone_tags' => 'folders',
        'verbose' => false,
    ];
    $positionals = [];
    $help = false;

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];
        if ($argument === '--help' || $argument === '-h') {
            $help = true;
        } elseif ($argument === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($argument === '--include-trashed') {
            $options['include_trashed'] = true;
        } elseif ($argument === '--exclude-archived') {
            $options['exclude_archived'] = true;
        } elseif ($argument === '--verbose') {
            $options['verbose'] = true;
        } elseif (str_starts_with($argument, '--folder-separator=')) {
            $options['folder_separator'] = substr($argument, strlen('--folder-separator='));
        } elseif (str_starts_with($argument, '--standalone-tags=')) {
            $options['standalone_tags'] = substr($argument, strlen('--standalone-tags='));
        } elseif (str_starts_with($argument, '-')) {
            throw new ExportFailure("Unknown option. Run with --help for usage.");
        } else {
            $positionals[] = $argument;
        }
    }

    if ($help) {
        return ['options' => $options, 'input' => '', 'output' => '', 'help' => true];
    }

    if (count($positionals) !== 2) {
        throw new ExportFailure('Expected an input file and an output directory. Run with --help for usage.');
    }

    $separator = $options['folder_separator'];
    if (
        $separator === ''
        || strlen($separator) > 8
        || preg_match('/[\x00-\x1F\x7F\x{2028}\x{2029}\/\\\\]/u', $separator) === 1
    ) {
        throw new ExportFailure('The folder separator must be 1-8 safe characters and cannot contain path separators or controls.');
    }

    if (!in_array($options['standalone_tags'], ['folders', 'metadata'], true)) {
        throw new ExportFailure('The --standalone-tags value must be folders or metadata.');
    }

    return [
        'options' => $options,
        'input' => $positionals[0],
        'output' => $positionals[1],
        'help' => false,
    ];
}

function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function resolvePath(string $path): string
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new ExportFailure('A file path is empty or contains a null byte.');
    }

    $path = str_replace('\\', '/', $path);
    if (!isAbsolutePath($path)) {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new ExportFailure('Could not determine the current working directory.');
        }
        $path = str_replace('\\', '/', $cwd) . '/' . $path;
    }

    $prefix = str_starts_with($path, '/') ? '/' : substr($path, 0, 2) . '/';
    $rest = str_starts_with($path, '/') ? substr($path, 1) : substr($path, 3);
    $segments = [];
    foreach (explode('/', $rest) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                throw new ExportFailure('A requested path escapes its filesystem root.');
            }
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    $resolved = $prefix . implode('/', $segments);
    return $resolved === '' ? '/' : rtrim($resolved, '/');
}

function readBackup(string $inputPath): array
{
    $resolvedInput = resolvePath($inputPath);
    if (!is_file($resolvedInput) || !is_readable($resolvedInput)) {
        throw new ExportFailure('The input file does not exist or is not readable.');
    }

    $json = file_get_contents($resolvedInput);
    if ($json === false) {
        throw new ExportFailure('The input file could not be read.');
    }

    try {
        $backup = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new ExportFailure('The input is not valid JSON.');
    }

    if (!is_array($backup) || !array_key_exists('items', $backup) || !is_array($backup['items'])) {
        throw new ExportFailure('The backup is missing the required top-level items array.');
    }

    return $backup;
}

function normalizeUnicode(string $value): string
{
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (is_string($normalized)) {
            return $normalized;
        }
    }
    return $value;
}

function portableCaseFold(string $value): string
{
    $normalized = normalizeUnicode($value);
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
    }
    return strtolower($normalized);
}

function truncateUtf8Bytes(string $value, int $maximumBytes): string
{
    if (strlen($value) <= $maximumBytes) {
        return $value;
    }

    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) {
        throw new ExportFailure('A title or tag contains invalid UTF-8.');
    }

    $result = '';
    foreach ($characters as $character) {
        if (strlen($result . $character) > $maximumBytes) {
            break;
        }
        $result .= $character;
    }
    return $result;
}

function sanitizeName(string $value, bool $isFilename): ?string
{
    $value = normalizeUnicode($value);
    $value = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '-', $value);
    if ($value === null) {
        throw new ExportFailure('A title or tag could not be sanitized as UTF-8.');
    }
    $value = preg_replace('/[ .]+$/u', '', $value) ?? '';
    $value = ltrim($value, ' ');
    $value = truncateUtf8Bytes($value, $isFilename ? MAX_FILENAME_BASE_BYTES : MAX_FOLDER_SEGMENT_BYTES);
    $value = preg_replace('/[ .]+$/u', '', $value) ?? '';

    if ($value === '' || $value === '.' || $value === '..') {
        return $isFilename ? 'Untitled' : null;
    }

    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/iu', $value) === 1) {
        $value = '_' . $value;
    }

    return $value;
}

function trimVisibleWhitespace(string $value): string
{
    $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);
    return $trimmed ?? trim($value);
}

function isStructuralTitleLine(string $line): bool
{
    if ($line === '') {
        return true;
    }
    if (preg_match('/^(?:`{3,}|~{3,}).*$/u', $line) === 1) {
        return true;
    }
    if (preg_match('/^(?:(?:\*\s*){3,}|(?:-\s*){3,}|(?:_\s*){3,})$/u', $line) === 1) {
        return true;
    }

    $tableLine = trim($line, " \t|");
    if ($tableLine !== '' && str_contains($line, '|')) {
        $cells = array_map('trim', explode('|', $tableLine));
        $delimiterCells = array_filter(
            $cells,
            static fn(string $cell): bool => preg_match('/^:?-{3,}:?$/D', $cell) === 1
        );
        if ($cells !== [] && count($delimiterCells) === count($cells)) {
            return true;
        }
    }
    return false;
}

function removeMarkdownTitlePrefixes(string $line): string
{
    do {
        $previous = $line;
        $line = preg_replace('/^[ \t]{0,3}#{1,6}(?:[ \t]+|$)/u', '', $line) ?? $line;
        $line = preg_replace('/^(?:[ \t]*>[ \t]?)+/u', '', $line) ?? $line;
        $line = preg_replace('/^[ \t]*(?:[-+*][ \t]+)?\[(?: |x|X)\][ \t]+/u', '', $line) ?? $line;
        $line = preg_replace('/^[ \t]*[-+*][ \t]+/u', '', $line) ?? $line;
        $line = preg_replace('/^[ \t]*\d{1,9}[.)][ \t]+/u', '', $line) ?? $line;
        $line = trimVisibleWhitespace($line);
    } while ($line !== $previous);
    return $line;
}

function removeMarkdownTitleFormatting(string $line): string
{
    for ($pass = 0; $pass < 6; $pass++) {
        $previous = $line;
        $line = preg_replace('/(?<!\\\\)(\*\*|__|~~)(?=\S)(.+?\S)\1/u', '$2', $line) ?? $line;
        $line = preg_replace('/(?<![\\\\\p{L}\p{N}])(\*|_)(?=\S)(.+?\S)\1(?![\p{L}\p{N}])/u', '$2', $line) ?? $line;
        $line = preg_replace('/(?<!\\\\)(`+)(?=\S)(.+?\S)\1/u', '$2', $line) ?? $line;
        if ($line === $previous) {
            break;
        }
    }
    return $line;
}

function meaningfulTitleFromLine(string $line): ?string
{
    $line = trimVisibleWhitespace($line);
    if (isStructuralTitleLine($line)) {
        return null;
    }
    $line = removeMarkdownTitlePrefixes($line);
    if ($line === '' || isStructuralTitleLine($line)) {
        return null;
    }
    $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $line = strip_tags($line);
    $line = removeMarkdownTitleFormatting($line);
    $line = preg_replace('/[\s\x{00A0}]+/u', ' ', $line) ?? $line;
    $line = trimVisibleWhitespace($line);
    if ($line === '' || isStructuralTitleLine($line)) {
        return null;
    }
    if (preg_match('/^[\x20-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]+$/D', $line) === 1) {
        return null;
    }
    return $line;
}

function deriveFilenameTitleFromBody(string $body): ?string
{
    if ($body === '') {
        return null;
    }
    $byteLimit = min(strlen($body), MAX_TITLE_DERIVATION_BYTES);
    $excerpt = substr($body, 0, $byteLimit);
    while ($excerpt !== '' && preg_match('//u', $excerpt) !== 1) {
        $excerpt = substr($excerpt, 0, -1);
    }
    $lines = preg_split('/\R/u', $excerpt, MAX_TITLE_DERIVATION_LINES + 1);
    if ($lines === false) {
        return null;
    }
    foreach (array_slice($lines, 0, MAX_TITLE_DERIVATION_LINES) as $line) {
        $candidate = meaningfulTitleFromLine($line);
        if ($candidate !== null) {
            return $candidate;
        }
    }
    return null;
}

/** @return array{title: string, source: string} */
function filenameTitleForNote(string $originalTitle, string $finalBody): array
{
    $trimmedTitle = trimVisibleWhitespace($originalTitle);
    $shouldDerive = $trimmedTitle === '' || portableCaseFold($trimmedTitle) === 'untitled';
    if (!$shouldDerive) {
        return ['title' => $originalTitle, 'source' => 'standard_notes_title'];
    }
    $derived = deriveFilenameTitleFromBody($finalBody);
    if ($derived !== null) {
        return ['title' => $derived, 'source' => 'first_meaningful_body_line'];
    }
    return ['title' => 'Untitled', 'source' => 'fallback_untitled'];
}

function safeRelativePath(array $segments, ?string $filename = null): string
{
    $parts = [];
    foreach ($segments as $segment) {
        if (
            !is_string($segment)
            || $segment === ''
            || $segment === '.'
            || $segment === '..'
            || preg_match('/[\x00-\x1F\x7F\/\\\\]/u', $segment) === 1
        ) {
            throw new ExportFailure('An unsafe export path was detected while validating the plan.');
        }
        $parts[] = $segment;
    }
    if ($filename !== null) {
        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || preg_match('/[\x00-\x1F\x7F\/\\\\]/u', $filename) === 1
        ) {
            throw new ExportFailure('An unsafe export filename was detected while validating the plan.');
        }
        $parts[] = $filename;
    }

    $relative = implode('/', $parts);
    if ($relative === '' || strlen($relative) > MAX_RELATIVE_PATH_BYTES) {
        throw new ExportFailure('A resolved export path is empty or too long for a portable vault.');
    }
    return $relative;
}

function canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $child) {
        $value[$key] = canonicalize($child);
    }
    return $value;
}

function structuralHash(array $record): string
{
    try {
        return hash('sha256', json_encode(canonicalize($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (JsonException $exception) {
        throw new ExportFailure('A backup record could not be represented as valid UTF-8 JSON.');
    }
}

function booleanValue(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function appData(array $content): array
{
    $appData = $content['appData'] ?? [];
    if (!is_array($appData)) {
        return [];
    }
    $standardNotes = $appData['org.standardnotes.sn'] ?? [];
    return is_array($standardNotes) ? $standardNotes : [];
}

/** @return array{date: ?DateTimeImmutable, normalized: ?string} */
function parseTimestamp(mixed $value): array
{
    if (is_int($value) || is_float($value)) {
        $seconds = (float) $value;
        if ($seconds > 100000000000.0) {
            $seconds /= 1000.0;
        }
        if ($seconds < -62135596800 || $seconds > 253402300799) {
            return ['date' => null, 'normalized' => null];
        }
        $formatted = sprintf('%.6F', $seconds);
        $date = DateTimeImmutable::createFromFormat('U.u', $formatted, new DateTimeZone('UTC'));
        if ($date === false) {
            return ['date' => null, 'normalized' => null];
        }
        $date = $date->setTimezone(new DateTimeZone('UTC'));
        return ['date' => $date, 'normalized' => $date->format('Y-m-d\TH:i:s.u\Z')];
    }

    if (!is_string($value) || $value === '') {
        return ['date' => null, 'normalized' => null];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
        return ['date' => null, 'normalized' => null];
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $exception) {
        return ['date' => null, 'normalized' => null];
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return ['date' => null, 'normalized' => null];
    }
    $date = $date->setTimezone(new DateTimeZone('UTC'));
    return ['date' => $date, 'normalized' => $date->format('Y-m-d\TH:i:s.u\Z')];
}

/** @return array{created: ?string, modified: ?string, modified_date: ?DateTimeImmutable, modified_source: ?string, warning: bool} */
function selectTimestamps(array $item, array $content): array
{
    $createdCandidates = [
        'created_at' => $item['created_at'] ?? null,
        'created_at_timestamp' => $item['created_at_timestamp'] ?? null,
    ];
    $created = null;
    foreach ($createdCandidates as $candidate) {
        $parsed = parseTimestamp($candidate);
        if ($parsed['normalized'] !== null) {
            $created = $parsed['normalized'];
            break;
        }
    }

    $domain = appData($content);
    $updatedCandidates = [
        'client_updated_at' => $domain['client_updated_at'] ?? null,
        'updated_at' => $item['updated_at'] ?? null,
        'updated_at_timestamp' => $item['updated_at_timestamp'] ?? null,
        'created_at' => $item['created_at'] ?? null,
        'created_at_timestamp' => $item['created_at_timestamp'] ?? null,
    ];
    $modified = null;
    $modifiedDate = null;
    $source = null;
    foreach ($updatedCandidates as $name => $candidate) {
        $parsed = parseTimestamp($candidate);
        if ($parsed['date'] !== null) {
            $modified = $parsed['normalized'];
            $modifiedDate = $parsed['date'];
            $source = $name;
            break;
        }
    }

    $clientProvided = array_key_exists('client_updated_at', $domain);
    $clientValid = !$clientProvided || parseTimestamp($domain['client_updated_at'])['date'] !== null;
    $warning = $created === null || $modified === null || !$clientValid || $source !== 'client_updated_at';

    return [
        'created' => $created,
        'modified' => $modified,
        'modified_date' => $modifiedDate,
        'modified_source' => $source,
        'warning' => $warning,
    ];
}

function addConversionWarning(array &$diagnostics, string $warning): void
{
    $diagnostics['warnings'][$warning] = true;
}

function addNodeType(array &$diagnostics, string $type): void
{
    if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $type) === 1) {
        $diagnostics['node_types'][$type] = true;
    } else {
        $diagnostics['node_types']['nonstandard-node-type'] = true;
    }
}

function addUnsupportedNodeType(array &$diagnostics, string $type): void
{
    addNodeType($diagnostics, $type);
    $safeType = preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $type) === 1
        ? $type
        : 'nonstandard-node-type';
    $diagnostics['unsupported_node_types'][$safeType] = true;
    addConversionWarning($diagnostics, 'unsupported_lexical_node');
}

function conversionDiagnostics(): array
{
    return ['node_types' => [], 'unsupported_node_types' => [], 'warnings' => []];
}

function finalizedDiagnostics(array $diagnostics): array
{
    $nodeTypes = array_keys($diagnostics['node_types']);
    $unsupported = array_keys($diagnostics['unsupported_node_types']);
    $warnings = array_keys($diagnostics['warnings']);
    sort($nodeTypes, SORT_STRING);
    sort($unsupported, SORT_STRING);
    sort($warnings, SORT_STRING);
    return ['node_types' => $nodeTypes, 'unsupported_node_types' => $unsupported, 'warnings' => $warnings];
}

function escapeMarkdownInline(string $text): string
{
    $text = str_replace('&', '&amp;', $text);
    $text = str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
    $escaped = preg_replace('/([\\\\`*_[\]~])/u', '\\\\$1', $text);
    if ($escaped === null) {
        return $text;
    }
    $escaped = preg_replace('/(^|\n)([ \t]*)([#>+-]|\d+\.)(?=\s)/u', '$1$2\\$3', $escaped);
    return $escaped ?? $text;
}

function escapeMarkdownHeading(string $text): string
{
    return str_replace(["\r\n", "\r", "\n"], ' ', escapeMarkdownInline($text));
}

function renderInlineCode(string $text): string
{
    preg_match_all('/`+/u', $text, $matches);
    $maximum = 0;
    foreach ($matches[0] ?? [] as $run) {
        $maximum = max($maximum, strlen($run));
    }
    $delimiter = str_repeat('`', max(1, $maximum + 1));
    $padding = $text !== '' && (str_starts_with($text, '`') || str_ends_with($text, '`') || trim($text) !== $text)
        ? ' '
        : '';
    return $delimiter . $padding . $text . $padding . $delimiter;
}

function renderFormattedText(string $text, int $format, array &$diagnostics): string
{
    $knownMask = 1 | 2 | 4 | 8 | 16 | 32 | 64 | 128 | 256 | 512 | 1024;
    if (($format & ~$knownMask) !== 0) {
        addConversionWarning($diagnostics, 'unknown_text_format_bits_omitted');
    }
    if (($format & (256 | 512 | 1024)) !== 0) {
        addConversionWarning($diagnostics, 'text_case_format_omitted');
    }

    $prefix = '';
    $suffix = '';
    $core = $text;
    if (($format & 16) === 0 && $format !== 0
        && preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $parts) === 1) {
        $prefix = $parts[1];
        $core = $parts[2];
        $suffix = $parts[3];
        if ($core === '') {
            return escapeMarkdownInline($text);
        }
    }

    $output = ($format & 16) !== 0 ? renderInlineCode($core) : escapeMarkdownInline($core);
    if (($format & 1) !== 0) {
        $output = '**' . $output . '**';
    }
    if (($format & 2) !== 0) {
        $output = '*' . $output . '*';
    }
    if (($format & 4) !== 0) {
        $output = '~~' . $output . '~~';
    }
    if (($format & 8) !== 0) {
        $output = '<u>' . $output . '</u>';
    }
    if (($format & 32) !== 0) {
        $output = '<sub>' . $output . '</sub>';
    }
    if (($format & 64) !== 0) {
        $output = '<sup>' . $output . '</sup>';
    }
    if (($format & 128) !== 0) {
        $output = '<mark>' . $output . '</mark>';
    }
    return escapeMarkdownInline($prefix) . $output . escapeMarkdownInline($suffix);
}

function lexicalRawText(mixed $node): string
{
    if (!is_array($node)) {
        return '';
    }
    if (isset($node['text']) && is_string($node['text'])) {
        return $node['text'];
    }
    if (($node['type'] ?? null) === 'linebreak') {
        return "\n";
    }
    $output = '';
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $output .= lexicalRawText($child);
        }
    }
    return $output;
}

function lexicalRenderChildren(array $node, array &$diagnostics, string $context = 'inline'): string
{
    if (!isset($node['children']) || !is_array($node['children'])) {
        addConversionWarning($diagnostics, 'lexical_children_missing_or_invalid');
        return '';
    }
    $output = '';
    foreach ($node['children'] as $child) {
        $output .= lexicalRenderNode($child, $diagnostics, $context);
    }
    return $output;
}

function lexicalRenderLink(array $node, array &$diagnostics): string
{
    $children = lexicalRenderChildren($node, $diagnostics, 'inline');
    if (($node['type'] ?? '') === 'autolink' && ($node['isUnlinked'] ?? false) === true) {
        return $children;
    }
    if (!isset($node['url']) || !is_string($node['url']) || $node['url'] === '') {
        addConversionWarning($diagnostics, 'link_url_missing');
        return $children;
    }
    $url = str_replace(['\\', ' ', '(', ')'], ['\\\\', '%20', '\\(', '\\)'], $node['url']);
    if (($node['type'] ?? '') === 'autolink' && lexicalRawText($node) === $node['url'] && !str_contains($url, ' ')) {
        return '<' . $url . '>';
    }
    $title = '';
    if (isset($node['title']) && is_string($node['title']) && $node['title'] !== '') {
        $title = ' "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $node['title']) . '"';
    }
    return '[' . $children . '](' . $url . $title . ')';
}

function lexicalRenderList(array $node, array &$diagnostics, int $depth = 0): string
{
    $listType = isset($node['listType']) && is_string($node['listType']) ? $node['listType'] : 'bullet';
    if (!in_array($listType, ['bullet', 'number', 'check'], true)) {
        addConversionWarning($diagnostics, 'unsupported_list_type');
        $listType = 'bullet';
    }
    $start = isset($node['start']) && is_int($node['start']) && $node['start'] > 0 ? $node['start'] : 1;
    $lines = [];
    $itemIndex = 0;
    foreach (($node['children'] ?? []) as $item) {
        if (!is_array($item) || ($item['type'] ?? null) !== 'listitem') {
            addConversionWarning($diagnostics, 'list_child_is_not_listitem');
            continue;
        }
        addNodeType($diagnostics, 'listitem');
        $itemText = '';
        $nested = [];
        foreach (($item['children'] ?? []) as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'list') {
                $nested[] = lexicalRenderList($child, $diagnostics, $depth + 1);
            } elseif (is_array($child) && ($child['type'] ?? null) === 'paragraph') {
                $part = lexicalRenderChildren($child, $diagnostics, 'inline');
                $itemText .= ($itemText === '' ? '' : '<br>') . $part;
            } else {
                $itemText .= lexicalRenderNode($child, $diagnostics, 'inline');
            }
        }
        if ($listType === 'number') {
            $value = isset($item['value']) && is_int($item['value']) && $item['value'] > 0
                ? $item['value']
                : $start + $itemIndex;
            $marker = $value . '. ';
        } elseif ($listType === 'check') {
            if (isset($item['checked']) && !is_bool($item['checked'])) {
                addConversionWarning($diagnostics, 'checklist_state_invalid');
            }
            $marker = ($item['checked'] ?? false) === true ? '- [x] ' : '- [ ] ';
        } else {
            $marker = '- ';
        }
        $lines[] = str_repeat('    ', $depth) . $marker . $itemText;
        foreach ($nested as $nestedList) {
            if ($nestedList !== '') {
                $lines[] = $nestedList;
            }
        }
        $itemIndex++;
    }
    return implode("\n", $lines);
}

function lexicalTableCellText(array $cell, array &$diagnostics): string
{
    $parts = [];
    foreach (($cell['children'] ?? []) as $child) {
        $rendered = lexicalRenderNode($child, $diagnostics, 'table');
        if ($rendered !== '') {
            $parts[] = $rendered;
        }
    }
    $text = implode('<br>', $parts);
    $text = str_replace(["\r\n", "\r", "\n"], '<br>', $text);
    return str_replace('|', '\\|', $text);
}

function lexicalRenderTable(array $node, array &$diagnostics): string
{
    $rows = [];
    $maximumColumns = 0;
    foreach (($node['children'] ?? []) as $row) {
        if (!is_array($row) || ($row['type'] ?? null) !== 'tablerow') {
            addConversionWarning($diagnostics, 'table_child_is_not_row');
            continue;
        }
        addNodeType($diagnostics, 'tablerow');
        $cells = [];
        $alignments = [];
        foreach (($row['children'] ?? []) as $cell) {
            if (!is_array($cell) || ($cell['type'] ?? null) !== 'tablecell') {
                addConversionWarning($diagnostics, 'table_row_child_is_not_cell');
                continue;
            }
            addNodeType($diagnostics, 'tablecell');
            $span = isset($cell['colSpan']) && is_int($cell['colSpan']) && $cell['colSpan'] > 0 ? $cell['colSpan'] : 1;
            if ($span > 1) {
                addConversionWarning($diagnostics, 'table_column_span_approximated');
            }
            if (isset($cell['rowSpan']) && is_int($cell['rowSpan']) && $cell['rowSpan'] > 1) {
                addConversionWarning($diagnostics, 'table_row_span_approximated');
            }
            if (array_key_exists('width', $cell) && $cell['width'] !== null) {
                addConversionWarning($diagnostics, 'table_width_omitted');
            }
            if (array_key_exists('backgroundColor', $cell) && $cell['backgroundColor'] !== null && $cell['backgroundColor'] !== '') {
                addConversionWarning($diagnostics, 'table_background_color_omitted');
            }
            $format = isset($cell['format']) && is_string($cell['format']) ? $cell['format'] : '';
            $alignment = match ($format) {
                'center' => ':---:',
                'right', 'end' => '---:',
                'left', 'start' => ':---',
                default => '---',
            };
            $cells[] = lexicalTableCellText($cell, $diagnostics);
            $alignments[] = $alignment;
            for ($extra = 1; $extra < $span; $extra++) {
                $cells[] = '';
                $alignments[] = $alignment;
            }
        }
        $maximumColumns = max($maximumColumns, count($cells));
        $rows[] = ['cells' => $cells, 'alignments' => $alignments];
    }
    if ($rows === [] || $maximumColumns === 0) {
        addConversionWarning($diagnostics, 'empty_table');
        return '';
    }
    foreach ($rows as &$row) {
        while (count($row['cells']) < $maximumColumns) {
            $row['cells'][] = '';
            $row['alignments'][] = '---';
        }
    }
    unset($row);
    $hasHeader = false;
    foreach (($node['children'][0]['children'] ?? []) as $cell) {
        if (is_array($cell) && isset($cell['headerState']) && is_int($cell['headerState']) && ($cell['headerState'] & 1) === 1) {
            $hasHeader = true;
            break;
        }
    }
    if (!$hasHeader) {
        addConversionWarning($diagnostics, 'table_header_synthesized_from_first_row');
    }
    $output = [];
    $output[] = '| ' . implode(' | ', $rows[0]['cells']) . ' |';
    $output[] = '| ' . implode(' | ', $rows[0]['alignments']) . ' |';
    for ($index = 1; $index < count($rows); $index++) {
        $output[] = '| ' . implode(' | ', $rows[$index]['cells']) . ' |';
    }
    return implode("\n", $output);
}

function lexicalRenderNode(mixed $node, array &$diagnostics, string $context = 'block'): string
{
    if (!is_array($node) || array_is_list($node)) {
        addConversionWarning($diagnostics, 'lexical_node_invalid');
        return '[Unsupported rich-note element]';
    }
    $type = isset($node['type']) && is_string($node['type']) ? $node['type'] : 'nonstandard-node-type';
    addNodeType($diagnostics, $type);
    switch ($type) {
        case 'root':
            return lexicalRenderRoot($node, $diagnostics);
        case 'paragraph':
            $format = isset($node['format']) && is_string($node['format']) ? $node['format'] : '';
            if (!in_array($format, ['', 'left', 'start'], true)) {
                addConversionWarning($diagnostics, 'block_alignment_omitted');
            }
            return lexicalRenderChildren($node, $diagnostics, $context === 'table' ? 'table' : 'inline');
        case 'heading':
            $level = isset($node['tag']) && preg_match('/^h([1-6])$/D', (string) $node['tag'], $matches) === 1
                ? (int) $matches[1]
                : 2;
            if (!isset($matches[1])) {
                addConversionWarning($diagnostics, 'heading_level_invalid');
            }
            return str_repeat('#', $level) . ' ' . lexicalRenderChildren($node, $diagnostics, 'inline');
        case 'text':
        case 'hashtag':
            if (!isset($node['text']) || !is_string($node['text'])) {
                addConversionWarning($diagnostics, 'text_node_value_missing');
                return '';
            }
            $format = isset($node['format']) && is_int($node['format']) ? $node['format'] : 0;
            if (isset($node['format']) && !is_int($node['format'])) {
                addConversionWarning($diagnostics, 'text_format_invalid');
            }
            return renderFormattedText($node['text'], $format, $diagnostics);
        case 'linebreak':
            return $context === 'table' ? '<br>' : "  \n";
        case 'link':
        case 'autolink':
            return lexicalRenderLink($node, $diagnostics);
        case 'list':
            return lexicalRenderList($node, $diagnostics);
        case 'listitem':
            return lexicalRenderChildren($node, $diagnostics, 'inline');
        case 'quote':
            $quote = lexicalRenderChildren($node, $diagnostics, 'inline');
            return implode("\n", array_map(static fn(string $line): string => '> ' . $line, explode("\n", $quote)));
        case 'code':
            $code = lexicalRawText($node);
            preg_match_all('/`{3,}/', $code, $runs);
            $maximum = 2;
            foreach ($runs[0] ?? [] as $run) {
                $maximum = max($maximum, strlen($run));
            }
            $fence = str_repeat('`', $maximum + 1);
            $language = isset($node['language']) && is_string($node['language'])
                && preg_match('/^[A-Za-z0-9_+.#-]{1,40}$/D', $node['language']) === 1
                ? $node['language']
                : '';
            return $fence . $language . "\n" . $code . "\n" . $fence;
        case 'horizontalrule':
            return '---';
        case 'table':
            return lexicalRenderTable($node, $diagnostics);
        case 'snfile':
            addUnsupportedNodeType($diagnostics, 'snfile');
            addConversionWarning($diagnostics, 'unresolved_embedded_file');
            return '[Embedded file unavailable from this backup]';
        default:
            addUnsupportedNodeType($diagnostics, $type);
            if (isset($node['children']) && is_array($node['children'])) {
                return lexicalRenderChildren($node, $diagnostics, $context);
            }
            if (isset($node['text']) && is_string($node['text'])) {
                return escapeMarkdownInline($node['text']);
            }
            return '[Unsupported rich-note element]';
    }
}

function lexicalRenderRoot(array $root, array &$diagnostics): string
{
    addNodeType($diagnostics, 'root');
    $blocks = [];
    foreach (($root['children'] ?? []) as $child) {
        $blocks[] = lexicalRenderNode($child, $diagnostics, 'block');
    }
    $output = '';
    $emptyBlocks = 0;
    foreach ($blocks as $block) {
        if ($block === '') {
            if ($output !== '') {
                $emptyBlocks = min(2, $emptyBlocks + 1);
            }
            continue;
        }
        if ($output !== '') {
            $output .= str_repeat("\n", min(4, 2 + $emptyBlocks));
        }
        $output .= $block;
        $emptyBlocks = 0;
    }
    return $output;
}

function isLexicalDocument(mixed $decoded): bool
{
    if (!is_array($decoded) || array_is_list($decoded) || !isset($decoded['root']) || !is_array($decoded['root'])) {
        return false;
    }
    $root = $decoded['root'];
    return !array_is_list($root)
        && ($root['type'] ?? null) === 'root'
        && isset($root['version'])
        && is_int($root['version'])
        && isset($root['children'])
        && is_array($root['children'])
        && array_is_list($root['children']);
}

function isStructuredTaskDocument(array $content, mixed $decoded): bool
{
    $editors = ['com.sncommunity.advanced-checklist', 'org.standardnotes.simple-task-editor'];
    return ($content['noteType'] ?? null) === 'task'
        && isset($content['editorIdentifier'])
        && is_string($content['editorIdentifier'])
        && in_array($content['editorIdentifier'], $editors, true)
        && is_array($decoded)
        && !array_is_list($decoded)
        && isset($decoded['schemaVersion'])
        && is_string($decoded['schemaVersion'])
        && isset($decoded['groups'])
        && is_array($decoded['groups'])
        && array_is_list($decoded['groups'])
        && isset($decoded['defaultSections'])
        && is_array($decoded['defaultSections'])
        && array_is_list($decoded['defaultSections']);
}

function renderTaskDescription(string $description): string
{
    return str_replace(["\r\n", "\r", "\n"], '<br>', escapeMarkdownInline($description));
}

function renderTaskItem(mixed $task, array &$warnings, array &$unsupported): string
{
    if (!is_array($task) || array_is_list($task)) {
        $warnings['task_item_invalid'] = true;
        $unsupported['task_item_invalid'] = true;
        return '- [ ] [Unsupported task item]';
    }
    $description = isset($task['description']) && is_string($task['description'])
        ? renderTaskDescription($task['description'])
        : '[Unsupported task item]';
    if ($description === '[Unsupported task item]') {
        $warnings['task_description_missing_or_invalid'] = true;
        $unsupported['task_description_missing_or_invalid'] = true;
    }
    if (isset($task['completed']) && !is_bool($task['completed'])) {
        $warnings['task_completion_state_invalid'] = true;
    }
    return (($task['completed'] ?? false) === true ? '- [x] ' : '- [ ] ') . $description;
}

function convertStructuredTasks(array $content, array $document): array
{
    $warnings = [];
    $unsupported = [];
    if (($document['schemaVersion'] ?? '') !== '1.0.0') {
        $warnings['unsupported_task_schema_version'] = true;
        $unsupported['unsupported_task_schema_version'] = true;
    }
    if (($content['editorIdentifier'] ?? '') === 'org.standardnotes.simple-task-editor') {
        $warnings['task_schema_editor_identifier_mismatch'] = true;
    }
    $blocks = [];
    $taskCount = 0;
    $groupCount = 0;
    foreach ($document['groups'] as $group) {
        $groupCount++;
        if (!is_array($group) || array_is_list($group)) {
            $warnings['task_group_invalid'] = true;
            $unsupported['task_group_invalid'] = true;
            $blocks[] = '## Tasks';
            continue;
        }
        $groupName = isset($group['name']) && is_string($group['name']) && $group['name'] !== ''
            ? escapeMarkdownHeading($group['name'])
            : 'Tasks';
        if ($groupName === 'Tasks' && (!isset($group['name']) || $group['name'] === '')) {
            $warnings['task_group_name_missing'] = true;
        }
        $groupBlocks = ['## ' . $groupName];
        if (isset($group['draft']) && is_string($group['draft']) && $group['draft'] !== '') {
            $groupBlocks[] = '- [ ] ' . renderTaskDescription($group['draft']);
            $warnings['task_group_draft_exported_as_unchecked_task'] = true;
        }
        $tasks = isset($group['tasks']) && is_array($group['tasks']) && array_is_list($group['tasks'])
            ? $group['tasks']
            : [];
        if (!isset($group['tasks']) || !is_array($group['tasks'])) {
            $warnings['task_group_tasks_missing_or_invalid'] = true;
            $unsupported['task_group_tasks_missing_or_invalid'] = true;
        }
        $taskCount += count($tasks);
        $sections = array_key_exists('sections', $group) && is_array($group['sections']) && array_is_list($group['sections'])
            ? $group['sections']
            : $document['defaultSections'];
        $emitted = [];
        if ($sections === []) {
            if ($tasks !== []) {
                $warnings['task_sections_missing'] = true;
                $unsupported['task_sections_missing'] = true;
                $taskLines = [];
                foreach ($tasks as $index => $task) {
                    $taskLines[] = renderTaskItem($task, $warnings, $unsupported);
                    $emitted[$index] = true;
                }
                $groupBlocks[] = implode("\n", $taskLines);
            }
        } else {
            $seenKinds = [];
            foreach ($sections as $section) {
                if (!is_array($section) || array_is_list($section)) {
                    $warnings['task_section_invalid'] = true;
                    $unsupported['task_section_invalid'] = true;
                    continue;
                }
                $sectionName = isset($section['name']) && is_string($section['name']) && $section['name'] !== ''
                    ? escapeMarkdownHeading($section['name'])
                    : 'Tasks';
                $sectionId = isset($section['id']) && is_string($section['id']) ? $section['id'] : '';
                $kind = $sectionId === 'completed-tasks' ? 'completed' : 'open';
                if (!in_array($sectionId, ['open-tasks', 'completed-tasks'], true)) {
                    $warnings['unsupported_task_section_identifier'] = true;
                    $unsupported['unsupported_task_section_identifier'] = true;
                }
                $groupBlocks[] = '### ' . $sectionName;
                $taskLines = [];
                if (!isset($seenKinds[$kind])) {
                    foreach ($tasks as $index => $task) {
                        $completed = is_array($task) && ($task['completed'] ?? false) === true;
                        if (($kind === 'completed') === $completed) {
                            $taskLines[] = renderTaskItem($task, $warnings, $unsupported);
                            $emitted[$index] = true;
                        }
                    }
                    $seenKinds[$kind] = true;
                } else {
                    $warnings['task_section_overlap_avoided'] = true;
                }
                if ($taskLines !== []) {
                    $groupBlocks[] = implode("\n", $taskLines);
                }
            }
        }
        $remaining = [];
        foreach ($tasks as $index => $task) {
            if (!isset($emitted[$index])) {
                $remaining[] = renderTaskItem($task, $warnings, $unsupported);
            }
        }
        if ($remaining !== []) {
            $warnings['unmatched_tasks_preserved'] = true;
            $unsupported['unmatched_tasks_preserved'] = true;
            $groupBlocks[] = '### Tasks';
            $groupBlocks[] = implode("\n", $remaining);
        }
        $blocks[] = implode("\n\n", $groupBlocks);
    }
    $warningList = array_keys($warnings);
    $unsupportedList = array_keys($unsupported);
    sort($warningList, SORT_STRING);
    sort($unsupportedList, SORT_STRING);
    return [
        'body' => implode("\n\n", $blocks),
        'schema_version' => $document['schemaVersion'],
        'group_count' => $groupCount,
        'task_count' => $taskCount,
        'warnings' => $warningList,
        'unsupported_structures' => $unsupportedList,
    ];
}

/** @return array{body: string, format: array<string, mixed>} */
function processNoteBody(array $content, string $body): array
{
    $noteType = isset($content['noteType']) && is_string($content['noteType']) ? $content['noteType'] : null;
    $editorIdentifier = isset($content['editorIdentifier']) && is_string($content['editorIdentifier'])
        ? $content['editorIdentifier']
        : null;
    $format = [
        'note_type' => $noteType,
        'editor_identifier' => $editorIdentifier,
        'classification' => 'plain-text-or-markdown',
        'detected_source_format' => 'plain-text-or-markdown',
        'conversion_performed' => false,
        'may_not_be_markdown' => false,
        'node_types' => [],
        'unsupported_node_types' => [],
        'unsupported_lexical_structures' => [],
        'unsupported_task_structures' => [],
        'conversion_warnings' => [],
    ];
    $trimmed = ltrim($body);
    $jsonLike = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    $decoded = null;
    $validJson = false;
    if ($jsonLike) {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $validJson = true;
        } catch (JsonException) {
            $validJson = false;
        }
    }

    if ($validJson && isStructuredTaskDocument($content, $decoded)) {
        $conversion = convertStructuredTasks($content, $decoded);
        $format['classification'] = 'structured-task-json';
        $format['detected_source_format'] = 'standard-notes-structured-task-v1';
        $format['conversion_performed'] = 'structured-task-to-gfm';
        $format['may_not_be_markdown'] = true;
        $format['task_schema_version'] = $conversion['schema_version'];
        $format['task_group_count'] = $conversion['group_count'];
        $format['task_count'] = $conversion['task_count'];
        $format['unsupported_task_structures'] = $conversion['unsupported_structures'];
        $format['conversion_warnings'] = $conversion['warnings'];
        return ['body' => $conversion['body'], 'format' => $format];
    }

    if ($validJson && isLexicalDocument($decoded)) {
        $diagnostics = conversionDiagnostics();
        $converted = lexicalRenderRoot($decoded['root'], $diagnostics);
        $final = finalizedDiagnostics($diagnostics);
        $format['classification'] = 'lexical-super-note';
        $format['detected_source_format'] = 'lexical-editor-state-json';
        $format['conversion_performed'] = 'lexical-to-gfm';
        $format['may_not_be_markdown'] = true;
        $format['node_types'] = $final['node_types'];
        $format['unsupported_node_types'] = $final['unsupported_node_types'];
        $format['conversion_warnings'] = $final['warnings'];
        $unsupportedStructures = $final['unsupported_node_types'];
        foreach ($final['warnings'] as $warning) {
            if (in_array($warning, [
                'block_alignment_omitted',
                'table_background_color_omitted',
                'table_column_span_approximated',
                'table_row_span_approximated',
                'table_width_omitted',
                'unresolved_embedded_file',
                'unknown_text_format_bits_omitted',
            ], true)) {
                $unsupportedStructures[] = $warning;
            }
        }
        $unsupportedStructures = array_values(array_unique($unsupportedStructures));
        sort($unsupportedStructures, SORT_STRING);
        $format['unsupported_lexical_structures'] = $unsupportedStructures;
        return ['body' => $converted, 'format' => $format];
    }

    if ($validJson) {
        $format['classification'] = 'json-like-unrecognized';
        $format['detected_source_format'] = 'json-like-unrecognized';
        $format['may_not_be_markdown'] = true;
        $format['conversion_warnings'] = ['unrecognized_json_preserved'];
        return ['body' => $body, 'format' => $format];
    }

    $combined = portableCaseFold(trim(($noteType ?? '') . ' ' . ($editorIdentifier ?? '')));
    $plainHint = $combined !== '' && (
        str_contains($combined, 'markdown')
        || str_contains($combined, 'plain')
        || str_contains($combined, 'standard-editor')
    );
    $looksHtml = preg_match('/^<(?:!doctype|html|body|div|p|h[1-6]|ul|ol|table|section|article)\b/i', $trimmed) === 1;
    if (!$plainHint && ($combined !== '' || $looksHtml)) {
        $format['classification'] = 'editor-specific-text-preserved';
        $format['detected_source_format'] = 'editor-specific-text-unconverted';
        $format['may_not_be_markdown'] = true;
    }
    return ['body' => $body, 'format' => $format];
}

/** @return array{tags: array<int, array<string, mixed>>, tags_by_uuid: array<string, array<int, int>>, note_tag_indexes: array<string, array<int, int>>, parent_uuids: array<string, bool>, global_issues: array<int, array{code: string, count: int}>} */
function parseTags(array $items): array
{
    $tags = [];
    $tagsByUuid = [];
    $noteTagIndexes = [];
    $parentUuids = [];
    $existingNoteUuids = [];
    $invalidItemRecords = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            $invalidItemRecords++;
            continue;
        }
        if (
            ($item['content_type'] ?? null) === 'Note'
            && isset($item['uuid'])
            && is_string($item['uuid'])
            && $item['uuid'] !== ''
        ) {
            $existingNoteUuids[$item['uuid']] = true;
        }
    }
    $orphanNoteReferences = 0;
    $malformedTagRecords = 0;
    $malformedTagReferences = 0;

    foreach ($items as $recordIndex => $item) {
        if (!is_array($item) || ($item['content_type'] ?? null) !== 'Tag') {
            continue;
        }
        $content = $item['content'] ?? null;
        if (!is_array($content)) {
            $malformedTagRecords++;
            continue;
        }
        $uuid = isset($item['uuid']) && is_string($item['uuid']) && $item['uuid'] !== '' ? $item['uuid'] : null;
        $title = isset($content['title']) && is_string($content['title']) ? $content['title'] : '';
        $references = isset($content['references']) && is_array($content['references']) ? $content['references'] : [];
        if ($uuid === null || !isset($content['title']) || !is_string($content['title']) || !is_array($content['references'] ?? null)) {
            $malformedTagRecords++;
        }
        $referencedNoteUuids = [];
        $parents = [];
        foreach ($references as $reference) {
            if (!is_array($reference) || !isset($reference['uuid']) || !is_string($reference['uuid'])) {
                $malformedTagReferences++;
                continue;
            }
            if (($reference['content_type'] ?? null) === 'Note') {
                $referencedNoteUuids[$reference['uuid']] = true;
            }
            if (
                ($reference['content_type'] ?? null) === 'Tag'
                && ($reference['reference_type'] ?? null) === 'TagToParentTag'
            ) {
                $parents[$reference['uuid']] = true;
                $parentUuids[$reference['uuid']] = true;
            }
        }

        $tagIndex = count($tags);
        $tags[] = [
            'record_index' => $recordIndex + 1,
            'uuid' => $uuid,
            'title' => $title,
            'parent_uuids' => array_keys($parents),
        ];
        if ($uuid !== null) {
            $tagsByUuid[$uuid][] = $tagIndex;
        }
        foreach (array_keys($referencedNoteUuids) as $noteUuid) {
            $noteTagIndexes[$noteUuid][$tagIndex] = $tagIndex;
            if (!isset($existingNoteUuids[$noteUuid])) {
                $orphanNoteReferences++;
            }
        }
    }

    $duplicateTagUuids = 0;
    foreach ($tagsByUuid as $indexes) {
        if (count($indexes) > 1) {
            $duplicateTagUuids += count($indexes) - 1;
        }
    }
    $globalIssues = [];
    if ($orphanNoteReferences > 0) {
        $globalIssues[] = ['code' => 'tag_references_nonexistent_note', 'count' => $orphanNoteReferences];
    }
    if ($duplicateTagUuids > 0) {
        $globalIssues[] = ['code' => 'repeated_tag_uuid_records', 'count' => $duplicateTagUuids];
    }
    if ($malformedTagRecords > 0) {
        $globalIssues[] = ['code' => 'malformed_tag_records', 'count' => $malformedTagRecords];
    }
    if ($malformedTagReferences > 0) {
        $globalIssues[] = ['code' => 'malformed_tag_references', 'count' => $malformedTagReferences];
    }
    if ($invalidItemRecords > 0) {
        $globalIssues[] = ['code' => 'invalid_top_level_item_records', 'count' => $invalidItemRecords];
    }

    return [
        'tags' => $tags,
        'tags_by_uuid' => $tagsByUuid,
        'note_tag_indexes' => $noteTagIndexes,
        'parent_uuids' => $parentUuids,
        'global_issues' => $globalIssues,
    ];
}

/** @return array{paths: array<int, array<int, string>>, issues: array<int, string>} */
function explicitTagPaths(int $tagIndex, array $tagData, array $stack = []): array
{
    if (count($stack) >= MAX_FOLDER_DEPTH || in_array($tagIndex, $stack, true)) {
        return ['paths' => [], 'issues' => ['folder_parent_cycle_or_depth_limit']];
    }
    $tag = $tagData['tags'][$tagIndex];
    $segment = sanitizeName($tag['title'], false);
    if ($segment === null) {
        return ['paths' => [], 'issues' => ['invalid_folder_segment']];
    }
    if ($tag['parent_uuids'] === []) {
        return ['paths' => [[$segment]], 'issues' => []];
    }

    $paths = [];
    $issues = [];
    $nextStack = [...$stack, $tagIndex];
    foreach ($tag['parent_uuids'] as $parentUuid) {
        $parentIndexes = $tagData['tags_by_uuid'][$parentUuid] ?? [];
        if ($parentIndexes === []) {
            $issues[] = 'missing_parent_tag_reference';
            continue;
        }
        foreach ($parentIndexes as $parentIndex) {
            $parentResult = explicitTagPaths($parentIndex, $tagData, $nextStack);
            $issues = [...$issues, ...$parentResult['issues']];
            foreach ($parentResult['paths'] as $parentPath) {
                $paths[] = [...$parentPath, $segment];
            }
        }
    }

    return ['paths' => $paths, 'issues' => array_values(array_unique($issues))];
}

/** @return array{candidates: array<int, array<string, mixed>>, tags: array<int, string>, issues: array<int, string>} */
function folderCandidatesForNote(array $note, array $tagData, array $options): array
{
    $tagIndexes = [];
    $issues = [];
    if ($note['uuid'] !== null) {
        foreach ($tagData['note_tag_indexes'][$note['uuid']] ?? [] as $tagIndex) {
            $tagIndexes[$tagIndex] = $tagIndex;
        }
    }
    foreach ($note['tag_reference_uuids'] as $tagUuid) {
        $referencedTagIndexes = $tagData['tags_by_uuid'][$tagUuid] ?? [];
        if ($referencedTagIndexes === []) {
            $issues[] = 'note_references_nonexistent_tag';
        }
        foreach ($referencedTagIndexes as $tagIndex) {
            $tagIndexes[$tagIndex] = $tagIndex;
        }
    }
    ksort($tagIndexes, SORT_NUMERIC);

    $originalTags = [];
    $candidateByKey = [];
    foreach ($tagIndexes as $tagIndex) {
        $tag = $tagData['tags'][$tagIndex];
        $originalTags[] = $tag['title'];
        $participatesInHierarchy = $tag['parent_uuids'] !== []
            || ($tag['uuid'] !== null && isset($tagData['parent_uuids'][$tag['uuid']]));
        $source = null;
        $paths = [];

        if ($participatesInHierarchy) {
            $source = 'explicit-parent-relationship';
            $result = explicitTagPaths($tagIndex, $tagData);
            $paths = $result['paths'];
            $issues = [...$issues, ...$result['issues']];
        } elseif (str_contains($tag['title'], $options['folder_separator'])) {
            $source = 'legacy-separated-tag';
            $rawSegments = explode($options['folder_separator'], $tag['title']);
            $segments = [];
            foreach ($rawSegments as $rawSegment) {
                $segment = sanitizeName($rawSegment, false);
                if ($segment === null) {
                    $segments = [];
                    $issues[] = 'malformed_legacy_folder_tag';
                    break;
                }
                $segments[] = $segment;
            }
            if ($segments !== []) {
                $paths[] = $segments;
            }
        } elseif ($options['standalone_tags'] === 'folders') {
            $source = 'standalone-tag-assumed-folder';
            $segment = sanitizeName($tag['title'], false);
            if ($segment !== null) {
                $paths[] = [$segment];
            } else {
                $issues[] = 'invalid_standalone_tag';
            }
        }

        foreach ($paths as $segments) {
            $path = safeRelativePath($segments);
            $key = portableCaseFold($path);
            $candidate = [
                'path' => $path,
                'segments' => $segments,
                'depth' => count($segments),
                'source' => $source,
            ];
            $sourceRanks = [
                'explicit-parent-relationship' => 0,
                'legacy-separated-tag' => 1,
                'standalone-tag-assumed-folder' => 2,
            ];
            if (isset($candidateByKey[$key])) {
                $existing = $candidateByKey[$key];
                $candidateOrder = [$sourceRanks[$candidate['source']] ?? 3, $candidate['path']];
                $existingOrder = [$sourceRanks[$existing['source']] ?? 3, $existing['path']];
                if ($candidateOrder >= $existingOrder) {
                    continue;
                }
            }
            $candidateByKey[$key] = $candidate;
        }
    }
    $candidates = array_values($candidateByKey);

    usort($candidates, static function (array $left, array $right): int {
        if ($left['depth'] !== $right['depth']) {
            return $right['depth'] <=> $left['depth'];
        }
        $keyCompare = portableCaseFold($left['path']) <=> portableCaseFold($right['path']);
        return $keyCompare !== 0 ? $keyCompare : $left['path'] <=> $right['path'];
    });
    $originalTags = array_values(array_unique($originalTags, SORT_STRING));
    usort($originalTags, static function (string $left, string $right): int {
        $normalized = portableCaseFold($left) <=> portableCaseFold($right);
        return $normalized !== 0 ? $normalized : $left <=> $right;
    });

    return [
        'candidates' => $candidates,
        'tags' => $originalTags,
        'issues' => array_values(array_unique($issues)),
    ];
}

/** @return array{notes: array<int, array<string, mixed>>, source_note_records: int, status_counts: array<string, int>} */
function parseNotes(array $items): array
{
    $rawNotes = [];
    $statusCounts = ['active' => 0, 'archived' => 0, 'trashed' => 0];
    foreach ($items as $recordIndex => $item) {
        if (!is_array($item) || ($item['content_type'] ?? null) !== 'Note') {
            continue;
        }
        $content = $item['content'] ?? null;
        if (!is_array($content)) {
            throw new ExportFailure('A Note record contains encrypted or otherwise unusable content; no files were written.');
        }
        $issues = [];
        $uuid = isset($item['uuid']) && is_string($item['uuid']) && $item['uuid'] !== '' ? $item['uuid'] : null;
        if ($uuid === null) {
            $issues[] = 'missing_uuid';
        }
        $title = isset($content['title']) && is_string($content['title']) ? $content['title'] : '';
        if (!isset($content['title']) || !is_string($content['title']) || $title === '') {
            $issues[] = 'missing_or_empty_title';
        }
        if (!array_key_exists('text', $content)) {
            $body = '';
            $issues[] = 'missing_text_treated_as_empty';
        } elseif (!is_string($content['text'])) {
            throw new ExportFailure('A Note record has a non-text body that cannot be exported without data loss; no files were written.');
        } else {
            $body = $content['text'];
        }

        $domain = appData($content);
        $trashed = booleanValue($content['trashed'] ?? false)
            || booleanValue($domain['trashed'] ?? false);
        $archived = booleanValue($content['archived'] ?? false)
            || booleanValue($domain['archived'] ?? false);
        $pinned = booleanValue($content['pinned'] ?? false)
            || booleanValue($domain['pinned'] ?? false);
        if ($trashed && $archived) {
            $issues[] = 'trashed_status_takes_precedence_over_archived';
        }
        $status = $trashed ? 'trashed' : ($archived ? 'archived' : 'active');
        $statusCounts[$status]++;
        $timestamps = selectTimestamps($item, $content);
        if ($timestamps['warning']) {
            $issues[] = 'timestamp_fallback_or_invalid_timestamp';
        }
        $duplicateOf = isset($item['duplicate_of']) && is_string($item['duplicate_of']) && $item['duplicate_of'] !== ''
            ? $item['duplicate_of']
            : null;
        if ($duplicateOf !== null) {
            $issues[] = 'duplicate_of_relationship_preserved';
        }
        $tagReferenceUuids = [];
        $references = isset($content['references']) && is_array($content['references']) ? $content['references'] : [];
        foreach ($references as $reference) {
            if (
                is_array($reference)
                && ($reference['content_type'] ?? null) === 'Tag'
                && isset($reference['uuid'])
                && is_string($reference['uuid'])
            ) {
                $tagReferenceUuids[$reference['uuid']] = true;
            }
        }
        $processedBody = processNoteBody($content, $body);

        $rawNotes[] = [
            'record_index' => $recordIndex + 1,
            'source_record_count' => 1,
            'source_hash' => structuralHash($item),
            'source_body_hash' => hash('sha256', $body),
            'body_hash' => hash('sha256', $processedBody['body']),
            'uuid' => $uuid,
            'title' => $title,
            'body' => $processedBody['body'],
            'content' => $content,
            'status' => $status,
            'trashed' => $trashed,
            'archived' => $archived,
            'pinned' => $pinned,
            'duplicate_of' => $duplicateOf,
            'tag_reference_uuids' => array_keys($tagReferenceUuids),
            'timestamps' => $timestamps,
            'format' => $processedBody['format'],
            'issues' => $issues,
        ];
    }

    $uuidCounts = [];
    foreach ($rawNotes as $note) {
        if ($note['uuid'] !== null) {
            $uuidCounts[$note['uuid']] = ($uuidCounts[$note['uuid']] ?? 0) + 1;
        }
    }

    $deduplicated = [];
    $identicalIndexes = [];
    foreach ($rawNotes as $note) {
        $identityKey = ($note['uuid'] ?? '__missing_' . $note['record_index']) . ':' . $note['source_hash'];
        if (isset($identicalIndexes[$identityKey])) {
            $existingIndex = $identicalIndexes[$identityKey];
            $deduplicated[$existingIndex]['source_record_count']++;
            $deduplicated[$existingIndex]['issues'][] = 'identical_repeated_record_collapsed';
            continue;
        }
        if ($note['uuid'] !== null && ($uuidCounts[$note['uuid']] ?? 0) > 1) {
            $note['issues'][] = 'repeated_uuid_record_preserved';
        }
        $note['issues'] = array_values(array_unique($note['issues']));
        $identicalIndexes[$identityKey] = count($deduplicated);
        $deduplicated[] = $note;
    }

    return [
        'notes' => $deduplicated,
        'source_note_records' => count($rawNotes),
        'status_counts' => $statusCounts,
    ];
}

function shouldExport(array $note, array $options): bool
{
    if ($note['status'] === 'trashed' && !$options['include_trashed']) {
        return false;
    }
    if ($note['status'] === 'archived' && $options['exclude_archived']) {
        return false;
    }
    return true;
}

/** @return array{notes: array<int, array<string, mixed>>, summary: array<string, int>, manifest_context: array<string, mixed>} */
function buildExportPlan(array $backup, array $options): array
{
    $tagData = parseTags($backup['items']);
    $parsedNotes = parseNotes($backup['items']);
    $notes = $parsedNotes['notes'];
    $summary = [
        'total_notes_found' => $parsedNotes['source_note_records'],
        'active_notes_found' => $parsedNotes['status_counts']['active'],
        'archived_notes_found' => $parsedNotes['status_counts']['archived'],
        'trashed_notes_found' => $parsedNotes['status_counts']['trashed'],
        'active_notes_exported' => 0,
        'archived_notes_exported' => 0,
        'trashed_notes_exported' => 0,
        'unfiled_notes' => 0,
        'filename_collisions' => 0,
        'filenames_derived_from_body' => 0,
        'filenames_remaining_untitled' => 0,
        'derived_title_filename_collisions' => 0,
        'duplicate_or_malformed_records' => 0,
        'timestamp_warnings' => 0,
        'lexical_notes_detected' => 0,
        'lexical_notes_converted' => 0,
        'unsupported_lexical_structures' => 0,
        'lexical_conversion_warnings' => 0,
        'structured_task_notes_detected' => 0,
        'structured_task_notes_converted' => 0,
        'task_conversion_warnings' => 0,
        'unsupported_task_structures' => 0,
        'unrecognized_json_like_notes_preserved' => 0,
        'editor_specific_text_notes_preserved' => 0,
        'conversion_warnings' => 0,
    ];

    $planned = [];
    $problemRecords = 0;
    foreach ($notes as $note) {
        $folderData = folderCandidatesForNote($note, $tagData, $options);
        $note['issues'] = array_values(array_unique([...$note['issues'], ...$folderData['issues']]));
        $nonTimestampIssues = array_diff($note['issues'], ['timestamp_fallback_or_invalid_timestamp']);
        if ($nonTimestampIssues !== []) {
            $problemRecords++;
        }
        $note['original_tags'] = $folderData['tags'];
        $note['folder_candidates'] = $folderData['candidates'];
        $note['selected_folder'] = $folderData['candidates'][0] ?? null;
        if (!shouldExport($note, $options)) {
            continue;
        }
        $summary[$note['status'] . '_notes_exported']++;
        if ($note['timestamps']['warning']) {
            $summary['timestamp_warnings']++;
        }
        if ($note['format']['detected_source_format'] === 'lexical-editor-state-json') {
            $summary['lexical_notes_detected']++;
            $summary['lexical_notes_converted']++;
            $summary['lexical_conversion_warnings'] += count($note['format']['conversion_warnings']);
            $summary['unsupported_lexical_structures'] += count($note['format']['unsupported_lexical_structures']);
            $summary['conversion_warnings'] += count($note['format']['conversion_warnings']);
        } elseif ($note['format']['detected_source_format'] === 'standard-notes-structured-task-v1') {
            $summary['structured_task_notes_detected']++;
            $summary['structured_task_notes_converted']++;
            $summary['task_conversion_warnings'] += count($note['format']['conversion_warnings']);
            $summary['unsupported_task_structures'] += count($note['format']['unsupported_task_structures']);
            $summary['conversion_warnings'] += count($note['format']['conversion_warnings']);
        } elseif ($note['format']['detected_source_format'] === 'json-like-unrecognized') {
            $summary['unrecognized_json_like_notes_preserved']++;
        } elseif ($note['format']['detected_source_format'] === 'editor-specific-text-unconverted') {
            $summary['editor_specific_text_notes_preserved']++;
        }

        $folderSegments = $note['selected_folder']['segments'] ?? ['Unfiled'];
        if ($note['selected_folder'] === null) {
            $summary['unfiled_notes']++;
        }
        if ($note['status'] === 'archived') {
            array_unshift($folderSegments, '_Archived');
        } elseif ($note['status'] === 'trashed') {
            array_unshift($folderSegments, '_Trashed');
        }
        $note['directory_segments'] = $folderSegments;
        $filenameTitle = filenameTitleForNote($note['title'], $note['body']);
        $note['filename_title_source'] = $filenameTitle['source'];
        $note['base_filename'] = sanitizeName($filenameTitle['title'], true) ?? 'Untitled';
        if ($filenameTitle['source'] === 'first_meaningful_body_line') {
            $summary['filenames_derived_from_body']++;
        } elseif ($filenameTitle['source'] === 'fallback_untitled') {
            $summary['filenames_remaining_untitled']++;
        }
        $planned[] = $note;
    }
    $globalIssueCount = array_sum(array_column($tagData['global_issues'], 'count'));
    $summary['duplicate_or_malformed_records'] = $problemRecords + $globalIssueCount;

    usort($planned, static function (array $left, array $right): int {
        $leftDirectory = safeRelativePath($left['directory_segments']);
        $rightDirectory = safeRelativePath($right['directory_segments']);
        $keysLeft = [
            portableCaseFold($leftDirectory), portableCaseFold($left['base_filename']), $left['uuid'] ?? '',
            $left['timestamps']['created'] ?? '', $left['timestamps']['modified'] ?? '', $left['body_hash'], $left['source_hash'],
            sprintf('%010d', $left['record_index']),
        ];
        $keysRight = [
            portableCaseFold($rightDirectory), portableCaseFold($right['base_filename']), $right['uuid'] ?? '',
            $right['timestamps']['created'] ?? '', $right['timestamps']['modified'] ?? '', $right['body_hash'], $right['source_hash'],
            sprintf('%010d', $right['record_index']),
        ];
        return $keysLeft <=> $keysRight;
    });

    $baseFilenameGroups = [];
    foreach ($planned as $note) {
        $groupKey = portableCaseFold(safeRelativePath($note['directory_segments']) . '/' . $note['base_filename']);
        if (!isset($baseFilenameGroups[$groupKey])) {
            $baseFilenameGroups[$groupKey] = ['total' => 0, 'not_derived' => 0];
        }
        $baseFilenameGroups[$groupKey]['total']++;
        if ($note['filename_title_source'] !== 'first_meaningful_body_line') {
            $baseFilenameGroups[$groupKey]['not_derived']++;
        }
    }
    foreach ($baseFilenameGroups as $group) {
        $allCollisions = max(0, $group['total'] - 1);
        $preexistingCollisions = max(0, $group['not_derived'] - 1);
        $summary['derived_title_filename_collisions'] += $allCollisions - $preexistingCollisions;
    }

    $occupied = [];
    foreach ($planned as &$note) {
        $directory = safeRelativePath($note['directory_segments']);
        $suffix = 1;
        do {
            $base = $note['base_filename'];
            if ($suffix > 1) {
                $suffixText = ' (' . $suffix . ')';
                $base = truncateUtf8Bytes($base, MAX_FILENAME_BASE_BYTES - strlen($suffixText)) . $suffixText;
            }
            $filename = $base . '.md';
            $relativePath = safeRelativePath($note['directory_segments'], $filename);
            $collisionKey = portableCaseFold($relativePath);
            $suffix++;
        } while (isset($occupied[$collisionKey]));

        $occupied[$collisionKey] = true;
        $note['filename'] = $filename;
        $note['relative_path'] = $relativePath;
        $note['collision_adjusted_filename'] = $suffix > 2 ? $filename : null;
        if ($note['collision_adjusted_filename'] !== null) {
            $summary['filename_collisions']++;
            $note['issues'][] = 'filename_collision_resolved';
        }
        $note['issues'] = array_values(array_unique($note['issues']));
    }
    unset($note);

    return [
        'notes' => $planned,
        'summary' => $summary,
        'manifest_context' => [
            'schema_version' => MANIFEST_SCHEMA_VERSION,
            'folder_mapping' => [
                'explicit_parent_relationships_are_authoritative' => true,
                'legacy_folder_separator' => $options['folder_separator'],
                'standalone_tags_mode' => $options['standalone_tags'],
                'standalone_tag_ambiguity' => 'Standard Notes does not identify whether a standalone tag is an ordinary tag or a top-level folder.',
                'selection_rule' => 'Deepest valid candidate, then alphabetically first normalized path.',
            ],
            'content_conversion' => [
                'plain_text_and_markdown' => 'Written byte-for-byte unchanged.',
                'lexical_editor_state_json' => 'Converted conservatively to GitHub Flavored Markdown.',
                'structured_task_json' => 'Converted conservatively to Markdown section headings and checklists.',
                'unrecognized_json_like_content' => 'Written byte-for-byte unchanged.',
                'metadata_location' => 'Conversion diagnostics are stored only in this manifest; Markdown files contain only note content.',
            ],
            'filename_title_derivation' => [
                'eligible_original_titles' => 'Blank after trimming, or exactly Untitled case-insensitively after trimming.',
                'source' => 'The first meaningful line of the final exported body, after content conversion.',
                'scan_limit' => MAX_TITLE_DERIVATION_LINES . ' lines or ' . MAX_TITLE_DERIVATION_BYTES . ' bytes, whichever comes first.',
                'body_behavior' => 'Filename derivation never changes the exported note body.',
            ],
            'global_issues' => $tagData['global_issues'],
        ],
    ];
}

function manifestForPlan(array $plan): array
{
    $entries = [];
    foreach ($plan['notes'] as $note) {
        $candidatePaths = array_map(static fn(array $candidate): string => $candidate['path'], $note['folder_candidates']);
        $candidateDetails = array_map(
            static fn(array $candidate): array => ['path' => $candidate['path'], 'source' => $candidate['source']],
            $note['folder_candidates']
        );
        $entries[] = [
            'standard_notes_uuid' => $note['uuid'],
            'original_title' => $note['title'],
            'filename_title_source' => $note['filename_title_source'],
            'final_relative_markdown_path' => $note['relative_path'],
            'creation_timestamp' => $note['timestamps']['created'],
            'modification_timestamp' => $note['timestamps']['modified'],
            'filesystem_modification_time_source' => $note['timestamps']['modified_source'],
            'original_standard_notes_tags' => $note['original_tags'],
            'detected_folder_paths' => $candidatePaths,
            'folder_candidates' => $candidateDetails,
            'selected_folder_path' => $note['selected_folder']['path'] ?? null,
            'archived' => $note['archived'],
            'trashed' => $note['trashed'],
            'pinned' => $note['pinned'],
            'collision_adjusted_filename' => $note['collision_adjusted_filename'],
            'duplicate_of' => $note['duplicate_of'],
            'source_record_count' => $note['source_record_count'],
            'content_format' => $note['format'],
            'issues' => $note['issues'],
        ];
    }

    usort($entries, static function (array $left, array $right): int {
        $path = portableCaseFold($left['final_relative_markdown_path']) <=> portableCaseFold($right['final_relative_markdown_path']);
        if ($path !== 0) {
            return $path;
        }
        return ($left['standard_notes_uuid'] ?? '') <=> ($right['standard_notes_uuid'] ?? '');
    });

    return $plan['manifest_context'] + ['notes' => $entries];
}

function createDirectoryChain(string $directory, array &$createdDirectories): void
{
    $missing = [];
    $cursor = $directory;
    while (!is_dir($cursor)) {
        if (file_exists($cursor) || is_link($cursor)) {
            throw new ExportFailure('The output parent path is not a directory.');
        }
        $missing[] = $cursor;
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            throw new ExportFailure('No usable parent directory exists for the output path.');
        }
        $cursor = $parent;
    }
    if (!is_writable($cursor)) {
        throw new ExportFailure('The output parent directory is not writable.');
    }
    foreach (array_reverse($missing) as $path) {
        if (!mkdir($path, 0700) && !is_dir($path)) {
            throw new ExportFailure('An output parent directory could not be created.');
        }
        $createdDirectories[] = $path;
    }
}

function removeCreatedTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    $entries = scandir($path);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeCreatedTree($path . '/' . $entry);
    }
    @rmdir($path);
}

function cleanupCreatedParents(array $createdParents): void
{
    foreach (array_reverse($createdParents) as $path) {
        if (is_dir($path)) {
            @rmdir($path);
        }
    }
}

function ensureInsideStaging(string $stagingRoot, string $directory): void
{
    $rootReal = realpath($stagingRoot);
    $directoryReal = realpath($directory);
    if ($rootReal === false || $directoryReal === false) {
        throw new ExportFailure('An export directory could not be resolved safely.');
    }
    if ($directoryReal !== $rootReal && !str_starts_with($directoryReal, $rootReal . DIRECTORY_SEPARATOR)) {
        throw new ExportFailure('A planned path escaped the staging directory.');
    }
}

function writeVault(array &$plan, string $outputPath): void
{
    $resolvedOutput = resolvePath($outputPath);
    if ($resolvedOutput === '/' || file_exists($resolvedOutput) || is_link($resolvedOutput)) {
        throw new ExportFailure('The output directory already exists or is not a safe new directory.');
    }

    $parent = dirname($resolvedOutput);
    $createdParents = [];
    $staging = '';
    try {
        createDirectoryChain($parent, $createdParents);
        do {
            $staging = $parent . '/.' . basename($resolvedOutput) . '.standardnotes-staging-' . bin2hex(random_bytes(8));
        } while (file_exists($staging) || is_link($staging));
        if (!mkdir($staging, 0700)) {
            throw new ExportFailure('The private staging directory could not be created.');
        }

        foreach ($plan['notes'] as $index => &$note) {
            $directory = $staging . '/' . implode('/', $note['directory_segments']);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new ExportFailure('A note directory could not be created.');
            }
            ensureInsideStaging($staging, $directory);
            $destination = $staging . '/' . $note['relative_path'];
            if (file_exists($destination) || is_link($destination)) {
                throw new ExportFailure('A planned note path unexpectedly already exists in staging.');
            }
            $written = file_put_contents($destination, $note['body'], LOCK_EX);
            if ($written === false || $written !== strlen($note['body'])) {
                throw new ExportFailure('A Markdown note could not be written completely.');
            }
            @chmod($destination, 0600);
            $verification = file_get_contents($destination);
            if ($verification === false || !hash_equals($note['body_hash'], hash('sha256', $verification))) {
                throw new ExportFailure('A written Markdown note failed byte-for-byte verification.');
            }
            if ($note['timestamps']['modified_date'] !== null) {
                if (!@touch($destination, $note['timestamps']['modified_date']->getTimestamp())) {
                    $note['issues'][] = 'filesystem_timestamp_could_not_be_set';
                    $note['issues'] = array_values(array_unique($note['issues']));
                    $plan['summary']['timestamp_warnings']++;
                }
            }
            if ($plan['options']['verbose']) {
                fwrite(STDOUT, 'Validated export record ' . ($index + 1) . ' of ' . count($plan['notes']) . '.' . PHP_EOL);
            }
        }
        unset($note);

        $manifest = manifestForPlan($plan);
        try {
            $manifestJson = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new ExportFailure('The manifest could not be encoded as UTF-8 JSON.');
        }
        $manifestPath = $staging . '/' . MANIFEST_FILENAME;
        $manifestWritten = file_put_contents($manifestPath, $manifestJson, LOCK_EX);
        if ($manifestWritten === false || $manifestWritten !== strlen($manifestJson)) {
            throw new ExportFailure('The manifest could not be written completely.');
        }
        @chmod($manifestPath, 0600);
        try {
            json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExportFailure('The written manifest failed JSON verification.');
        }

        if (!rename($staging, $resolvedOutput)) {
            throw new ExportFailure('The completed staging directory could not be moved into place.');
        }
        $staging = '';
    } finally {
        if ($staging !== '' && (file_exists($staging) || is_link($staging))) {
            removeCreatedTree($staging);
        }
        cleanupCreatedParents($createdParents);
    }
}

function validateOutputTarget(string $outputPath): string
{
    $resolved = resolvePath($outputPath);
    if ($resolved === '/' || file_exists($resolved) || is_link($resolved)) {
        throw new ExportFailure('The output directory already exists or is not a safe new directory.');
    }
    $cursor = dirname($resolved);
    while (!file_exists($cursor)) {
        $next = dirname($cursor);
        if ($next === $cursor) {
            throw new ExportFailure('No existing parent directory is available for the output path.');
        }
        $cursor = $next;
    }
    if (!is_dir($cursor) || !is_writable($cursor)) {
        throw new ExportFailure('The output location has no writable parent directory.');
    }
    return $resolved;
}

function printSummary(array $summary, string $outputPath, bool $dryRun): void
{
    $resolvedOutput = resolvePath($outputPath);
    $label = $dryRun ? 'Dry run complete; nothing was written.' : 'Export complete.';
    $lines = [
        $label,
        'Total note records found: ' . $summary['total_notes_found'],
        'Active notes found/exported: ' . $summary['active_notes_found'] . '/' . $summary['active_notes_exported'],
        'Archived notes found/exported: ' . $summary['archived_notes_found'] . '/' . $summary['archived_notes_exported'],
        'Trashed notes found/exported: ' . $summary['trashed_notes_found'] . '/' . $summary['trashed_notes_exported'],
        'Notes placed in Unfiled: ' . $summary['unfiled_notes'],
        'Filenames derived from body content: ' . $summary['filenames_derived_from_body'],
        'Notes remaining Untitled after fallback: ' . $summary['filenames_remaining_untitled'],
        'Filename collisions resolved: ' . $summary['filename_collisions'],
        'New filename collisions caused by derived titles: ' . $summary['derived_title_filename_collisions'],
        'Duplicate or malformed records encountered: ' . $summary['duplicate_or_malformed_records'],
        'Timestamp warnings: ' . $summary['timestamp_warnings'],
        'Lexical notes detected/converted: ' . $summary['lexical_notes_detected'] . '/' . $summary['lexical_notes_converted'],
        'Unsupported Lexical structures: ' . $summary['unsupported_lexical_structures'],
        'Lexical conversion warnings: ' . $summary['lexical_conversion_warnings'],
        'Structured task notes detected/converted: ' . $summary['structured_task_notes_detected'] . '/' . $summary['structured_task_notes_converted'],
        'Task conversion warnings: ' . $summary['task_conversion_warnings'],
        'Unsupported task structures: ' . $summary['unsupported_task_structures'],
        'Unrecognized JSON-like notes preserved: ' . $summary['unrecognized_json_like_notes_preserved'],
        'Editor-specific text notes preserved unchanged: ' . $summary['editor_specific_text_notes_preserved'],
        'Conversion warnings: ' . $summary['conversion_warnings'],
        'Vault path: ' . $resolvedOutput,
        'Manifest path: ' . $resolvedOutput . '/' . MANIFEST_FILENAME,
    ];
    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}

function runExporter(array $argv): int
{
    try {
        $arguments = parseArguments($argv);
        if ($arguments['help']) {
            printHelp();
            return 0;
        }
        validateOutputTarget($arguments['output']);
        $backup = readBackup($arguments['input']);
        $plan = buildExportPlan($backup, $arguments['options']);
        $plan['options'] = $arguments['options'];

        if (!$arguments['options']['dry_run']) {
            writeVault($plan, $arguments['output']);
        }
        printSummary($plan['summary'], $arguments['output'], $arguments['options']['dry_run']);
        return 0;
    } catch (ExportFailure $exception) {
        fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
        return 1;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Error: The export stopped because of an unexpected internal failure.' . PHP_EOL);
        return 1;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(runExporter($argv));
}
