/* ════════════════════════════════════════════════════════════════════════
   Mochajaki — customer site
   ─────────────────────────────────────────────────────────────────────────
   • No prices are hardcoded here. Every product arrives from api/products.php
     together with its `option_spec` (sizes, flavours, add-ons and what each
     one costs), and this file renders the order form and adds up the same
     numbers. api/orders.php re-prices the order, so a tampered browser cannot
     change what is charged — it can only ever disagree with itself.
   • No inline onclick/onerror attributes: everything is handled with
     delegated listeners so the pages can run under a strict Content Security
     Policy (see .htaccess).
   ════════════════════════════════════════════════════════════════════════ */
'use strict';

/* ═══════════════════════════ 1. Helpers ═══════════════════════════ */

const API = '../api';
const CART_KEY = 'mochajaki_cart';

const $ = (id) => document.getElementById(id);
const money = (value) => '₱' + Number(value || 0).toFixed(2);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

/** Random row id. crypto.randomUUID() only exists on secure origins, and a
 *  phone demo over http://192.168.x.x is not one, so fall back to random(). */
const uid = () => (typeof crypto !== 'undefined' && crypto.randomUUID
  ? crypto.randomUUID()
  : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2));

/** Small transient message at the bottom of the screen.
 *  Pass 'error' for failures so the toast turns red instead of green. */
function toast(message, variant = 'success') {
  const node = document.createElement('div');
  node.className = variant === 'error' ? 'cart-toast cart-toast-error' : 'cart-toast';
  node.textContent = message;
  document.body.appendChild(node);
  setTimeout(() => node.remove(), 3200);
}

/* ═══════════════════════════ 2. State ═══════════════════════════ */

const state = {
  products: [],
  deliveryFee: 0,
  paymentInfo: {},
  cart: loadCart(),
  user: null,
  orders: [],
  activeCategory: 'All',
  authReturnView: 'account',
  modalProduct: null,
};

/* ═══════════════════════════ 3. API client ═══════════════════════════ */

async function api(path, options = {}) {
  const headers = { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) };
  if (!(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(`${API}/${path}`, { credentials: 'same-origin', ...options, headers });

  let data;
  try {
    data = await response.json();
  } catch {
    throw new Error('Unexpected server response.');
  }
  if (!response.ok) {
    throw new Error(data.error || 'Request failed');
  }
  return data;
}

/* ═══════════════════════════ 4. Pricing (mirrors api/pricing.php) ═══════════════════════════ */

function findProduct(id) {
  return state.products.find((product) => Number(product.id) === Number(id));
}

/** Unit price = listed price + chosen size/pieces deltas + checked add-ons. */
function unitPrice(product, customizations) {
  const spec = product.option_spec || { fields: [], addons: [] };
  let price = Number(product.price);

  spec.fields.forEach((field) => {
    const chosen = customizations ? customizations[field.key] : undefined;
    if (chosen === undefined || chosen === null || chosen === '') return;
    field.options.forEach((option) => {
      if (String(option.value) === String(chosen)) price += Number(option.delta || 0);
    });
  });

  spec.addons.forEach((addon) => {
    if (customizations && customizations[addon.key]) price += Number(addon.price || 0);
  });

  return Math.round(price * 100) / 100;
}

/** "Rice type: Plain · Add pearl" — the same wording the admin panel shows. */
function customizationsText(product, customizations) {
  const spec = product ? (product.option_spec || { fields: [], addons: [] }) : { fields: [], addons: [] };
  const labels = {};
  spec.fields.concat(spec.addons).forEach((field) => { labels[field.key] = field.label; });

  return Object.entries(customizations || {})
    .filter(([, value]) => value !== null && value !== '' && value !== false)
    .map(([key, value]) => {
      const label = labels[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
      return value === true ? label : `${label}: ${value}`;
    })
    .join(' · ');
}

/* ═══════════════════════════ 5. Cart storage ═══════════════════════════ */

/** Reads the saved cart and upgrades anything the old format left behind. */
function loadCart() {
  try {
    const raw = JSON.parse(localStorage.getItem(CART_KEY) || '[]');
    if (!Array.isArray(raw)) return [];
    return raw
      .filter((line) => line && Number(line.id) > 0)
      .map((line) => ({
        key: line.key || uid(),
        id: Number(line.id),
        quantity: Math.min(50, Math.max(1, Number(line.quantity) || 1)),
        customizations: (line.customizations && typeof line.customizations === 'object') ? line.customizations : {},
        unavailable: false,
      }));
  } catch {
    return [];
  }
}

function saveCart() {
  try {
    localStorage.setItem(CART_KEY, JSON.stringify(state.cart.map(({ key, id, quantity, customizations }) => (
      { key, id, quantity, customizations }
    ))));
  } catch {
    /* private browsing / quota — the cart simply will not survive a reload */
  }
}

/**
 * Re-checks the saved cart against the live menu: prices may have changed and
 * items may have been switched off since they were added (the old version kept
 * yesterday's price and only failed at checkout).
 */
function refreshCartAgainstMenu() {
  let priceChanged = false;

  state.cart.forEach((line) => {
    const product = findProduct(line.id);
    if (!product || Number(product.is_available) !== 1) {
      line.unavailable = true;
      return;
    }
    line.unavailable = false;

    const current = unitPrice(product, line.customizations);
    if (line.unitPrice !== undefined && line.unitPrice !== current) {
      priceChanged = true;
    }
    line.unitPrice = current;
  });

  if (priceChanged) {
    toast('Some prices were updated since you added them — the cart now shows current prices.');
  }
}

/* ═══════════════════════════ 6. Views ═══════════════════════════ */

function showView(name) {
  $('menu-pop').hidden = true;
  $('header-account').classList.toggle('active', name === 'account' || name === 'orders');
  document.querySelectorAll('.view').forEach((view) => { view.hidden = view.id !== `view-${name}`; });
  document.querySelectorAll('[data-view]').forEach((button) => (
    button.classList.toggle('active', button.dataset.view === name)
  ));

  if (name === 'cart') renderCart();
  if (name === 'orders') loadOrders();
  if (name === 'account') refreshAccount();

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ═══════════════════════════ 7. Menu ═══════════════════════════ */

async function loadMenu() {
  try {
    const data = await api('products.php');
    state.products = data.products.map((product) => ({
      ...product,
      price: Number(product.price),
      is_available: Number(product.is_available),
    }));
    state.deliveryFee = Number(data.delivery_fee || 0);
    state.paymentInfo = data.payment || {};
    refreshCartAgainstMenu();
    renderCategories();
    renderMenu();
    renderCart();
    renderPaymentInfo();
  } catch (error) {
    $('menu-grid').innerHTML = `<p class="muted">${esc(error.message)}</p>`;
  }
}

function renderCategories() {
  const categories = ['All', ...new Set(state.products.map((product) => product.category))];
  $('categories').innerHTML = categories.map((category) => `
    <button class="category-chip ${category === state.activeCategory ? 'active' : ''}"
            data-action="pick-category" data-category="${esc(category)}" type="button">${esc(category)}</button>
  `).join('');
}

function renderMenu() {
  const query = $('search').value.trim().toLowerCase();
  const list = state.products.filter((product) => (
    (state.activeCategory === 'All' || product.category === state.activeCategory)
    && `${product.name} ${product.category} ${product.description || ''}`.toLowerCase().includes(query)
  ));

  $('menu-grid').innerHTML = list.map((product) => `
    <article class="product-card">
      <div class="product-image">
        <img alt="${esc(product.name)}" loading="lazy" src="${esc(product.image_url || '')}"/>
      </div>
      <div class="product-body">
        <h3>${esc(product.name)}</h3>
        <p>${esc(product.description || '')}</p>
        <div class="product-foot">
          <span class="price">${money(product.price)}</span>
          <button class="add-button" ${product.is_available === 1 ? '' : 'disabled'}
                  data-action="open-product" data-product="${product.id}" type="button">
            ${product.is_available === 1 ? '＋ Add' : 'Unavailable'}
          </button>
        </div>
      </div>
    </article>
  `).join('') || '<p class="muted">No matching items.</p>';
}

/* ═══════════════════════════ 8. Product options modal ═══════════════════════════ */

function openProductModal(productId) {
  const product = findProduct(productId);
  if (!product) return;

  state.modalProduct = product;
  const spec = product.option_spec || { fields: [], addons: [] };

  const fields = spec.fields.map((field) => `
    <label>${esc(field.label)}
      <select name="${esc(field.key)}" data-spec-field="${esc(field.key)}" ${field.required ? 'required' : ''}>
        <option value="">Choose ${esc(field.label.toLowerCase())}</option>
        ${field.options.map((option) => `
          <option value="${esc(option.value)}" data-delta="${Number(option.delta || 0)}">
            ${esc(option.value)}${Number(option.delta) ? ` (+${money(option.delta)})` : ''}
          </option>
        `).join('')}
      </select>
    </label>
  `).join('');

  const addons = spec.addons.map((addon) => `
    <label class="check-row">
      <input name="${esc(addon.key)}" data-spec-addon="${esc(addon.key)}" data-price="${Number(addon.price || 0)}" type="checkbox"/>
      ${esc(addon.label)}${Number(addon.price) ? ` (+${money(addon.price)})` : ''}
    </label>
  `).join('');

  const addonsBlock = addons
    ? `<div class="option-note">Optional add-ons</div>${addons}`
    : '';

  $('modal-content').innerHTML = `
    <img class="modal-product-image" alt="${esc(product.name)}" src="${esc(product.image_url || '')}"/>
    <span class="eyebrow">${esc(product.category)}</span>
    <h2 id="modal-title">${esc(product.name)}</h2>
    <p class="muted">${esc(product.description || '')}</p>
    <p class="modal-price"><span id="modal-price">${money(product.price)}</span> <small id="modal-price-note" class="muted"></small></p>
    <form id="custom-form">
      ${fields}
      ${addonsBlock}
      <label>Quantity<input class="modal-qty" max="50" min="1" name="quantity" required="" type="number" value="1"/></label>
      <button class="primary full" type="submit">Add to cart</button>
    </form>
  `;

  $('product-modal').hidden = false;
  updateModalPrice();
}

/** Recomputes the live "price each" line while the customer picks options. */
function updateModalPrice() {
  const product = state.modalProduct;
  const form = $('custom-form');
  if (!product || !form) return;

  const customizations = readCustomForm(form, product);
  const price = unitPrice(product, customizations);
  const quantity = Math.max(1, Math.min(50, Number(form.elements.quantity?.value) || 1));

  $('modal-price').textContent = money(price);
  $('modal-price-note').textContent = Number(quantity) > 1
    ? `each · ${quantity} × ${money(price)} = ${money(price * quantity)}`
    : 'each';
}

/** Reads only the options this product actually offers. */
function readCustomForm(form, product) {
  const spec = product.option_spec || { fields: [], addons: [] };
  const customizations = {};

  spec.fields.forEach((field) => {
    const control = form.elements[field.key];
    if (control && control.value !== '') customizations[field.key] = control.value;
  });
  spec.addons.forEach((addon) => {
    const control = form.elements[addon.key];
    if (control && control.checked) customizations[addon.key] = true;
  });

  return customizations;
}

function addToCart(form) {
  const product = state.modalProduct;
  if (!product) return;

  const quantity = Math.max(1, Math.min(50, Number(form.elements.quantity?.value) || 1));
  const customizations = readCustomForm(form, product);

  state.cart.push({
    key: uid(),
    id: Number(product.id),
    quantity,
    customizations,
    unitPrice: unitPrice(product, customizations),
    unavailable: false,
  });

  saveCart();
  renderCart();
  closeModal('product-modal');
  toast(`${product.name} added to your cart`);
}

/* ═══════════════════════════ 9. Cart ═══════════════════════════ */

function cartCount() {
  return state.cart.reduce((sum, line) => sum + line.quantity, 0);
}

function cartSubtotal() {
  return state.cart.reduce((sum, line) => sum + line.unitPrice * line.quantity, 0);
}

/** The delivery fee only applies to Delivery orders (it used to hit Pickup too). */
function cartDeliveryFee() {
  return $('order-type').value === 'Delivery' ? state.deliveryFee : 0;
}

function renderCart() {
  saveCart();

  const count = cartCount();
  $('cart-count').textContent = count;
  $('cart-count-label').textContent = `${count} item${count === 1 ? '' : 's'}`;

  if (state.cart.length === 0) {
    $('cart-items').innerHTML = '<p class="muted">Your cart is empty. Browse the menu to add something delicious.</p>';
  } else {
    const unavailable = state.cart.filter((line) => line.unavailable).length;
    const notice = unavailable
      ? `<div class="cart-warning">
           ${unavailable} item${unavailable === 1 ? ' is' : 's are'} no longer available.
           <button class="secondary-button" data-action="remove-unavailable" type="button">Remove them</button>
         </div>`
      : '';

    $('cart-items').innerHTML = notice + state.cart.map((line) => {
      const product = findProduct(line.id);
      const name = product ? product.name : 'Item no longer on the menu';
      const options = customizationsText(product, line.customizations) || 'Standard';
      const total = line.unitPrice * line.quantity;

      return `
        <div class="cart-row ${line.unavailable ? 'cart-row-unavailable' : ''}">
          <img alt="" class="cart-thumb" src="${esc(product ? (product.image_url || '') : '')}"/>
          <div class="cart-info">
            <strong>${esc(name)}</strong>
            <small>${esc(options)}</small>
            ${line.unavailable ? '<small class="cart-unavailable-note">Unavailable — please remove</small>' : ''}
            <div class="qty-controls">
              <button aria-label="Decrease quantity" data-action="qty-down" data-key="${esc(line.key)}" type="button">−</button>
              <span>${line.quantity}</span>
              <button aria-label="Increase quantity" data-action="qty-up" data-key="${esc(line.key)}" type="button">+</button>
              <button class="remove-button" data-action="remove-line" data-key="${esc(line.key)}" type="button">Remove</button>
            </div>
          </div>
          <div class="cart-price">${money(total)}</div>
        </div>
      `;
    }).join('');
  }

  const subtotal = cartSubtotal();
  const fee = cartDeliveryFee();

  $('delivery-fee-label').textContent = $('order-type').value === 'Delivery'
    ? 'Delivery fee'
    : 'Delivery fee (pickup)';
  $('cart-subtotal').textContent = money(subtotal);
  $('delivery-fee').textContent = money(fee);
  $('cart-total').textContent = money(subtotal + fee);

  $('address-field').hidden = $('order-type').value !== 'Delivery';
  $('contact-field').hidden = $('order-type').value !== 'Delivery';
  $('online-payment-info').hidden = $('payment').value === 'Cash';
  $('place-order').disabled = state.cart.length === 0 || state.cart.some((line) => line.unavailable);

  renderPaymentInfo();
}

function changeQuantity(key, delta) {
  const line = state.cart.find((item) => item.key === key);
  if (!line) return;
  line.quantity = Math.max(1, Math.min(50, line.quantity + delta));
  renderCart();
}

function removeLine(key) {
  state.cart = state.cart.filter((line) => line.key !== key);
  renderCart();
}

/* ═══════════════════════════ 10. Payment details ═══════════════════════════ */

/**
 * Shows the QR code of the method the customer picked. If the shop has not
 * installed an image (assets/payments/*.png) and no account label is set, the
 * customer is told to ask the store instead of seeing an empty box.
 */
function renderPaymentInfo() {
  const method = $('payment').value;
  const row = $('qr-row');
  const note = $('payment-note');

  if (method === 'Cash') {
    row.hidden = true;
    return;
  }

  const info = state.paymentInfo[method] || {};
  row.hidden = false;
  $('qr-instructions').textContent = `Scan the ${method} QR code, or use the account details below, then submit your reference number after placing the order.`;
  row.innerHTML = `<div><b>${esc(method)} QR</b><img alt="${esc(method)} QR code" id="qr-image" src="${esc(info.qr || '')}"/></div>`;

  // Plain text only (textContent), so shop-configured text can never inject HTML.
  const fallback = info.label
    ? `Send the payment to ${info.label}. `
    : `The ${method} QR code is not installed yet — please ask the store for the account details. `;
  note.textContent = `${fallback}Payment stays pending until an administrator verifies it.`;

  const image = $('qr-image');
  if (image) {
    // No QR image installed yet: hide the empty box and say what to do instead.
    image.addEventListener('error', () => {
      row.hidden = true;
      $('qr-instructions').textContent = fallback.trim();
    });
  }
}

function openPaymentModal(orderId, method, orderCode) {
  $('payment-order-id').value = orderId;
  $('payment-modal-copy').textContent = orderCode
    ? `Order ${orderCode} created. Submit your ${method} reference for verification.`
    : `Submit your ${method} reference and optional screenshot. Payment stays pending until it is verified.`;
  $('payment-message').textContent = '';
  $('payment-modal').hidden = false;
  $('payment-reference').focus();
}

/* ═══════════════════════════ 11. Checkout ═══════════════════════════ */

async function placeOrder() {
  const message = $('checkout-message');
  message.textContent = '';

  if (state.cart.length === 0) {
    message.textContent = 'Your cart is empty.';
    return;
  }
  if (state.cart.some((line) => line.unavailable)) {
    message.textContent = 'Remove the unavailable items before placing your order.';
    return;
  }
  if (!state.user) {
    state.authReturnView = 'cart';
    showView('account');
    $('auth-message').textContent = 'Sign in or create an account to place your order.';
    return;
  }

  const orderType = $('order-type').value;
  const address = $('address').value.trim();
  const contact = $('contact-number').value.trim();

  if (orderType === 'Delivery' && address === '') {
    message.textContent = 'Enter a delivery address.';
    return;
  }
  if (orderType === 'Delivery' && contact === '') {
    message.textContent = 'Enter a contact number so the rider can reach you.';
    return;
  }

  const button = $('place-order');
  button.disabled = true;
  message.textContent = 'Placing your order…';

  try {
    const result = await api('orders.php', {
      method: 'POST',
      body: JSON.stringify({
        items: state.cart.map((line) => ({
          product_id: line.id,
          quantity: line.quantity,
          customizations: line.customizations,
        })),
        order_type: orderType,
        address,
        contact_number: contact,
        notes: $('order-notes').value.trim(),
        payment_method: $('payment').value,
      }),
    });

    state.cart = [];
    saveCart();
    renderCart();
    await loadOrders();
    showView('orders');

    // The server's total is the authoritative one — show it, not the cart guess.
    $('orders-message').textContent = `Order ${result.order_code} placed · total ${money(result.total)}.`;
    toast(`Order ${result.order_code} placed · ${money(result.total)}`);

    if (result.needs_payment_info) {
      openPaymentModal(result.order_id, result.payment_method, result.order_code);
    }
  } catch (error) {
    message.textContent = error.message;
  } finally {
    button.disabled = false;
  }
}

/* ═══════════════════════════ 12. Orders ═══════════════════════════ */

async function loadOrders() {
  if (!state.user) {
    $('orders-list').innerHTML = `
      <div class="surface empty-state">
        <div class="empty-icon">🧾</div>
        <h2>Log in to see your orders</h2>
        <p class="muted">Track each order from preparation to pickup or delivery.</p>
        <div class="empty-actions">
          <button class="primary" data-action="sign-in-to-order" type="button">Log in</button>
          <button class="secondary-button" data-action="sign-up-to-order" type="button">Create account</button>
        </div>
      </div>`;
    return;
  }

  try {
    const data = await api('orders.php');
    state.orders = data.orders;
    renderOrders();
    renderAccountStats();
  } catch (error) {
    $('orders-list').innerHTML = `<div class="surface">${esc(error.message)}</div>`;
  }
}

function renderOrders() {
  if (state.orders.length === 0) {
    $('orders-list').innerHTML = '<div class="surface">You have not placed any orders yet.</div>';
    return;
  }

  $('orders-list').innerHTML = state.orders.map((order) => `
    <article class="order-card">
      <div class="order-card-top">
        <div>
          <h3>${esc(order.order_code)}</h3>
          <p>${esc(order.created_at)} · ${esc(order.order_type)}</p>
        </div>
        <span class="status-pill">${esc(order.status)}</span>
      </div>
      <div class="order-card-top">
        <span class="muted">${esc(order.items_summary || 'Order details')}</span>
        <span class="order-total">${money(order.total)}</span>
      </div>
      <div class="order-details">
        Payment: ${esc(order.payment_method || '')} · ${esc(order.payment_status || 'Pending')}
        ${order.payment_reference ? ` · Reference: ${esc(order.payment_reference)}` : ''}
        ${order.delivery_address ? `<br>Delivery address: ${esc(order.delivery_address)}` : ''}
        ${order.contact_number ? `<br>Contact: ${esc(order.contact_number)}` : ''}
        ${order.notes ? `<br>Notes: ${esc(order.notes)}` : ''}
        <br>Subtotal ${money(order.subtotal)} · Delivery fee ${money(order.delivery_fee)}
      </div>
      ${order.can_submit_payment ? `
        <button class="secondary-button" data-action="open-payment"
                data-order="${Number(order.id)}" data-method="${esc(order.payment_method)}"
                data-code="${esc(order.order_code)}" type="button">Submit payment details</button>` : ''}
    </article>
  `).join('');
}

/* ═══════════════════════════ 13. Account ═══════════════════════════ */

async function refreshAccount() {
  try {
    const data = await api('me.php');
    state.user = data.user;
    renderAccount();
  } catch {
    // 401 simply means "nobody is signed in" — show the guest state.
    state.user = null;
    state.orders = [];
    renderAccount();
  }
  await loadOrders();
}

function renderAccount() {
  const user = state.user;

  if (!user) {
    $('account-state').textContent = 'You are not signed in.';
    $('auth-grid').hidden = false;
    $('profile-details').hidden = true;
    $('profile-details').innerHTML = '';
    $('password-form').hidden = true;
    $('account-stats').hidden = true;
    $('logout').hidden = true;
    $('guest-actions').hidden = false;
    $('user-menu').hidden = true;
    $('nav-orders').hidden = true;
    return;
  }

  const name = String(user.full_name || 'Customer').trim();
  const initial = name.charAt(0).toUpperCase();

  $('account-state').textContent = 'You are signed in.';
  $('profile-details').hidden = false;
  $('profile-details').innerHTML = `
    <div class="profile-avatar">${esc(initial)}</div>
    <div>
      <span class="eyebrow">ACCOUNT DETAILS</span>
      <h2>${esc(name)}</h2>
      <p>${esc(user.email || '')}</p>
      <p class="muted">${user.role === 'admin' ? 'Administrator account' : 'Customer account'}</p>
    </div>`;
  $('auth-grid').hidden = true;
  $('password-form').hidden = false;
  $('logout').hidden = false;
  $('pill-label').textContent = name.split(' ')[0];
  $('pill-avatar').textContent = initial;
  $('guest-actions').hidden = true;
  $('user-menu').hidden = false;
  $('nav-orders').hidden = false;
}

function renderAccountStats() {
  const live = state.orders.filter((order) => order.status !== 'Cancelled');
  const active = live.filter((order) => order.status !== 'Completed').length;
  const ordered = live.reduce((sum, order) => sum + Number(order.total || 0), 0);

  $('account-stats').hidden = false;
  $('account-stats').innerHTML = `
    <div><b>${state.orders.length}</b><span>Orders</span></div>
    <div><b>${active}</b><span>In progress</span></div>
    <div><b>${money(ordered)}</b><span>Total ordered</span></div>`;
}

/** Returns to the view the customer was on before they were asked to sign in. */
function afterAuth(message) {
  const back = state.authReturnView;
  state.authReturnView = 'account';
  refreshAccount().then(() => {
    if (back !== 'account') showView(back);
    toast(message);
  });
}

/* ═══════════════════════════ 14. Modals ═══════════════════════════ */

function closeModal(id) {
  $(id).hidden = true;
}

function closeOpenModals() {
  ['product-modal', 'payment-modal'].forEach((id) => { if (!$(id).hidden) $(id).hidden = true; });
}

/* ═══════════════════════════ 15. Events (delegated) ═══════════════════════════ */

const actions = {
  'scroll-menu': () => document.getElementById('menu').scrollIntoView({ behavior: 'smooth' }),
  'scroll-top': (button, event) => {
    event.preventDefault();
    showView('menu');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  },
  'focus-login': () => { showView('account'); $('login-email').focus(); },
  'focus-register': () => { showView('account'); $('reg-name').focus(); },
  'sign-in-to-order': () => { state.authReturnView = 'orders'; showView('account'); $('login-email').focus(); },
  'sign-up-to-order': () => { state.authReturnView = 'orders'; showView('account'); $('reg-name').focus(); },
  'pick-category': (button) => {
    state.activeCategory = button.dataset.category;
    renderCategories();
    renderMenu();
  },
  'open-product': (button) => openProductModal(button.dataset.product),
  'qty-up': (button) => changeQuantity(button.dataset.key, 1),
  'qty-down': (button) => changeQuantity(button.dataset.key, -1),
  'remove-line': (button) => removeLine(button.dataset.key),
  'remove-unavailable': () => {
    state.cart = state.cart.filter((line) => !line.unavailable);
    renderCart();
  },
  'place-order': () => placeOrder(),
  'open-payment': (button) => openPaymentModal(
    Number(button.dataset.order),
    button.dataset.method,
    button.dataset.code
  ),
  logout: () => signOut(),
};

document.addEventListener('click', (event) => {
  const viewButton = event.target.closest('[data-view]');
  if (viewButton) {
    showView(viewButton.dataset.view);
    return;
  }

  const actionButton = event.target.closest('[data-action]');
  if (actionButton) {
    const handler = actions[actionButton.dataset.action];
    if (handler) handler(actionButton, event);
    return;
  }

  const closer = event.target.closest('[data-close-modal]');
  if (closer && (closer.classList.contains('modal-close') || closer === event.target)) {
    closeModal(closer.dataset.closeModal);
    return;
  }

  // Clicking anywhere outside the account menu closes it.
  if (!event.target.closest('#user-menu')) $('menu-pop').hidden = true;
});

document.addEventListener('submit', (event) => {
  const form = event.target;

  if (form.id === 'custom-form') {
    event.preventDefault();
    addToCart(form);
    return;
  }
  if (form.id === 'login-form') {
    event.preventDefault();
    submitLogin(form);
    return;
  }
  if (form.id === 'register-form') {
    event.preventDefault();
    submitRegister(form);
    return;
  }
  if (form.id === 'password-form') {
    event.preventDefault();
    submitPasswordChange(form);
    return;
  }
  if (form.id === 'payment-form') {
    event.preventDefault();
    submitPaymentDetails(form);
  }
});

// Live price while choosing options, and the payment QR after switching method.
document.addEventListener('change', (event) => {
  if (event.target.closest('#custom-form')) updateModalPrice();
  if (event.target.id === 'order-type' || event.target.id === 'payment') renderCart();
});
document.addEventListener('input', (event) => {
  if (event.target.closest('#custom-form')) updateModalPrice();
  if (event.target.id === 'search') renderMenu();
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    closeOpenModals();
    $('menu-pop').hidden = true;
  }
});

/** Pictures that fail to load fade out instead of showing a broken icon. */
document.addEventListener('error', (event) => {
  if (event.target instanceof HTMLImageElement) event.target.classList.add('img-broken');
}, true);

$('header-account').addEventListener('click', (event) => {
  event.stopPropagation();
  const willOpen = $('menu-pop').hidden;
  $('menu-pop').hidden = !willOpen;
  $('header-account').setAttribute('aria-expanded', String(willOpen));
});

/* ═══════════════════════════ 16. Form handlers ═══════════════════════════ */

async function submitLogin(form) {
  const message = $('auth-message');
  message.textContent = '';
  try {
    await api('login.php', {
      method: 'POST',
      body: JSON.stringify({
        email: $('login-email').value,
        password: $('login-password').value,
      }),
    });
    form.reset();
    afterAuth('Welcome back!');
  } catch (error) {
    message.textContent = error.message;
  }
}

async function submitRegister(form) {
  const message = $('auth-message');
  message.textContent = '';
  try {
    await api('register.php', {
      method: 'POST',
      body: JSON.stringify({
        full_name: $('reg-name').value,
        email: $('reg-email').value,
        password: $('reg-password').value,
      }),
    });
    form.reset();
    afterAuth('Account created. Welcome to Mochajaki!');
  } catch (error) {
    message.textContent = error.message;
  }
}

async function submitPasswordChange(form) {
  const message = $('password-message');
  message.textContent = '';
  try {
    await api('change-password.php', {
      method: 'POST',
      body: JSON.stringify({
        current_password: $('pw-current').value,
        new_password: $('pw-new').value,
      }),
    });
    form.reset();
    message.textContent = 'Password updated.';
  } catch (error) {
    message.textContent = error.message;
  }
}

async function signOut() {
  $('menu-pop').hidden = true;
  try {
    await api('logout.php', { method: 'POST', body: '{}' });
    state.user = null;
    state.orders = [];
    state.authReturnView = 'account';
    renderAccount();
    await loadOrders();
    toast('Signed out');
  } catch (error) {
    $('auth-message').textContent = error.message;
  }
}

async function submitPaymentDetails(form) {
  const message = $('payment-message');
  message.textContent = '';

  const payload = new FormData();
  payload.append('order_id', $('payment-order-id').value);
  payload.append('reference', $('payment-reference').value.trim());
  const file = $('payment-screenshot').files[0];
  if (file) payload.append('screenshot', file);

  try {
    await api('payment-submit.php', { method: 'POST', body: payload });
    form.reset();
    message.textContent = 'Payment details submitted for verification.';
    await loadOrders();
    setTimeout(() => closeModal('payment-modal'), 1200);
  } catch (error) {
    message.textContent = error.message;
  }
}

/* ═══════════════════════════ 17. Boot ═══════════════════════════ */

(async function boot() {
  renderCart();
  loadMenu();
  await refreshAccount();
  showView('menu');
})();
