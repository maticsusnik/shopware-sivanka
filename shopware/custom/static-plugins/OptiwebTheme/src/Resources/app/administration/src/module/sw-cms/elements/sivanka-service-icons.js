/**
 * Icon keys for the Sivanka service (storitve) CMS elements.
 *
 * Keep this list in sync with the Twig partial that renders the glyphs:
 * `src/Resources/views/storefront/utilities/sivanka-icon.html.twig`.
 *
 * The labels are snippets, so build the select options inside a component:
 *     computed: { iconOptions() { return sivankaIconOptions(this.$tc); } }
 */
export const SIVANKA_SERVICE_ICON_KEYS = [
    'ruler',
    'calculator',
    'fabric',
    'home',
    'shirt',
    'scissors',
    'needle',
    'bag',
    'zipper',
    'button',
    'wrench',
    'clock',
    'arrow',
];

export function sivankaIconSnippet(key) {
    return `sw-cms.elements.sivankaIcons.${key}`;
}

/**
 * @param {(key: string) => string} translate the component's `$tc`
 */
export function sivankaIconOptions(translate) {
    return SIVANKA_SERVICE_ICON_KEYS.map((key) => ({
        value: key,
        label: translate(sivankaIconSnippet(key)),
    }));
}

export default SIVANKA_SERVICE_ICON_KEYS;
