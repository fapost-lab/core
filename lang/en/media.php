<?php

declare(strict_types=1);

return [
    'navigation_group'   => 'Content',
    'model_label'        => 'Media file',
    'plural_model_label' => 'Media',

    'fields' => [
        'name'             => 'Name',
        'kind'             => 'Type',
        'size'             => 'Size',
        'folder'           => 'Folder',
        'parent_folder'    => 'Parent folder',
        'mime_type'        => 'Mime type',
        'references'       => 'Used in',
        'uploaded_at'      => 'Uploaded',
        'file'             => 'File',
        'move_files_to'    => 'Move files to',
        'move_contents_to' => 'Move contents to',
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
        'root'       => 'Root',
        'back'       => 'Back',
        'no_folders' => 'No folders yet',
    ],

    'actions' => [
        'upload'                     => 'Upload files',
        'create_folder'              => 'Create folder',
        'delete_folder'              => 'Delete folder',
        'delete_folder_heading'      => 'Delete folder',
        'delete_folder_confirm'      => 'This folder is empty. It will be permanently deleted.',
        'delete_folder_has_files'    => 'This folder contains :count file(s). Choose where to move them before deleting.',
        'delete_folder_has_children' => 'This folder contains :count subfolder(s). Choose where to move them before deleting.',
        'delete_folder_has_both'     => 'This folder contains :files file(s) and :folders subfolder(s). Choose where to move everything before deleting.',
        'rename_folder'              => 'Rename folder',
        'rename'                     => 'Rename',
        'save'                       => 'Save',
        'move'                       => 'Move',
        'references'                 => 'See usage',
        'close'                      => 'Close',
        'download'                   => 'Download',
        'cancel'                     => 'Cancel',
        'delete'                     => 'Delete',
    ],

    'notifications' => [
        'uploaded'       => ':count file(s) uploaded.',
        'folder_created' => 'Folder created.',
        'folder_deleted' => 'Folder deleted.',
        'folder_renamed' => 'Folder renamed.',
        'files_moved'    => ':count file(s) moved.',
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
        'has_references'        => 'Cannot force delete: file is referenced by one or more flows.',
        'file_too_large'        => 'File exceeds the maximum allowed size of :max MB.',
        'storage_limit_title'   => 'Media storage is full',
        'storage_limit_reached' => 'Media storage is full: :used of :limit used, the file needs :needed. Delete unneeded files permanently (from the trash) to free space.',
        'storage_limit_saved'   => ':saved of :total file(s) were saved before the limit was reached.',
    ],
];
