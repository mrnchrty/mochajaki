/* ════════════════════════════════════════════════════════════════════════
   Mochajaki — admin panel
   ─────────────────────────────────────────────────────────────────────────
   • Signs in through api/admin-login.php, which uses its own session, so the
     panel and the customer site no longer sign each other out.
   • When the session expires every request answers 401/403; the panel notices
     that and returns to the sign-in screen instead of showing stale orders.
   • Which payment buttons are offered comes from the server
     (admin-order-detail.php → payment.actions), so the panel and
     api/admin-payments.php can never disagree.
   • Screen prints live in api/admin-payment-proof.php, so payment screenshots are
     no longer readable by anyone who guesses the file name.
   ════════════════════════════════════════════════════════════════════════ */
'use strict';

/* ═══════════════════════════ 1. Helpers ═══════════════════════════ */

const API = '../api';

const $ = (id) => document.getElementById(id);
const money = (value) => '₱' + Number(value || 0).toFixed(2);
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

/**
 * Small transient message at the bottom-right of the screen.
 * Pass 'error' for failures so the toast turns red instead of green.
 */
function toast(message, variant = 'success') {
  const node = document.createElement('div');
  node.className = variant === 'error' ? 'toast toast-error' : 'toast';
  node.textContent = message;
  document.body.appendChild(node);
  setTimeout(() => node.remove(), 3200);
}

/* ═══════════════════════════ 2. State ═══════════════════════════ */

const state = {
  user: null,
  orders: [],
  products: [],
  customers: [],
  bestSellers: [],
  statuses: [],
  paymentStatuses: [],
  currentOrder: null,
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
    const error = new Error(data.error || 'Request failed');
    error.status = response.status;
    throw error;
  }
  return data;
}

/** True when the answer means "you are not an administrator any more". */
function isAuthError(error) {
  return error.status === 401 || error.status === 403;
}

/* ═══════════════════════════ 4. Screens ═══════════════════════════ */

function showLoginScreen(message = '') {
  state.user = null;
  $('admin-app').hidden = true;
  $('login-screen').hidden = false;
  $('login-message').textContent = message;
  closeAllModals();
}

function showApp() {
  $('login-screen').hidden = true;
  $('admin-app').hidden = false;
  $('admin-name').textContent = state.user.full_name || 'Administrator';
  $('admin-avatar').textContent = String(state.user.full_name || 'A').charAt(0).toUpperCase();
}

function showSection(section) {
  document.querySelectorAll('.admin-section').forEach((node) => { node.hidden = node.id !== `section-${section}`; });
  document.querySelectorAll('[data-section]').forEach((button) => (
    button.classList.toggle('active', button.dataset.section === section)
  ));
  $('page-title').textContent = {
    overview: 'Overview',
    orders: 'Order management',
    menu: 'Menu management',
    customers: 'Customers',
  }[section] || section;
}

/* ═══════════════════════════ 5. Loading data ═══════════════════════════ */

async function startApp() {
  if (!state.user) {
    const me = await api('admin-me.php');
    state.user = me.user;
  }
  showApp();
  await refreshAll();
}

async function refreshAll() {
  try {
    const [orderData, productData, customerData] = await Promise.all([
      api('admin-orders.php'),
      api('admin-products.php'),
      api('admin-customers.php'),
    ]);

    state.orders = orderData.orders || [];
    state.bestSellers = orderData.best_sellers || [];
    state.statuses = orderData.statuses || [];
    state.products = productData.products || [];
    state.customers = customerData.customers || [];

    state.paymentStatuses = [...new Set(state.orders.map((order) => order.payment_status).filter(Boolean))];

    renderFilters();
    renderDashboard();
    renderOrders();
    renderProducts();
    renderCustomers();
    $('admin-message').textContent = '';
  } catch (error) {
    if (isAuthError(error)) {
      showLoginScreen('Your session ended. Please sign in again.');
      return;
    }
    $('admin-message').textContent = error.message;
  }
}

/* ═══════════════════════════ 6. Overview ═══════════════════════════ */

function renderDashboard() {
  const orders = state.orders;
  const confirmed = orders.filter((order) => order.status !== 'Cancelled' && order.payment_status === 'Paid');

  // "Needs attention" = orders nobody has picked up yet, plus online payments
  // still waiting to be checked. Cancelled orders are ignored.
  const needsAttention = orders.filter((order) => order.status !== 'Cancelled' && (
    order.status === 'Order Placed'
    || (order.payment_method !== 'Cash' && ['Pending', 'Pending Verification'].includes(order.payment_status))
  )).length;

  $('stat-orders').textContent = orders.length;
  $('stat-sales').textContent = money(confirmed.reduce((sum, order) => sum + Number(order.total), 0));
  $('stat-products').textContent = state.products.length;
  $('stat-pending').textContent = needsAttention;
  $('pending-count').textContent = needsAttention;

  $('recent-orders').innerHTML = ordersTable(orders.slice(0, 6), false);
  renderStatusSummary(orders);
  renderBestSellers();
}

function renderStatusSummary(orders) {
  const counts = state.statuses.map((status) => ({
    status,
    count: orders.filter((order) => order.status === status).length,
  }));

  $('status-summary').innerHTML = counts.map(({ status, count }) => `
    <div class="status-row">
      <span>${esc(status)}</span><b>${count}</b>
      <div class="status-track">
        <i data-width="${orders.length ? Math.round((count / orders.length) * 100) : 0}"></i>
      </div>
    </div>
  `).join('');

  // Widths are applied through the CSSOM: inline style attributes would need
  // 'unsafe-inline' in the Content Security Policy (see .htaccess).
  $('status-summary').querySelectorAll('i[data-width]').forEach((bar) => {
    bar.style.width = `${bar.dataset.width}%`;
  });
}

function renderBestSellers() {
  $('best-sellers').innerHTML = state.bestSellers.length
    ? state.bestSellers.map((item, index) => `
        <div class="best-item">
          <strong>${index + 1}. ${esc(item.product_name)}</strong>
          <span>${Number(item.qty)} sold</span>
        </div>`).join('')
    : '<p class="table-muted">Sales data will appear when orders are placed.</p>';
}

/* ═══════════════════════════ 7. Orders ═══════════════════════════ */

function renderFilters() {
  const orderFilter = $('order-filter');
  const paymentFilter = $('payment-filter');
  const chosenStatus = orderFilter.value || 'All';
  const chosenPayment = paymentFilter.value || 'All';

  orderFilter.innerHTML = '<option value="All">All statuses</option>'
    + state.statuses.map((status) => `<option>${esc(status)}</option>`).join('');

  const paymentOptions = [...new Set([...state.paymentStatuses, 'Pending', 'Pending Verification', 'Paid', 'Failed', 'Refunded'])];
  paymentFilter.innerHTML = '<option value="All">All payments</option>'
    + paymentOptions.map((status) => `<option>${esc(status)}</option>`).join('');

  orderFilter.value = [...orderFilter.options].some((option) => option.value === chosenStatus) ? chosenStatus : 'All';
  paymentFilter.value = [...paymentFilter.options].some((option) => option.value === chosenPayment) ? chosenPayment : 'All';
}

function ordersTable(list, showPayment = true) {
  if (!list.length) {
    return '<p class="table-muted">No orders to display.</p>';
  }

  const rows = list.map((order) => `
    <tr>
      <td>
        <span class="order-code">${esc(order.order_code)}</span>
        <div class="table-muted">${esc(order.created_at)} · ${esc(order.order_type)}</div>
      </td>
      <td>
        ${esc(order.full_name)}
        <div class="table-muted">${esc(order.email)}</div>
      </td>
      <td>
        <b>${money(order.total)}</b>
        <div class="table-muted">${Number(order.item_lines) || 0} line(s)</div>
      </td>
      <td><span class="status-badge ${statusClass(order.status)}">${esc(order.status)}</span></td>
      ${showPayment ? `<td><span class="payment-badge ${paymentClass(order.payment_status)}">${esc(order.payment_method || 'Cash')} · ${esc(order.payment_status || 'Pending')}</span></td>` : ''}
      <td><button class="table-action" data-action="view-order" data-order="${Number(order.id)}" type="button">View</button></td>
    </tr>
  `).join('');

  return `
    <table>
      <thead>
        <tr>
          <th>Order</th><th>Customer</th><th>Total</th><th>Status</th>
          ${showPayment ? '<th>Payment</th>' : ''}<th></th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>`;
}

function statusClass(status) {
  if (status === 'Completed') return 'completed';
  if (status === 'Cancelled') return 'cancelled';
  if (status === 'Preparing' || status === 'Confirmed') return 'preparing';
  return '';
}

function paymentClass(status) {
  if (status === 'Paid') return 'paid';
  if (status === 'Failed') return 'failed';
  if (status === 'Pending Verification') return 'pending-verification';
  return '';
}

function renderOrders() {
  const query = ($('order-search').value || '').toLowerCase();
  const status = $('order-filter').value || 'All';
  const payment = $('payment-filter').value || 'All';

  const list = state.orders.filter((order) => (
    (status === 'All' || order.status === status)
    && (payment === 'All' || order.payment_status === payment)
    && `${order.order_code} ${order.full_name} ${order.email}`.toLowerCase().includes(query)
  ));

  $('orders-table').innerHTML = ordersTable(list, true);
}

/* ═══════════════════════════ 8. Order detail ═══════════════════════════ */

async function viewOrder(orderId) {
  try {
    const data = await api(`admin-order-detail.php?id=${Number(orderId)}`);
    const order = data.order;
    const payment = order.payment || {};
    state.currentOrder = order;

    // Only statuses that fit this order type are offered.
    const usableStatuses = (data.statuses || []).filter((status) => (
      order.order_type === 'Pickup' ? status !== 'Out for Delivery' : status !== 'Ready for Pickup'
    ));

    const itemRows = (order.items || []).map((item) => `
      <tr>
        <td>${esc(item.product_name)}<div class="table-muted">${esc(item.customizations_text || 'Standard')}</div></td>
        <td>${Number(item.quantity)}</td>
        <td>${money(item.unit_price)}</td>
        <td>${money(item.line_total)}</td>
      </tr>`).join('');

    const paymentActions = (payment.actions || []).map((action) => `
      <button class="${action.style === 'danger' ? 'danger-button' : 'primary'}"
              data-action="payment-status" data-order="${Number(order.id)}"
              data-status="${esc(action.status)}" type="button">${esc(action.label)}</button>`).join('');

    $('order-modal-content').innerHTML = `
      <span class="eyebrow">ORDER DETAILS</span>
      <h2>${esc(order.order_code)}</h2>
      <div class="detail-grid">
        <div class="detail-box">
          <h3>Customer</h3>
          <p>${esc(order.full_name)}</p>
          <p>${esc(order.email)}</p>
          <p>${esc(order.order_type)} · ${esc(order.created_at)}</p>
          <p>${esc(order.delivery_address || 'Pickup — no address needed')}</p>
          <p>${order.contact_number ? 'Contact: ' + esc(order.contact_number) : 'No contact number'}</p>
          ${order.notes ? `<p>Notes: ${esc(order.notes)}</p>` : ''}
        </div>
        <div class="detail-box">
          <h3>Payment</h3>
          <p>Method: <b>${esc(payment.method || 'Cash')}</b></p>
          <p>Status: <b>${esc(payment.status || 'Pending')}</b></p>
          <p>Amount: <b>${money(payment.amount || order.total)}</b></p>
          <p>Reference: ${esc(payment.gateway_reference || 'Not submitted')}</p>
          ${payment.verified_at ? `<p>Checked by ${esc(payment.verified_by_name || 'administrator')} on ${esc(payment.verified_at)}</p>` : ''}
          ${payment.has_screenshot ? `<a href="${API}/admin-payment-proof.php?id=${Number(payment.id)}" target="_blank" rel="noopener">View screenshot</a>` : ''}
        </div>
      </div>
      <div class="table-wrap">
        <table class="detail-items">
          <thead><tr><th>Item and options</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
          <tbody>${itemRows}</tbody>
        </table>
      </div>
      <div class="detail-total">
        <div><span>Subtotal</span><b>${money(order.subtotal)}</b></div>
        <div><span>Delivery fee</span><b>${money(order.delivery_fee)}</b></div>
        <div><span>Total</span><b>${money(order.total)}</b></div>
      </div>
      <div class="modal-actions">
        <label>Order status
          <select id="detail-status">
            ${usableStatuses.map((status) => `<option ${order.status === status ? 'selected' : ''}>${esc(status)}</option>`).join('')}
          </select>
        </label>
        <button class="primary" data-action="save-status" data-order="${Number(order.id)}" type="button">Update status</button>
        ${paymentActions}
      </div>
      <p class="message" id="detail-message" role="status"></p>`;

    $('order-modal').hidden = false;
  } catch (error) {
    if (isAuthError(error)) {
      showLoginScreen('Your session ended. Please sign in again.');
      return;
    }
    toast(error.message, 'error');
  }
}

async function saveOrderStatus(orderId) {
  const message = $('detail-message');
  message.textContent = '';
  try {
    await api('admin-orders.php', {
      method: 'PATCH',
      body: JSON.stringify({ id: Number(orderId), status: $('detail-status').value }),
    });
    await refreshAll();
    await viewOrder(orderId);
  } catch (error) {
    if (isAuthError(error)) return showLoginScreen('Your session ended. Please sign in again.');
    message.textContent = error.message;
  }
}

async function setPaymentStatus(orderId, status) {
  const question = {
    Paid: 'Confirm that this payment was received?',
    Failed: 'Reject this payment submission?',
    Refunded: 'Mark this payment as refunded?',
  }[status] || 'Update this payment?';

  if (!window.confirm(question)) return;

  try {
    const data = await api('admin-payments.php', {
      method: 'PATCH',
      body: JSON.stringify({ order_id: Number(orderId), status }),
    });
    await refreshAll();
    await viewOrder(orderId);
    toast(data.message || 'Payment updated.');
  } catch (error) {
    if (isAuthError(error)) return showLoginScreen('Your session ended. Please sign in again.');
    const message = $('detail-message');
    if (message) message.textContent = error.message;
  }
}

/* ═══════════════════════════ 9. Menu management ═══════════════════════════ */

function renderProducts() {
  const query = ($('product-search').value || '').toLowerCase();
  const list = state.products.filter((product) => `${product.name} ${product.category}`.toLowerCase().includes(query));

  if (!list.length) {
    $('products-table').innerHTML = '<p class="table-muted">No menu items found.</p>';
    return;
  }

  $('products-table').innerHTML = `
    <table>
      <thead><tr><th>Item</th><th>Category</th><th>Price</th><th>Availability</th><th>Actions</th></tr></thead>
      <tbody>
        ${list.map((product) => `
          <tr>
            <td><b>${esc(product.name)}</b><div class="table-muted">${esc(product.description || '')}</div></td>
            <td>${esc(product.category)}</td>
            <td><b>${money(product.price)}</b></td>
            <td><span class="status-badge ${Number(product.is_available) ? 'completed' : 'cancelled'}">${Number(product.is_available) ? 'Available' : 'Unavailable'}</span></td>
            <td>
              <button class="table-action" data-action="edit-product" data-product="${Number(product.id)}" type="button">Edit</button>
              <button class="table-action" data-action="toggle-product" data-product="${Number(product.id)}" data-available="${Number(product.is_available) ? 0 : 1}" type="button">${Number(product.is_available) ? 'Disable' : 'Enable'}</button>
              <button class="table-action danger-text" data-action="delete-product" data-product="${Number(product.id)}" type="button">Delete</button>
            </td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

function openProductModal(product) {
  $('product-modal-title').textContent = product ? 'Edit menu item' : 'Add menu item';
  $('product-id').value = product ? product.id : '';
  $('product-name').value = product ? product.name : '';
  $('product-category').value = product ? product.category : '';
  $('product-description').value = product ? (product.description || '') : '';
  $('product-price').value = product ? product.price : '';
  $('product-image').value = product ? (product.image_url || '') : '';
  $('product-message').textContent = '';
  $('product-modal').hidden = false;
  $('product-name').focus();
}

async function saveProduct(form) {
  const message = $('product-message');
  const id = $('product-id').value;
  message.textContent = '';

  try {
    await api('admin-products.php', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify({
        id: id ? Number(id) : undefined,
        name: $('product-name').value,
        category: $('product-category').value,
        description: $('product-description').value,
        price: Number($('product-price').value),
        image_url: $('product-image').value,
      }),
    });
    form.reset();
    closeModal('product-modal');
    await refreshAll();
    toast(id ? 'Menu item updated.' : 'Menu item added.');
  } catch (error) {
    if (isAuthError(error)) return showLoginScreen('Your session ended. Please sign in again.');
    message.textContent = error.message;
  }
}

async function toggleProduct(productId, isAvailable) {
  try {
    await api('admin-products.php', {
      method: 'PATCH',
      body: JSON.stringify({ id: Number(productId), is_available: Number(isAvailable) }),
    });
    await refreshAll();
  } catch (error) {
    if (isAuthError(error)) return showLoginScreen('Your session ended. Please sign in again.');
    toast(error.message, 'error');
  }
}

async function deleteProduct(productId) {
  const product = state.products.find((item) => Number(item.id) === Number(productId));
  const name = product ? product.name : 'this item';
  // Past orders keep the item name and totals, so history stays intact.
  if (!window.confirm(`Remove "${name}" from the menu? Orders that already include it keep their records.`)) return;

  try {
    await api('admin-products.php', { method: 'DELETE', body: JSON.stringify({ id: Number(productId) }) });
    await refreshAll();
    toast('Menu item removed.');
  } catch (error) {
    if (isAuthError(error)) return showLoginScreen('Your session ended. Please sign in again.');
    toast(error.message, 'error');
  }
}

/* ═══════════════════════════ 10. Customers ═══════════════════════════ */

function renderCustomers() {
  if (!state.customers.length) {
    $('customers-table').innerHTML = '<p class="table-muted">No registered customers yet.</p>';
    return;
  }

  $('customers-table').innerHTML = `
    <table>
      <thead><tr><th>Customer</th><th>Email</th><th>Joined</th><th>Orders</th><th>Ordered</th><th>Last order</th><th></th></tr></thead>
      <tbody>
        ${state.customers.map((customer) => `
          <tr>
            <td><b>${esc(customer.full_name)}</b></td>
            <td>${esc(customer.email)}</td>
            <td>${esc(customer.created_at)}</td>
            <td>${Number(customer.order_count)}</td>
            <td>${money(customer.ordered_total)}</td>
            <td>${esc(customer.last_order_at || '—')}</td>
            <td><button class="table-action" data-action="customer-orders" data-email="${esc(customer.email)}" type="button">View orders</button></td>
          </tr>`).join('')}
      </tbody>
    </table>`;
}

/** Jumps to the Orders section showing only this customer's orders. */
function showCustomerOrders(email) {
  showSection('orders');
  $('order-search').value = email;
  $('order-filter').value = 'All';
  $('payment-filter').value = 'All';
  renderOrders();
}

/* ═══════════════════════════ 11. Modals ═══════════════════════════ */

function closeModal(id) {
  $(id).hidden = true;
}

function closeAllModals() {
  ['order-modal', 'product-modal'].forEach((id) => { if (!$(id).hidden) $(id).hidden = true; });
}

/* ═══════════════════════════ 12. Events ═══════════════════════════ */

const actions = {
  'view-order': (button) => viewOrder(Number(button.dataset.order)),
  'save-status': (button) => saveOrderStatus(Number(button.dataset.order)),
  'payment-status': (button) => setPaymentStatus(Number(button.dataset.order), button.dataset.status),
  'edit-product': (button) => openProductModal(
    state.products.find((product) => Number(product.id) === Number(button.dataset.product))
  ),
  'toggle-product': (button) => toggleProduct(button.dataset.product, button.dataset.available),
  'delete-product': (button) => deleteProduct(button.dataset.product),
  'customer-orders': (button) => showCustomerOrders(button.dataset.email),
};

document.addEventListener('click', (event) => {
  const sectionButton = event.target.closest('[data-section]');
  if (sectionButton) {
    showSection(sectionButton.dataset.section);
    return;
  }

  const gotoButton = event.target.closest('[data-goto]');
  if (gotoButton) {
    showSection(gotoButton.dataset.goto);
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
  }
});

document.addEventListener('submit', (event) => {
  if (event.target.id === 'login-form') {
    event.preventDefault();
    signIn(event.target);
  }
  if (event.target.id === 'product-form') {
    event.preventDefault();
    saveProduct(event.target);
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeAllModals();
});

document.addEventListener('input', (event) => {
  if (event.target.id === 'order-search') renderOrders();
  if (event.target.id === 'product-search') renderProducts();
});
document.addEventListener('change', (event) => {
  if (event.target.id === 'order-filter' || event.target.id === 'payment-filter') renderOrders();
});

$('refresh').addEventListener('click', refreshAll);
$('add-product-open').addEventListener('click', () => openProductModal(null));
$('logout').addEventListener('click', signOut);

/* ═══════════════════════════ 13. Sign in / out ═══════════════════════════ */

async function signIn(form) {
  $('login-message').textContent = '';
  try {
    const data = await api('admin-login.php', {
      method: 'POST',
      body: JSON.stringify({ email: $('email').value, password: $('password').value }),
    });
    state.user = data.user;
    form.reset();
    showApp();
    await refreshAll();
  } catch (error) {
    $('login-message').textContent = error.message;
  }
}

async function signOut() {
  try {
    await api('admin-logout.php', { method: 'POST', body: '{}' });
  } catch {
    /* signing out locally is still correct even if the request failed */
  }
  showLoginScreen('Signed out.');
}

/* ═══════════════════════════ 14. Boot ═══════════════════════════ */

(async function boot() {
  try {
    const me = await api('admin-me.php');
    state.user = me.user;
    showApp();
    await refreshAll();
  } catch (error) {
    // Not signed in (401) or not an administrator (403) → sign-in screen.
    showLoginScreen(isAuthError(error) && error.status === 403 ? error.message : '');
  }
})();
