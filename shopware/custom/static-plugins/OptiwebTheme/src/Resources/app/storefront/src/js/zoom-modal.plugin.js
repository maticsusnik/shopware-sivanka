import ZoomModalPlugin from 'src/plugin/zoom-modal/zoom-modal.plugin';

export default class OwZoomModalPlugin extends ZoomModalPlugin {

    _showModal(modal) {
        if (!this._bootstrapModalInstance) {
            this._bootstrapModalInstance = new bootstrap.Modal(modal);
        }

        if (!this._showModalListener) {
            this._showModalListener = () => {
                this._initSlider(modal);
                this.$emitter.publish('modalShow', { modal });
            };
        }

        if (!this._hideModalListener) {
            this._hideModalListener = () => {
                window.focusHandler.resumeFocusState('zoom-modal');
            };
        }

        modal.removeEventListener('shown.bs.modal', this._showModalListener);
        modal.addEventListener('shown.bs.modal', this._showModalListener);

        modal.removeEventListener('hidden.bs.modal', this._hideModalListener);
        modal.addEventListener('hidden.bs.modal', this._hideModalListener);

        this._bootstrapModalInstance.show();
    }
}
