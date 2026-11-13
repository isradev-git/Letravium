import test from 'node:test'
import assert from 'node:assert/strict'
import {readFile} from 'node:fs/promises'
import {createHash} from 'node:crypto'
import {
  createThesaurus,
  decodeLatin1,
  loadThesaurus,
  parseThesaurus
} from '../synonyms.mjs'

const thesaurus = await loadThesaurus()

test('decodes ISO-8859-1 bytes without Windows-1252 remapping', () => {
  assert.equal(decodeLatin1(Uint8Array.of(0xf1)), 'ñ')
  assert.equal(decodeLatin1(Uint8Array.of(0x80)), '\u0080')
})

test('parses groups and preserves unknown sense labels as null', () => {
  const fixture = 'ISO8859-1\nfeliz|2\n-|contento|dichoso\nalegría|satisfecho\n'
  const fixtureThesaurus = createThesaurus(fixture)

  assert.deepEqual(fixtureThesaurus.lookup('feliz'), {
    word: 'feliz',
    found: true,
    groups: [
      {meaning: null, synonyms: ['contento', 'dichoso']},
      {meaning: 'alegría', synonyms: ['satisfecho']}
    ]
  })
  assert.deepEqual(parseThesaurus(fixture).get('feliz').length, 2)
})

test('returns synonym groups for exact forms', () => {
  const expectedGroups = {feliz: 5, rápido: 7, banco: 5, correr: 6}

  for (const [word, groupCount] of Object.entries(expectedGroups)) {
    const result = thesaurus.lookup(word)
    assert.equal(result.found, true, word)
    assert.equal(result.groups.length, groupCount, word)
    for (const group of result.groups) {
      assert.ok(group.synonyms.length > 0, word)
    }
  }

  assert.ok(thesaurus.lookup('banco').groups.length > 1)
})

test('returns not found for missing inflected exact forms', () => {
  for (const word of ['rápida', 'corriendo']) {
    assert.equal(thesaurus.has(word), false, word)
    assert.deepEqual(thesaurus.lookup(word), {word, found: false, groups: []})
  }
})

test('keeps the pinned thesaurus data unchanged', async () => {
  const bytes = await readFile(new URL('../data/th_es_v2.dat', import.meta.url))
  assert.equal(
    createHash('sha256').update(bytes).digest('hex'),
    'ee0b7ad7dfede636aa5d5509cbbcbab59bef4cb8cbdff01f9528b15bd139b889'
  )
})
