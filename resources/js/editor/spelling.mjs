import nspell from 'nspell'

export async function createSpelling({aff, dic} = {}) {
  if (!aff || !dic) {
    const dictionary = (await import('dictionary-es')).default
    aff = dictionary.aff
    dic = dictionary.dic
  }

  const spell = nspell(aff, dic)
  const personalWords = new Set()

  return {
    check(word) {
      return spell.correct(String(word))
    },
    suggest(word) {
      return spell.suggest(String(word))
    },
    add(word) {
      const value = String(word)
      spell.add(value)
      personalWords.add(value)
    },
    remove(word) {
      const value = String(word)
      spell.remove(value)
      personalWords.delete(value)
    },
    personalWords() {
      return [...personalWords]
    }
  }
}
