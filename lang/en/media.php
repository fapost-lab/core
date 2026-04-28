<?php

declare(strict_types=1);

return [
    'navigation_group'   => 'Content',
    'model_label'        => 'Media file',
    'plural_model_label' => 'Media',

    'fields' => [
        'name'          => 'Name',
        'kind'          => 'Type',
        'size'          => 'Size',
        'folder'        => 'Folder',
        'parent_folder' => 'Parent folder',
        'mime_type'     => 'Mime type',
        'references'    => 'Used in',
        'uploaded_at'   => 'Uploaded',
        'file'          => 'File',
    ],

    'kinds' => [
        'image'    => 'Image',
        'video'    => 'Video',
        'audio'    => 'Audio',
        'document' => 'Document',
        'sticker'  => 'Sticker',
        'other'    => 'Other',
    ],

    'filters' => [
        'root_folder' => '— Root —',
    ],

    'navigation' => [
        'root' => 'All files',
        'back' => 'Back',
    ],

    'actions' => [
        'upload'        => 'Upload files',
        'create_folder' => 'Create folder',
        'rename'        => 'Rename',
        'move'          => 'Move',
        'references'    => 'See usage',
        'close'         => 'Close',
        'download'      => 'Download',
    ],

    'notifications' => [
        'uploaded'       => ':count file(s) uploaded.',
        'folder_created' => 'Folder created.',
    ],

    'references' => [
        'modal_heading' => 'Media usage',
        'node'          => 'Node',
        'empty'         => 'This file is not referenced by any flow.',
        'types'         => [
            'flow_definition' => 'Flow',
        ],
    ],

    'errors' => [
        'has_references' => 'Cannot force delete: file is referenced by one or more flows.',
        'file_too_large' => 'File exceeds the maximum allowed size of :max MB.',
    ],
];
