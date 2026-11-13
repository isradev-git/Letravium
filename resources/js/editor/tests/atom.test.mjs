import test from 'node:test';
import assert from 'node:assert/strict';

import {atomHtml, buildImageBlock, buildTableBlock} from '../atom.mjs';

test('table atoms render escaped cells, header scope and caption', () => {
    const html = atomHtml({
        id: 't1',
        type: 'table',
        caption: 'Cronología <real>',
        rows: [
            {id: 'r1', header: true, cells: [{id: 'c1', text: 'Fecha'}, {id: 'c2', text: 'Hecho'}]},
            {id: 'r2', header: false, cells: [{id: 'c3', text: 'Año <1>'}, {id: 'c4', text: 'Fundación'}]},
        ],
    });

    assert.ok(html.includes('<table>'));
    assert.ok(html.includes('<th scope="col">Fecha</th>'));
    assert.ok(html.includes('<td>Año &lt;1&gt;</td>'));
    assert.ok(html.includes('<figcaption>Cronología &lt;real&gt;</figcaption>'));
});

test('image atoms only use authorized asset ids and escape metadata', () => {
    const html = atomHtml({
        id: 'i1',
        type: 'image',
        attrs: {
            asset_id: 7,
            alt: 'Mapa de <Arven>',
            caption: 'Mapa',
            width: 640,
            height: 480,
            alignment: 'right',
        },
    });

    assert.ok(html.includes('src="/workspace/assets/7/view"'));
    assert.ok(html.includes('alt="Mapa de &lt;Arven&gt;"'));
    assert.ok(html.includes('width="640"'));
    assert.ok(html.includes('height="480"'));
    assert.ok(html.includes('data-alignment="right"'));
    assert.ok(html.includes('<figcaption>Mapa</figcaption>'));
});

test('invalid image atoms render a clear placeholder without a source', () => {
    const html = atomHtml({id: 'i1', type: 'image', attrs: {asset_id: 'javascript:alert(1)'}});

    assert.ok(!html.includes('<img'));
    assert.ok(html.includes('Imagen no válida'));
});

test('table building preserves existing identities and fills new ones', () => {
    const block = buildTableBlock({
        id: 't1',
        caption: '  Cronología  ',
        rows: [
            {id: 'r1', header: true, cells: [{id: 'c1', text: 'Fecha'}]},
            {cells: [{text: 'Año 1'}]},
        ],
    });

    assert.equal(block.id, 't1');
    assert.equal(block.caption, 'Cronología');
    assert.equal(block.rows[0].id, 'r1');
    assert.equal(block.rows[0].cells[0].id, 'c1');
    assert.notEqual(block.rows[1].id, 'r1');
    assert.match(block.rows[1].cells[0].id, /^blk-/);
    assert.equal(block.rows[1].header, false);
    assert.equal(block.rows[1].cells[0].text, 'Año 1');
});

test('table building rejects a grid without cells', () => {
    assert.equal(buildTableBlock({rows: []}), null);
    assert.equal(buildTableBlock({rows: [{cells: []}]}), null);
});

test('image building validates the asset and omits empty optional fields', () => {
    const block = buildImageBlock({
        id: 'i1',
        assetId: 7,
        alt: '  Mapa  ',
        caption: '   ',
        width: 0,
        height: null,
        alignment: 'diagonal',
    });

    assert.deepEqual(block, {
        id: 'i1',
        type: 'image',
        attrs: {asset_id: 7, alt: 'Mapa', alignment: 'center'},
    });
    assert.equal(buildImageBlock({assetId: 0, alt: 'x'}), null);
});
