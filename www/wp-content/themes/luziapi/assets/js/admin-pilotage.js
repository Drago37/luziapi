document.addEventListener('DOMContentLoaded', () => {
    const euroFormatter = new Intl.NumberFormat('fr-FR', {
        style: 'currency',
        currency: 'EUR',
    });

    const createBarChart = (canvas, label, valueFormatter, integerScale = false) => new window.Chart(canvas, {
        type: 'bar',
        data: {
            labels: JSON.parse(canvas.dataset.labels || '[]'),
            datasets: [{
                label,
                data: JSON.parse(canvas.dataset.values || '[]'),
                backgroundColor: '#d49a28',
                borderColor: '#a87313',
                borderWidth: 1,
                borderRadius: 5,
            }],
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => valueFormatter(context.parsed.y || 0),
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: valueFormatter,
                        ...(integerScale ? { precision: 0, stepSize: 1 } : {}),
                    },
                },
                x: { grid: { display: false } },
            },
        },
    });

    if (typeof window.Chart !== 'undefined') {
        const salesCanvas = document.getElementById('luziapi-monthly-sales-chart');
        if (salesCanvas) createBarChart(salesCanvas, 'Commandes validées', (value) => euroFormatter.format(value));

        const productsCanvas = document.getElementById('luziapi-products-chart');
        if (productsCanvas) {
            createBarChart(
                productsCanvas,
                'Pots vendus',
                (value) => `${value} pot${value > 1 ? 's' : ''}`,
                true,
            );
        }
    }

    const quickSale = document.querySelector('[data-quick-sale]');
    if (quickSale) {
        const email = quickSale.querySelector('[data-quick-email]');
        const sendEmail = quickSale.querySelector('[data-send-email]');
        const fulfillment = quickSale.querySelector('[data-fulfillment]');
        const deliveryAddress = quickSale.querySelector('[data-delivery-address]');
        const updateEmail = () => {
            sendEmail.disabled = !email.value.trim();
            if (sendEmail.disabled) sendEmail.checked = false;
        };
        const updateDelivery = () => {
            const delivery = fulfillment.value === 'delivery';
            deliveryAddress.hidden = !delivery;
            deliveryAddress.querySelectorAll('input').forEach((input) => { input.required = delivery; });
        };
        email.addEventListener('input', updateEmail);
        fulfillment.addEventListener('change', updateDelivery);
        quickSale.addEventListener('submit', () => {
            const button = quickSale.querySelector('.luziapi-pilotage__submit-bar button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = 'Création en cours…';
            }
        });
        updateEmail();
        updateDelivery();
    }

    const stockMovement = document.querySelector('[data-stock-movement]');
    if (stockMovement) {
        const product = stockMovement.querySelector('[data-stock-product]');
        const lot = stockMovement.querySelector('[data-stock-lot]');
        const type = stockMovement.querySelector('[data-stock-type]');
        const quantity = stockMovement.querySelector('[data-stock-quantity]');
        const quantityHelp = stockMovement.querySelector('[data-stock-quantity-help]');
        const updateLots = () => {
            lot.querySelectorAll('option[data-product-id]').forEach((option) => {
                option.disabled = option.dataset.productId !== product.value || option.dataset.available !== 'yes';
            });
            if (lot.selectedOptions[0]?.disabled) lot.value = '';
        };
        const updateQuantity = () => {
            const correction = type.value === 'correction';
            quantity.min = correction ? '' : '1';
            quantityHelp.textContent = correction
                ? 'Saisir un nombre positif pour ajouter, négatif pour retirer.'
                : 'Saisir le nombre de pots sortis.';
        };
        product.addEventListener('change', updateLots);
        type.addEventListener('change', updateQuantity);
        updateLots();
        updateQuantity();
    }

    const harvestForm = document.querySelector('[data-harvest-form]');
    if (harvestForm) {
        const registrations = harvestForm.querySelectorAll('input[name="stock_registration"]');
        const submit = harvestForm.querySelector('[data-harvest-submit]');
        const updateSubmit = () => {
            const selected = harvestForm.querySelector('input[name="stock_registration"]:checked');
            submit.textContent = selected?.value === 'existing'
                ? 'Enregistrer la récolte sans modifier le stock'
                : 'Enregistrer la récolte et ajouter le stock';
        };
        registrations.forEach((registration) => registration.addEventListener('change', updateSubmit));
        updateSubmit();
    }
});
