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