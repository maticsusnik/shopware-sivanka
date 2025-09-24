import HttpClient from 'src/service/http-client.service';

const httpClient = new HttpClient();
import Plugin from 'src/plugin-system/plugin.class';


export default class AddAllToCart extends Plugin {

    init() {
        let buttons = this.el.querySelectorAll('[data-add-all-to-cart-button]');

        if (buttons.length > 0) {
            buttons.forEach(button => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    if (button.classList.contains('loading')) {
                        return; // Prevent further logic if already in a loading state
                    }

                    button.classList.add('loading');
                    this.addAllToCart(button);
                });
            });
        }
    }

    addAllToCart(button) {
        const productIds = JSON.parse(this.el.dataset.products);

        if (productIds.length) {
            const formData = new FormData();

            productIds.forEach((productId) => {
                formData.append(`lineItems[${productId}][quantity]`, 1);
                formData.append(`lineItems[${productId}][id]`, productId);
                formData.append(`lineItems[${productId}][type]`, 'product');
                formData.append(`lineItems[${productId}][referencedId]`, productId);
                formData.append(`lineItems[${productId}][stackable]`, 1);
                formData.append(`lineItems[${productId}][removable]`, 1);
            });

            httpClient.post('/checkout/line-item/add', formData, () => {
                this.redirectToOffcanvas();
                button.classList.remove('loading');
            });

        } else {
            console.log("No products to add to cart!");
            this.event.target.classList.remove('loading');
        }
    }

    /**
     * reload to show cart offcanvas & message
     */
    redirectToOffcanvas() {
        if (!window.location.search.includes('offcanvas=1')) {
            window.location.href = `${window.location.origin}${window.location.pathname}?offcanvas=1`;
        } else {
            window.location.reload();
        }
    }
}
