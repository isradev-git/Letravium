export function styleCss(definition = {}) {
    const css = [];

    if (definition.bold === true) {
        css.push('font-weight: 700');
    }

    if (definition.italic === true) {
        css.push('font-style: italic');
    }

    const decorations = [];

    if (definition.underline === true) {
        decorations.push('underline');
    }

    if (definition.strike === true) {
        decorations.push('line-through');
    }

    if (decorations.length > 0) {
        css.push(`text-decoration: ${decorations.join(' ')}`);
    }

    if (definition.small_caps === true) {
        css.push('font-variant: small-caps');
    }

    if (Number.isInteger(definition.size) && definition.size >= 8 && definition.size <= 72) {
        css.push(`font-size: ${definition.size}pt`);
    }

    if (typeof definition.color === 'string' && /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(definition.color)) {
        css.push(`color: ${definition.color}`);
    }

    if (Number.isInteger(definition.font_id) && definition.font_id > 0) {
        css.push(`font-family: 'AppFont${definition.font_id}', serif`);
    } else if (definition.font_family === 'serif') {
        css.push("font-family: 'Source Serif 4', Georgia, serif");
    } else if (definition.font_family === 'sans') {
        css.push('font-family: var(--font-sans)');
    } else if (typeof definition.font_family === 'string' && /^font:\d+$/.test(definition.font_family)) {
        css.push(`font-family: 'AppFont${definition.font_family.slice(5)}', serif`);
    }

    return css.join('; ');
}
