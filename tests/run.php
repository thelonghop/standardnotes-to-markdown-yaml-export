<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$script = $root . '/standard-notes-to-markdown.php';
$fixture = $root . '/tests/fixtures/comprehensive-export.json';
$temporaryRoot = sys_get_temp_dir() . '/standard-notes-export-tests-' . bin2hex(random_bytes(6));
$assertions = 0;

require_once $script;

function failTest(string $message): never
{
    throw new RuntimeException($message);
}

function assertTrue(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        failTest($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        failTest($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

/** @return array{exit: int, stdout: string, stderr: string} */
function runCommand(array $arguments): array
{
    global $root, $script;
    $command = array_merge([PHP_BINARY, $script], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        failTest('Could not start the exporter process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return ['exit' => $exit, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . '/' . $entry);
    }
    rmdir($path);
}

/** @return array<string, string> */
function markdownFiles(string $vault): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vault, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($vault) + 1));
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

/** @return array<string, string> */
function vaultSnapshot(string $vault): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($vault, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($vault) + 1));
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    return $files;
}

function loadManifest(string $vault): array
{
    $path = $vault . '/_standardnotes-export-manifest.json';
    assertTrue(is_file($path), 'Manifest was not created.');
    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(is_array($manifest), 'Manifest is not a JSON object.');
    return $manifest;
}

/** @return array<int, array<string, mixed>> */
function entriesForUuid(array $manifest, ?string $uuid): array
{
    return array_values(array_filter(
        $manifest['notes'],
        static fn(array $entry): bool => $entry['standard_notes_uuid'] === $uuid
    ));
}

function singleEntry(array $manifest, string $uuid): array
{
    $entries = entriesForUuid($manifest, $uuid);
    assertSameValue(1, count($entries), 'Expected exactly one manifest entry for a fictional UUID.');
    return $entries[0];
}

/** @return array<int, string> */
function expectedDefaultBodies(string $fixture): array
{
    $backup = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
    $seen = [];
    $bodies = [];
    foreach ($backup['items'] as $item) {
        if (($item['content_type'] ?? null) !== 'Note' || !is_array($item['content'] ?? null)) {
            continue;
        }
        $content = $item['content'];
        $domain = is_array($content['appData']['org.standardnotes.sn'] ?? null)
            ? $content['appData']['org.standardnotes.sn']
            : [];
        $trashed = ($content['trashed'] ?? false) === true || ($domain['trashed'] ?? false) === true;
        if ($trashed) {
            continue;
        }
        $identity = ($item['uuid'] ?? '__missing') . ':' . hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (isset($seen[$identity])) {
            continue;
        }
        $seen[$identity] = true;
        $bodies[] = is_string($content['text'] ?? null) ? $content['text'] : '';
    }
    sort($bodies, SORT_STRING);
    return $bodies;
}

function fictionalNote(string $uuid, string $title, string $text, array $extraContent = []): array
{
    return [
        'uuid' => $uuid,
        'content_type' => 'Note',
        'content' => $extraContent + ['title' => $title, 'text' => $text, 'references' => []],
        'created_at' => '2025-04-01T00:00:00.000Z',
        'updated_at' => '2025-04-02T00:00:00.000Z',
    ];
}

try {
    if (!mkdir($temporaryRoot, 0700, true)) {
        failTest('Could not create the test directory.');
    }

    $lexicalDocument = [
        'root' => [
            'type' => 'root',
            'version' => 1,
            'format' => '',
            'indent' => 0,
            'direction' => null,
            'children' => [
                ['type' => 'heading', 'version' => 1, 'tag' => 'h2', 'children' => [
                    ['type' => 'text', 'version' => 1, 'text' => 'Fictional Orchard 🍋', 'format' => 0],
                ]],
                ['type' => 'paragraph', 'version' => 1, 'format' => 'center', 'children' => [
                    ['type' => 'text', 'version' => 1, 'text' => 'Plain *symbols* ', 'format' => 0],
                    ['type' => 'text', 'version' => 1, 'text' => 'bold', 'format' => 1],
                    ['type' => 'text', 'version' => 1, 'text' => ' italic', 'format' => 2],
                    ['type' => 'text', 'version' => 1, 'text' => ' both', 'format' => 3],
                    ['type' => 'text', 'version' => 1, 'text' => ' strike', 'format' => 4],
                    ['type' => 'text', 'version' => 1, 'text' => ' underline', 'format' => 8],
                    ['type' => 'text', 'version' => 1, 'text' => ' code`tick', 'format' => 16],
                    ['type' => 'text', 'version' => 1, 'text' => ' highlight', 'format' => 128],
                    ['type' => 'linebreak', 'version' => 1],
                    ['type' => 'hashtag', 'version' => 1, 'text' => '#Invented', 'format' => 0],
                ]],
                ['type' => 'paragraph', 'version' => 1, 'format' => 'start', 'children' => [
                    ['type' => 'link', 'version' => 1, 'url' => 'https://example.invalid/fictional_(page)', 'title' => 'Invented link', 'children' => [
                        ['type' => 'text', 'version' => 1, 'text' => 'Reference', 'format' => 0],
                    ]],
                    ['type' => 'text', 'version' => 1, 'text' => ' and ', 'format' => 0],
                    ['type' => 'autolink', 'version' => 1, 'url' => 'https://example.invalid/automatic', 'children' => [
                        ['type' => 'text', 'version' => 1, 'text' => 'https://example.invalid/automatic', 'format' => 0],
                    ]],
                ]],
                ['type' => 'quote', 'version' => 1, 'children' => [
                    ['type' => 'text', 'version' => 1, 'text' => 'Invented quotation', 'format' => 0],
                ]],
                ['type' => 'list', 'version' => 1, 'listType' => 'bullet', 'start' => 1, 'children' => [
                    ['type' => 'listitem', 'version' => 1, 'value' => 1, 'children' => [
                        ['type' => 'paragraph', 'version' => 1, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Outer fruit', 'format' => 0]]],
                        ['type' => 'list', 'version' => 1, 'listType' => 'number', 'start' => 2, 'children' => [
                            ['type' => 'listitem', 'version' => 1, 'value' => 2, 'children' => [
                                ['type' => 'paragraph', 'version' => 1, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Nested seed', 'format' => 0]]],
                            ]],
                        ]],
                    ]],
                ]],
                ['type' => 'list', 'version' => 1, 'listType' => 'check', 'start' => 1, 'children' => [
                    ['type' => 'listitem', 'version' => 1, 'checked' => false, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Water sapling', 'format' => 0]]],
                    ['type' => 'listitem', 'version' => 1, 'checked' => true, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Label planter', 'format' => 0]]],
                ]],
                ['type' => 'code', 'version' => 1, 'language' => 'php', 'children' => [
                    ['type' => 'text', 'version' => 1, 'text' => "<?php\n\$fruit = 'invented';", 'format' => 0],
                ]],
                ['type' => 'horizontalrule', 'version' => 1],
                ['type' => 'table', 'version' => 1, 'children' => [
                    ['type' => 'tablerow', 'version' => 1, 'children' => [
                        ['type' => 'tablecell', 'version' => 1, 'headerState' => 0, 'colSpan' => 1, 'rowSpan' => 1, 'width' => 120, 'format' => 'center', 'children' => [
                            ['type' => 'paragraph', 'version' => 1, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Variety', 'format' => 0]]],
                        ]],
                        ['type' => 'tablecell', 'version' => 1, 'headerState' => 0, 'colSpan' => 1, 'rowSpan' => 1, 'children' => [
                            ['type' => 'paragraph', 'version' => 1, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Count | note', 'format' => 0]]],
                        ]],
                    ]],
                    ['type' => 'tablerow', 'version' => 1, 'children' => [
                        ['type' => 'tablecell', 'version' => 1, 'headerState' => 0, 'colSpan' => 2, 'rowSpan' => 1, 'children' => [
                            ['type' => 'paragraph', 'version' => 1, 'children' => [['type' => 'text', 'version' => 1, 'text' => 'Fictional total', 'format' => 0]]],
                        ]],
                    ]],
                ]],
                ['type' => 'paragraph', 'version' => 1, 'children' => [
                    ['type' => 'snfile', 'version' => 1, 'fileUuid' => '61000000-0000-4000-8000-000000000099'],
                ]],
                ['type' => 'inventedcontainer', 'version' => 1, 'children' => [
                    ['type' => 'text', 'version' => 1, 'text' => 'Fallback child retained', 'format' => 0],
                ]],
            ],
        ],
    ];
    $taskDocument = [
        'schemaVersion' => '1.0.0',
        'defaultSections' => [
            ['id' => 'open-tasks', 'name' => 'Planned'],
            ['id' => 'completed-tasks', 'name' => 'Finished'],
        ],
        'groups' => [
            [
                'name' => 'Fictional Workshop',
                'draft' => 'Capture invented draft',
                'sections' => [
                    ['id' => 'open-tasks', 'name' => 'Planned'],
                    ['id' => 'completed-tasks', 'name' => 'Finished'],
                ],
                'tasks' => [
                    ['description' => 'Assemble [sample] frame', 'completed' => false],
                    ['description' => "Test fictional latch\nRecord result", 'completed' => true],
                ],
            ],
            [
                'name' => 'Empty Fictional Group',
                'draft' => '',
                'sections' => [
                    ['id' => 'open-tasks', 'name' => 'Planned'],
                    ['id' => 'completed-tasks', 'name' => 'Finished'],
                ],
                'tasks' => [],
            ],
        ],
    ];
    $plainMarkdownBody = "---\nkind: user-authored\n---\n# Fictional plain note\n";
    $plainBraceBody = "{This is ordinary fictional text, not JSON.}\n";
    $ordinaryJsonBody = '{"invented":"ordinary user JSON","items":[1,2]}';
    $textLikeTaskBody = "- [ ] Already textual fictional task\n";
    $richTaskFixture = $temporaryRoot . '/rich-task-export.json';
    file_put_contents($richTaskFixture, json_encode([
        'items' => [
            fictionalNote(
                '61000000-0000-4000-8000-000000000001',
                'Fictional Rich Document',
                json_encode($lexicalDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['noteType' => 'super', 'editorIdentifier' => 'com.standardnotes.super-editor']
            ),
            fictionalNote(
                '61000000-0000-4000-8000-000000000002',
                'Fictional Structured Tasks',
                json_encode($taskDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['noteType' => 'task', 'editorIdentifier' => 'com.sncommunity.advanced-checklist']
            ),
            fictionalNote('61000000-0000-4000-8000-000000000003', 'Fictional Plain Markdown', $plainMarkdownBody, ['noteType' => 'markdown']),
            fictionalNote('61000000-0000-4000-8000-000000000004', 'Fictional Brace Text', $plainBraceBody, ['noteType' => 'plain-text']),
            fictionalNote('61000000-0000-4000-8000-000000000005', 'Fictional Ordinary JSON', $ordinaryJsonBody, ['noteType' => 'plain-text']),
            fictionalNote(
                '61000000-0000-4000-8000-000000000006',
                'Fictional Text Task',
                $textLikeTaskBody,
                ['noteType' => 'task', 'editorIdentifier' => 'org.standardnotes.simple-task-editor']
            ),
            fictionalNote(
                '61000000-0000-4000-8000-000000000007',
                'Fictional Structured Tasks Alternate',
                json_encode($taskDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['noteType' => 'task', 'editorIdentifier' => 'org.standardnotes.simple-task-editor']
            ),
            fictionalNote(
                '61000000-0000-4000-8000-000000000008',
                'Fictional Unsupported Task Shape',
                json_encode([
                    'schemaVersion' => '9.9.9',
                    'defaultSections' => [],
                    'groups' => [['name' => 'Fictional Broken Group', 'tasks' => 'invalid fixture value']],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['noteType' => 'task', 'editorIdentifier' => 'com.sncommunity.advanced-checklist']
            ),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $titleFixtureItems = [];
    $titleExpectations = [];
    $titleCaseIndex = 0;
    $addTitleCase = static function (
        string $sourceTitle,
        string $body,
        ?string $expectedBase,
        string $expectedSource,
        array $extraContent = []
    ) use (&$titleFixtureItems, &$titleExpectations, &$titleCaseIndex): string {
        $titleCaseIndex++;
        $uuid = sprintf('62000000-0000-4000-8000-%012d', $titleCaseIndex);
        $note = fictionalNote($uuid, $sourceTitle, $body, $extraContent);
        $titleFixtureItems[] = $note;
        $titleExpectations[$uuid] = [
            'source_title' => $sourceTitle,
            'source_body' => $body,
            'final_body' => processNoteBody($note['content'], $body)['body'],
            'expected_base' => $expectedBase,
            'expected_source' => $expectedSource,
        ];
        return $uuid;
    };

    $addTitleCase('', "Normal fictional first line\nSecond line\n", 'Normal fictional first line', 'first_meaningful_body_line');
    $addTitleCase('Untitled', "Literal untitled source\n", 'Literal untitled source', 'first_meaningful_body_line');
    $addTitleCase('uNtItLeD', "Case-insensitive source\n", 'Case-insensitive source', 'first_meaningful_body_line');
    $meaningfulTitleUuid = $addTitleCase('Existing Fictional Name', "Body must not replace this name\n", 'Existing Fictional Name', 'standard_notes_title');
    $addTitleCase('', "\n\n  \nLeading blanks skipped\n", 'Leading blanks skipped', 'first_meaningful_body_line');
    $addTitleCase('', "### Heading-derived title\n", 'Heading-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "**Bold-derived title**\n", 'Bold-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "_Italic-derived title_\n", 'Italic-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "- Bullet-derived title\n", 'Bullet-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "7. Numbered-derived title\n", 'Numbered-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "- [ ] Unchecked-derived title\n", 'Unchecked-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "- [x] Checked-derived title\n", 'Checked-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "> ### **Quoted-derived title**\n", 'Quoted-derived title', 'first_meaningful_body_line');
    $addTitleCase('', "`Inline-code title`\n", 'Inline-code title', 'first_meaningful_body_line');
    $addTitleCase('', "<h1>Tea &amp; Biscuits</h1>\n", 'Tea & Biscuits', 'first_meaningful_body_line');
    $addTitleCase('', "---\nAfter horizontal rule\n", 'After horizontal rule', 'first_meaningful_body_line');
    $addTitleCase('', "```markdown\nFenced visible line\n```\n", 'Fenced visible line', 'first_meaningful_body_line');
    $addTitleCase('', "| :--- | ---: |\nAfter table delimiter\n", 'After table delimiter', 'first_meaningful_body_line');
    $addTitleCase('', "Café 🚀 Ω title\n", 'Café 🚀 Ω title', 'first_meaningful_body_line');
    $addTitleCase('', "Release 2.0, ready!\n", 'Release 2.0, ready!', 'first_meaningful_body_line');
    $structuralOnlyUuid = $addTitleCase('', "---\n***\n___\n```\n| --- | :---: |\n!!!\n<!-- fictional -->\n", 'Untitled', 'fallback_untitled');
    $emptyUntitledUuid = $addTitleCase('Untitled', '', 'Untitled', 'fallback_untitled');
    $derivedCollisionOne = $addTitleCase('', "Shared derived title\n", 'Shared derived title', 'first_meaningful_body_line');
    $derivedCollisionTwo = $addTitleCase('untitled', "Shared derived title\nDifferent body\n", 'Shared derived title', 'first_meaningful_body_line');
    $reservedDerivedUuid = $addTitleCase('', "CON\n", '_CON', 'first_meaningful_body_line');
    $unsafeDerivedUuid = $addTitleCase('', "../Unsafe\\Name\n", '..-Unsafe-Name', 'first_meaningful_body_line');
    $longDerivedUuid = $addTitleCase('', str_repeat('Long fictional title ', 20) . "\n", null, 'first_meaningful_body_line');
    $lexicalTitleUuid = $addTitleCase(
        '',
        json_encode($lexicalDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'Fictional Orchard 🍋',
        'first_meaningful_body_line',
        ['noteType' => 'super', 'editorIdentifier' => 'com.standardnotes.super-editor']
    );
    $taskTitleUuid = $addTitleCase(
        'Untitled',
        json_encode($taskDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'Fictional Workshop',
        'first_meaningful_body_line',
        ['noteType' => 'task', 'editorIdentifier' => 'com.sncommunity.advanced-checklist']
    );
    $scanLimitUuid = $addTitleCase('', str_repeat("\n", 100) . "Beyond documented scan limit\n", 'Untitled', 'fallback_untitled');
    $titleFixture = $temporaryRoot . '/title-derivation-export.json';
    file_put_contents($titleFixture, json_encode(
        ['items' => $titleFixtureItems],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));

    $help = runCommand(['--help']);
    assertSameValue(0, $help['exit'], 'Help command failed.');
    assertTrue(str_contains($help['stdout'], '--standalone-tags=folders'), 'Help omits standalone folder mode.');
    assertTrue(str_contains($help['stdout'], '--include-trashed'), 'Help omits trashed option.');

    $invalidArgument = runCommand(['--not-a-real-option', $fixture, $temporaryRoot . '/invalid-option']);
    assertTrue($invalidArgument['exit'] !== 0, 'Unknown option unexpectedly succeeded.');

    $dryOutput = $temporaryRoot . '/dry-run-vault';
    $dryRun = runCommand(['--dry-run', $fixture, $dryOutput]);
    assertSameValue(0, $dryRun['exit'], 'Dry run failed: ' . $dryRun['stderr']);
    assertTrue(!file_exists($dryOutput), 'Dry run created an output directory.');
    assertTrue(str_contains($dryRun['stdout'], 'nothing was written'), 'Dry-run report is unclear.');

    $vault = $temporaryRoot . '/default-vault';
    $result = runCommand([$fixture, $vault]);
    assertSameValue(0, $result['exit'], 'Default conversion failed: ' . $result['stderr']);
    assertTrue(is_dir($vault), 'Default vault was not created.');
    assertTrue(!str_contains($result['stdout'], 'FIRST-CHARACTER-MARKER'), 'Console output leaked a note body.');
    assertTrue(!str_contains($result['stdout'], '20000000-0000-4000-8000-000000000001'), 'Console output leaked a UUID.');
    assertTrue(!str_contains($result['stdout'], 'Meeting'), 'Console output leaked a title.');
    assertTrue(str_contains($result['stdout'], 'Editor-specific text notes preserved unchanged: 1'), 'Editor-specific warning count is missing.');

    $manifest = loadManifest($vault);
    assertSameValue(2, $manifest['schema_version'], 'Unexpected manifest schema version.');
    assertSameValue('folders', $manifest['folder_mapping']['standalone_tags_mode'], 'Standalone tags are not folders by default.');
    assertTrue(str_contains($manifest['folder_mapping']['standalone_tag_ambiguity'], 'does not identify'), 'Manifest omits standalone-tag ambiguity.');
    assertTrue(count($manifest['global_issues']) > 0, 'Manifest omits global malformed-reference conditions.');

    $meetingA = singleEntry($manifest, '20000000-0000-4000-8000-000000000001');
    $meetingB = singleEntry($manifest, '20000000-0000-4000-8000-000000000002');
    assertSameValue('Projects/Research/Meeting.md', $meetingA['final_relative_markdown_path'], 'First collision filename is wrong.');
    assertSameValue('Projects/Research/Meeting (2).md', $meetingB['final_relative_markdown_path'], 'Second collision filename is wrong.');
    assertSameValue('Meeting (2).md', $meetingB['collision_adjusted_filename'], 'Manifest omits adjusted filename.');

    $standalone = singleEntry($manifest, '20000000-0000-4000-8000-000000000004');
    assertSameValue('Standalone/Café 🚀.md', $standalone['final_relative_markdown_path'], 'Standalone tag was not used as a top-level folder.');
    assertSameValue('standalone-tag-assumed-folder', $standalone['folder_candidates'][0]['source'], 'Standalone folder source is not documented.');

    $deep = singleEntry($manifest, '20000000-0000-4000-8000-000000000012');
    assertSameValue('Projects/Research/Deep', $deep['selected_folder_path'], 'Deepest folder candidate was not selected.');
    $alphabetical = singleEntry($manifest, '20000000-0000-4000-8000-000000000013');
    assertSameValue('Alpha/Beta', $alphabetical['selected_folder_path'], 'Alphabetical tie-break was not applied.');
    assertSameValue(2, count($alphabetical['detected_folder_paths']), 'Manifest does not preserve competing folder paths.');

    $frontmatter = singleEntry($manifest, '20000000-0000-4000-8000-000000000003');
    assertTrue(str_starts_with((string) file_get_contents($vault . '/' . $frontmatter['final_relative_markdown_path']), "---\nuser-owned"), 'User-owned YAML-like content was altered.');
    $emptyBody = singleEntry($manifest, '20000000-0000-4000-8000-000000000006');
    assertSameValue('', file_get_contents($vault . '/' . $emptyBody['final_relative_markdown_path']), 'Empty body was not preserved.');

    $archived = singleEntry($manifest, '20000000-0000-4000-8000-000000000015');
    assertTrue(str_starts_with($archived['final_relative_markdown_path'], '_Archived/Archive/Top/'), 'Archived note path is wrong.');
    assertTrue($archived['archived'] === true, 'Archived status missing from manifest.');
    assertSameValue([], entriesForUuid($manifest, '20000000-0000-4000-8000-000000000016'), 'Trashed note was exported by default.');
    $pinned = singleEntry($manifest, '20000000-0000-4000-8000-000000000017');
    assertTrue($pinned['pinned'] === true, 'Pinned status missing from manifest.');

    $rich = singleEntry($manifest, '20000000-0000-4000-8000-000000000018');
    assertTrue($rich['content_format']['may_not_be_markdown'] === true, 'Rich content was not detected.');
    assertSameValue('rich-text', $rich['content_format']['note_type'], 'Rich note type missing from manifest.');
    assertSameValue("<p>RICH-EDITOR-BODY-MARKER <strong>unchanged</strong></p>\n", file_get_contents($vault . '/' . $rich['final_relative_markdown_path']), 'Rich content was altered.');
    assertTrue($meetingA['content_format']['may_not_be_markdown'] === false, 'Markdown content was incorrectly marked rich.');

    $repeated = entriesForUuid($manifest, '20000000-0000-4000-8000-000000000019');
    assertSameValue(2, count($repeated), 'Differing repeated-UUID records were discarded.');
    $exactRepeat = singleEntry($manifest, '20000000-0000-4000-8000-000000000022');
    assertSameValue(2, $exactRepeat['source_record_count'], 'Identical repeated records were not recorded.');
    $duplicateOf = singleEntry($manifest, '20000000-0000-4000-8000-000000000023');
    assertSameValue('20000000-0000-4000-8000-000000000001', $duplicateOf['duplicate_of'], 'duplicate_of relationship missing.');
    $missingTag = singleEntry($manifest, '20000000-0000-4000-8000-000000000024');
    assertTrue(in_array('note_references_nonexistent_tag', $missingTag['issues'], true), 'Missing tag reference was not recorded.');

    $metadataEntry = singleEntry($manifest, '20000000-0000-4000-8000-000000000014');
    assertSameValue("Manifest \"Quote\"\nLine Ω", $metadataEntry['original_title'], 'Manifest did not preserve quoted Unicode metadata.');
    assertSameValue("Meta \"Tag\"\nΩ", $metadataEntry['original_standard_notes_tags'][0], 'Manifest did not preserve tag metadata.');

    $markdown = markdownFiles($vault);
    $actualBodies = array_values($markdown);
    sort($actualBodies, SORT_STRING);
    assertSameValue(expectedDefaultBodies($fixture), $actualBodies, 'Generated Markdown bodies do not exactly match retained fixture bodies.');
    foreach ($markdown as $relative => $body) {
        assertTrue(strlen($relative) <= 240, 'A relative path exceeds the portable length limit.');
        assertTrue(preg_match('/ \d{14}(?: \(\d+\))?\.md$/', basename($relative)) !== 1, 'An old creation-date suffix remains.');
        assertTrue(preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/', basename($relative)) !== 1, 'A filename contains a cross-platform-invalid character.');
        assertTrue(preg_match('/[ .]\.md$/', basename($relative)) !== 1, 'A filename retains a trailing space or period.');
        $real = realpath($vault . '/' . $relative);
        assertTrue($real !== false && str_starts_with($real, realpath($vault) . DIRECTORY_SEPARATOR), 'A note escaped the vault.');
    }
    $manifestJson = (string) file_get_contents($vault . '/_standardnotes-export-manifest.json');
    foreach (array_filter($actualBodies, static fn(string $body): bool => $body !== '') as $body) {
        assertTrue(!str_contains($manifestJson, $body), 'Manifest contains note body text.');
    }

    $richTaskVault = $temporaryRoot . '/rich-task-vault';
    $richTaskResult = runCommand([$richTaskFixture, $richTaskVault]);
    assertSameValue(0, $richTaskResult['exit'], 'Fictional rich/task export failed: ' . $richTaskResult['stderr']);
    assertTrue(str_contains($richTaskResult['stdout'], 'Lexical notes detected/converted: 1/1'), 'Lexical summary counts are wrong.');
    assertTrue(str_contains($richTaskResult['stdout'], 'Structured task notes detected/converted: 3/3'), 'Structured-task summary counts are wrong.');
    assertTrue(str_contains($richTaskResult['stdout'], 'Unrecognized JSON-like notes preserved: 1'), 'Unrecognized JSON summary count is wrong.');
    assertTrue(str_contains($richTaskResult['stdout'], 'Editor-specific text notes preserved unchanged: 1'), 'Text-like task preservation count is wrong.');
    $richTaskManifest = loadManifest($richTaskVault);
    assertSameValue(2, $richTaskManifest['schema_version'], 'Rich/task export did not use manifest schema 2.');
    assertTrue(isset($richTaskManifest['content_conversion']), 'Manifest omits its conversion policy.');

    $lexicalEntry = singleEntry($richTaskManifest, '61000000-0000-4000-8000-000000000001');
    $lexicalBody = (string) file_get_contents($richTaskVault . '/' . $lexicalEntry['final_relative_markdown_path']);
    assertTrue(!str_starts_with(ltrim($lexicalBody), '{'), 'Recognized Lexical JSON was written raw.');
    assertTrue(str_starts_with($lexicalBody, "## Fictional Orchard 🍋\n\n"), 'Lexical heading or initial-byte behavior is wrong.');
    assertTrue(str_contains($lexicalBody, '**bold**'), 'Lexical bold formatting was not converted.');
    assertTrue(str_contains($lexicalBody, ' *italic*'), 'Lexical italic formatting was not converted.');
    assertTrue(str_contains($lexicalBody, ' ~~strike~~'), 'Lexical strikethrough formatting was not converted.');
    assertTrue(str_contains($lexicalBody, ' <u>underline</u>'), 'Lexical underline formatting was not retained.');
    assertTrue(str_contains($lexicalBody, ' <mark>highlight</mark>'), 'Lexical highlight formatting was not retained.');
    assertTrue(str_contains($lexicalBody, '- [ ] Water sapling'), 'Lexical unchecked task was not converted.');
    assertTrue(str_contains($lexicalBody, '- [x] Label planter'), 'Lexical checked task was not converted.');
    assertTrue(str_contains($lexicalBody, '| Variety | Count \\| note |'), 'Lexical table was not converted to GFM.');
    assertTrue(str_contains($lexicalBody, '[Embedded file unavailable from this backup]'), 'Unresolved file placeholder is missing.');
    assertTrue(!str_contains($lexicalBody, '61000000-0000-4000-8000-000000000099'), 'Unresolved file placeholder exposed its UUID.');
    assertTrue(str_contains($lexicalBody, 'Fallback child retained'), 'Unknown Lexical container discarded understood child text.');
    assertTrue(!str_contains($lexicalBody, '<div') && !str_contains($lexicalBody, '<p align'), 'Block alignment introduced an HTML wrapper.');
    assertTrue(in_array('block_alignment_omitted', $lexicalEntry['content_format']['conversion_warnings'], true), 'Omitted alignment warning is missing.');
    assertTrue(in_array('table_header_synthesized_from_first_row', $lexicalEntry['content_format']['conversion_warnings'], true), 'Synthesized table-header warning is missing.');
    assertTrue(in_array('snfile', $lexicalEntry['content_format']['unsupported_node_types'], true), 'Unresolved file node was not diagnosed.');
    assertTrue(in_array('inventedcontainer', $lexicalEntry['content_format']['unsupported_node_types'], true), 'Unknown Lexical node was not diagnosed.');
    foreach (['root', 'paragraph', 'text', 'heading', 'linebreak', 'link', 'autolink', 'hashtag', 'list', 'listitem', 'table', 'tablerow', 'tablecell', 'snfile'] as $nodeType) {
        assertTrue(in_array($nodeType, $lexicalEntry['content_format']['node_types'], true), 'A fictional Lexical node type was not inventoried.');
    }

    $expectedTaskMarkdown = "## Fictional Workshop\n\n"
        . "- [ ] Capture invented draft\n\n"
        . "### Planned\n\n"
        . "- [ ] Assemble \\[sample\\] frame\n\n"
        . "### Finished\n\n"
        . "- [x] Test fictional latch<br>Record result\n\n"
        . "## Empty Fictional Group\n\n"
        . "### Planned\n\n"
        . "### Finished";
    $taskEntry = singleEntry($richTaskManifest, '61000000-0000-4000-8000-000000000002');
    $taskBody = (string) file_get_contents($richTaskVault . '/' . $taskEntry['final_relative_markdown_path']);
    assertSameValue($expectedTaskMarkdown, $taskBody, 'Structured tasks did not map exactly to headings and checklists.');
    assertTrue(strpos($taskBody, 'Capture invented draft') < strpos($taskBody, '### Planned'), 'Saved task draft was not written before sections.');
    assertSameValue('1.0.0', $taskEntry['content_format']['task_schema_version'], 'Task schema version is missing from the manifest.');
    assertSameValue(2, $taskEntry['content_format']['task_group_count'], 'Task group count is wrong.');
    assertSameValue(2, $taskEntry['content_format']['task_count'], 'Task count is wrong.');
    assertTrue(in_array('task_group_draft_exported_as_unchecked_task', $taskEntry['content_format']['conversion_warnings'], true), 'Task-draft conversion warning is missing.');
    assertSameValue([], $taskEntry['content_format']['unsupported_task_structures'], 'Supported fictional task schema was marked unsupported.');

    $alternateTaskEntry = singleEntry($richTaskManifest, '61000000-0000-4000-8000-000000000007');
    assertSameValue(
        $expectedTaskMarkdown,
        file_get_contents($richTaskVault . '/' . $alternateTaskEntry['final_relative_markdown_path']),
        'Structured tasks under the alternate official identifier were not converted.'
    );
    assertTrue(in_array('task_schema_editor_identifier_mismatch', $alternateTaskEntry['content_format']['conversion_warnings'], true), 'Alternate task identifier warning is missing.');

    $unsupportedTaskEntry = singleEntry($richTaskManifest, '61000000-0000-4000-8000-000000000008');
    $unsupportedTaskBody = (string) file_get_contents($richTaskVault . '/' . $unsupportedTaskEntry['final_relative_markdown_path']);
    assertTrue(!str_starts_with(ltrim($unsupportedTaskBody), '{'), 'Recognized unsupported task JSON was emitted raw.');
    assertTrue(str_contains($unsupportedTaskBody, '## Fictional Broken Group'), 'Recognized task fallback discarded its group name.');
    assertTrue($unsupportedTaskEntry['content_format']['unsupported_task_structures'] !== [], 'Unsupported task structure was not recorded.');

    foreach ([
        '61000000-0000-4000-8000-000000000003' => $plainMarkdownBody,
        '61000000-0000-4000-8000-000000000004' => $plainBraceBody,
        '61000000-0000-4000-8000-000000000005' => $ordinaryJsonBody,
        '61000000-0000-4000-8000-000000000006' => $textLikeTaskBody,
    ] as $uuid => $expectedBody) {
        $entry = singleEntry($richTaskManifest, $uuid);
        assertSameValue($expectedBody, file_get_contents($richTaskVault . '/' . $entry['final_relative_markdown_path']), 'An ordinary or unrecognized fictional body changed.');
        assertTrue($entry['content_format']['conversion_performed'] === false, 'An unchanged fictional note is incorrectly marked converted.');
    }
    $richTaskManifestJson = (string) file_get_contents($richTaskVault . '/_standardnotes-export-manifest.json');
    foreach (['Fictional Orchard 🍋', 'Capture invented draft', 'ordinary user JSON', 'Already textual fictional task'] as $bodyFragment) {
        assertTrue(!str_contains($richTaskManifestJson, $bodyFragment), 'Manifest leaked fictional note body content.');
    }

    $titleVault = $temporaryRoot . '/title-derivation-vault';
    $titleResult = runCommand([$titleFixture, $titleVault]);
    assertSameValue(0, $titleResult['exit'], 'Fictional title-derivation export failed: ' . $titleResult['stderr']);
    assertTrue(str_contains($titleResult['stdout'], 'Filenames derived from body content: 26'), 'Derived-title summary count is wrong.');
    assertTrue(str_contains($titleResult['stdout'], 'Notes remaining Untitled after fallback: 3'), 'Untitled fallback summary count is wrong.');
    assertTrue(str_contains($titleResult['stdout'], 'Filename collisions resolved: 3'), 'Title fixture collision count is wrong.');
    assertTrue(str_contains($titleResult['stdout'], 'New filename collisions caused by derived titles: 1'), 'Derived-title collision count is wrong.');
    assertTrue(!str_contains($titleResult['stdout'], 'Normal fictional first line'), 'Console output leaked a derived title.');
    $titleManifest = loadManifest($titleVault);
    assertTrue(isset($titleManifest['filename_title_derivation']), 'Manifest omits the title-derivation policy.');
    assertTrue(str_contains($titleManifest['filename_title_derivation']['scan_limit'], '100 lines'), 'Manifest omits the line scan limit.');
    assertTrue(str_contains($titleManifest['filename_title_derivation']['scan_limit'], '65536 bytes'), 'Manifest omits the byte scan limit.');

    foreach ($titleExpectations as $uuid => $expectation) {
        $entry = singleEntry($titleManifest, $uuid);
        assertSameValue($expectation['source_title'], $entry['original_title'], 'Original Standard Notes title was not retained.');
        assertSameValue($expectation['expected_source'], $entry['filename_title_source'], 'Filename-title source is wrong.');
        assertTrue(!array_key_exists('derived_title', $entry), 'Manifest duplicated derived title content.');
        $actualBody = (string) file_get_contents($titleVault . '/' . $entry['final_relative_markdown_path']);
        assertSameValue($expectation['final_body'], $actualBody, 'Filename derivation changed a final note body.');
        $beforeHash = hash('sha256', $expectation['final_body']);
        filenameTitleForNote($expectation['source_title'], $expectation['final_body']);
        assertSameValue($beforeHash, hash('sha256', $expectation['final_body']), 'Title inspection changed a body hash.');
        if ($expectation['expected_base'] !== null
            && !in_array($uuid, [$emptyUntitledUuid, $derivedCollisionTwo, $scanLimitUuid], true)) {
            assertSameValue(
                $expectation['expected_base'],
                basename($entry['final_relative_markdown_path'], '.md'),
                'Derived or existing filename base is wrong.'
            );
        }
    }
    assertSameValue('Existing Fictional Name.md', basename(singleEntry($titleManifest, $meaningfulTitleUuid)['final_relative_markdown_path']), 'Meaningful source title was replaced.');
    assertSameValue('Untitled.md', basename(singleEntry($titleManifest, $structuralOnlyUuid)['final_relative_markdown_path']), 'Structural-only body did not retain Untitled.');
    assertSameValue('Untitled (2).md', basename(singleEntry($titleManifest, $emptyUntitledUuid)['final_relative_markdown_path']), 'Empty-body Untitled collision is wrong.');
    assertSameValue('Untitled (3).md', basename(singleEntry($titleManifest, $scanLimitUuid)['final_relative_markdown_path']), 'Bounded scan did not fall back deterministically.');
    assertSameValue('Shared derived title.md', basename(singleEntry($titleManifest, $derivedCollisionOne)['final_relative_markdown_path']), 'First derived collision filename is wrong.');
    assertSameValue('Shared derived title (2).md', basename(singleEntry($titleManifest, $derivedCollisionTwo)['final_relative_markdown_path']), 'Second derived collision filename is wrong.');
    assertSameValue('_CON.md', basename(singleEntry($titleManifest, $reservedDerivedUuid)['final_relative_markdown_path']), 'Derived reserved filename was not protected.');
    assertSameValue('..-Unsafe-Name.md', basename(singleEntry($titleManifest, $unsafeDerivedUuid)['final_relative_markdown_path']), 'Derived unsafe filename was not sanitized.');
    $longDerivedEntry = singleEntry($titleManifest, $longDerivedUuid);
    assertTrue(strlen(basename($longDerivedEntry['final_relative_markdown_path'], '.md')) <= 120, 'Long derived filename was not limited.');
    assertSameValue('Fictional Orchard 🍋.md', basename(singleEntry($titleManifest, $lexicalTitleUuid)['final_relative_markdown_path']), 'Lexical-converted heading was not used for its filename.');
    assertSameValue('Fictional Workshop.md', basename(singleEntry($titleManifest, $taskTitleUuid)['final_relative_markdown_path']), 'Task-converted group heading was not used for its filename.');
    $titleManifestJson = (string) file_get_contents($titleVault . '/_standardnotes-export-manifest.json');
    foreach ($titleExpectations as $expectation) {
        $sourceBody = $expectation['source_body'];
        if ($sourceBody !== '' && !str_contains($expectation['final_body'], $sourceBody)) {
            assertTrue(!str_contains($titleManifestJson, $sourceBody), 'Manifest leaked serialized editor body content.');
        }
    }

    $long = singleEntry($manifest, '20000000-0000-4000-8000-000000000020');
    assertTrue(strlen(basename($long['final_relative_markdown_path'], '.md')) <= 120, 'Long title was not shortened safely.');
    $reserved = singleEntry($manifest, '20000000-0000-4000-8000-000000000008');
    assertTrue(str_starts_with(basename($reserved['final_relative_markdown_path']), '_CON'), 'Windows reserved filename was not protected.');
    assertTrue(!file_exists($temporaryRoot . '/Escape'), 'A traversal-like tag created a directory outside the vault.');
    $separateFolderA = singleEntry($manifest, '20000000-0000-4000-8000-000000000010');
    $separateFolderB = singleEntry($manifest, '20000000-0000-4000-8000-000000000011');
    assertTrue($separateFolderA['final_relative_markdown_path'] !== $separateFolderB['final_relative_markdown_path'], 'Same title in different folders was not preserved independently.');
    $fallbackTimestamp = singleEntry($manifest, '20000000-0000-4000-8000-000000000006');
    assertSameValue('updated_at', $fallbackTimestamp['filesystem_modification_time_source'], 'Timestamp fallback is wrong.');
    assertSameValue(strtotime('2024-01-02T03:04:05.000Z'), filemtime($vault . '/' . $meetingA['final_relative_markdown_path']), 'Filesystem modification time was not preserved.');

    $beforeOverwrite = vaultSnapshot($vault);
    $overwrite = runCommand([$fixture, $vault]);
    assertTrue($overwrite['exit'] !== 0, 'Existing output directory was overwritten.');
    assertSameValue($beforeOverwrite, vaultSnapshot($vault), 'Overwrite refusal changed the existing vault.');

    $deterministicA = $temporaryRoot . '/deterministic-a';
    $deterministicB = $temporaryRoot . '/deterministic-b';
    assertSameValue(0, runCommand([$fixture, $deterministicA])['exit'], 'First deterministic export failed.');
    assertSameValue(0, runCommand([$fixture, $deterministicB])['exit'], 'Second deterministic export failed.');
    assertSameValue(vaultSnapshot($deterministicA), vaultSnapshot($deterministicB), 'Repeated exports are not deterministic.');

    $strictVault = $temporaryRoot . '/strict-vault';
    $strict = runCommand(['--standalone-tags=metadata', $fixture, $strictVault]);
    assertSameValue(0, $strict['exit'], 'Strict standalone-tag conversion failed.');
    $strictManifest = loadManifest($strictVault);
    assertSameValue('metadata', $strictManifest['folder_mapping']['standalone_tags_mode'], 'Strict mode missing from manifest.');
    $strictStandalone = singleEntry($strictManifest, '20000000-0000-4000-8000-000000000004');
    assertTrue(str_starts_with($strictStandalone['final_relative_markdown_path'], 'Unfiled/'), 'Strict mode treated standalone tag as a folder.');
    $strictDotted = singleEntry($strictManifest, '20000000-0000-4000-8000-000000000003');
    assertTrue(str_starts_with($strictDotted['final_relative_markdown_path'], 'Alpha/Beta/'), 'Strict mode disabled legacy dotted folders.');

    $trashVault = $temporaryRoot . '/trash-vault';
    assertSameValue(0, runCommand(['--include-trashed', $fixture, $trashVault])['exit'], 'Trashed-note export failed.');
    $trashEntry = singleEntry(loadManifest($trashVault), '20000000-0000-4000-8000-000000000016');
    assertTrue(str_starts_with($trashEntry['final_relative_markdown_path'], '_Trashed/Trash/Top/'), 'Trashed note path is wrong.');

    $noArchiveVault = $temporaryRoot . '/no-archive-vault';
    assertSameValue(0, runCommand(['--exclude-archived', $fixture, $noArchiveVault])['exit'], 'Archived exclusion failed.');
    assertSameValue([], entriesForUuid(loadManifest($noArchiveVault), '20000000-0000-4000-8000-000000000015'), 'Archived note was not excluded.');

    $customFixture = $temporaryRoot . '/custom-separator.json';
    file_put_contents($customFixture, json_encode([
        'items' => [
            [
                'uuid' => '30000000-0000-4000-8000-000000000001',
                'content_type' => 'Tag',
                'content' => [
                    'title' => 'Top::Child',
                    'references' => [['uuid' => '40000000-0000-4000-8000-000000000001', 'content_type' => 'Note']],
                ],
            ],
            [
                'uuid' => '40000000-0000-4000-8000-000000000001',
                'content_type' => 'Note',
                'content' => ['title' => 'Custom', 'text' => 'CUSTOM-SEPARATOR-BODY', 'references' => []],
                'created_at' => '2024-02-01T00:00:00.000Z',
                'updated_at' => '2024-02-02T00:00:00.000Z',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $customVault = $temporaryRoot . '/custom-vault';
    assertSameValue(0, runCommand(['--folder-separator=::', $customFixture, $customVault])['exit'], 'Custom separator failed.');
    assertTrue(is_file($customVault . '/Top/Child/Custom.md'), 'Custom separator did not create nested folders.');

    $malformed = runCommand([$root . '/tests/fixtures/malformed.json', $temporaryRoot . '/malformed-vault']);
    assertTrue($malformed['exit'] !== 0, 'Malformed JSON unexpectedly succeeded.');
    assertTrue(!file_exists($temporaryRoot . '/malformed-vault'), 'Malformed JSON left an output directory.');
    $missingItems = runCommand([$root . '/tests/fixtures/missing-items.json', $temporaryRoot . '/missing-items-vault']);
    assertTrue($missingItems['exit'] !== 0, 'Missing items structure unexpectedly succeeded.');

    $nonTextFixture = $temporaryRoot . '/non-text-body.json';
    file_put_contents($nonTextFixture, json_encode([
        'items' => [[
            'uuid' => '50000000-0000-4000-8000-000000000001',
            'content_type' => 'Note',
            'content' => ['title' => 'Unsafe body type', 'text' => ['invented' => 'structured value'], 'references' => []],
            'created_at' => '2024-03-01T00:00:00.000Z',
            'updated_at' => '2024-03-02T00:00:00.000Z',
        ]],
    ], JSON_THROW_ON_ERROR));
    $nonTextOutput = $temporaryRoot . '/non-text-vault';
    $nonTextResult = runCommand([$nonTextFixture, $nonTextOutput]);
    assertTrue($nonTextResult['exit'] !== 0, 'A non-string note body was silently exported.');
    assertTrue(!file_exists($nonTextOutput), 'Rejected non-string body left an output directory.');

    $blockingFile = $temporaryRoot . '/not-a-directory';
    file_put_contents($blockingFile, 'fictional blocker');
    $writeFailure = runCommand([$fixture, $blockingFile . '/vault']);
    assertTrue($writeFailure['exit'] !== 0, 'Output creation failure unexpectedly succeeded.');
    assertSameValue('fictional blocker', file_get_contents($blockingFile), 'Output failure modified an existing file.');

    fwrite(STDOUT, 'All ' . $assertions . ' assertions passed.' . PHP_EOL);
    removeTree($temporaryRoot);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'TEST FAILURE: ' . $exception->getMessage() . PHP_EOL);
    removeTree($temporaryRoot);
    exit(1);
}
