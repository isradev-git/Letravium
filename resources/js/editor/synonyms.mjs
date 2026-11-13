const DEFAULT_DATA_URL = new URL('./data/th_es_v2.dat', import.meta.url)

export function decodeLatin1(bytes) {
  let text = ''
  for (let offset = 0; offset < bytes.length; offset += 0x8000) {
    text += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000))
  }
  return text
}

export function parseThesaurus(text) {
  const lines = String(text).split(/\r?\n/)
  const entries = new Map()
  let index = 1

  while (index < lines.length) {
    const header = lines[index]
    if (header === '') {
      index++
      continue
    }

    const separator = header.lastIndexOf('|')
    if (separator === -1) {
      throw new Error(`Malformed thesaurus header at line ${index + 1}`)
    }

    const word = header.slice(0, separator)
    const groupCount = Number(header.slice(separator + 1))
    if (!Number.isInteger(groupCount) || groupCount < 0) {
      throw new Error(`Malformed thesaurus group count at line ${index + 1}`)
    }

    index++
    const groups = []
    for (let group = 0; group < groupCount; group++, index++) {
      if (lines[index] === undefined) {
        throw new Error(`Missing thesaurus group for "${word}"`)
      }
      const parts = lines[index].split('|')
      const meaning = parts.shift()
      groups.push({
        meaning: meaning === '-' || meaning === '' ? null : meaning,
        synonyms: parts.filter((synonym) => synonym !== '')
      })
    }

    entries.set(word, groups)
  }

  return entries
}

export function createThesaurus(text) {
  const entries = parseThesaurus(text)

  return {
    has(word) {
      return entries.has(word)
    },
    lookup(word) {
      const groups = entries.get(word)
      return groups
        ? {word, found: true, groups}
        : {word, found: false, groups: []}
    }
  }
}

export async function loadThesaurus({url = DEFAULT_DATA_URL} = {}) {
  const {readFile} = await import('node:fs/promises')
  const bytes = await readFile(url)
  return createThesaurus(decodeLatin1(bytes))
}
