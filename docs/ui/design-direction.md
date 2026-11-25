# UI design direction

Principles adapted from the README of Nutlope/hallmark (MIT), a design skill that aims to avoid
generic, machine-made UI. No theme or asset is copied. This brief is ours.

## The job of each screen
- **Alert queue:** decide what to look at next. Ends on the action to start working an alert.
- **Alert detail:** understand why it fired, then record a decision. Ends on the decision.
- **Case:** keep one investigation's evidence, notes and decisions together.
- **Rule change:** see what a change would do, then approve or reject as a second person.

## Voice
- People, not ids: "Maya started looking at this 3 minutes ago."
- The stored explanation sentence leads. The rule code is secondary.
- Times are relative, with the exact time on hover (and in a machine-readable attribute).
- The score is explained in words ("40 for severity, 5 for a repeat, 15 for a second rule"), then shown as a number.
- Errors say what happened, what is safe, and what to do next.
- Empty states say what will appear here and when.
- A quiet, permanent note that the data is synthetic. Honest, not decorative.

## Avoid (the tells)
- An eyebrow above every heading; tiny tracked-capital labels on everything.
- One coloured word in a headline; decorative cards with nothing in them.
- A success message before the server has confirmed (closing an alert is final: wait for the server).
- Invented numbers in demos. Everything shown comes from the synthetic data.

## The floor (never ships broken)
- Text contrast, visible keyboard focus on every control, reduced-motion respected.
- Risk is never colour alone: always a word and a shape too.
- Works at phone width.
- Numbers use tabular figures so columns line up.

## Interaction
- Reversible actions (start, release) may update immediately and roll back honestly if refused.
- Irreversible actions (close, freeze account) explain the consequence, require a written note, and wait for confirmation.

## Material (proposal, to confirm in the UI slice)
Warm paper background, near-black ink, one muted accent, a humane serif for headings and a clean
sans for data. Hallmark's catalogue lists themes whose briefs sit close to ours (Dusk, bank
reconciliation; Furrow, farm records; Dose, a family medication app). We will look at them
before choosing and write the choice here.

## Theme: Furrow (from Hallmark, applied in Phase 4)
Theme: Hallmark's "Furrow" (modern-minimal, warm manila paper, near-black ink).
Scope: Hallmark's "app screen" rules. The theme lends its palette, type faces,
radii and surfaces; it does not lend its layout, map or ornament, because this is a
tool people work in, not a page people read.

- **Tokens** live only in `frontend/src/tokens.css` (taken from the theme file).
  No colour or font family appears anywhere else.
- **Type:** Red Hat Display 600 for headings, Red Hat Text for everything else,
  Red Hat Mono only for figures people copy (times and amounts in tables). Served
  locally through `@fontsource`, so the demo makes no third-party font requests.
- **Accent (tangerine)** is a fill with dark ink on it: the primary action and the
  selected tab. It is never used as text colour (it fails contrast against paper).
- **Risk** is always a word plus a number; high risk is also red, never red alone.
- **States:** every screen that loads data has loading (skeleton rows), empty
  (says what belongs here), error (plain wording and "Try again") and full.
- **State in the URL:** the queue filter (`?status=`) and the case filter (`?show=`).
- **Irreversible or money-affecting actions** (close, freeze, unfreeze, assign) wait
  for the server and ask for a reason; reversible ones (start, release) update at once.

Checks run in Slice 5b: Hallmark `sloplint --scope app --render` on five screens at
desktop and 375px phone width: 0 FAIL, 0 REFLEX, 0 WARN. Contrast computed for
every text pair: lowest is 5.4:1 (red on paper); focus ring 3.8:1 against paper.