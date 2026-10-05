export const customFieldSetAndGet = (property, customFieldName) => {
    return {
        get() {
            try {
                return this[property].customFields[customFieldName];
            } catch (error) {
                return "";
            }
        },
        set(newValue) {
            const updatedValue = {[customFieldName]: newValue};
            this[property].customFields = {...this[property].customFields, ...updatedValue};
        }
    }
}
