# Opti UI tokens (wave 6)

Status: contract of semantic names; values stay per product. Written 2026-10-24 from the CSS of each `main`.

The four web apps grew separate stylesheets with different names for the same idea (`--primary`, `--brand`, `--navy`). Moving them to one
shared package would touch four frontends at once and needs a package registry the repositories do not have, so wave 6 fixes the **names**
and records today's **values**. A product adopts a name when it next touches its stylesheet; nothing is renamed in bulk.

## Semantic names

| Token | Meaning |
|---|---|
| `--bg`, `--surface`, `--surface-2` | page, card, nested card background |
| `--text`, `--text-muted` | body and secondary text |
| `--border`, `--border-strong` | hairlines, inputs |
| `--brand`, `--brand-hover`, `--brand-ink`, `--brand-weak` | product colour, its hover, text on it, tinted background |
| `--danger`, `--success`, `--warning` (+ `-bg`, `-fg`) | status colours and their badge pairs |

Dark mode redefines the same names under `@media (prefers-color-scheme: dark)`; components never hard-code a colour.

## Today (light theme)

| Product | `--brand` today | Name used today | Other tokens |
|---|---|---|---|
| OptiNexus | `#4338ca` | `--primary` | `--bg #f6f7fb`, `--surface #fff`, `--border #e2e4ea`, `--text #1f2333`, status pairs present |
| OptiFleet | `#1d4ed8` | `--primary`, `--primary-dark` | `--bg #f3f4f6`, `--text #111827`; no surface/border tokens |
| OptiEntry | `#0f4c81` | `--brand` (already the target names, plus dark mode) | `--bg #f4f6f9`, `--surface #fff`, `--border #dbe1e9` |
| OptiDeli | navy `#124968` (accent orange `#d9560a`) | `--navy` | `--muted #617486`, `--line #e2e8ee`, `--warn-bg/-text` |

OptiEntry's `index.css` is the reference for the target names. Product colours stay different on purpose: they identify the product in the
product switcher.

## Adoption order (when each product next edits its stylesheet)

1. OptiNexus: alias `--primary` to `--brand`, `--text-muted` stays.
2. OptiFleet: add `--surface`, `--border`, status pairs; keep `--primary` as an alias of `--brand`.
3. OptiDeli: map `--navy` to `--brand`, `--line` to `--border`, `--muted` to `--text-muted`, `--warn-*` to `--warning-*`.
