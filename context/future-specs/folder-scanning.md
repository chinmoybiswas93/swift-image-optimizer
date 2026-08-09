# Future Spec — Optimize Files Outside the Media Library

## Idea

Optimize images that live in `uploads/` but have no attachment record, or that live in theme
and plugin directories.

## Why

ShortPixel and EWWW both do this. The common cases are images uploaded over FTP, theme demo
content, gallery plugins that write outside the Media Library, and old imports.

## Why it is hard here

This plugin's entire safety model is built around attachments. The log table is keyed on
`attachment_id`, backups mirror the uploads structure, and URL rewriting is driven by
attachment metadata. A file with no attachment record has none of that.

It would need:

- A second log keyed on **file path** rather than attachment ID
- URL rewriting driven by path rather than metadata — far less reliable, because there is no
  authoritative list of that file's subsizes to build a map from
- A hard exclusion list: never touch anything inside `wp-admin/`, `wp-includes/`, or any
  plugin's or theme's own asset directory

That last point is the real danger. Optimizing a theme's bundled asset means the next theme
update silently reverts it, and optimizing a plugin's icon could break an integrity check.

## Recommendation

Only build this if there is real demand. It roughly doubles the surface area of the riskiest
part of the plugin to serve a case most sites do not have. If it is built, restrict it to
`uploads/` and nothing else.

## Effort

Large.
