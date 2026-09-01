class ShoppingCart {
    constructor() {
        this.items = JSON.parse(localStorage.getItem('cart')) || [];
        this.updateCartCount();
        this.initEventListeners();
    }

    initEventListeners() {
        // Solo agregar listeners una vez
        document.querySelectorAll('.add-to-cart').forEach(button => {
            button.onclick = (e) => {
                const productId = button.dataset.productId;
                this.addItem(productId);
            };
        });

        document.querySelectorAll('.update-quantity').forEach(input => {
            input.onchange = (e) => {
                const productId = input.dataset.productId;
                const quantity = parseInt(input.value);
                this.updateQuantity(productId, quantity);
            };
        });

        document.querySelectorAll('.remove-from-cart').forEach(button => {
            button.onclick = (e) => {
                const productId = button.dataset.productId;
                this.removeItem(productId);
            };
        });
    }

    addItem(productId) {
    const productElement = document.querySelector(`[data-product-id="${productId}"]`);
    if (!productElement) return false;

    // Leer el aroma seleccionado
    const aromaSelect = document.querySelector(`.aroma-select[data-product-id="${productId}"]`);
    const aromaId = aromaSelect ? aromaSelect.value : '';
    const aromaName = aromaSelect ? aromaSelect.options[aromaSelect.selectedIndex].text : '';

    // Validar que se haya elegido aroma si corresponde
    if (aromaSelect && !aromaId) {
        this.showNotification('Por favor, seleccioná un aroma.', 'danger');
        return false;
    }

    const stock = parseInt(productElement.dataset.productStock || 0);
    // Ahora el carrito puede tener el mismo producto con distintos aromas
    const currentCartQuantity = this.items
        .filter(item => item.productId === productId && item.aromaId === aromaId)
        .reduce((sum, item) => sum + item.quantity, 0);
    const availableStock = stock - currentCartQuantity;

    if (availableStock <= 0) {
      
        return false;
    }

    // Actualizar o agregar el item al carrito
    const existingItem = this.items.find(item => item.productId === productId && item.aromaId === aromaId);
    if (existingItem) {
        existingItem.quantity++;
    } else {
        this.items.push({
            productId: productId,
            quantity: 1,
            aromaId: aromaId,
            aromaName: aromaName
        });
    }

    this.saveCart();
    this.updateProductStockDisplay(productId);
    this.showNotification('Producto agregado al carrito', 'success');
    return true;
}

    getProductQuantityInCart(productId) {
        // Sumar todas las cantidades del mismo producto, independientemente del aroma
        return this.items
            .filter(item => item.productId === productId)
            .reduce((total, item) => total + item.quantity, 0);
    }

    updateProductStockDisplay(productId) {
        const productElement = document.querySelector(`[data-product-id="${productId}"]`);
        if (!productElement) return;

        const stock = parseInt(productElement.dataset.productStock || 0);
        const currentCartQuantity = this.getProductQuantityInCart(productId);
        const availableStock = stock - currentCartQuantity;

        // Disparar evento personalizado para actualizar la UI
        const event = new CustomEvent('cartUpdated', {
            detail: {
                productId: productId,
                newStock: availableStock
            }
        });
        document.dispatchEvent(event);
    }

    updateQuantity(productId, quantity) {
        const productElement = document.querySelector(`[data-product-id="${productId}"]`);
        if (productElement) {
            const stock = parseInt(productElement.dataset.productStock || 0);
            const currentCartQuantity = this.getProductQuantityInCart(productId);
            const availableStock = stock - (currentCartQuantity - quantity);

            if (quantity > stock) {
                
                return false;
            }
        }

        const item = this.items.find(item => item.productId === productId);
        if (item) {
            if (quantity > 0) {
                item.quantity = quantity;
            } else {
                this.removeItem(productId);
                return;
            }
        }
        this.saveCart();
        this.updateProductStockDisplay(productId);

        if (window.location.pathname.includes('cart.php')) {
            window.location.reload();
        }
        return true;
    }

    showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notification alert alert-${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    removeItem(productId) {
        this.items = this.items.filter(item => item.productId !== productId);
        this.saveCart();
        this.updateProductStockDisplay(productId);

        if (window.location.pathname.includes('cart.php')) {
            window.location.reload();
        }
    }

    saveCart() {
        localStorage.setItem('cart', JSON.stringify(this.items));
        this.updateCartCount();
    }

    updateCartCount() {
        const count = this.items.reduce((total, item) => total + item.quantity, 0);
        document.querySelectorAll('.cart-count').forEach(el => {
            el.textContent = count;
        });

        const floatingCart = document.querySelector('.floating-cart');
        if (floatingCart) {
            floatingCart.style.display = count > 0 ? 'flex' : 'none';
        }
    }

    getWhatsAppMessage() {
        let message = "¡Hola! Me gustaría hacer el siguiente pedido:\n\n";
        let total = 0;

        this.items.forEach(item => {
            const productElement = document.querySelector(`[data-product-id="${item.productId}"]`);
            if (productElement) {
                const name = productElement.dataset.productName;
                const price = parseFloat(productElement.dataset.productPrice);
                const subtotal = price * item.quantity;
                total += subtotal;

                message += `${item.quantity}x ${name}`;
                if (item.aromaName) message += ` (${item.aromaName})`;
                message += ` - $${subtotal.toFixed(2)}\n`;
            }
        });

        message += `\nTotal: $${total.toFixed(2)}`;
        return encodeURIComponent(message);
    }
}

const cart = new ShoppingCart();
