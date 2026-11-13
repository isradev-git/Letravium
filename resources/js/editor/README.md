# Local editor modules

`document.mjs` is connected to the editor page and serializes the contenteditable back to the canonical document. The typography, spelling and synonym modules still have no UI. They run without network access and never send manuscript text anywhere.

## Contract

### `typography.mjs`

```js
import {apply, undo, NBSP} from './typography.mjs'

const change = apply(selectedText, {dialogueDash: true, angularQuotes: true, ellipsis: true, nonBreakingSpace: true})
change.text      // transformed text for the current selection or paragraph
change.edits     // ordered local edits; store them in the undo record
undo(change)     // exact original text, or throws if the text changed since apply
```

Rules: `...` → `…`; `"…"` → `«…»`; a line-start `- ` → `— `; a space between a number and a unit/`%`/currency → `NBSP`. Call it only on an explicit user action and only on the affected selection/paragraph. Undo maps each edited span, not a regenerated sentence. `undo` deliberately fails on stale text.

### `spelling.mjs`

```js
import {createSpelling} from './spelling.mjs'

const spelling = await createSpelling()
spelling.check(word)       // boolean
spelling.suggest(word)     // ranked strings; never auto-apply
spelling.add(word)         // personal word, in memory only
spelling.remove(word)
spelling.personalWords()
```

The caller owns tokenization, occurrence marking per text revision, persistence, permissions and the universe word list. `nspell` does only word checks and suggestions.

In a browser build, load `nspell` as a normal dependency and pass the dictionary as decoded UTF-8 strings: `createSpelling({aff, dic})`. `nspell` accepts Buffer or string, not a plain `Uint8Array`, so decode the bundled files with `TextDecoder('utf-8')`. Without arguments the Node loader uses `dictionary-es`, whose entry reads its files with `node:fs/promises`.

### `synonyms.mjs`

```js
import {loadThesaurus, createThesaurus, decodeLatin1, parseThesaurus} from './synonyms.mjs'

const thesaurus = await loadThesaurus()
const result = thesaurus.lookup(word)
// {word, found, groups: [{meaning: string | null, synonyms: string[]}]}
thesaurus.has(word)
```

`lookup` is exact-form only and returns every group/sense. It has no replace function: the author chooses a synonym and the editor applies it with its own formatting and undo path. In a browser, fetch the data file, decode it with `decodeLatin1(bytes)` rather than `TextDecoder('iso-8859-1')` (browser labels remap to Windows-1252), then call `createThesaurus(decodedText)` once and reuse it.

The editor's `contextmenu` handler opens the same groups in a word-anchored popover (right-click on a word); the toolbar «Sinónimos» panel remains the keyboard path. Choosing a synonym replaces only that form, preserves marks and uses the editor undo history. The data is exact-form only, so inflected forms may report no synonyms.

### `spellcheck.mjs`

`findWords(text)` returns `{word, start, end}` tokens in codepoint offsets; it keeps Spanish interior hyphens and apostrophes. `findMisspellings(text, spelling, limit = 40)` calls the supplied nspell instance, returns only rejected words with up to five explicit suggestions, and never changes text. `occurrenceAt(occurrences, blockId, offset)` finds the misspelling under a codepoint offset for the editor's click popover.

The editor loads the bundled dictionary after the page settles, debounces a scan of the focused scene on input, and marks rejected words with the `spelling-errors` CSS highlight (red wavy underline). Clicking a marked word opens a local popover with explicit suggestions; choosing one replaces only that word through the existing format/undo path. Closing the Ortografía panel does not clear the inline marks. Personal words remain in memory for the session; persistence is not implemented.

## Tests

```sh
node --test resources/js/editor/tests/
```

## Licenses

| Artifact | Version | License |
| --- | --- | --- |
| `nspell` | 2.1.5 | MIT (engine code) |
| `is-buffer` | 2.0.5 | MIT (transitive) |
| `dictionary-es` | 4.0.0 | Data: GPL-3.0-or-later OR LGPL-3.0-or-later OR MPL-1.1-or-later. **MPL-1.1-or-later selected** for the bundled copy; see `data/LICENSE_es.txt`. The dictionary is Spanish version 2.8. |
| `data/es.aff`, `data/es.dic` | copied from `dictionary-es` 4.0.0 | MPL-1.1-or-later; SHA-256 `892039d59db11d747061b81c3a92a52d48ba0e055b5835af1943cbd3c2fba85b` / `907f786a8ceb3456722b20ad91dd4dbe99c5c16e45015ea63155e36f26b06d2c` |
| `data/th_es_v2.dat` | OpenThesaurus snapshot 2012-01-11 | LGPL 2.1; see `data/LICENSE_th_es.md` and `data/README_th_es.txt` |

The dictionary data is not MIT. The thesaurus file is pinned by SHA-256 `ee0b7ad7dfede636aa5d5509cbbcbab59bef4cb8cbdff01f9528b15bd139b889`.

## Known limits

- Spelling: `teórico-práctico` is rejected by the default dictionary; numeric tokens such as `2026` are not words. Personal words are in memory only.
- Synonyms: exact-form lookup only; `rápida` and `corriendo` are absent even though `rápido` and `correr` exist. 98.2% of sense labels are missing (`-`), so groups can be unrelated and the UI must not auto-select. The data is a 2012 snapshot.
- Typography: a line-start hyphen is treated as a dialogue dash, so an explicitly transformed list line would also change. There is no sentence-level rewriting, capitalization guessing or morphological adjustment.
