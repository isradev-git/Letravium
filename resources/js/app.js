import {newBlockId, serializeBlock, serializeDocument} from './editor/document.mjs';
import {atomHtml, buildImageBlock, buildTableBlock} from './editor/atom.mjs';
import {removeRunMarks, setRunMark} from './editor/run-format.mjs';
import {removeNoteReferences, setNoteReference} from './editor/note-format.mjs';
import {transformSegments} from './editor/typography-segments.mjs';
import {createThesaurus, decodeLatin1} from './editor/synonyms.mjs';
import {replaceRange} from './editor/synonym-replace.mjs';
import thesaurusUrl from './editor/data/th_es_v2.dat?url';
import {createSpelling} from './editor/spelling.mjs';
import {findMisspellings, occurrenceAt, wordAt} from './editor/spellcheck.mjs';
import spellingAffUrl from './editor/data/es.aff?url';
import spellingDicUrl from './editor/data/es.dic?url';
import {blockHtml, droppedMessage, freshBlockIds, plainTextBlocks} from './editor/paste.mjs';
import {initDialogs} from './dialog.js';

function initEditorPage() {
    const editors = [...document.querySelectorAll('.scene-editor')];

    if (!editors.length) {
        return;
    }

    let characterStyles = [];

    try {
        characterStyles = JSON.parse(document.getElementById('character-styles')?.textContent ?? '[]');
    } catch {
        characterStyles = [];
    }

    const stylesMap = Object.fromEntries(
        (Array.isArray(characterStyles) ? characterStyles : [])
            .map((style) => [String(style.id), style.definition ?? {}]),
    );

    const status = document.getElementById('editor-status');
    const saveButton = document.getElementById('save-all');
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const dirty = new Set();
    let active = null;
    let suppressHistoryReset = false;
    const formatHistory = new Map();
    let lastFormatEditor = null;

    const setStatus = (text, state) => {
        if (!status) {
            return;
        }

        status.textContent = text;
        status.dataset.state = state;
    };

    const markDirty = (editor) => {
        if (!editor) {
            return;
        }

        dirty.add(editor);
        setStatus('Cambios sin guardar', 'dirty');
    };

    const focusedEditor = () => active ?? editors[0];

    const clearDirty = (editor) => {
        dirty.delete(editor);

        if (dirty.size === 0) {
            setStatus('Guardado en servidor', 'saved');
        }
    };

    for (const editor of editors) {
        editor.addEventListener('focusin', () => {
            active = editor;
            scheduleInlineSpelling(300);
        });
        editor.addEventListener('input', (event) => {
            markDirty(editor);

            if (!suppressHistoryReset) {
                formatHistory.delete(editor);

                if (lastFormatEditor === editor) {
                    lastFormatEditor = null;
                }
            }

            if (!event.isComposing) {
                scheduleInlineSpelling(700);
            }
        });
        editor.addEventListener('click', openSpellingPopover);
        editor.addEventListener('contextmenu', openSynonymPopover);
    }

    const keepSelection = (event) => event.preventDefault();

    let pendingPaste = null;

    for (const editor of editors) {
        editor.addEventListener('paste', (event) => {
            const clipboard = event.clipboardData;

            if (!clipboard) {
                return;
            }

            event.preventDefault();

            const html = (clipboard.getData('text/html') ?? '').trim();
            const text = clipboard.getData('text/plain') ?? '';

            if (html === '' && text.trim() === '') {
                setStatus('El portapapeles no contiene texto compatible.', 'error');

                return;
            }

            pendingPaste = {editor, html, text, anchor: currentBlock(editor)};

            if (pasteSummary) {
                pasteSummary.textContent = html !== ''
                    ? 'Contenido externo pegado. Elige cómo insertarlo.'
                    : 'Texto sin formato pegado. Elige cómo insertarlo.';
            }

            if (pasteFormattedButton) {
                pasteFormattedButton.disabled = html === '';
            }

            hidePanels();
            pastePanel?.removeAttribute('hidden');
            (html !== '' ? pasteFormattedButton : pastePlainButton)?.focus();
        });

        editor.addEventListener('click', (event) => {
            const atom = event.target.closest?.('.editor-atom[data-kind="atom"]');

            if (!atom || !editor.contains(atom)) {
                return;
            }

            const block = parseBlock(atom);

            if (block?.type === 'table') {
                active = editor;
                openTablePanel(atom);
            } else if (block?.type === 'image') {
                const editorSheet = editor.closest('[data-is-owner]');

                if (editorSheet?.dataset.isOwner === 'false') {
                    setStatus('Solo el propietario puede editar imágenes en este borrador.', 'error');

                    return;
                }

                active = editor;
                openImagePanel(atom);
            }
        });

        editor.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            const atom = event.target.closest?.('.editor-atom[data-kind="atom"]');

            if (atom) {
                event.preventDefault();
                atom.click();
            }
        });
    }

    const blockForNode = (editor, node) => {
        if (!node || node === editor) {
            return null;
        }

        if (node.nodeType === 3) {
            node = node.parentNode;
        }

        while (node && node !== editor) {
            if (node.nodeType === 1 && node.dataset.kind === 'text') {
                return node;
            }

            node = node.parentNode;
        }

        return null;
    };

    const currentBlock = (editor) => {
        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0) {
            return null;
        }

        const container = selection.getRangeAt(0).startContainer;

        if (container === editor) {
            return editor.querySelector('[data-kind="text"]');
        }

        return blockForNode(editor, container);
    };

    const placeCaretAtEnd = (element) => {
        const range = document.createRange();
        range.selectNodeContents(element);
        range.collapse(false);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    };

    const blockElement = (block) => {
        const tag = block.type === 'heading' ? 'h2' : block.type === 'quote' ? 'blockquote' : 'p';
        const element = document.createElement(tag);
        element.className = 'editor-block';
        element.dataset.kind = 'text';
        element.dataset.blockId = block.id || newBlockId();
        element.dataset.original = JSON.stringify(block);
        element.innerHTML = blockHtml(block);

        return element;
    };

    const insertBlocks = (editor, blocks, anchor) => {
        let reference = anchor && anchor.isConnected && editor.contains(anchor) ? anchor : null;
        let last = null;

        for (const block of freshBlockIds(blocks)) {
            const element = blockElement(block);

            if (reference) {
                reference.after(element);
            } else {
                editor.appendChild(element);
            }

            reference = element;
            last = element;
        }

        if (last) {
            editor.focus();
            placeCaretAtEnd(last);
        }

        markDirty(editor);
    };

    const setBlockTag = (tag) => {
        const editor = focusedEditor();
        const block = currentBlock(editor);

        if (!block || block.tagName.toLowerCase() === tag) {
            return;
        }

        const replacement = document.createElement(tag);

        for (const attribute of block.attributes) {
            replacement.setAttribute(attribute.name, attribute.value);
        }

        delete replacement.dataset.listType;
        delete replacement.dataset.depth;

        for (const child of [...block.childNodes]) {
            replacement.appendChild(child);
        }

        block.replaceWith(replacement);
        placeCaretAtEnd(replacement);
        markDirty(editor);
    };

    const insertSceneBreak = () => {
        const editor = focusedEditor();
        const id = newBlockId();
        const breakElement = document.createElement('div');
        breakElement.className = 'editor-atom';
        breakElement.contentEditable = 'false';
        breakElement.dataset.kind = 'break';
        breakElement.dataset.blockId = id;
        breakElement.dataset.original = JSON.stringify({id, type: 'scene_break'});
        breakElement.textContent = 'Separación de escena';

        const paragraphId = newBlockId();
        const paragraph = document.createElement('p');
        paragraph.className = 'editor-block';
        paragraph.dataset.kind = 'text';
        paragraph.dataset.blockId = paragraphId;
        paragraph.dataset.original = JSON.stringify({
            id: paragraphId,
            type: 'paragraph',
            text: '',
            marks: [],
        });

        const block = currentBlock(editor);

        if (block) {
            block.after(breakElement);
        } else {
            editor.appendChild(breakElement);
        }

        breakElement.after(paragraph);
        editor.focus();
        placeCaretAtEnd(paragraph);
        markDirty(editor);
    };

    const isListCommand = (command) => command === 'insertUnorderedList' || command === 'insertOrderedList';

    const formatStacks = (editor) => {
        if (!formatHistory.has(editor)) {
            formatHistory.set(editor, {undo: [], redo: []});
        }

        return formatHistory.get(editor);
    };

    const rememberFormatState = (editor, block) => {
        const stacks = formatStacks(editor);
        stacks.undo.push({blockId: block.dataset.blockId, html: block.outerHTML});

        if (stacks.undo.length > 30) {
            stacks.undo.shift();
        }

        stacks.redo = [];
        lastFormatEditor = editor;
    };

    const rememberEditorState = (editor) => {
        const stacks = formatStacks(editor);
        stacks.undo.push({editorHtml: editor.innerHTML});

        if (stacks.undo.length > 30) {
            stacks.undo.shift();
        }

        stacks.redo = [];
        lastFormatEditor = editor;
    };

    const stepFormat = (editor, direction) => {
        const stacks = formatStacks(editor);
        const from = direction === 'undo' ? stacks.undo : stacks.redo;
        const to = direction === 'undo' ? stacks.redo : stacks.undo;
        const entry = from.pop();

        if (!entry) {
            return false;
        }

        if (typeof entry.editorHtml === 'string') {
            to.push({editorHtml: editor.innerHTML});
            editor.innerHTML = entry.editorHtml;
            editor.focus();
            placeCaretAtEnd(editor.querySelector('[data-kind="text"]') ?? editor);
            markDirty(editor);

            return true;
        }

        const block = [...editor.querySelectorAll('[data-block-id]')]
            .find((element) => element.dataset.blockId === entry.blockId);

        if (!block) {
            return false;
        }

        const template = document.createElement('template');
        template.innerHTML = entry.html;
        const replacement = template.content.firstElementChild;

        if (!replacement) {
            return false;
        }

        to.push({blockId: entry.blockId, html: block.outerHTML});
        block.replaceWith(replacement);
        editor.focus();
        placeCaretAtEnd(replacement);
        markDirty(editor);

        return true;
    };

    for (const button of document.querySelectorAll('[data-command]')) {
        button.addEventListener('mousedown', keepSelection);
        button.addEventListener('click', () => {
            const editor = focusedEditor();
            const command = button.dataset.command;

            if ((command === 'undo' || command === 'redo')
                && lastFormatEditor === editor
                && stepFormat(editor, command)) {
                return;
            }

            const stableId = isListCommand(command)
                ? currentBlock(editor)?.dataset.blockId
                : null;

            editor.focus();
            document.execCommand(command, false, null);

            if (stableId) {
                const listItem = editor.querySelector('li:not([data-block-id])');

                if (listItem) {
                    listItem.dataset.blockId = stableId;
                }
            }

            markDirty(editor);
        });
    }

    for (const button of document.querySelectorAll('[data-block]')) {
        button.addEventListener('mousedown', keepSelection);
        button.addEventListener('click', () => setBlockTag(button.dataset.block));
    }

    for (const button of document.querySelectorAll('[data-break]')) {
        button.addEventListener('mousedown', keepSelection);
        button.addEventListener('click', insertSceneBreak);
    }

    let savedRange = null;
    let bookmarkTarget = null;

    const linkPanel = document.getElementById('link-panel');
    const bookmarkPanel = document.getElementById('bookmark-panel');
    const paragraphPanel = document.getElementById('paragraph-panel');
    const pastePanel = document.getElementById('paste-panel');
    const pasteSummary = document.getElementById('paste-summary');
    const pasteFormattedButton = document.getElementById('paste-formatted');
    const pastePlainButton = document.getElementById('paste-plain');
    const linkInput = document.getElementById('link-url');
    const bookmarkInput = document.getElementById('bookmark-name');
    const tablePanel = document.getElementById('table-panel');
    const imagePanel = document.getElementById('image-panel');
    const tableGridBox = document.getElementById('table-grid');
    const tableCaptionInput = document.getElementById('table-caption');
    const imageAssetSelect = document.getElementById('image-asset');
    const imageFileInput = document.getElementById('image-file');
    const imageAltInput = document.getElementById('image-alt');
    const imageCaptionInput = document.getElementById('image-caption');
    const imageWidthInput = document.getElementById('image-width');
    const imageHeightInput = document.getElementById('image-height');
    const imageAlignInput = document.getElementById('image-align');
    const imageStatus = document.getElementById('image-status');
    const novelId = document.querySelector('[data-novel-id]')?.dataset.novelId ?? null;
    const notePanel = document.getElementById('note-panel');
    const noteSelect = document.getElementById('note-select');
    const noteKind = document.getElementById('note-kind');
    const noteBody = document.getElementById('note-body');
    const noteStatus = document.getElementById('note-status');
    const noteOrphans = document.getElementById('note-orphans');
    const typographyPanel = document.getElementById('typography-panel');
    const typographyOptions = {
        dialogue: document.getElementById('typography-dialogue'),
        quotes: document.getElementById('typography-quotes'),
        ellipsis: document.getElementById('typography-ellipsis'),
        nbsp: document.getElementById('typography-nbsp'),
    };
    const synonymsPanel = document.getElementById('synonyms-panel');
    const synonymsWord = document.getElementById('synonyms-word');
    const synonymsStatus = document.getElementById('synonyms-status');
    const synonymsGroups = document.getElementById('synonyms-groups');
    const spellingPanel = document.getElementById('spelling-panel');
    const spellingSummary = document.getElementById('spelling-summary');
    const spellingList = document.getElementById('spelling-list');
    const spellingPopover = document.getElementById('spelling-popover');
    const spellingPopoverWord = document.getElementById('spelling-popover-word');
    const spellingPopoverActions = document.getElementById('spelling-popover-actions');
    const synonymPopover = document.getElementById('synonym-popover');
    const synonymPopoverWord = document.getElementById('synonym-popover-word');
    const synonymPopoverStatus = document.getElementById('synonym-popover-status');
    const synonymPopoverGroups = document.getElementById('synonym-popover-groups');
    const characterStyleSelect = document.getElementById('format-character-style');

    if (characterStyleSelect) {
        for (const style of Array.isArray(characterStyles) ? characterStyles : []) {
            const option = document.createElement('option');
            option.value = String(style.id);
            option.textContent = style.name;
            characterStyleSelect.appendChild(option);
        }
    }

    const saveSelection = () => {
        const selection = window.getSelection();

        if (selection && selection.rangeCount > 0) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }
    };

    const restoreSelection = () => {
        if (!savedRange) {
            return;
        }

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedRange);
    };

    const hidePanels = () => {
        linkPanel?.setAttribute('hidden', '');
        bookmarkPanel?.setAttribute('hidden', '');
        paragraphPanel?.setAttribute('hidden', '');
        pastePanel?.setAttribute('hidden', '');
        tablePanel?.setAttribute('hidden', '');
        imagePanel?.setAttribute('hidden', '');
        notePanel?.setAttribute('hidden', '');
        typographyPanel?.setAttribute('hidden', '');
        synonymsPanel?.setAttribute('hidden', '');
        spellingPanel?.setAttribute('hidden', '');
        closeSpellingPopover();
        closeSynonymPopover();
    };

    for (const button of document.querySelectorAll('[data-panel]')) {
        button.addEventListener('mousedown', keepSelection);
        button.addEventListener('click', () => {
            saveSelection();

            hidePanels();

            if (button.dataset.panel === 'link') {
                linkInput.value = '';
                linkPanel?.removeAttribute('hidden');
                linkInput?.focus();

                return;
            }

            if (button.dataset.panel === 'bookmark') {
                bookmarkTarget = currentBlock(focusedEditor());
                bookmarkInput.value = bookmarkTarget?.dataset.bookmark ?? '';
                bookmarkPanel?.removeAttribute('hidden');
                bookmarkInput?.focus();

                return;
            }

            if (button.dataset.panel === 'table') {
                openTablePanel(null);

                return;
            }

            if (button.dataset.panel === 'image') {
                openImagePanel(null);

                return;
            }

            if (button.dataset.panel === 'note') {
                openNotePanel();

                return;
            }

            if (button.dataset.panel === 'typography') {
                openTypographyPanel();

                return;
            }

            if (button.dataset.panel === 'synonyms') {
                openSynonymsPanel();

                return;
            }

            if (button.dataset.panel === 'spelling') {
                openSpellingPanel();

                return;
            }

            openParagraphPanel();
        });
    }

    document.getElementById('link-apply')?.addEventListener('click', () => {
        const url = linkInput.value.trim();

        if (!/^https?:\/\/[^\s]+$/i.test(url)) {
            setStatus('El enlace debe empezar por http:// o https://', 'error');

            return;
        }

        restoreSelection();
        document.execCommand('createLink', false, url);
        hidePanels();
        markDirty(focusedEditor());
    });

    document.getElementById('link-remove')?.addEventListener('click', () => {
        restoreSelection();
        document.execCommand('unlink', false, null);
        hidePanels();
        markDirty(focusedEditor());
    });

    document.getElementById('link-cancel')?.addEventListener('click', hidePanels);

    document.getElementById('bookmark-apply')?.addEventListener('click', () => {
        if (!bookmarkTarget) {
            return;
        }

        const name = bookmarkInput.value.trim();

        if (name) {
            bookmarkTarget.dataset.bookmark = name;
        } else {
            delete bookmarkTarget.dataset.bookmark;
        }

        hidePanels();
        markDirty(focusedEditor());
    });

    document.getElementById('bookmark-remove')?.addEventListener('click', () => {
        if (bookmarkTarget) {
            delete bookmarkTarget.dataset.bookmark;
        }

        hidePanels();
        markDirty(focusedEditor());
    });

    document.getElementById('bookmark-cancel')?.addEventListener('click', hidePanels);

    pastePlainButton?.addEventListener('click', () => {
        const pending = pendingPaste;

        if (!pending) {
            return;
        }

        const blocks = plainTextBlocks(pending.text);
        pendingPaste = null;
        hidePanels();

        if (blocks.length === 0) {
            setStatus('El portapapeles no contiene texto compatible.', 'error');

            return;
        }

        insertBlocks(pending.editor, blocks, pending.anchor);
        setStatus('Texto pegado sin formato. Cambios sin guardar.', 'dirty');
    });

    pasteFormattedButton?.addEventListener('click', async () => {
        const pending = pendingPaste;

        if (!pending || pending.html === '') {
            return;
        }

        pasteFormattedButton.disabled = true;
        pastePlainButton?.setAttribute('disabled', '');
        setStatus('Revisando el contenido pegado…', 'saving');

        try {
            const response = await fetch('/workspace/editor/sanitize-paste', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({html: pending.html}),
            });

            if (!response.ok) {
                setStatus('No se pudo revisar el contenido pegado. No se insertó nada.', 'error');

                return;
            }

            const payload = await response.json();
            const blocks = Array.isArray(payload.blocks) ? payload.blocks : [];
            pendingPaste = null;
            hidePanels();

            if (blocks.length === 0) {
                setStatus('El contenido pegado no tenía texto compatible.', 'error');

                return;
            }

            insertBlocks(pending.editor, blocks, pending.anchor);
            setStatus(
                `${droppedMessage(payload.dropped) || 'Contenido pegado con formato compatible.'} Cambios sin guardar.`,
                'dirty',
            );
        } catch {
            setStatus('Sin conexión. No se insertó el contenido pegado.', 'error');
        } finally {
            pasteFormattedButton.disabled = false;
            pastePlainButton?.removeAttribute('disabled');
        }
    });

    document.getElementById('paste-cancel')?.addEventListener('click', () => {
        pendingPaste = null;
        hidePanels();
    });

    const parseBlock = (element) => {
        try {
            return JSON.parse(element.dataset.original);
        } catch {
            return null;
        }
    };

    const atomElement = (block) => {
        const element = document.createElement('div');
        element.className = 'editor-atom editor-atom-block';
        element.contentEditable = 'false';
        element.tabIndex = 0;
        element.setAttribute('role', 'button');
        element.dataset.kind = 'atom';
        element.dataset.blockId = block.id;
        element.dataset.original = JSON.stringify(block);
        element.setAttribute('aria-label', block.type === 'table' ? 'Editar tabla' : 'Editar imagen');
        element.innerHTML = atomHtml(block);

        return element;
    };

    const placeAtom = (editor, block, target, anchor) => {
        const element = atomElement(block);

        if (target && target.isConnected && editor.contains(target)) {
            rememberFormatState(editor, target);
            target.replaceWith(element);
        } else {
            rememberEditorState(editor);
            const reference = anchor && anchor.isConnected && editor.contains(anchor)
                ? anchor
                : currentBlock(editor);

            if (reference) {
                reference.after(element);
            } else {
                editor.appendChild(element);
            }
        }

        markDirty(editor);
    };

    const removeAtom = (editor, target, message) => {
        if (!target || !editor.contains(target)) {
            return;
        }

        rememberEditorState(editor);
        target.remove();
        markDirty(editor);
        hidePanels();
        setStatus(message, 'dirty');
    };

    let tableTarget = null;
    let tableAnchor = null;
    let tableDraft = [];

    const defaultTableDraft = () => ([
        {header: true, cells: [{text: ''}, {text: ''}]},
        {header: false, cells: [{text: ''}, {text: ''}]},
    ]);

    const tableDraftFromBlock = (block) => {
        const rows = Array.isArray(block?.rows) ? block.rows : [];

        if (rows.length === 0) {
            return defaultTableDraft();
        }

        return rows.map((row) => ({
            id: row.id,
            header: row.header === true,
            cells: (Array.isArray(row.cells) ? row.cells : []).map((cell) => ({
                id: cell.id,
                text: String(cell.text ?? ''),
            })),
        }));
    };

    const captureTableGrid = () => {
        if (!tableGridBox) {
            return;
        }

        const rows = [...tableGridBox.querySelectorAll('.table-grid-row')];
        tableDraft = rows.map((row, rowIndex) => {
            const header = row.querySelector('input[type="checkbox"]')?.checked ?? false;
            const inputs = [...row.querySelectorAll('input[type="text"]')];

            return {
                id: tableDraft[rowIndex]?.id,
                header,
                cells: inputs.map((input, columnIndex) => ({
                    id: tableDraft[rowIndex]?.cells?.[columnIndex]?.id,
                    text: input.value,
                })),
            };
        });
    };

    const renderTableGrid = () => {
        if (!tableGridBox) {
            return;
        }

        const width = Math.max(1, ...tableDraft.map((row) => row.cells.length));
        tableGridBox.innerHTML = '';

        tableDraft.forEach((row, rowIndex) => {
            const rowElement = document.createElement('div');
            rowElement.className = 'table-grid-row';
            const header = document.createElement('input');
            header.type = 'checkbox';
            header.checked = row.header === true;
            header.setAttribute('aria-label', `Fila ${rowIndex + 1}: cabecera`);
            rowElement.appendChild(header);

            for (let column = 0; column < width; column++) {
                if (!row.cells[column]) {
                    row.cells[column] = {text: ''};
                }

                const input = document.createElement('input');
                input.type = 'text';
                input.value = row.cells[column].text ?? '';
                input.maxLength = 2000;
                input.setAttribute('aria-label', `Fila ${rowIndex + 1}, columna ${column + 1}`);
                rowElement.appendChild(input);
            }

            tableGridBox.appendChild(rowElement);
        });
    };

    const openTablePanel = (target) => {
        const editor = focusedEditor();
        hidePanels();
        tableTarget = target;
        tableAnchor = target ? null : currentBlock(editor);
        const block = target ? parseBlock(target) : null;
        tableDraft = tableDraftFromBlock(block);

        if (tableCaptionInput) {
            tableCaptionInput.value = typeof block?.caption === 'string' ? block.caption : '';
        }

        renderTableGrid();
        tablePanel?.removeAttribute('hidden');
        tableCaptionInput?.focus();
    };

    document.getElementById('table-add-row')?.addEventListener('click', () => {
        captureTableGrid();
        const width = Math.max(1, ...tableDraft.map((row) => row.cells.length));
        tableDraft.push({header: false, cells: Array.from({length: width}, () => ({text: ''}))});
        renderTableGrid();
    });

    document.getElementById('table-add-column')?.addEventListener('click', () => {
        captureTableGrid();

        for (const row of tableDraft) {
            row.cells.push({text: ''});
        }

        renderTableGrid();
    });

    document.getElementById('table-remove-row')?.addEventListener('click', () => {
        captureTableGrid();

        if (tableDraft.length > 1) {
            tableDraft.pop();
        }

        renderTableGrid();
    });

    document.getElementById('table-remove-column')?.addEventListener('click', () => {
        captureTableGrid();
        const width = Math.max(1, ...tableDraft.map((row) => row.cells.length));

        if (width > 1) {
            for (const row of tableDraft) {
                row.cells.pop();
            }
        }

        renderTableGrid();
    });

    document.getElementById('table-apply')?.addEventListener('click', () => {
        captureTableGrid();
        const block = buildTableBlock({
            id: tableTarget ? parseBlock(tableTarget)?.id : undefined,
            caption: tableCaptionInput?.value ?? '',
            rows: tableDraft,
        });

        if (!block) {
            setStatus('Añade al menos una celda con texto a la tabla.', 'error');

            return;
        }

        placeAtom(focusedEditor(), block, tableTarget, tableAnchor);
        tableTarget = null;
        tableAnchor = null;
        hidePanels();
        setStatus('Tabla guardada en el borrador. Cambios sin guardar.', 'dirty');
    });

    document.getElementById('table-delete')?.addEventListener('click', () => {
        removeAtom(focusedEditor(), tableTarget, 'Tabla eliminada. Cambios sin guardar.');
        tableTarget = null;
        tableAnchor = null;
    });

    document.getElementById('table-cancel')?.addEventListener('click', () => {
        tableTarget = null;
        tableAnchor = null;
        hidePanels();
    });

    let imageTarget = null;
    let imageAnchor = null;
    let imageAssetsLoaded = false;

    const loadImageAssets = async () => {
        if (!imageAssetSelect || imageAssetsLoaded) {
            return;
        }

        try {
            const response = await fetch('/workspace/assets', {headers: {Accept: 'application/json'}});

            if (!response.ok) {
                throw new Error('assets');
            }

            const payload = await response.json();

            for (const asset of payload.assets ?? []) {
                if (asset.kind !== 'image') {
                    continue;
                }

                const option = document.createElement('option');
                option.value = String(asset.id);
                option.textContent = asset.original_name ?? `Imagen ${asset.id}`;
                imageAssetSelect.appendChild(option);
            }

            imageAssetsLoaded = true;
        } catch {
            if (imageStatus) {
                imageStatus.textContent = 'No se pudieron cargar las imágenes.';
            }
        }
    };

    const openImagePanel = (target) => {
        const editor = focusedEditor();
        hidePanels();
        imageTarget = target;
        imageAnchor = target ? null : currentBlock(editor);
        const block = target ? parseBlock(target) : null;
        const attrs = block?.attrs ?? {};

        if (imageAssetSelect) {
            imageAssetSelect.value = Number.isInteger(attrs.asset_id) ? String(attrs.asset_id) : '';
        }

        if (imageAltInput) {
            imageAltInput.value = typeof attrs.alt === 'string' ? attrs.alt : '';
        }

        if (imageCaptionInput) {
            imageCaptionInput.value = typeof attrs.caption === 'string' ? attrs.caption : '';
        }

        if (imageWidthInput) {
            imageWidthInput.value = Number.isInteger(attrs.width) ? attrs.width : '';
        }

        if (imageHeightInput) {
            imageHeightInput.value = Number.isInteger(attrs.height) ? attrs.height : '';
        }

        if (imageAlignInput) {
            imageAlignInput.value = ['left', 'center', 'right'].includes(attrs.alignment)
                ? attrs.alignment
                : 'center';
        }

        if (imageStatus) {
            imageStatus.textContent = '';
        }

        imagePanel?.removeAttribute('hidden');
        imageAltInput?.focus();
        loadImageAssets().then(() => {
            if (imageAssetSelect && Number.isInteger(attrs.asset_id)) {
                imageAssetSelect.value = String(attrs.asset_id);
            }
        });
    };

    imageFileInput?.addEventListener('change', async () => {
        const file = imageFileInput.files?.[0];

        if (!file || !imageAssetSelect) {
            return;
        }

        if (imageStatus) {
            imageStatus.textContent = 'Subiendo imagen…';
        }

        const form = new FormData();
        form.append('kind', 'image');
        form.append('file', file);

        if (novelId) {
            form.append('novel_id', novelId);
        }

        try {
            const response = await fetch('/workspace/assets', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: form,
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (imageStatus) {
                    imageStatus.textContent = payload.message ?? 'No se pudo subir la imagen.';
                }

                return;
            }

            const asset = payload.asset;
            const option = document.createElement('option');
            option.value = String(asset.id);
            option.textContent = asset.original_name ?? `Imagen ${asset.id}`;
            imageAssetSelect.appendChild(option);
            imageAssetSelect.value = String(asset.id);
            imageFileInput.value = '';
            imageAssetsLoaded = true;

            if (imageStatus) {
                imageStatus.textContent = 'Imagen subida y seleccionada.';
            }
        } catch {
            if (imageStatus) {
                imageStatus.textContent = 'Sin conexión. No se subió la imagen.';
            }
        }
    });

    document.getElementById('image-apply')?.addEventListener('click', () => {
        const assetId = Number(imageAssetSelect?.value ?? 0);
        const alt = (imageAltInput?.value ?? '').trim();
        const widthRaw = (imageWidthInput?.value ?? '').trim();
        const heightRaw = (imageHeightInput?.value ?? '').trim();
        const width = widthRaw === '' ? null : Math.round(Number(widthRaw));
        const height = heightRaw === '' ? null : Math.round(Number(heightRaw));

        if (!Number.isInteger(assetId) || assetId < 1) {
            setStatus('Selecciona o sube una imagen.', 'error');

            return;
        }

        if (alt === '') {
            setStatus('Añade una descripción alternativa a la imagen.', 'error');

            return;
        }

        if ((width !== null && (!Number.isFinite(width) || width < 1))
            || (height !== null && (!Number.isFinite(height) || height < 1))) {
            setStatus('El ancho y el alto deben ser números positivos.', 'error');

            return;
        }

        const block = buildImageBlock({
            id: imageTarget ? parseBlock(imageTarget)?.id : undefined,
            assetId,
            alt,
            caption: imageCaptionInput?.value ?? '',
            width,
            height,
            alignment: imageAlignInput?.value ?? 'center',
        });

        if (!block) {
            setStatus('No se pudo preparar la imagen.', 'error');

            return;
        }

        placeAtom(focusedEditor(), block, imageTarget, imageAnchor);
        imageTarget = null;
        imageAnchor = null;
        hidePanels();
        setStatus('Imagen guardada en el borrador. Cambios sin guardar.', 'dirty');
    });

    document.getElementById('image-delete')?.addEventListener('click', () => {
        removeAtom(focusedEditor(), imageTarget, 'Imagen eliminada. Cambios sin guardar.');
        imageTarget = null;
        imageAnchor = null;
    });

    document.getElementById('image-cancel')?.addEventListener('click', () => {
        imageTarget = null;
        imageAnchor = null;
        hidePanels();
    });

    let notesList = [];
    const notesMap = {};
    let noteDraftId = null;

    const currentSceneId = () => focusedEditor().closest('[data-scene-id]')?.dataset.sceneId ?? null;

    const noteLabel = (note) => `${note.kind === 'end' ? 'Nota final' : 'Nota'} ${note.number}`;

    const noteBlockElement = (block) => {
        const type = block.type;
        const tag = type === 'heading'
            ? `h${Math.min(6, Math.max(1, Number(block.level) || 2))}`
            : type === 'quote' ? 'blockquote' : 'p';
        const element = document.createElement(tag);
        element.className = 'editor-block';
        element.dataset.kind = 'text';
        element.dataset.blockId = typeof block.id === 'string' && block.id !== '' ? block.id : newBlockId();
        element.dataset.original = JSON.stringify(block);

        if (type === 'list_item') {
            element.dataset.listType = block.list_type === 'ordered' ? 'ordered' : 'bullet';
            element.dataset.depth = String(Math.max(0, Math.min(5, Number(block.depth) || 0)));
        }

        element.innerHTML = blockHtml(block, notesMap, stylesMap);

        return element;
    };

    const renderNoteBody = (content, fallbackText = '') => {
        if (!noteBody) {
            return;
        }

        noteBody.innerHTML = '';
        const blocks = Array.isArray(content?.blocks) ? content.blocks : [];

        for (const block of blocks) {
            if (block && typeof block === 'object') {
                noteBody.appendChild(noteBlockElement(block));
            }
        }

        if (!noteBody.querySelector('[data-kind="text"]')) {
            noteBody.appendChild(noteBlockElement({
                id: newBlockId(),
                type: 'paragraph',
                text: String(fallbackText ?? ''),
                marks: [],
            }));
        }
    };

    const populateNoteSelect = () => {
        if (!noteSelect) {
            return;
        }

        noteSelect.innerHTML = '';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = 'Nueva nota';
        noteSelect.appendChild(blank);

        for (const note of notesList) {
            const option = document.createElement('option');
            option.value = String(note.id);
            option.textContent = `${noteLabel(note)}: ${String(note.text ?? '').replace(/\s+/g, ' ').slice(0, 40)}`;
            noteSelect.appendChild(option);
        }

        noteSelect.value = noteDraftId ? String(noteDraftId) : '';
    };

    const loadNotes = async () => {
        if (!novelId) {
            return;
        }

        try {
            const response = await fetch(`/workspace/novels/${novelId}/footnotes`, {
                headers: {Accept: 'application/json'},
            });

            if (!response.ok) {
                throw new Error('notes');
            }

            const payload = await response.json();
            notesList = Array.isArray(payload.footnotes) ? payload.footnotes : [];

            for (const key of Object.keys(notesMap)) {
                delete notesMap[key];
            }

            for (const note of notesList) {
                notesMap[note.id] = note.number;
            }

            populateNoteSelect();
        } catch {
            if (noteStatus) {
                noteStatus.textContent = 'No se pudieron cargar las notas.';
            }
        }
    };

    const selectNote = (note) => {
        noteDraftId = note ? note.id : null;

        if (noteSelect) {
            noteSelect.value = note ? String(note.id) : '';
        }

        if (noteKind) {
            noteKind.value = note?.kind ?? 'foot';
            noteKind.disabled = Boolean(note);
        }

        renderNoteBody(note?.content, note?.text ?? '');

        if (noteStatus) {
            noteStatus.textContent = note ? `${noteLabel(note)} cargada.` : 'Nueva nota sin guardar.';
        }
    };

    const refreshOrphans = async () => {
        if (!noteOrphans) {
            return;
        }

        const sceneId = currentSceneId();

        if (!sceneId) {
            noteOrphans.textContent = '';

            return;
        }

        try {
            const response = await fetch(`/workspace/scenes/${sceneId}/notes/audit`, {
                headers: {Accept: 'application/json'},
            });

            if (!response.ok) {
                throw new Error('audit');
            }

            const payload = await response.json();
            const orphaned = Array.isArray(payload.orphaned) ? payload.orphaned : [];
            noteOrphans.textContent = orphaned.length
                ? `Sin referencia en esta escena: ${orphaned.map((note) => `${note.kind === 'end' ? 'Nota final' : 'Nota'} ${note.number}`).join(', ')}.`
                : 'Todas las notas de esta escena tienen referencia.';
        } catch {
            noteOrphans.textContent = '';
        }
    };

    const openNotePanel = () => {
        hidePanels();
        selectNote(null);

        if (noteSelect) {
            noteSelect.value = '';
        }

        if (noteStatus) {
            noteStatus.textContent = 'Creando una nota nueva.';
        }

        notePanel?.removeAttribute('hidden');
        loadNotes();
        refreshOrphans();
        noteBody?.focus();
    };

    noteSelect?.addEventListener('change', () => {
        const id = Number(noteSelect.value);
        const note = notesList.find((entry) => entry.id === id);
        selectNote(note ?? null);
    });

    noteBody?.addEventListener('paste', (event) => {
        if (!event.clipboardData) {
            return;
        }

        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain') ?? '');
    });

    document.getElementById('note-save')?.addEventListener('click', async () => {
        if (!noteBody || !notePanel) {
            return;
        }

        const content = serializeDocument(noteBody);
        const text = content.blocks.map((block) => block.text ?? '').join('\n').trim();

        if (text === '') {
            if (noteStatus) {
                noteStatus.textContent = 'Escribe el cuerpo de la nota.';
            }

            return;
        }

        const payload = noteDraftId
            ? {content}
            : {
                kind: noteKind?.value === 'end' ? 'end' : 'foot',
                content,
                ...(currentSceneId() ? {scene_id: Number(currentSceneId())} : {}),
            };
        const url = noteDraftId
            ? `/workspace/footnotes/${noteDraftId}`
            : `/workspace/novels/${novelId}/footnotes`;

        if (noteStatus) {
            noteStatus.textContent = 'Guardando nota…';
        }

        try {
            const response = await fetch(url, {
                method: noteDraftId ? 'PATCH' : 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify(payload),
            });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (noteStatus) {
                    noteStatus.textContent = body.message ?? 'No se pudo guardar la nota.';
                }

                return;
            }

            const note = body.footnote;
            noteDraftId = note.id;
            notesMap[note.id] = note.number;
            notesList = [...notesList.filter((entry) => entry.id !== note.id), note]
                .sort((a, b) => a.kind === b.kind ? a.number - b.number : (a.kind === 'foot' ? -1 : 1));
            populateNoteSelect();

            if (noteKind) {
                noteKind.value = note.kind;
                noteKind.disabled = true;
            }

            renderNoteBody(note.content, note.text);

            if (noteStatus) {
                noteStatus.textContent = `${noteLabel(note)} guardada.`;
            }

            refreshOrphans();
        } catch {
            if (noteStatus) {
                noteStatus.textContent = 'Sin conexión. No se guardó la nota.';
            }
        }
    });

    document.getElementById('note-reference')?.addEventListener('mousedown', keepSelection);
    document.getElementById('note-reference')?.addEventListener('click', () => {
        const editor = focusedEditor();
        saveSelection();
        restoreSelection();
        const target = selectedTextRange(editor);

        if (!noteDraftId) {
            if (noteStatus) {
                noteStatus.textContent = 'Guarda la nota antes de insertar la referencia.';
            }

            return;
        }

        if (!target) {
            if (noteStatus) {
                noteStatus.textContent = 'Selecciona el texto que ancla la nota en el editor.';
            }

            return;
        }

        rememberFormatState(editor, target.block);
        const data = serializeBlock(target.block);
        const marks = setNoteReference(
            Array.isArray(data.marks) ? data.marks : [],
            noteDraftId,
            target.start,
            target.end,
        );
        renderRunFormat(editor, target.block, {...data, marks});
        const note = notesList.find((entry) => entry.id === noteDraftId);

        if (noteStatus) {
            noteStatus.textContent = `Referencia a ${note ? noteLabel(note) : 'la nota'} insertada.`;
        }

        refreshOrphans();
    });

    document.getElementById('note-remove-reference')?.addEventListener('mousedown', keepSelection);
    document.getElementById('note-remove-reference')?.addEventListener('click', () => {
        const editor = focusedEditor();
        saveSelection();
        restoreSelection();
        const target = selectedTextRange(editor);

        if (!target) {
            if (noteStatus) {
                noteStatus.textContent = 'Selecciona el texto con la referencia que quieres quitar.';
            }

            return;
        }

        const data = serializeBlock(target.block);
        const marks = removeNoteReferences(data.marks, target.start, target.end);

        if (marks.length === (Array.isArray(data.marks) ? data.marks.length : 0)) {
            if (noteStatus) {
                noteStatus.textContent = 'No hay una referencia de nota en la selección.';
            }

            return;
        }

        rememberFormatState(editor, target.block);
        renderRunFormat(editor, target.block, {...data, marks});

        if (noteStatus) {
            noteStatus.textContent = 'Referencia de nota quitada.';
        }

        refreshOrphans();
    });

    document.getElementById('note-close')?.addEventListener('click', hidePanels);

    const typographySettings = () => ({
        dialogueDash: typographyOptions.dialogue?.checked ?? true,
        angularQuotes: typographyOptions.quotes?.checked ?? true,
        ellipsis: typographyOptions.ellipsis?.checked ?? true,
        nonBreakingSpace: typographyOptions.nbsp?.checked ?? true,
    });

    const openTypographyPanel = () => {
        hidePanels();
        typographyPanel?.removeAttribute('hidden');
    };

    document.getElementById('typography-apply')?.addEventListener('mousedown', keepSelection);
    document.getElementById('typography-apply')?.addEventListener('click', () => {
        const editor = focusedEditor();
        saveSelection();
        restoreSelection();
        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
            setStatus('Selecciona el texto al que quieres aplicar las ayudas tipográficas.', 'error');

            return;
        }

        const range = selection.getRangeAt(0);
        const block = blockForNode(editor, range.startContainer);

        if (!block || block !== blockForNode(editor, range.endContainer)) {
            setStatus('Selecciona texto dentro de un mismo párrafo.', 'error');

            return;
        }

        const entries = [];
        const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT);

        while (walker.nextNode()) {
            const node = walker.currentNode;

            if (!range.intersectsNode(node)) {
                continue;
            }

            const start = node === range.startContainer ? range.startOffset : 0;
            const end = node === range.endContainer ? range.endOffset : node.nodeValue.length;

            if (end <= start) {
                continue;
            }

            const value = node.nodeValue;
            entries.push({
                node,
                prefix: value.slice(0, start),
                value: value.slice(start, end),
                suffix: value.slice(end),
            });
        }

        const result = transformSegments(
            entries.map((entry) => entry.value),
            typographySettings(),
        );

        if (result.count === 0) {
            setStatus('No había signos tipográficos que sustituir en la selección.', 'error');

            return;
        }

        rememberFormatState(editor, block);

        entries.forEach((entry, index) => {
            entry.node.nodeValue = entry.prefix + result.segments[index] + entry.suffix;
        });

        editor.focus();
        placeCaretAtEnd(block);
        markDirty(editor);
        hidePanels();
        setStatus(`Sustituciones tipográficas aplicadas: ${result.count}. Cambios sin guardar.`, 'dirty');
    });

    document.getElementById('typography-cancel')?.addEventListener('click', hidePanels);

    let thesaurusPromise = null;
    let synonymTarget = null;

    const getThesaurus = () => {
        if (!thesaurusPromise) {
            thesaurusPromise = fetch(thesaurusUrl)
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('thesaurus');
                    }

                    return response.arrayBuffer();
                })
                .then((buffer) => createThesaurus(decodeLatin1(new Uint8Array(buffer))))
                .catch((error) => {
                    thesaurusPromise = null;

                    throw error;
                });
        }

        return thesaurusPromise;
    };

    const applySynonym = (replacement) => {
        if (!synonymTarget) {
            return;
        }

        const editor = focusedEditor();
        const block = [...editor.querySelectorAll('[data-block-id]')]
            .find((element) => element.dataset.blockId === synonymTarget.blockId);

        if (!block) {
            if (synonymsStatus) {
                synonymsStatus.textContent = 'El párrafo ya no está disponible. Vuelve a seleccionar la palabra.';
            }

            return;
        }

        const data = serializeBlock(block);
        const characters = [...data.text];

        if (characters.slice(synonymTarget.start, synonymTarget.end).join('') !== synonymTarget.word) {
            if (synonymsStatus) {
                synonymsStatus.textContent = 'La selección cambió. Vuelve a abrir Sinónimos.';
            }

            return;
        }

        rememberFormatState(editor, block);
        const replaced = replaceRange(data, synonymTarget.start, synonymTarget.end, replacement);

        if (!replaced) {
            if (synonymsStatus) {
                synonymsStatus.textContent = 'No se pudo sustituir la palabra.';
            }

            return;
        }

        renderRunFormat(editor, block, replaced);
        markDirty(editor);
        hidePanels();
        scheduleInlineSpelling(300);
        setStatus(`Sustituido «${synonymTarget.word}» por «${replacement}». Cambios sin guardar.`, 'dirty');
    };

    const renderSynonyms = (word, result) => {
        if (!synonymsGroups) {
            return;
        }

        synonymsGroups.innerHTML = '';
        const groups = Array.isArray(result?.groups) ? result.groups : [];
        let shown = 0;

        for (const group of groups) {
            const synonyms = (Array.isArray(group?.synonyms) ? group.synonyms : [])
                .filter((synonym) => synonym !== word);

            if (synonyms.length === 0) {
                continue;
            }

            const box = document.createElement('div');
            box.className = 'synonym-group';
            const label = document.createElement('p');
            label.className = 'synonym-meaning';
            label.textContent = group.meaning ?? 'Sentido sin etiqueta';
            box.appendChild(label);
            const list = document.createElement('div');
            list.className = 'synonym-list';

            for (const synonym of synonyms) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-secondary';
                button.textContent = synonym;
                button.addEventListener('click', () => applySynonym(synonym));
                list.appendChild(button);
                shown++;
            }

            box.appendChild(list);
            synonymsGroups.appendChild(box);
        }

        if (synonymsStatus) {
            synonymsStatus.textContent = shown === 0
                ? 'No hay sinónimos exactos para esta palabra.'
                : `${shown} sinónimos. Elige uno expresamente; nada se sustituye solo.`;
        }
    };

    const openSynonymsPanel = () => {
        hidePanels();
        restoreSelection();
        const editor = focusedEditor();
        const target = selectedTextRange(editor);
        synonymTarget = null;

        if (synonymsGroups) {
            synonymsGroups.innerHTML = '';
        }

        if (synonymsWord) {
            synonymsWord.textContent = 'Selecciona una palabra en el texto.';
        }

        if (!target) {
            if (synonymsStatus) {
                synonymsStatus.textContent = 'Selecciona una sola palabra para buscar sinónimos.';
            }

            synonymsPanel?.removeAttribute('hidden');

            return;
        }

        const block = target.block;
        const data = serializeBlock(block);
        const characters = [...data.text];
        let start = target.start;
        let end = target.end;

        while (start < end && /\s/.test(characters[start])) {
            start++;
        }

        while (end > start && /\s/.test(characters[end - 1])) {
            end--;
        }

        const word = characters.slice(start, end).join('');

        if (word === '' || /\s/.test(word)) {
            if (synonymsWord) {
                synonymsWord.textContent = 'La selección debe ser una sola palabra.';
            }

            if (synonymsStatus) {
                synonymsStatus.textContent = 'Selecciona una sola palabra para buscar sinónimos.';
            }

            synonymsPanel?.removeAttribute('hidden');

            return;
        }

        synonymTarget = {
            blockId: block.dataset.blockId,
            start,
            end,
            word,
        };

        if (synonymsWord) {
            synonymsWord.textContent = `«${word}»`;
        }

        if (synonymsStatus) {
            synonymsStatus.textContent = 'Buscando sinónimos locales…';
        }

        synonymsPanel?.removeAttribute('hidden');

        getThesaurus()
            .then((thesaurus) => renderSynonyms(word, thesaurus.lookup(word)))
            .catch(() => {
                if (synonymsStatus) {
                    synonymsStatus.textContent = 'No se pudo cargar el diccionario de sinónimos local.';
                }
            });
    };

    document.getElementById('synonyms-close')?.addEventListener('click', hidePanels);

    let spellingPromise = null;
    let spellingOccurrences = [];

    const getSpelling = () => {
        if (!spellingPromise) {
            spellingPromise = Promise.all([fetch(spellingAffUrl), fetch(spellingDicUrl)])
                .then(async (responses) => {
                    if (responses.some((response) => !response.ok)) {
                        throw new Error('spelling');
                    }

                    const [aff, dic] = await Promise.all(
                        responses.map((response) => response.arrayBuffer()),
                    );

                    const decoder = new TextDecoder('utf-8');

                    return createSpelling({
                        aff: decoder.decode(new Uint8Array(aff)),
                        dic: decoder.decode(new Uint8Array(dic)),
                    });
                })
                .catch((error) => {
                    spellingPromise = null;

                    throw error;
                });
        }

        return spellingPromise;
    };

    const rangeForOccurrence = (block, start, end) => {
        const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT);
        const entries = [];
        let offset = 0;

        while (walker.nextNode()) {
            const node = walker.currentNode;
            const length = [...node.nodeValue].length;

            if (length > 0) {
                entries.push({node, start: offset, end: offset + length});
                offset += length;
            }
        }

        const locate = (position, endBias) => {
            for (const entry of entries) {
                if (position < entry.end || (endBias && position === entry.end)) {
                    return {node: entry.node, offset: position - entry.start};
                }
            }

            return null;
        };

        const from = locate(start, false);
        const to = locate(end, true);

        if (!from || !to) {
            return null;
        }

        const range = document.createRange();
        range.setStart(from.node, from.offset);
        range.setEnd(to.node, to.offset);

        return range;
    };

    const highlightSpelling = () => {
        if (!window.CSS?.highlights || typeof window.Highlight !== 'function') {
            return;
        }

        const ranges = spellingOccurrences
            .map((occurrence) => {
                const block = [...occurrence.editor.querySelectorAll('[data-block-id]')]
                    .find((element) => element.dataset.blockId === occurrence.blockId);

                return block ? rangeForOccurrence(block, occurrence.start, occurrence.end) : null;
            })
            .filter(Boolean);

        if (ranges.length === 0) {
            window.CSS.highlights.delete('spelling-errors');

            return;
        }

        window.CSS.highlights.set('spelling-errors', new Highlight(...ranges));
    };

    const renderSpelling = () => {
        if (!spellingList) {
            return;
        }

        spellingList.innerHTML = '';

        if (spellingSummary) {
            spellingSummary.textContent = spellingOccurrences.length === 0
                ? 'No se detectaron errores ortográficos en esta escena.'
                : `${spellingOccurrences.length} ${spellingOccurrences.length === 1 ? 'posible error ortográfico' : 'posibles errores ortográficos'}. Elige una sugerencia o deja la palabra como está.`;
        }

        for (const occurrence of spellingOccurrences) {
            const item = document.createElement('div');
            item.className = 'spelling-item';
            const word = document.createElement('p');
            word.className = 'spelling-word';
            word.textContent = occurrence.word;
            item.appendChild(word);
            const actions = document.createElement('div');
            actions.className = 'synonym-list';
            const suggestions = Array.isArray(occurrence.suggestions) ? occurrence.suggestions : [];

            if (suggestions.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'muted';
                empty.textContent = 'Sin sugerencias';
                actions.appendChild(empty);
            }

            for (const suggestion of suggestions) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-secondary';
                button.textContent = suggestion;
                button.addEventListener('click', () => applySpelling(occurrence, suggestion));
                actions.appendChild(button);
            }

            item.appendChild(actions);
            spellingList.appendChild(item);
        }
    };

    const scanSpelling = async ({renderPanel = true} = {}) => {
        const editor = focusedEditor();

        if (renderPanel && spellingSummary) {
            spellingSummary.textContent = 'Revisando la escena…';
        }

        if (renderPanel && spellingList) {
            spellingList.innerHTML = '';
        }

        spellingOccurrences = [];
        window.CSS?.highlights?.delete('spelling-errors');

        try {
            const spelling = await getSpelling();

            for (const block of editor.querySelectorAll('[data-kind="text"]')) {
                if (spellingOccurrences.length >= 40) {
                    break;
                }

                const data = serializeBlock(block);
                const remaining = 40 - spellingOccurrences.length;

                for (const occurrence of findMisspellings(data.text, spelling, remaining)) {
                    spellingOccurrences.push({
                        ...occurrence,
                        editor,
                        blockId: block.dataset.blockId,
                        blockText: data.text,
                    });
                }
            }
        } catch {
            if (renderPanel && spellingSummary) {
                spellingSummary.textContent = 'No se pudo cargar el diccionario ortográfico local.';
            }

            return;
        }

        if (renderPanel) {
            renderSpelling();
        }

        highlightSpelling();
    };

    let spellingTimer = null;

    const scheduleInlineSpelling = (delay = 700) => {
        clearTimeout(spellingTimer);
        spellingTimer = setTimeout(() => {
            const editor = focusedEditor();

            if (!editor) {
                return;
            }

            scanSpelling({renderPanel: Boolean(spellingPanel && !spellingPanel.hidden)});
        }, delay);
    };

    function closeSpellingPopover() {
        spellingPopover?.setAttribute('hidden', '');
    }

    function closeSynonymPopover() {
        synonymPopover?.setAttribute('hidden', '');
    }

    function positionPopover(popover, event) {
        if (!popover) {
            return;
        }

        const rect = popover.getBoundingClientRect();
        const left = Math.min(Math.max(8, event.clientX), window.innerWidth - rect.width - 8);
        const top = Math.min(Math.max(8, event.clientY + 12), window.innerHeight - rect.height - 8);
        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
    }

    const codepointOffsetInBlock = (block, targetNode, targetOffset) => {
        const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT);
        let offset = 0;

        while (walker.nextNode()) {
            const node = walker.currentNode;

            if (node === targetNode) {
                return offset + [...node.nodeValue.slice(0, targetOffset)].length;
            }

            offset += [...node.nodeValue].length;
        }

        return null;
    };

    function openSpellingPopover(event) {
        closeSynonymPopover();
        const caret = document.caretRangeFromPoint?.(event.clientX, event.clientY);

        if (!caret || caret.startContainer.nodeType !== Node.TEXT_NODE) {
            closeSpellingPopover();

            return;
        }

        const block = caret.startContainer.parentElement?.closest('[data-block-id]');
        const editor = block?.closest('.scene-editor');

        if (!block || !editor || !editors.includes(editor)) {
            closeSpellingPopover();

            return;
        }

        const offset = codepointOffsetInBlock(block, caret.startContainer, caret.startOffset);
        const occurrence = offset === null ? null : occurrenceAt(spellingOccurrences, block.dataset.blockId, offset);

        if (!occurrence) {
            closeSpellingPopover();

            return;
        }

        if (spellingPopoverWord) {
            spellingPopoverWord.textContent = occurrence.word;
        }

        if (spellingPopoverActions) {
            spellingPopoverActions.innerHTML = '';
            const suggestions = Array.isArray(occurrence.suggestions) ? occurrence.suggestions : [];

            if (suggestions.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'muted';
                empty.textContent = 'Sin sugerencias';
                spellingPopoverActions.appendChild(empty);
            }

            for (const suggestion of suggestions) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-secondary';
                button.textContent = suggestion;
                button.addEventListener('click', () => {
                    applySpelling(occurrence, suggestion);
                    closeSpellingPopover();
                });
                spellingPopoverActions.appendChild(button);
            }
        }

        spellingPopover?.removeAttribute('hidden');
        positionPopover(spellingPopover, event);
    }

    function renderSynonymPopover(word, result) {
        if (!synonymPopoverGroups) {
            return;
        }

        synonymPopoverGroups.innerHTML = '';
        const groups = Array.isArray(result?.groups) ? result.groups : [];
        let shown = 0;

        for (const group of groups) {
            const synonyms = (Array.isArray(group?.synonyms) ? group.synonyms : [])
                .filter((synonym) => synonym !== word);

            if (synonyms.length === 0) {
                continue;
            }

            const box = document.createElement('div');
            box.className = 'synonym-group';
            const label = document.createElement('p');
            label.className = 'synonym-meaning';
            label.textContent = group.meaning ?? 'Sentido sin etiqueta';
            box.appendChild(label);
            const list = document.createElement('div');
            list.className = 'synonym-list';

            for (const synonym of synonyms) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button button-secondary';
                button.textContent = synonym;
                button.addEventListener('click', () => {
                    applySynonym(synonym);
                    closeSynonymPopover();
                });
                list.appendChild(button);
                shown++;
            }

            box.appendChild(list);
            synonymPopoverGroups.appendChild(box);
        }

        if (synonymPopoverStatus) {
            synonymPopoverStatus.textContent = shown === 0
                ? 'No hay sinónimos exactos para esta palabra.'
                : `${shown} sinónimos. Elige uno expresamente; nada se sustituye solo.`;
        }
    }

    function openSynonymPopover(event) {
        event.preventDefault();
        closeSpellingPopover();
        const caret = document.caretRangeFromPoint?.(event.clientX, event.clientY);

        if (!caret || caret.startContainer.nodeType !== Node.TEXT_NODE) {
            closeSynonymPopover();

            return;
        }

        const block = caret.startContainer.parentElement?.closest('[data-block-id]');
        const editor = block?.closest('.scene-editor');

        if (!block || !editor || !editors.includes(editor)) {
            closeSynonymPopover();

            return;
        }

        const offset = codepointOffsetInBlock(block, caret.startContainer, caret.startOffset);
        const data = serializeBlock(block);
        const token = offset === null ? null : wordAt(data.text, offset);

        if (!token) {
            closeSynonymPopover();

            return;
        }

        synonymTarget = {
            blockId: block.dataset.blockId,
            start: token.start,
            end: token.end,
            word: token.word,
        };

        if (synonymPopoverWord) {
            synonymPopoverWord.textContent = `«${token.word}»`;
        }

        if (synonymPopoverStatus) {
            synonymPopoverStatus.textContent = 'Buscando sinónimos locales…';
        }

        if (synonymPopoverGroups) {
            synonymPopoverGroups.innerHTML = '';
        }

        synonymPopover?.removeAttribute('hidden');
        positionPopover(synonymPopover, event);

        getThesaurus()
            .then((thesaurus) => renderSynonymPopover(token.word, thesaurus.lookup(token.word)))
            .catch(() => {
                if (synonymPopoverStatus) {
                    synonymPopoverStatus.textContent = 'No se pudo cargar el diccionario de sinónimos local.';
                }
            });
    }

    const applySpelling = (occurrence, suggestion) => {
        const editor = occurrence.editor;
        const block = [...editor.querySelectorAll('[data-block-id]')]
            .find((element) => element.dataset.blockId === occurrence.blockId);

        if (!block) {
            if (spellingSummary) {
                spellingSummary.textContent = 'El párrafo ya no está disponible. Vuelve a revisar.';
            }

            return;
        }

        const data = serializeBlock(block);

        if (data.text !== occurrence.blockText) {
            if (spellingSummary) {
                spellingSummary.textContent = 'El texto cambió desde la revisión. Actualizando…';
            }

            scanSpelling();

            return;
        }

        rememberFormatState(editor, block);
        const replaced = replaceRange(data, occurrence.start, occurrence.end, suggestion);

        if (!replaced) {
            if (spellingSummary) {
                spellingSummary.textContent = 'No se pudo sustituir la palabra.';
            }

            return;
        }

        renderRunFormat(editor, block, replaced);
        markDirty(editor);
        setStatus(`Corregido «${occurrence.word}» por «${suggestion}». Cambios sin guardar.`, 'dirty');
        scanSpelling();
    };

    const openSpellingPanel = () => {
        hidePanels();
        spellingPanel?.removeAttribute('hidden');
        scanSpelling();
    };

    document.getElementById('spelling-close')?.addEventListener('click', hidePanels);
    document.getElementById('spelling-popover-close')?.addEventListener('click', closeSpellingPopover);
    document.getElementById('synonym-popover-close')?.addEventListener('click', closeSynonymPopover);

    document.addEventListener('pointerdown', (event) => {
        if (spellingPopover && !spellingPopover.hidden && !spellingPopover.contains(event.target)) {
            closeSpellingPopover();
        }

        if (synonymPopover && !synonymPopover.hidden && !synonymPopover.contains(event.target)) {
            closeSynonymPopover();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSpellingPopover();
            closeSynonymPopover();
        }
    });

    scheduleInlineSpelling(1200);

    const paragraphInputs = {
        fontFamily: document.getElementById('format-font'),
        fontSize: document.getElementById('format-size'),
        align: document.getElementById('format-align'),
        indentFirst: document.getElementById('format-indent-first'),
        indentLeft: document.getElementById('format-indent-left'),
        lineHeight: document.getElementById('format-line-height'),
        spacingBefore: document.getElementById('format-spacing-before'),
        spacingAfter: document.getElementById('format-spacing-after'),
    };
    const paragraphLimits = {
        fontSize: [8, 72],
        indentFirst: [-6, 6],
        indentLeft: [0, 6],
        lineHeight: [100, 300],
        spacingBefore: [0, 72],
        spacingAfter: [0, 72],
    };
    const fontFamilyCss = (value) => {
        if (value === 'sans') {
            return 'var(--font-sans)';
        }

        if (/^font:\d+$/.test(value)) {
            return `'AppFont${value.slice(5)}', serif`;
        }

        return "'Source Serif 4', Georgia, serif";
    };
    const formatStyles = {
        align: (value) => ['textAlign', value],
        fontFamily: (value) => ['fontFamily', fontFamilyCss(value)],
        fontSize: (value) => ['fontSize', `${value}pt`],
        indentFirst: (value) => ['textIndent', `${value}em`],
        indentLeft: (value) => ['marginLeft', `${value}em`],
        lineHeight: (value) => ['lineHeight', `${value}%`],
        spacingBefore: (value) => ['marginTop', `${value}pt`],
        spacingAfter: (value) => ['marginBottom', `${value}pt`],
    };
    let paragraphTarget = null;

    const setFormatField = (block, field, raw) => {
        const [property] = formatStyles[field](0);
        let value = String(raw ?? '').trim();

        if (value === '') {
            delete block.dataset[field];
            block.style[property] = '';

            return;
        }

        const limits = paragraphLimits[field];

        if (limits) {
            const number = Math.round(Number(value));
            const safe = Number.isFinite(number) ? number : limits[0];
            value = String(Math.min(limits[1], Math.max(limits[0], safe)));
        }

        block.dataset[field] = value;
        const [styleProperty, css] = formatStyles[field](value);
        block.style[styleProperty] = css;
    };

    const openParagraphPanel = () => {
        paragraphTarget = currentBlock(focusedEditor());

        if (!paragraphTarget) {
            setStatus('Coloca el cursor en un párrafo para aplicar formato.', 'error');

            return;
        }

        for (const [field, input] of Object.entries(paragraphInputs)) {
            input.value = paragraphTarget.dataset[field] ?? '';
        }

        if (runFamily) {
            runFamily.value = '';
        }

        if (runSize) {
            runSize.value = '';
        }

        paragraphPanel?.removeAttribute('hidden');
        paragraphInputs.fontFamily?.focus();
    };

    document.getElementById('paragraph-apply')?.addEventListener('click', () => {
        if (!paragraphTarget) {
            return;
        }

        const editor = focusedEditor();
        rememberFormatState(editor, paragraphTarget);

        for (const [field, input] of Object.entries(paragraphInputs)) {
            setFormatField(paragraphTarget, field, input.value);
        }

        hidePanels();
        markDirty(editor);
    });

    document.getElementById('paragraph-clear')?.addEventListener('click', () => {
        const editor = focusedEditor();

        if (paragraphTarget) {
            rememberFormatState(editor, paragraphTarget);

            for (const field of Object.keys(paragraphInputs)) {
                setFormatField(paragraphTarget, field, '');
            }
        }

        restoreSelection();
        suppressHistoryReset = true;

        try {
            document.execCommand('removeFormat', false, null);
            document.execCommand('unlink', false, null);
        } finally {
            suppressHistoryReset = false;
        }

        hidePanels();
        markDirty(editor);
    });

    const runFamily = document.getElementById('format-run-family');
    const runSize = document.getElementById('format-run-size');

    const selectedTextRange = (editor) => {
        const selection = window.getSelection();

        if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
            return null;
        }

        const range = selection.getRangeAt(0);
        const block = blockForNode(editor, range.startContainer);

        if (!block || block !== blockForNode(editor, range.endContainer)) {
            return null;
        }

        try {
            const measured = document.createRange();
            measured.selectNodeContents(block);
            measured.setEnd(range.startContainer, range.startOffset);
            const start = [...measured.toString()].length;
            measured.setEnd(range.endContainer, range.endOffset);
            const end = [...measured.toString()].length;

            return end > start ? {block, start, end} : null;
        } catch {
            return null;
        }
    };

    const renderRunFormat = (editor, block, data) => {
        block.innerHTML = blockHtml(data, notesMap, stylesMap);
        block.dataset.original = JSON.stringify(data);
        editor.focus();
        placeCaretAtEnd(block);
        markDirty(editor);
    };

    document.getElementById('run-apply')?.addEventListener('mousedown', keepSelection);
    document.getElementById('run-apply')?.addEventListener('click', () => {
        const editor = focusedEditor();
        restoreSelection();
        const target = selectedTextRange(editor);
        const family = runFamily?.value ?? '';
        const rawSize = (runSize?.value ?? '').trim();
        const size = rawSize === '' ? null : Math.round(Number(rawSize));

        if (!target) {
            setStatus('Selecciona texto dentro de un mismo párrafo.', 'error');

            return;
        }

        if (family === '' && size === null) {
            setStatus('Elige una fuente o un tamaño para el texto seleccionado.', 'error');

            return;
        }

        if (size !== null && (!Number.isFinite(size) || size < 8 || size > 72)) {
            setStatus('El tamaño del texto debe estar entre 8 y 72 pt.', 'error');

            return;
        }

        rememberFormatState(editor, target.block);
        const data = serializeBlock(target.block);
        let marks = Array.isArray(data.marks) ? data.marks : [];

        if (family !== '') {
            marks = setRunMark(marks, 'font_family', target.start, target.end, {family});
        }

        if (size !== null) {
            marks = setRunMark(marks, 'font_size', target.start, target.end, {size});
        }

        renderRunFormat(editor, target.block, {...data, marks});
        hidePanels();
        setStatus('Formato del texto aplicado. Cambios sin guardar.', 'dirty');
    });

    document.getElementById('run-remove')?.addEventListener('mousedown', keepSelection);
    document.getElementById('run-remove')?.addEventListener('click', () => {
        const editor = focusedEditor();
        restoreSelection();
        const target = selectedTextRange(editor);

        if (!target) {
            setStatus('Selecciona texto dentro de un mismo párrafo.', 'error');

            return;
        }

        rememberFormatState(editor, target.block);
        const data = serializeBlock(target.block);
        const marks = removeRunMarks(
            data.marks,
            ['font_family', 'font_size'],
            target.start,
            target.end,
        );

        renderRunFormat(editor, target.block, {...data, marks});
        hidePanels();
        setStatus('Formato del texto quitado. Cambios sin guardar.', 'dirty');
    });

    document.getElementById('character-apply')?.addEventListener('mousedown', keepSelection);
    document.getElementById('character-apply')?.addEventListener('click', () => {
        const editor = focusedEditor();
        restoreSelection();
        const target = selectedTextRange(editor);
        const styleId = characterStyleSelect?.value ?? '';

        if (!target) {
            setStatus('Selecciona texto dentro de un mismo párrafo.', 'error');

            return;
        }

        if (styleId === '') {
            setStatus('Elige un estilo de carácter.', 'error');

            return;
        }

        rememberFormatState(editor, target.block);
        const data = serializeBlock(target.block);
        const marks = setRunMark(
            data.marks,
            'character_style',
            target.start,
            target.end,
            {id: styleId},
        );
        const style = characterStyles.find((entry) => String(entry.id) === styleId);

        renderRunFormat(editor, target.block, {...data, marks});
        hidePanels();
        setStatus(`Estilo ${style ? `«${style.name}» ` : ''}aplicado al texto. Cambios sin guardar.`, 'dirty');
    });

    document.getElementById('character-remove')?.addEventListener('mousedown', keepSelection);
    document.getElementById('character-remove')?.addEventListener('click', () => {
        const editor = focusedEditor();
        restoreSelection();
        const target = selectedTextRange(editor);

        if (!target) {
            setStatus('Selecciona texto dentro de un mismo párrafo.', 'error');

            return;
        }

        const data = serializeBlock(target.block);
        const marks = removeRunMarks(data.marks, ['character_style'], target.start, target.end);

        if (marks.length === (Array.isArray(data.marks) ? data.marks.length : 0)) {
            setStatus('No hay un estilo de carácter en la selección.', 'error');

            return;
        }

        rememberFormatState(editor, target.block);
        renderRunFormat(editor, target.block, {...data, marks});
        hidePanels();
        setStatus('Estilo de carácter quitado. Cambios sin guardar.', 'dirty');
    });

    document.getElementById('paragraph-cancel')?.addEventListener('click', hidePanels);

    for (const panel of [linkPanel, bookmarkPanel, paragraphPanel, pastePanel, tablePanel, imagePanel, notePanel, typographyPanel, synonymsPanel, spellingPanel]) {
        panel?.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                pendingPaste = null;
                hidePanels();
            }
        });
    }

    const saveAll = async () => {
        if (dirty.size === 0) {
            setStatus('Todo guardado', 'saved');

            return;
        }

        for (const editor of [...dirty]) {
            const scene = editor.closest('[data-scene-id]');

            if (!scene) {
                clearDirty(editor);

                continue;
            }

            setStatus('Guardando en el servidor…', 'saving');

            try {
                if (editor.dataset.requiresLease === 'true') {
                    const chapterId = scene.closest('[data-chapter-id]')?.dataset.chapterId;
                    const lease = await fetch(`/workspace/collaboration/leases/chapter/${chapterId}`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': token,
                        },
                    });

                    if (lease.status === 409) {
                        setStatus('Capítulo reservado por otro editor. Tu copia local se conserva.', 'conflict');

                        continue;
                    }

                    if (!lease.ok) {
                        setStatus('No se pudo reservar el capítulo. Tu copia local sigue aquí.', 'error');

                        continue;
                    }
                }

                const response = await fetch(`/workspace/scenes/${scene.dataset.sceneId}/content`, {
                    method: 'PUT',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    body: JSON.stringify({
                        base_revision: Number(scene.dataset.revision),
                        content: serializeDocument(editor),
                    }),
                });

                if (response.status === 409) {
                    setStatus('Conflicto: revisar copias. Hay una versión más reciente; tu copia local se conserva.', 'conflict');

                    continue;
                }

                if (!response.ok) {
                    setStatus('No se pudo guardar. Tu copia local sigue en esta página.', 'error');

                    continue;
                }

                const payload = await response.json();
                scene.dataset.revision = payload.scene.revision;
                clearDirty(editor);
            } catch {
                setStatus('Sin conexión. Tu copia local sigue en esta página.', 'error');
            }
        }
    };

    saveButton?.addEventListener('click', saveAll);

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            saveAll();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!(event.metaKey || event.ctrlKey)
            || event.target.closest?.('input, textarea, select')) {
            return;
        }

        const key = event.key.toLowerCase();

        if (key !== 'z' && key !== 'y') {
            return;
        }

        const editor = focusedEditor();
        const redo = key === 'y' || event.shiftKey;

        if (lastFormatEditor === editor && stepFormat(editor, redo ? 'redo' : 'undo')) {
            event.preventDefault();
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirty.size === 0) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });
}

function initFocusMode() {
    const toggle = document.getElementById('focus-toggle');

    if (!toggle) {
        return;
    }

    const setMode = (on) => {
        document.body.classList.toggle('focus-mode', on);
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.textContent = on ? 'Salir de Solo texto' : 'Solo texto';

        if (on) {
            document.dispatchEvent(new Event('editor:close-panels'));
            const editor = document.activeElement?.closest?.('.scene-editor')
                ?? document.querySelector('.scene-editor');
            editor?.focus();
        } else {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => {
        setMode(!document.body.classList.contains('focus-mode'));
    });

    document.addEventListener('keydown', (event) => {
        const key = event.key.toLowerCase();

        if ((event.metaKey || event.ctrlKey) && event.shiftKey && key === 'f') {
            event.preventDefault();
            setMode(!document.body.classList.contains('focus-mode'));

            return;
        }

        if (event.key === 'Escape' && document.body.classList.contains('focus-mode')) {
            event.preventDefault();
            setMode(false);
        }
    });
}

function initEditorPanels() {
    const toggles = [...document.querySelectorAll('[data-panel-toggle]')];
    const panels = new Map();

    for (const toggle of toggles) {
        const panel = document.getElementById(toggle.dataset.panelToggle);

        if (panel) {
            panels.set(toggle, panel);
        }
    }

    if (panels.size === 0) {
        return;
    }

    const isContextNarrow = () => window.matchMedia('(max-width: 1199px)').matches;
    const isNarrow = () => window.matchMedia('(max-width: 900px)').matches;
    const hiddenByBreakpoint = (panel) => panel.id === 'editor-context' ? isContextNarrow() : isNarrow();
    const setInert = (panel, inert) => {
        if (inert) {
            panel.setAttribute('inert', '');
            panel.setAttribute('aria-hidden', 'true');
        } else {
            panel.removeAttribute('inert');
            panel.removeAttribute('aria-hidden');
        }
    };
    const sync = () => {
        for (const [toggle, panel] of panels) {
            if (!hiddenByBreakpoint(panel)) {
                panel.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                setInert(panel, false);
            } else if (!panel.classList.contains('is-open')) {
                setInert(panel, true);
            }
        }
    };
    const close = (returnFocus = false) => {
        for (const [toggle, panel] of panels) {
            if (!panel.classList.contains('is-open')) {
                continue;
            }

            panel.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');

            if (hiddenByBreakpoint(panel)) {
                setInert(panel, true);
            }

            if (returnFocus) {
                toggle.focus();
            }
        }
    };

    for (const toggle of toggles) {
        toggle.addEventListener('click', () => {
            const panel = panels.get(toggle);

            if (!panel) {
                return;
            }

            const wasOpen = panel.classList.contains('is-open');
            close();

            if (wasOpen) {
                return;
            }

            panel.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            setInert(panel, false);
            panel.querySelector('a, button, input, select, textarea, [tabindex]')?.focus();
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close(true);
        }
    });
    document.addEventListener('editor:close-panels', () => close(true));
    window.addEventListener('resize', sync);
    sync();
}

function initCorrectionAcceptance() {
    const forms = [...document.querySelectorAll('form[data-accept-correction]')];

    for (const form of forms) {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const chapterId = form.dataset.chapterId;
            const error = form.querySelector('[data-correction-error]');
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

            if (error) {
                error.textContent = '';
            }

            if (!chapterId) {
                if (error) {
                    error.textContent = 'La propuesta no tiene un capítulo reservable.';
                }

                return;
            }

            try {
                const response = await fetch(`/workspace/collaboration/leases/chapter/${chapterId}`, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                });

                if (!response.ok) {
                    if (error) {
                        error.textContent = response.status === 409
                            ? 'El capítulo está reservado por otro editor.'
                            : 'No se pudo reservar el capítulo.';
                    }

                    return;
                }

                form.submit();
            } catch {
                if (error) {
                    error.textContent = 'Sin conexión. Inténtalo de nuevo.';
                }
            }
        });
    }
}

function initBookExport() {
    const root = document.querySelector('[data-book-export]');

    if (!root) {
        return;
    }

    const form = document.getElementById('book-export-form');
    const button = document.getElementById('book-export-prepare');
    const status = document.getElementById('book-export-status');
    const linksBox = document.getElementById('book-export-links');
    const list = document.getElementById('book-export-list');
    const endpoint = root.dataset.endpoint;
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    button?.addEventListener('click', async () => {
        const formats = [...form.querySelectorAll('input[name="formats"]:checked')].map((input) => input.value);

        if (formats.length === 0) {
            status.textContent = 'Selecciona al menos un formato.';

            return;
        }

        const profile = form.querySelector('#book-profile')?.value;
        status.textContent = 'Preparando…';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    formats,
                    ...(profile ? {profile_id: Number(profile)} : {}),
                }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                status.textContent = payload.message ?? 'No se pudo preparar la exportación.';

                return;
            }

            const base = `/workspace/export-manifests/${payload.manifest.id}`;
            list.innerHTML = '';

            for (const format of formats) {
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = format === 'pdf' ? `${base}/pdf` : `${base}/${format}`;
                link.textContent = format === 'pdf' ? 'Abrir PDF' : `Descargar ${format.toUpperCase()}`;

                if (format === 'pdf') {
                    link.target = '_blank';
                    link.rel = 'noopener';
                }

                item.appendChild(link);
                list.appendChild(item);
            }

            linksBox.hidden = false;
            status.textContent = 'Exportación preparada.';
        } catch {
            status.textContent = 'Sin conexión. Inténtalo de nuevo.';
        }
    });
}

function initWorkspaceNav() {
    const nav = document.getElementById('workspace-nav');
    const toggle = document.querySelector('[data-workspace-nav-toggle]');
    const backdrop = document.querySelector('[data-workspace-nav-backdrop]');
    const close = document.querySelector('[data-workspace-nav-close]');
    const stage = document.querySelector('.workspace-stage');

    if (!nav || !toggle) {
        return;
    }

    const media = window.matchMedia('(max-width: 899px)');
    let returnFocus = null;

    const focusable = () => [...nav.querySelectorAll('a, button, [tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.hasAttribute('disabled') && element.offsetParent !== null);

    const onKeydown = (event) => {
        if (!nav.classList.contains('is-open')) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            setOpen(false);
            toggle.focus();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const items = focusable();

        if (items.length === 0) {
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const setOpen = (open, restoreFocus = true) => {
        nav.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (media.matches) {
            nav.toggleAttribute('inert', !open);
            stage?.toggleAttribute('inert', open);
            document.body.style.overflow = open ? 'hidden' : '';
        }

        if (backdrop) {
            backdrop.hidden = !open;
        }

        if (open) {
            returnFocus = document.activeElement;
            document.addEventListener('keydown', onKeydown);
            focusable()[0]?.focus();
        } else if (restoreFocus && returnFocus instanceof HTMLElement) {
            returnFocus.focus();
            returnFocus = null;
            document.removeEventListener('keydown', onKeydown);
        } else {
            document.removeEventListener('keydown', onKeydown);
        }
    };

    const sync = () => {
        if (media.matches) {
            nav.toggleAttribute('inert', !nav.classList.contains('is-open'));
            stage?.toggleAttribute('inert', nav.classList.contains('is-open'));
            document.body.style.overflow = nav.classList.contains('is-open') ? 'hidden' : '';
        } else {
            nav.removeAttribute('inert');
            nav.classList.remove('is-open');
            stage?.removeAttribute('inert');
            document.body.style.overflow = '';
            document.removeEventListener('keydown', onKeydown);

            if (backdrop) {
                backdrop.hidden = true;
            }
        }
    };

    toggle.addEventListener('click', () => setOpen(!nav.classList.contains('is-open')));
    close?.addEventListener('click', () => setOpen(false));
    backdrop?.addEventListener('click', () => setOpen(false));
    nav.addEventListener('click', (event) => {
        const link = event.target.closest('a');

        if (!link || !nav.classList.contains('is-open')) {
            return;
        }

        const target = new URL(link.href, location.href);

        if (target.pathname === location.pathname) {
            setOpen(false, false);
        }
    });

    media.addEventListener('change', sync);
    window.addEventListener('resize', sync);
    sync();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEditorPage);
    document.addEventListener('DOMContentLoaded', initFocusMode);
    document.addEventListener('DOMContentLoaded', initEditorPanels);
    document.addEventListener('DOMContentLoaded', initCorrectionAcceptance);
    document.addEventListener('DOMContentLoaded', initBookExport);
    document.addEventListener('DOMContentLoaded', initWorkspaceNav);
    document.addEventListener('DOMContentLoaded', initDialogs);
} else {
    initEditorPage();
    initFocusMode();
    initEditorPanels();
    initCorrectionAcceptance();
    initBookExport();
    initWorkspaceNav();
    initDialogs();
}
