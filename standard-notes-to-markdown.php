<?php

/* 

# Export [Standard Notes](https://github.com/standardnotes/web) decrypted backup JSON as separate Markdown files with YAML frontmatter. 

Link: https://github.com/hozza/standardnotes-to-markdown-yaml-export

Great keeping your notes metadata in a plaintext backup for longevity, or for migrating to [Zettlr](https://github.com/zettlr/zettlr), [Notable.app](https://github.com/notable/notable), and other note taking apps which use a plaintext markdown format.


Run with `php standard-notes-to-markdown.php sn-export-filename.txt ./export/directory/`

***

## Standard Notes JSON Backup Export Decrypted Syntax Specification Raw

There are many "items" in the Standard Notes exported JSON, but we're only concerned with Notes and Tags here. 

2021-02 Standard Notes exports markdown files in a sub-directory zip along with the file `Standard Notes Backup and Import File txt.txt`, these markdown files do not include meta data, but the JSON file includes metadata and notes content, so we just need this JSON file passed as argument 1.

Made for Standard Notes export JSON file which indicated `"version": "004"`, it may work for other versions.


### Notes Item

The Standard Notes "Note item" type can also have a bool of 'content>pinned' and a 'content>archived' instead of 'content>trashed' as shown in example.

```
    {
      "uuid": "1fa7d986-e8f7-4121-bcf2-7f408c85ed03",
      "content_type": "Note",
      "content": {
        "title": "personal-todo",
        "text": "## Title\n\n## Todo\n\n## Title1\n\n## Title2\n\n## Title5\n\n## Remember\n\nUnformatted Notes content/ to organize\n===============================\n\n",
        "references": [],
        "appData": {
          "org.standardnotes.sn": {
            "client_updated_at": "2014-05-30T15:39:59.000Z"
          }
        },
        "trashed": true
      },
      "created_at": "2014-05-30T15:39:59.000Z",
      "updated_at": "2021-02-20T18:20:24.915Z",
      "duplicate_of": null
    },
```

### Tag Item

Standard Note Tag titles contain a dot `.` if a paid account (called "Extended") had the ["Folders" or "Smart Tag"](https://standardnotes.org/extensions/folders) extension enabled and had nested tags/folders, the dot separates the parent tag from child tag. 

```
    {
      "uuid": "321a849e-d854-4cc4-808d-6aa0b80bceb8",
      "content_type": "Tag",
      "content": {
        "title": "personal.prose",
        "references": [
          {
            "uuid": "7b938444-5af3-444e-a0b5-e29ef43f6de2",
            "content_type": "Note"
          },
          {
            "uuid": "402e0be4-4ca6-4cd3-8c1a-f9175d82a5d5",
            "content_type": "Note"
          },
          {
            "uuid": "dff98b87-2246-4982-9218-a5224894e4a3",
            "content_type": "Note"
          }
        ],
        "appData": {
          "org.standardnotes.sn": {
            "client_updated_at": "2021-02-18T11:24:52.636Z"
          },
          "org.standardnotes.sn.components": {
            "84887fa7-85dd-41b6-b7c3-97d549460158": {}
          }
        }
      },
      "created_at": "2020-08-26T16:13:11.669Z",
      "updated_at": "2021-02-20T18:20:17.571Z",
      "duplicate_of": null
    },
```


*/



function yaml_escape_scalar($value) {
	$normalized = str_replace(array("\r\n", "\r"), "\n", (string)$value);
	return '"'.str_replace(array('\\', '"', "\n"), array('\\\\', '\\"', '\\n'), $normalized).'"';
}

// Require Args
$default_input_filename = 'Standard Notes Backup and Import File.txt';
$input_path = isset($argv[1]) ? $argv[1] : $default_input_filename;
if(!is_file($input_path) || !is_readable($input_path)) {
	echo 'Error: Input file does not exist or is not readable. Pass the decrypted Standard Notes JSON export path as argument 1.';
	exit(1);
}

$sn_file = file_get_contents($input_path);
if($sn_file === false) {
	echo 'Error: Failed to read input file.';
	exit(1);
}

if(!isset($argv[2])) $export_path = __DIR__.'/notes/';
else {
	$requested_export_path = trim($argv[2]);
	$export_path = preg_match('/^\//', $requested_export_path)
		? $requested_export_path
		: getcwd().'/'.$requested_export_path;
	$export_path = rtrim($export_path, '/').'/';
}

// sanity
if(file_exists($export_path)) {
	echo 'Error: Export path already exists! We don\'t want to overwrite anything... Delete it or choose another path.';
	exit(1);
}
elseif(!mkdir($export_path, 0700, true)) {
	echo 'Error: Could not create export path.';
	exit(1);
}


$notes = array();

// load SN file as associative array
$sn_json = json_decode($sn_file, true, 512, JSON_THROW_ON_ERROR);
if(!isset($sn_json['items']) || !is_array($sn_json['items'])) {
	echo 'Error: Input JSON does not contain an items array.';
	exit(1);
}

foreach($sn_json['items'] as $sn_item) {
	
	// Process the Notes
	if($sn_item['content_type'] == 'Note') {
		
		// can only really be one or the other as they're locations, right? We'll handle "pinned" later
			$sn_app_data = isset($sn_item['content']['appData']['org.standardnotes.sn']) ? $sn_item['content']['appData']['org.standardnotes.sn'] : array();
			if((isset($sn_item['content']['trashed']) && $sn_item['content']['trashed'] == true) || (isset($sn_app_data['trashed']) && $sn_app_data['trashed'] == true)) {
				$sn_note_status = 'trashed';
			}
			elseif((isset($sn_item['content']['archived']) && $sn_item['content']['archived'] == true) || (isset($sn_app_data['archived']) && $sn_app_data['archived'] == true)) {
				$sn_note_status = 'archived';
			}
		else $sn_note_status = false;

		// 🖖 Beam me up, Miles O'Brien
		if($sn_note_status) $notes[$sn_item['uuid']]['status'] = $sn_note_status;
		$notes[$sn_item['uuid']]['title'] = $sn_item['content']['title'];
		$notes[$sn_item['uuid']]['sn_content'] = $sn_item['content']['text'];
		$notes[$sn_item['uuid']]['created_at'] = $sn_item['created_at'];
		$notes[$sn_item['uuid']]['updated_at'] = isset($sn_app_data['client_updated_at']) ? $sn_app_data['client_updated_at'] : $sn_item['updated_at'];
		

		// pinned? Treat as an attribute not location/status.
			if((isset($sn_item['content']['pinned']) && $sn_item['content']['pinned'] == true) || (isset($sn_app_data['pinned']) && $sn_app_data['pinned'] == true)) $notes[$sn_item['uuid']]['tags']['pinned'] = true;

	}

	// Process the Tags
	if($sn_item['content_type'] == 'Tag') {
		
		if(count($sn_item['content']['references']) > 0) {$JSON[] = $sn_item;

		// loop all notes in tag
		foreach ($sn_item['content']['references'] as $tag_refs) {
			
			// is this tag referencing a note? not sure if it could ever reference anything else
			if($tag_refs['content_type'] == 'Note') {

				// store tag as key in note to prevent duplicates
				if(isset($tag_refs['uuid'])) { // some tags are empty
					$notes[$tag_refs['uuid']]['tags'][$sn_item['content']['title']] = true;
				}
			}

		}

		}

	}

}




// Export Markdown files with YAML frontmatter.
$exported_count = 0;
$note_ids = array();
foreach ($notes as $note_uuid => $note_data) {
	
	/* 
	YAML, useful for WYSIWYM markdown metadata

	WARNING: I'm not going to use a YAML lib to keep this small, however this means the resulting YAML could be malformed as it's not being validated/parsed.

	NOTE: 

	Zettlr uses `keywords` rather than `tags` in YAML.

	Created time will be in YMAL, modified times are applied to the file itself, not in YAML to keep it a little cleaner.

	Note `id` is used for ZettelKasten method and other universal/wiki-style note inter-linking schemes, to ensure link persistence across title changes. It's just the creation date in a tighter format down to the second. Due to note creation inconstancies, if a note ID already exists already, it'll be incremented by 1 second until it's unique. ¯\_(ツ)_/¯

	$filename will be the note title sanitized and the id... to avoid collisions and satisfy the zettelkasten/casual fans - maybe there will be filename options in the future?
	*/

	// only take real notes, not empty tag references to non-existent notes
	if(isset($note_data['created_at'])) {

		// create unique Zettel-style timestamp IDs.
		$note_id_prefix = '';
		$note_seconds = strtotime($note_data['created_at']);

		$note_id = $note_id_prefix.date("YmdHis", $note_seconds);
		while (isset($note_ids[$note_id])) {
			$note_seconds++;
			$note_id = $note_id_prefix.date("YmdHis", $note_seconds);
		}
		$note_ids[$note_id] = true;
		

		// manual tag YAML
		if(!empty($note_data['tags'])) {
			$note_tags_yaml = "tags:\n";
			foreach ($note_data['tags'] as $note_tag_title => $value) {
					$note_tags_yaml .= "  - ".yaml_escape_scalar($note_tag_title)."\n";
				}

		}
		else $note_tags_yaml = '';

			if(isset($note_data['status'])) $note_status_yaml = "status: ".yaml_escape_scalar($note_data['status'])."\n";
			else $note_status_yaml = '';

			// manual note YAML
			$note_yaml = "---\ntitle: ".yaml_escape_scalar($note_data['title'])."\ncreated: ".yaml_escape_scalar($note_data['created_at'])."\nuuid: ".yaml_escape_scalar($note_uuid)."\nid: ".yaml_escape_scalar($note_id)."\n$note_tags_yaml$note_status_yaml---\n\n";

		$filename = preg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '-', $note_data['title'])." $note_id.md";
		$note_content = $note_yaml.$note_data['sn_content'];

		// she lives... 👹
		$write_note = file_put_contents($export_path.$filename, $note_content);
		if (!$write_note) echo "Error: $note_uuid failed to write.";
		else echo "Exported '$filename' ($note_uuid)!\n\n";

		// modification time
		touch($export_path.$filename, strtotime($note_data['updated_at']));

		$exported_count++;

	}

}

echo "Exported $exported_count Notes to: $export_path\n";

?>
