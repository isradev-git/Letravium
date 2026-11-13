const overlapping = (mark, start, end) => mark.start < end && mark.end > start;

export function removeNoteReferences(marks, start, end) {
    return (Array.isArray(marks) ? marks : []).filter(
        (mark) => !(mark?.type === 'footnote_reference' && overlapping(mark, start, end)),
    );
}

export function setNoteReference(marks, noteId, start, end) {
    return [
        ...removeNoteReferences(marks, start, end),
        {type: 'footnote_reference', start, end, attrs: {note_id: noteId}},
    ];
}
