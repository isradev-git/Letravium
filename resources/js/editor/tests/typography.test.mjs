import test from 'node:test'
import assert from 'node:assert/strict'
import {apply, undo, NBSP} from '../typography.mjs'

test('applies local substitutions and undo restores the exact original', () => {
  const input =
    'Primera línea sin cambios.\n' +
    '- Hola, dijo: "adiós..."\n' +
    'Son 10 % y 5 km.\n' +
    '- Otra vez.'
  const expected =
    'Primera línea sin cambios.\n' +
    '— Hola, dijo: «adiós…»\n' +
    `Son 10${NBSP}% y 5${NBSP}km.\n` +
    '— Otra vez.'

  const result = apply(input)
  assert.equal(result.text, expected)
  assert.equal(undo(result), input)
})

test('leaves unrelated text untouched', () => {
  const input = 'Texto normal, sin diálogo ni comillas ni cifras.'
  const result = apply(input)
  assert.equal(result.text, input)
  assert.deepEqual(result.edits, [])
})

test('quote substitution only changes the quotation marks', () => {
  const input = '"Una frase entera."'
  const result = apply(input)
  assert.equal(result.text, '«Una frase entera.»')
  assert.equal(result.edits.length, 1)
  assert.equal(result.edits[0].before.slice(1, -1), result.edits[0].after.slice(1, -1))
})

test('dialogue dash only changes a hyphen at the start of a line', () => {
  assert.equal(apply('- Diálogo.').text, '— Diálogo.')
  assert.equal(apply('a - b').text, 'a - b')
  assert.equal(apply('  - lista').text, '  - lista')
})

test('non-breaking space respects units and plain words', () => {
  assert.equal(apply('10 %').text, `10${NBSP}%`)
  assert.equal(apply('5 km').text, `5${NBSP}km`)
  assert.equal(apply('3 metros').text, '3 metros')
  assert.equal(apply('2026').text, '2026')
})

test('rules can be disabled independently', () => {
  assert.equal(apply('... "x"', {ellipsis: false, angularQuotes: false}).text, '... "x"')
  assert.equal(apply('10 %', {nonBreakingSpace: false}).text, '10 %')
  assert.equal(apply('- Hola.', {dialogueDash: false}).text, '- Hola.')
})

test('undo refuses stale text', () => {
  const result = apply('...')
  assert.throws(() => undo({...result, text: 'otro texto'}), /text changed/)
})
