import test from 'node:test'
import assert from 'node:assert/strict'
import {createSpelling} from '../spelling.mjs'

const spelling = await createSpelling()

test('accepts accented words, ñ and feminine forms', () => {
  assert.equal(spelling.check('árbol'), true)
  assert.equal(spelling.check('niña'), true)
  assert.equal(spelling.check('rápida'), true)
})

test('reports known dictionary limits', () => {
  assert.equal(spelling.check('teórico-práctico'), false)
  assert.equal(spelling.check('2026'), false)
})

test('suggests corrections without applying them', () => {
  assert.ok(spelling.suggest('rapida').includes('rápida'))
  assert.ok(spelling.suggest('arvol').includes('árbol'))
})

test('adds and removes personal words', () => {
  assert.equal(spelling.check('Valdara'), false)
  spelling.add('Valdara')
  assert.equal(spelling.check('Valdara'), true)
  assert.deepEqual(spelling.personalWords(), ['Valdara'])
  spelling.remove('Valdara')
  assert.equal(spelling.check('Valdara'), false)
  assert.deepEqual(spelling.personalWords(), [])
})
