=== Swift Image Optimizer ===
Contributors: wpswift
Tags: images, webp, optimization, compress, performance
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically convert and compress images to WebP on upload, plus individual and bulk optimization for your existing Media Library.

== Description ==

Swift Image Optimizer converts your images to WebP and compresses them, cutting image weight by 60-80% without a visible quality loss.

Everything happens on your own server. There is no API key, no monthly quota, no account to create, and your images are never sent to a third party.

= Two ways to optimize =

**On upload.** New images are converted before WordPress even creates the attachment, so the image and every thumbnail WordPress generates are WebP from the start.

**Your existing library.** Optimize a single image from the Media Library, or run a bulk optimization across everything you have already uploaded. When an existing image is converted, every reference to it in your posts, pages, widgets and page-builder content is updated automatically.

= Safety first =

Converting images replaces files on disk and rewrites URLs across your database, so the plugin is built to be reversible:

* Every original is backed up before it is touched, on upload as well as in the library
* Backups are kept for a period you choose, then removed automatically
* Any image can be restored to its original with one click while its backup exists
* A dry run reports exactly what would change before anything is written
* Requests for an old image URL are transparently served the new file, so nothing 404s while caches catch up

Backing up uploaded originals roughly doubles the space your uploads use until the retention period passes. It can be turned off in Settings, but an uploaded original that is not backed up is gone for good once it is converted.

= When something goes wrong =

The **Troubleshoot** tab reports what your server can and cannot do, in plain language: which conversion engines are installed, the PHP limits that decide whether a large image can be processed, whether every directory the plugin needs is writable, and how much disk space is left. Each line that needs attention says what to do about it, and the whole report can be copied to the clipboard for a support request.

Turn on logging and the plugin records every step of every conversion to a file on your own server: the backup, the encode, the file rename, the URL rewrite and each file deleted, with timings and full paths. Failures are always recorded, even with logging off, so a problem that already happened still left evidence. The log is capped at 10MB, never leaves your server, and can be cleared at any time.

= Works with your server =

The plugin detects what your host provides and uses the best available option: Imagick, the cwebp binary, or GD. No binaries are bundled and no shell access is required. If nothing suitable is available, you are told plainly rather than left wondering.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate it through the Plugins menu
3. New uploads are optimized immediately
4. Visit Media > Bulk Optimize to handle images you already have

== Frequently Asked Questions ==

= Will this break my existing images? =

Converting an existing image changes its filename, so the plugin updates every reference it can find in your database. Originals are backed up first and any image can be restored while its backup exists. Run the dry run before your first bulk optimization to see exactly what will change.

= Are my images sent anywhere? =

No. All processing happens on your own server. The plugin makes no external requests.

= What happens when a backup expires? =

The original file is deleted and the disk space is reclaimed. The optimized image is unaffected, but it can no longer be restored. Choose "Keep forever" in the settings if you would rather not lose that option.

= Does it work on shared hosting? =

Yes. GD with WebP support is available on virtually every modern host. Large images are checked against the memory limit before processing and skipped with an explanation rather than crashing.

= What about images that are already small? =

If the WebP version would be larger than the original, the original is kept and the image is marked as skipped. This is common for small flat-colour PNGs.

= Can I restore an image that was optimized on upload? =

Yes, as long as "Keep a backup of uploaded originals" was on when it was uploaded, which it is by default. The original is stored alongside library backups and follows the same retention period.

= Why was an image skipped, and will it be retried? =

The Media Library says why in plain language. Skips caused by the image itself — a format that is not converted, or one where WebP would be larger — are final. Skips caused by the server at that moment, such as running out of memory or disk space, are not: fix the cause and use Requeue on the Troubleshoot tab to put those images back in the queue.

= Where is the log kept, and is it private? =

In a protected folder inside your uploads directory, under an unguessable filename, with a rule blocking direct web access. Nothing is sent anywhere. Use Reset on the Troubleshoot tab to delete it.

== Screenshots ==

1. Bulk optimization dashboard
2. Media Library optimization column
3. Settings

== Changelog ==

= 1.1.0 =
* New Troubleshoot tab: server diagnostics, an activity log with a viewer, and maintenance tools
* Optional file-based logging of every conversion step, capped at 10MB, with download and reset
* Uploaded originals are now backed up too, so images optimized on upload can be restored
* Fixed: photos taken in portrait could be saved sideways when the cwebp binary was the active engine
* Fixed: restoring an image whose thumbnails had not all regenerated could repoint full-size references at a thumbnail
* Fixed: an image the server could not handle at the time — out of memory, out of disk, no engine — can now be returned to the queue instead of being skipped forever
* Fixed: image URLs inside comments are now updated along with the rest of the database
* Added: conversion falls back to another installed engine instead of failing when one cannot handle a particular file, including CMYK JPEGs
* Added: animated PNGs are left alone rather than silently reduced to their first frame
* Added: a disk space check before backing up, so a conversion cannot half-write a backup
* Changed: bulk optimization no longer clears the whole object cache after every batch
* Changed: far fewer database scans during bulk URL rewriting
* Changed: old image URLs are resolved from an indexed table, and no longer confuse two images that share a filename in different months
* Changed: temporary files are written to their own directory and cleaned up automatically
* Changed: conversion locks can no longer be acquired twice by simultaneous requests

= 1.0.0 =
* Initial release
* Automatic WebP conversion on upload
* Individual and bulk optimization for existing media
* Original backups with configurable retention
* Database URL rewriting with dry run
* Restore to original
