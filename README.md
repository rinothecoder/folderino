# Folderino

Organise the WordPress Media Library into folders. Drag and drop, subfolders, and no file on disk is ever moved.

## How it works

Folders are terms in a private, hierarchical taxonomy (`folderino_folder`) attached to attachments. Putting a file in a folder only adds a row to `wp_term_relationships`. No files are moved, there are no custom tables, and switching the plugin off leaves the Media Library flat again.

## Features

- Folder sidebar on Media > Library (grid and list view), with counts
- Create, rename, delete, subfolders
- Drag files onto folders, drag folders to reorder or nest
- Folder dropdown in every media modal, also in the Bricks builder
- Uploads go into the open folder

## Coming from the old snippet

Folderino renames the old `rdb_media_folder` taxonomy to `folderino_folder` once, in place. Folder IDs, files, order and counts stay identical. Turn the old snippet off first. Until then, Folderino shows a notice and waits.

## Updates

Releases are published on GitHub. A release is a tag such as `v1.0.1`, higher than the `Version` header. The repository is public: no GitHub token, Adminino connection, or wp-config.php change is needed. Existing site tokens are ignored by Folderino.

## License

GPL-2.0-or-later