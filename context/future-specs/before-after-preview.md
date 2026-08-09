# Future Spec — Before / After Quality Preview

## Idea

Let the user see what the compression actually did to an image, before committing to it across
their whole library.

## Why

This plugin permanently replaces originals. Asking someone to accept that on trust, with only
a percentage as evidence, is a lot to ask. Imagify's comparison slider is the single most
reassuring thing in that product.

A quality complaint after a bulk run is unfixable once backups expire. A preview converts that
into a decision made up front, which is where it belongs.

## Scope

- Pick a representative image, convert it to a **temporary** file at the current quality
  setting, show original vs result side by side with a drag slider
- File sizes for both
- Re-render when the quality slider changes
- The temp file never enters the Media Library and is cleaned up immediately

## Where

On the Settings tab, directly beside the quality control — that is where the decision is
actually made.

## Constraints

- Must not create an attachment or leave anything behind in `uploads/`
- Must work before any bulk run has happened, so it needs to select a sample image itself
- Preview generation respects the same memory guard as a real conversion
- Serve the preview through a nonced REST route, not a public URL

## Effort

Medium. Mostly UI work; the conversion side is a single `Optimizer::optimize()` call against a
temp path.
