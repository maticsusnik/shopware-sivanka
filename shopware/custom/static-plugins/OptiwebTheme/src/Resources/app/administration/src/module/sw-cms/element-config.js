/**
 * Fills missing keys inside object-shaped config values from the element's defaults.
 *
 * Since Shopware 6.7.13 the `cms-element` mixin's `initElementConfig()` only adds config
 * keys that are missing entirely — it no longer deep-merges the defaults into a saved slot.
 * Elements whose `value` is an object (e.g. `title.value.text`, `button.value.*`) would
 * otherwise read `undefined` for any sub-key a slot was saved without.
 *
 * Call it right after `this.initElementConfig()`. Existing values are never overwritten;
 * a `null`/missing value gets a copy of the default, and a value of another type (e.g. a
 * legacy plain string) is left alone for the element to upgrade itself.
 *
 * @param {object} vm a component using the `cms-element` mixin
 */
export function fillNestedConfigDefaults(vm) {
    const { cloneDeep } = Shopware.Utils.object;
    const { isPlainObject } = Shopware.Utils.types;

    const element = vm.element;
    if (!element?.config) {
        return;
    }

    const defaults = {
        ...(vm.cmsElements?.[element.type]?.defaultConfig ?? {}),
        ...(vm.defaultConfig ?? {}),
    };

    Object.entries(defaults).forEach(([key, field]) => {
        const defaultValue = field?.value;
        const config = element.config[key];

        if (!isPlainObject(defaultValue) || !config) {
            return;
        }

        if (config.value === null || config.value === undefined) {
            config.value = cloneDeep(defaultValue);

            return;
        }

        if (!isPlainObject(config.value)) {
            return;
        }

        Object.entries(defaultValue).forEach(([subKey, subDefault]) => {
            if (!(subKey in config.value)) {
                config.value[subKey] = cloneDeep(subDefault);
            }
        });
    });
}

/**
 * Upgrades a legacy plain-text config value to HTML for `mt-text-editor`.
 *
 * Storitve copy fields used to be textareas rendered with `nl2br`; the rich-text editor
 * would collapse their line breaks. Values that already contain markup are left alone.
 *
 * @param {object} config an element config entry (`{ source, value }`)
 */
export function upgradePlainTextToHtml(config) {
    const value = config?.value;
    if (typeof value !== 'string' || value === '' || /<[a-z][^>]*>/i.test(value)) {
        return;
    }

    const escaped = value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    config.value = `<p>${escaped.replace(/\r?\n/g, '<br>')}</p>`;
}
