# Future Spec — Resize Existing Images

## Idea

Downscale oversized images as an operation separate from format conversion.

## Why

The `max_dimension` setting already resizes during conversion, but only when an image is
actually converted. Images that are already WebP, or that were skipped, keep their original
dimensions forever.

Dimension is frequently a bigger win than codec. A 4000px photo served into an 800px slot
wastes more bytes than any encoder choice can recover.

## Scope

- A separate "resize only" bulk operation that leaves the format alone
- Its own dimension setting, independent of the conversion one
- Applies to WebP images too, which the conversion path skips entirely
- Reuses the same backup, restore and URL-rewriting machinery

## The trap

WordPress encodes dimensions in subsize filenames, so a resize changes URLs **exactly** the way
a conversion does. This is not a lighter-weight operation and must not be presented as one — it
needs the full `AttachmentConverter` pipeline, backups included.

Anyone building this should read `specs/done/05-attachment-conversion.md` first and resist the
temptation to write a simpler path.

## Effort

Medium. Most of the machinery exists; the work is routing a second operation through the same
rewriter without duplicating it.
