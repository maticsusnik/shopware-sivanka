/**
 * Shape of the link value produced by the `ow-url` component.
 *
 * Kept in one place because the storefront side (`OptiwebTheme\Core\Content\Cms\LinkResolver`)
 * reads exactly these keys, and because several elements need to upgrade values that were
 * saved before they used `ow-url` — those hold a plain URL string instead of an object.
 */
export function emptyLink() {
    return {
        type: 'external',
        link: null,
        entity: null,
        entityId: null,
        text: '',
    };
}

/**
 * Accepts whatever is currently stored in a config key and returns a well-formed link
 * object. A legacy plain string is carried over as the external URL so no configured
 * link is lost when an element switches to `ow-url`.
 */
export function toLink(value) {
    if (typeof value === 'string') {
        return { ...emptyLink(), link: value || null };
    }

    if (!value || typeof value !== 'object') {
        return emptyLink();
    }

    return { ...emptyLink(), ...value };
}
