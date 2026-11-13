export const NBSP = '\u00a0'

export const DEFAULT_OPTIONS = Object.freeze({
  dialogueDash: true,
  angularQuotes: true,
  ellipsis: true,
  nonBreakingSpace: true
})

const rules = [
  {
    name: 'ellipsis',
    option: 'ellipsis',
    pattern: /\.\.\./g,
    replace: () => '…'
  },
  {
    name: 'angularQuotes',
    option: 'angularQuotes',
    pattern: /"([^"\n]*?)"/g,
    replace: (match, inner) => `«${inner}»`
  },
  {
    name: 'dialogueDash',
    option: 'dialogueDash',
    pattern: /^-([ \t]+)(?=\S)/gm,
    replace: (match, space) => `—${space}`
  },
  {
    name: 'nonBreakingSpace',
    option: 'nonBreakingSpace',
    pattern: /(\d) +(%|€|\$|km|kg|cm|mm|ml|mg|min|[mglsh])(?![\p{L}\p{N}])/gu,
    replace: (match, number, unit) => `${number}${NBSP}${unit}`
  }
]

export function apply(input, options = {}) {
  if (typeof input !== 'string') {
    throw new TypeError('typography.apply expects a string')
  }

  const settings = {...DEFAULT_OPTIONS, ...options}
  const edits = []
  let text = input

  for (const rule of rules) {
    if (!settings[rule.option]) continue
    const result = runRule(text, rule)
    text = result.text
    edits.push(...result.edits)
  }

  return {text, edits}
}

export function undo(transformation) {
  if (
    !transformation ||
    typeof transformation.text !== 'string' ||
    !Array.isArray(transformation.edits)
  ) {
    throw new TypeError('typography.undo expects the result of typography.apply')
  }

  let text = transformation.text
  for (let index = transformation.edits.length - 1; index >= 0; index--) {
    const edit = transformation.edits[index]
    if (text.slice(edit.start, edit.start + edit.after.length) !== edit.after) {
      throw new Error('Cannot undo typography: text changed since apply')
    }
    text =
      text.slice(0, edit.start) + edit.before + text.slice(edit.start + edit.after.length)
  }

  return text
}

function runRule(text, rule) {
  const edits = []
  let output = ''
  let lastIndex = 0
  rule.pattern.lastIndex = 0

  let match
  while ((match = rule.pattern.exec(text)) !== null) {
    const before = match[0]
    const after = rule.replace(...match)

    if (before === after) {
      if (before.length === 0) rule.pattern.lastIndex++
      continue
    }

    output += text.slice(lastIndex, match.index)
    const start = output.length
    output += after
    edits.push({rule: rule.name, start, sourceStart: match.index, before, after})
    lastIndex = match.index + before.length
  }

  output += text.slice(lastIndex)
  return {text: output, edits}
}
