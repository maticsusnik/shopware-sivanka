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
