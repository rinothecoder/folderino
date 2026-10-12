=== Folderino ===
Contributors: rinodeboer
Tags: media library, folders, media folders, organize, drag and drop
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Organise the Media Library into folders. Drag and drop, subfolders, and no file on disk is ever moved.

== Description ==

Folderino adds a folder sidebar to Media > Library, in grid and list view.

* Create, rename and delete folders, with subfolders
* Drag files onto a folder, drag folders to reorder or nest them
* A Folder dropdown inside every media modal (post editor, page builders)
* New uploads go into the folder you have open
* Works in the Bricks builder media modal

Folders are virtual. A file is only linked to a folder, so no file in wp-content/uploads is ever moved, renamed or deleted, and every file URL stays the same. Folderino uses no custom database tables, only WordPress taxonomies. Switch it off and the Media Library is simply flat again.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. Open Media > Library. The folder sidebar is on the left.

== Changelog ==

= 1.0.1 =
* Public GitHub updates require no site token or config changes.

= 1.0.0 =
* First release.