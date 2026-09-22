# UI foundation for extenders

Depth: normal — the problem is visible in the code (three token sets, no shared component), and the
only real fork is how far the shared source reaches.

## Idea

Step 5 of the product roadmap, in the owner's words: "UI foundation for extenders — one token
source shared by the operator-facing surfaces and the small set of primitives an extension actually
composes against."

## Goal and problem

- Who is worse off without this, and how: the author of a Solution that ships an interface. They
  have nothing to compose against: the builder, the Filament panel and the public site each define
  their own tokens, the builder and the Filament theme duplicate one palette under different names,
  and no component is shared. Anything an extension draws either re-invents the look or hard-codes
  values that drift the next time a surface is restyled.
- What is true when the work is done: one token source defines the operator-facing surfaces, a
  small set of primitives is importable by an extension, and a Solution's component placed in the
  builder looks like it belongs without copying values out of Core's CSS.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That the builder and the Filament panel can share tokens at all. They are separately built
    surfaces on different stacks (Inertia + Vue against a Filament theme); sharing works only at
    the CSS custom property level, not as components.
  - That "the small set of primitives an extension actually composes against" is knowable now. It
    is not, precisely — step 6 is what reveals which primitives a real Solution reaches for.
- The main trade-off: a shared token source removes the drift between surfaces but freezes their
  look together — restyling one surface now means deciding whether the other follows.
- The weakest point: the set of primitives is guessed until a Solution is built against it. Keeping
  the set deliberately small is the hedge; every primitive added before step 6 is a guess that has
  to be maintained.
- Failure modes — cause, what breaks, the signal that shows it:
  - Tokens are unified by renaming one palette into the other's names — every unmigrated usage
    silently falls back to an undefined custom property, and the surface loses its colours in the
    themes nobody checked.
  - A primitive is published without a version story — a Core restyle breaks an installed
    Solution's screen, and the integrator's fix is to pin an old Core.
  - The public site is pulled into the same token source — marketing changes start breaking the
    operator panel (this is open question D5).
- Other shapes considered, and why this one:
  - A full design system with its own package — rejected at this size: nothing yet consumes it, and
    the cost lands before the first external consumer exists.
  - Leaving each surface as it is and documenting the palette — rejected: documentation does not
    stop drift, and the duplicate palette already drifted once.

## Scope and non-goals

- In scope: one token source for the operator-facing surfaces (builder and Filament panel), the
  removal of the duplicate palette, and the minimal set of primitives an extension composes
  against, exposed through the agreed publish/Vite contract.
- Not doing: a visual redesign; a component library beyond what an extension needs; the public
  site's own styling unless D5 decides otherwise; the SaaS shell's surfaces, which live in another
  repository.

## Decisions

- The shared layer is tokens plus a small primitive set, not a component library — rejected: a
  full design system, because no external consumer exists yet to justify its maintenance.
- The operator-facing surfaces share the token source; the public site's participation is open
  (D5) — rejected: unifying all three surfaces up front, because the site's brand palette is a
  marketing concern with a different change rhythm.
- Extension-facing components ship through the agreed Vite glob / publish contract already used for
  vendor components — rejected: inventing a second distribution path for UI.

## Open questions

- D5 (from the product roadmap): does the public site keep its own brand palette, or does one token
  source cover every surface including it — a human decision; it sets the boundary of this work.
- Which primitives belong in the first set — answerable only by watching step 6 build against them;
  until then the set stays at the few a Solution obviously needs (form field, button, panel).
- Whether the token source is a CSS file Core owns or a package an extension can depend on
  independently of Core's version.

## Assumptions left untested

- That the builder's and the Filament theme's palettes are close enough to merge without a visual
  diff nobody signed off — taken at normal depth; a side-by-side render of both surfaces before and
  after would test it.
- That an extension only needs primitives inside the builder, not inside the Filament panel — taken
  at normal depth; step 6 tests it.
