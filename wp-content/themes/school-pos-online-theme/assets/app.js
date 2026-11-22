(function () {
	'use strict';

	var app = document.querySelector('.pos-app');
	if (!app) return;
	var root = window.SchoolPOS.root;
	var nonce = window.SchoolPOS.nonce;
	var role = app.dataset.role;
	var cart = [];

	function escapeHtml(value) {
		var node = document.createElement('span');
		node.textContent = value == null ? '' : String(value);
		return node.innerHTML;
	}

	function naira(kobo) {
		var amount = (Number(kobo || 0) / 100).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
		return '₦' + amount;
	}

	function notice(message, error) {
		var box = document.getElementById('pos-notice');
		box.textContent = message;
		box.className = 'pos-notice' + (error ? ' is-error' : '');
		box.hidden = false;
	}

	async function api(path, options) {
		options = options || {};
		options.headers = Object.assign({ 'X-WP-Nonce': nonce }, options.headers || {});
		if (options.body && typeof options.body !== 'string') {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(options.body);
		}
		var response = await fetch(root + path.replace(/^\/+/, ''), options);
		var data = await response.json().catch(function () { return {}; });
		if (!response.ok) throw new Error(data.message || 'Request failed');
		return data;
	}

	async function loadDashboard() {
		try {
			var data = await api('dashboard');
			var counts = data.counts;
			var sales = data.sales;
			var stats = [
				['Sales today', naira(sales.today_kobo)],
				['Sales — 7 days', naira(sales.seven_days_kobo)],
				['Sales — 30 days', naira(sales.thirty_days_kobo)],
				['Warehouse stock', (data.stock ? data.stock.warehouse_base_units : 0) + ' units'],
				['Storefront stock', (data.stock ? data.stock.storefront_base_units : 0) + ' units']
			];
			document.getElementById('pos-dashboard').innerHTML = stats.map(function (item) {
				return '<article class="pos-stat"><span>' + escapeHtml(item[0]) + '</span><strong>' + escapeHtml(item[1]) + '</strong></article>';
			}).join('');
		} catch (error) {
			notice(error.message, true);
		}
	}

	function status(value) {
		var key = String(value).toLowerCase();
		return '<span class="pos-status pos-status-' + escapeHtml(key) + '">' + escapeHtml(value) + '</span>';
	}

	async function renderIntakes() {
		var view = document.getElementById('pos-view');
		view.innerHTML = '<div class="pos-layout"><aside class="pos-card" id="intake-form"></aside><section class="pos-card"><h2>Intake records</h2><div id="intake-list" class="pos-table-wrap">Loading...</div></section></div>';
		try {
			var products = await api('products');
			var options = products.map(function (p) {
				return '<option value="' + p.product_id + '">' + escapeHtml(p.name) + ' — ' + p.units_per_pack + ' ' + escapeHtml(p.base_unit_label) + '/' + escapeHtml(p.bulk_unit_label) + '</option>';
			}).join('');
			document.getElementById('intake-form').innerHTML =
				'<h2>Log procurement intake</h2>' +
				(role === 'administrator' ? '<div class="pos-admin-note">Super Admin may manage master products below. Use the product form for stock setup.</div><h3>Add master product</h3><form id="product-form"><div class="pos-field"><label>Product name</label><input name="name" required></div><div class="pos-field-row"><div class="pos-field"><label>SKU</label><input name="sku"></div><div class="pos-field"><label>Category</label><select name="category"><option value="textbooks">Textbooks</option><option value="stationeries">Stationeries</option><option value="uniforms">Uniforms</option><option value="science_lab">Science &amp; Lab Supplies</option><option value="general">General</option></select></div></div><div class="pos-field-row"><div class="pos-field"><label>Pack/Box label</label><input name="bulk_unit_label" value="Pack"></div><div class="pos-field"><label>Base unit label</label><input name="base_unit_label" value="Piece"></div></div><div class="pos-field-row"><div class="pos-field"><label>Units per pack</label><input name="units_per_pack" type="number" min="1" value="1" required></div><div class="pos-field"><label>Low-stock threshold</label><input name="low_stock_threshold" type="number" min="0" value="0"></div></div><div class="pos-field-row"><div class="pos-field"><label>Pack/Box price (₦)</label><input name="pack_price_naira" type="number" min="0" step="0.01"></div><div class="pos-field"><label>Piece price (₦)</label><input name="piece_price_naira" type="number" min="0" step="0.01"></div></div><button class="pos-button" type="submit">Add product</button></form><hr>' : '') +
				'<h3>Submit intake</h3><form id="intake-form-element">' +
				'<div class="pos-field"><label>Product</label><select name="product_id" required><option value="">Select product</option>' + options + '</select></div>' +
				'<div class="pos-field-row"><div class="pos-field"><label>Supplier</label><input name="supplier_name"></div><div class="pos-field"><label>Waybill reference</label><input name="waybill_ref"></div></div>' +
				'<div class="pos-field-row"><div class="pos-field"><label>Quantity packs/boxes</label><input name="quantity_packs" type="number" min="1" value="1" required></div><div class="pos-field"><label>Unit cost (₦)</label><input name="unit_cost_naira" type="number" min="0" step="0.01"></div></div>' +
				'<button class="pos-button" type="submit">Submit for warehouse</button></form>';

			var productForm = document.getElementById('product-form');
			if (productForm) productForm.addEventListener('submit', async function (event) {
				event.preventDefault();
				var data = Object.fromEntries(new FormData(productForm));
				data.units_per_pack = Number(data.units_per_pack);
				data.pack_price_naira = Number(data.pack_price_naira || 0);
				data.piece_price_naira = Number(data.piece_price_naira || 0);
				data.low_stock_threshold = Number(data.low_stock_threshold || 0);
				try { await api('products', { method: 'POST', body: data }); notice('Master product created'); renderIntakes(); } catch (error) { notice(error.message, true); }
			});
			document.getElementById('intake-form-element').addEventListener('submit', async function (event) {
				event.preventDefault();
				var data = Object.fromEntries(new FormData(event.currentTarget));
				data.product_id = Number(data.product_id);
				data.quantity_packs = Number(data.quantity_packs);
				data.unit_cost_naira = Number(data.unit_cost_naira || 0);
				try { var created = await api('intakes', { method: 'POST', body: data }); notice('Intake submitted: ' + created.intake_code); renderIntakes(); } catch (error) { notice(error.message, true); }
			});
			await refreshIntakes();
		} catch (error) {
			view.innerHTML = '<div class="pos-card">' + escapeHtml(error.message) + '</div>';
		}
	}

	async function refreshIntakes() {
		var rows = await api('intakes');
		document.getElementById('intake-list').innerHTML = rows.length ? '<table class="pos-table"><thead><tr><th>Reference</th><th>Product</th><th>Received</th><th>Status</th></tr></thead><tbody>' + rows.map(function (row) {
			return '<tr><td>' + escapeHtml(row.intake_code) + '</td><td>' + escapeHtml(row.product_name) + '</td><td>' + row.quantity_packs + ' × ' + row.units_per_pack_snapshot + '</td><td>' + status(row.status) + '</td></tr>';
		}).join('') + '</tbody></table>' : '<div class="pos-empty">No intake records</div>';
	}

	async function renderWarehouse() {
		var view = document.getElementById('pos-view');
		view.innerHTML = '<div class="pos-layout"><section class="pos-card"><div class="pos-section-heading"><div><span class="pos-eyebrow">Warehouse inventory</span><h2>Incoming handovers</h2></div></div><div class="pos-field"><label for="warehouse-search">Search all warehouse products</label><input id="warehouse-search" type="search" placeholder="Type a product name or reference"></div><div id="warehouse-intakes" class="pos-table-wrap">Loading...</div><hr><h3>Current warehouse stock</h3><div id="warehouse-stock" class="pos-table-wrap">Loading...</div></section><aside class="pos-card"><h2>Dispatch to store</h2><form id="dispatch-form"><div class="pos-field"><label>Search product</label><input id="warehouse-product-search" list="warehouse-product-list" placeholder="Type product name" autocomplete="off"><datalist id="warehouse-product-list"></datalist><input type="hidden" name="product_id" required></div><div class="pos-field"><label>Base units to transfer</label><input name="quantity_base_units" type="number" min="1" required></div><button class="pos-button" type="submit">Create store transfer</button></form><hr><h3>Store transfer records</h3><div id="store-transfers" class="pos-table-wrap"></div></aside></div>';
		try {
			var products = await api('products');
			var datalist = document.getElementById('warehouse-product-list');
			datalist.innerHTML = products.map(function (p) { return '<option value="' + escapeHtml(p.name) + '" data-product-id="' + p.product_id + '"></option>'; }).join('');
			var searchInput = document.getElementById('warehouse-product-search');
			searchInput.addEventListener('change', function () {
				var match = products.find(function (p) { return p.name === searchInput.value; });
				document.querySelector('#dispatch-form input[name="product_id"]').value = match ? match.product_id : '';
			});
			document.getElementById('dispatch-form').addEventListener('submit', async function (event) {
				event.preventDefault();
				var data = Object.fromEntries(new FormData(event.currentTarget));
				data.product_id = Number(data.product_id);
				data.quantity_base_units = Number(data.quantity_base_units);
				try { var transfer = await api('handovers', { method: 'POST', body: data }); notice('Transfer created: ' + transfer.handover_code); renderWarehouse(); } catch (error) { notice(error.message, true); }
			});
			var intakes = await api('intakes');
			document.getElementById('warehouse-intakes').innerHTML = intakes.length ? '<table class="pos-table"><thead><tr><th>Reference</th><th>Product</th><th>Base units</th><th>Status</th><th></th></tr></thead><tbody>' + intakes.map(function (row) {
				var actions = row.status === 'PENDING_WAREHOUSE_HANDOVER' ? '<button class="pos-button pos-button-small" data-accept-intake="' + row.intake_id + '">Accept</button> <button class="pos-button pos-button-small pos-button-danger" data-discrepancy="' + row.intake_id + '">Flag</button>' : '';
				return '<tr><td>' + escapeHtml(row.intake_code) + '</td><td>' + escapeHtml(row.product_name) + '</td><td>' + row.quantity_base_units + '</td><td>' + status(row.status) + '</td><td>' + actions + '</td></tr>';
			}).join('') + '</tbody></table>' : '<div class="pos-empty">No incoming records</div>';
			var stock = await api('balances');
			var renderStock = function (query) {
				var filtered = stock.filter(function (row) {
					return String(row.name).toLowerCase().indexOf(String(query).toLowerCase()) !== -1;
				});
				document.getElementById('warehouse-stock').innerHTML = filtered.length ? '<table class="pos-table"><thead><tr><th>Product</th><th>Bulk</th><th>Total pieces</th></tr></thead><tbody>' + filtered.map(function (row) {
					var packs = Math.floor(row.quantity_base_units / row.units_per_pack);
					var pieces = row.quantity_base_units % row.units_per_pack;
					return '<tr><td><strong>' + escapeHtml(row.name) + '</strong></td><td>' + packs + ' ' + escapeHtml(row.bulk_unit_label) + '</td><td>' + row.quantity_base_units + ' ' + escapeHtml(row.base_unit_label) + ' (' + pieces + ' loose)</td></tr>';
				}).join('') + '</tbody></table>' : '<div class="pos-empty">No warehouse products match</div>';
			};
			renderStock('');
			document.getElementById('warehouse-search').addEventListener('input', function (event) {
				renderStock(event.currentTarget.value);
			});
			document.querySelectorAll('[data-accept-intake]').forEach(function (button) {
				button.addEventListener('click', async function () {
					try { await api('intakes/' + button.dataset.acceptIntake + '/accept', { method: 'POST' }); notice('Warehouse handover accepted'); renderWarehouse(); } catch (error) { notice(error.message, true); }
				});
			});
			document.querySelectorAll('[data-discrepancy]').forEach(function (button) {
				button.addEventListener('click', async function () {
					var note = prompt('Describe the discrepancy');
					if (note === null) return;
					try { await api('intakes/' + button.dataset.discrepancy + '/discrepancy', { method: 'POST', body: { note: note } }); notice('Discrepancy recorded'); renderWarehouse(); } catch (error) { notice(error.message, true); }
				});
			});
			var transfers = await api('handovers');
			document.getElementById('store-transfers').innerHTML = transfers.length ? '<table class="pos-table"><thead><tr><th>Reference</th><th>Product</th><th>Units</th><th>Status</th></tr></thead><tbody>' + transfers.map(function (row) {
				var action = row.status === 'PENDING' && (role === 'store_seller' || role === 'administrator') ? '<button class="pos-button pos-button-small" data-accept-handover="' + row.handover_id + '">Accept</button>' : status(row.status);
				return '<tr><td>' + escapeHtml(row.handover_code) + '</td><td>' + escapeHtml(row.product_name) + '</td><td>' + row.quantity_base_units + '</td><td>' + action + '</td></tr>';
			}).join('') + '</tbody></table>' : '<div class="pos-empty">No store transfers</div>';
			document.querySelectorAll('[data-accept-handover]').forEach(function (button) {
				button.addEventListener('click', async function () {
					try { await api('handovers/' + button.dataset.acceptHandover + '/accept', { method: 'POST' }); notice('Storefront handover accepted'); renderWarehouse(); } catch (error) { notice(error.message, true); }
				});
			});
		} catch (error) { notice(error.message, true); }
	}

	async function renderBursar() {
		document.getElementById('pos-view').innerHTML = '<div class="pos-layout"><aside class="pos-card"><h2>Issue bursar voucher</h2><form id="voucher-form"><div class="pos-field"><label>Student full name</label><input name="student_full_name" required></div><div class="pos-field-row"><div class="pos-field"><label>Admission number</label><input name="admission_number" required></div><div class="pos-field"><label>Class/grade</label><input name="class_grade" required></div></div><div class="pos-field-row"><div class="pos-field"><label>Amount paid (₦)</label><input name="amount_paid_naira" type="number" min="0.01" step="0.01" required></div><div class="pos-field"><label>Payment method</label><select name="payment_method"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="teller">Teller</option></select></div></div><div class="pos-field"><label>Teller/reference number</label><input name="teller_ref_no"></div><button class="pos-button" type="submit">Generate voucher</button></form></aside><section class="pos-card"><h2>Latest voucher</h2><div id="voucher-result" class="pos-empty">No voucher issued in this session</div></section></div>';
		document.getElementById('voucher-form').addEventListener('submit', async function (event) {
			event.preventDefault();
			var data = Object.fromEntries(new FormData(event.currentTarget));
			data.amount_paid_naira = Number(data.amount_paid_naira);
			try {
				var voucher = await api('vouchers', { method: 'POST', body: data });
				document.getElementById('voucher-result').innerHTML = '<div class="pos-voucher-result"><strong>' + escapeHtml(voucher.voucher_code) + '</strong><div>' + escapeHtml(voucher.student_full_name) + ' · ' + escapeHtml(voucher.admission_number) + '</div><div>' + naira(voucher.amount_paid_kobo) + '</div><a class="pos-button pos-button-small no-print" target="_blank" href="' + escapeHtml(window.location.pathname + '?school_pos_print=voucher&id=' + voucher.voucher_id) + '">Print 80mm voucher</a></div>';
				notice('Voucher issued: ' + voucher.voucher_code);
				loadDashboard();
			} catch (error) { notice(error.message, true); }
		});
	}

	async function renderPos() {
		cart = [];
		document.getElementById('pos-view').innerHTML = '<div class="pos-layout"><aside class="pos-card"><h2>Voucher remittance</h2><form id="lookup-form"><div class="pos-field"><label>Voucher code</label><input name="code" autocomplete="off" required></div><button class="pos-button" type="submit">Verify voucher</button></form><div id="voucher-result"></div><hr><h3>Add sale item</h3><form id="cart-form"><div class="pos-field"><label>Search product</label><input id="cart-product-search" list="cart-product-list" placeholder="Start typing a product name" autocomplete="off" required><datalist id="cart-product-list"></datalist><input type="hidden" name="product_id" required></div><div class="pos-field-row"><div class="pos-field"><label>Unit type</label><select name="unit_type"><option value="PIECE">Piece</option><option value="PACK">Pack/Box</option></select></div><div class="pos-field"><label>Quantity</label><input name="quantity" type="number" min="1" value="1" required></div></div><button class="pos-button pos-button-secondary" type="submit">Add to cart</button></form></aside><section class="pos-card"><h2>POS cart</h2><div id="cart-lines" class="pos-cart"><div class="pos-empty">Cart is empty</div></div><div class="pos-cart-total"><span>Grand Total</span><span id="cart-total">₦0.00</span></div><button id="checkout-button" class="pos-button" type="button" disabled>Complete &amp; print</button></section></div>';
		try {
			var products = await api('products');
			window.SchoolPOS.products = products;
			document.getElementById('cart-product-list').innerHTML = products.map(function (p) { return '<option value="' + escapeHtml(p.name) + '" data-product-id="' + p.product_id + '"></option>'; }).join('');
			document.getElementById('cart-product-search').addEventListener('change', function (event) {
				var match = products.find(function (p) { return p.name === event.currentTarget.value; });
				document.querySelector('#cart-form input[name="product_id"]').value = match ? match.product_id : '';
			});
			document.getElementById('lookup-form').addEventListener('submit', async function (event) {
				event.preventDefault();
				var code = new FormData(event.currentTarget).get('code');
				try {
					var voucher = await api('vouchers/lookup?code=' + encodeURIComponent(code));
					window.SchoolPOS.verifiedVoucher = voucher;
						var remaining = typeof voucher.remaining_kobo !== 'undefined' ? Number(voucher.remaining_kobo) : (voucher.status === 'REDEEMED' ? 0 : Number(voucher.amount_paid_kobo));
						document.getElementById('voucher-result').innerHTML = '<div class="pos-voucher-result"><strong>' + escapeHtml(voucher.status) + '</strong><div>' + escapeHtml(voucher.student_full_name) + '</div><div>' + escapeHtml(voucher.admission_number) + ' · ' + escapeHtml(voucher.class_grade) + '</div><div>Original: ' + naira(voucher.amount_paid_kobo) + '</div><div><strong>Remaining: ' + naira(remaining) + '</strong></div></div>';
				} catch (error) { window.SchoolPOS.verifiedVoucher = null; notice(error.message, true); }
			});
			document.getElementById('cart-form').addEventListener('submit', function (event) {
				event.preventDefault();
				var data = Object.fromEntries(new FormData(event.currentTarget));
				var product = products.find(function (p) { return String(p.product_id) === String(data.product_id); });
				if (!product) { notice('Select a product', true); return; }
				var quantity = Number(data.quantity);
				var unitPrice = data.unit_type === 'PACK' ? Number(product.pack_price_kobo) : Number(product.piece_price_kobo);
				if (!quantity || unitPrice < 1) { notice('Invalid quantity or price', true); return; }
				cart.push({ product_id: Number(data.product_id), name: product.name, unit_type: data.unit_type, quantity: quantity, unit_price: unitPrice, base_units: data.unit_type === 'PACK' ? quantity * Number(product.units_per_pack) : quantity });
				renderCart();
			});
			document.getElementById('checkout-button').addEventListener('click', async function () {
				if (!window.SchoolPOS.verifiedVoucher) { notice('Verify a voucher first', true); return; }
				var voucher = window.SchoolPOS.verifiedVoucher;
				var total = cart.reduce(function (sum, item) { return sum + item.quantity * item.unit_price; }, 0);
				if (cart.length === 0) { notice('Cart is empty', true); return; }
				var voucherRemaining = typeof voucher.remaining_kobo !== 'undefined' ? Number(voucher.remaining_kobo) : (voucher.status === 'REDEEMED' ? 0 : Number(voucher.amount_paid_kobo));
				if (voucherRemaining < total) { notice('Voucher shortfall: ' + naira(total - voucherRemaining), true); return; }
				try {
					var sale = await api('sales', { method: 'POST', body: { voucher_code: voucher.voucher_code, lines: cart.map(function (item) { return { product_id: item.product_id, quantity: item.quantity, unit_type: item.unit_type }; }) } });
					notice('Sale completed: ' + sale.receipt_no);
					window.open(window.location.pathname + '?school_pos_print=sale&id=' + sale.sale_id, '_blank');
					cart = [];
					renderCart();
					loadDashboard();
				} catch (error) { notice(error.message, true); }
			});
		} catch (error) { notice(error.message, true); }
	}

	function renderCart() {
		var container = document.getElementById('cart-lines');
		if (!cart.length) container.innerHTML = '<div class="pos-empty">Cart is empty</div>'; else container.innerHTML = cart.map(function (item, index) {
			return '<div class="pos-cart-line"><div><strong>' + escapeHtml(item.name) + '</strong><br><small>' + item.quantity + ' × ' + escapeHtml(item.unit_type) + ' · ' + naira(item.unit_price) + '</small></div><strong>' + naira(item.quantity * item.unit_price) + '</strong><button class="pos-button pos-button-secondary pos-button-small" type="button" data-remove-cart="' + index + '">Remove</button></div>';
		}).join('');
		var total = cart.reduce(function (sum, item) { return sum + item.quantity * item.unit_price; }, 0);
		document.getElementById('cart-total').textContent = naira(total);
		document.getElementById('checkout-button').disabled = cart.length === 0;
		document.querySelectorAll('[data-remove-cart]').forEach(function (button) {
			button.addEventListener('click', function () { cart.splice(Number(button.dataset.removeCart), 1); renderCart(); });
		});
	}

	async function renderReceiving() {
		var view = document.getElementById('pos-view');
		view.innerHTML = '<section class="pos-card"><div class="pos-section-heading"><div><span class="pos-eyebrow">Stock custody</span><h2>Receive warehouse transfers</h2></div><button class="pos-button pos-button-secondary" type="button" id="refresh-receiving">Refresh</button></div><div id="receiving-list" class="pos-table-wrap">Loading...</div></section>';
		async function refreshReceiving() {
			try {
				var transfers = await api('handovers');
				var pending = transfers.filter(function (row) { return row.status === 'PENDING'; });
				var all = transfers;
				view.querySelector('#receiving-list').innerHTML =
					(pending.length ? '<div class="pos-alert pos-alert-info"><strong>' + pending.length + ' pending transfer' + (pending.length === 1 ? '' : 's') + '</strong> Confirm receipt only after checking the physical goods.</div>' : '') +
					(all.length ? '<table class="pos-table"><thead><tr><th>Transfer</th><th>Product</th><th>Quantity</th><th>Status</th><th>Action</th></tr></thead><tbody>' + all.map(function (row) {
						var action = row.status === 'PENDING'
							? '<button class="pos-button pos-button-small" type="button" data-receive-transfer="' + row.handover_id + '">Receive &amp; stock</button>'
							: '<span class="pos-status pos-status-accepted">Received</span>';
						return '<tr><td><strong>' + escapeHtml(row.handover_code) + '</strong></td><td>' + escapeHtml(row.product_name) + '</td><td>' + row.quantity_base_units + ' base units</td><td>' + status(row.status) + '</td><td>' + action + '</td></tr>';
					}).join('') + '</tbody></table>' : '<div class="pos-empty">No warehouse transfers</div>');
				view.querySelectorAll('[data-receive-transfer]').forEach(function (button) {
					button.addEventListener('click', async function () {
						button.disabled = true;
						button.textContent = 'Receiving...';
						try {
							var result = await api('handovers/' + button.dataset.receiveTransfer + '/accept', { method: 'POST' });
							notice('Transfer received. ' + result.storefront_balance + ' base units now in storefront stock.');
							await refreshReceiving();
							loadDashboard();
						} catch (error) {
							button.disabled = false;
							button.textContent = 'Receive & stock';
							notice(error.message, true);
						}
					});
				});
			} catch (error) {
				view.querySelector('#receiving-list').innerHTML = '<div class="pos-empty">' + escapeHtml(error.message) + '</div>';
			}
		}
		view.querySelector('#refresh-receiving').addEventListener('click', refreshReceiving);
		await refreshReceiving();
	}

	async function renderAudit() {
		var view = document.getElementById('pos-view');
		view.innerHTML = '<section class="pos-card"><h2>Immutable audit ledger</h2><div id="audit-list" class="pos-table-wrap">Loading...</div></section>';
		try {
			var rows = await api('audit');
			view.querySelector('#audit-list').innerHTML = rows.length ? '<table class="pos-table"><thead><tr><th>ID</th><th>Event</th><th>Reference</th><th>IP</th><th>Time</th></tr></thead><tbody>' + rows.map(function (row) {
				return '<tr><td>' + row.event_id + '</td><td>' + escapeHtml(row.event_type) + '</td><td>' + escapeHtml(row.reference_code) + '</td><td>' + escapeHtml(row.ip_address) + '</td><td>' + escapeHtml(row.created_at) + '</td></tr>';
			}).join('') + '</tbody></table>' : '<div class="pos-empty">No audit records</div>';
		} catch (error) { notice(error.message, true); }
	}

	async function renderCatalog() {
		var view = document.getElementById('pos-view');
		view.innerHTML = '<section class="pos-card"><div class="pos-section-heading"><div><span class="pos-eyebrow">Master inventory</span><h2>Product catalog</h2></div><input id="catalog-search" type="search" placeholder="Search products"></div><div id="catalog-list" class="pos-table-wrap">Loading...</div></section>';
		try {
			var products = await api('products');
			var render = function (query) {
				var filtered = products.filter(function (p) { return (p.name + ' ' + p.sku + ' ' + p.category).toLowerCase().indexOf(query.toLowerCase()) !== -1; });
				view.querySelector('#catalog-list').innerHTML = filtered.length ? '<table class="pos-table"><thead><tr><th>Product</th><th>SKU</th><th>Pack</th><th>Piece</th><th>Stock threshold</th><th></th></tr></thead><tbody>' + filtered.map(function (p) {
					var editAction = role === 'administrator' ? '<button class="pos-button pos-button-small" type="button" data-edit-product="' + p.product_id + '">Edit prices</button>' : '<span class="pos-status pos-status-accepted">View only</span>';
					return '<tr><td><strong>' + escapeHtml(p.name) + '</strong><br><small>' + escapeHtml(p.category) + '</small></td><td>' + escapeHtml(p.sku) + '</td><td>' + naira(p.pack_price_kobo) + '</td><td>' + naira(p.piece_price_kobo) + '</td><td>' + p.low_stock_threshold_base_units + '</td><td>' + editAction + '</td></tr>';
				}).join('') + '</tbody></table>' : '<div class="pos-empty">No catalog products match</div>';
				view.querySelectorAll('[data-edit-product]').forEach(function (button) {
					button.addEventListener('click', async function () {
						var product = products.find(function (p) { return String(p.product_id) === String(button.dataset.editProduct); });
						var pack = prompt('Pack/Box price in Naira', (Number(product.pack_price_kobo || 0) / 100).toFixed(2));
						if (pack === null) return;
						var piece = prompt('Piece price in Naira', (Number(product.piece_price_kobo || 0) / 100).toFixed(2));
						if (piece === null) return;
						try {
							await api('products/' + product.product_id, { method: 'PATCH', body: { pack_price_naira: Number(pack), piece_price_naira: Number(piece) } });
							notice('Catalog prices updated for ' + product.name);
							renderCatalog();
						} catch (error) { notice(error.message, true); }
					});
				});
			};
			render('');
			view.querySelector('#catalog-search').addEventListener('input', function (event) { render(event.currentTarget.value); });
		} catch (error) { notice(error.message, true); }
	}

	async function renderOverview() {
		var view = document.getElementById('pos-view');
		try {
			var balances = await api('balances');
			var dashboard = await api('dashboard');
			var html = '<div class="pos-layout pos-layout-overview"><section class="pos-card"><div class="pos-section-heading"><div><span class="pos-eyebrow">Inventory overview</span><h2>Stock on ground</h2></div></div><div class="pos-overview-totals"><article><span>Warehouse units</span><strong>' + dashboard.stock.warehouse_base_units + '</strong></article><article><span>Storefront units</span><strong>' + dashboard.stock.storefront_base_units + '</strong></article></div><div class="pos-table-wrap">' +
				(balances.length ? '<table class="pos-table"><thead><tr><th>Product</th><th>Bulk</th><th>Pieces</th><th>Status</th></tr></thead><tbody>' + balances.map(function (row) {
					var packs = Math.floor(row.quantity_base_units / row.units_per_pack);
					var pieces = row.quantity_base_units % row.units_per_pack;
					return '<tr><td>' + escapeHtml(row.name) + '</td><td>' + packs + ' ' + escapeHtml(row.bulk_unit_label) + '</td><td>' + row.quantity_base_units + ' ' + escapeHtml(row.base_unit_label) + '</td><td>' + (row.quantity_base_units <= row.low_stock_threshold_base_units && row.low_stock_threshold_base_units > 0 ? '<span class="pos-status pos-status-discrepancy">LOW</span>' : '<span class="pos-status pos-status-accepted">OK</span>') + '</td></tr>';
				}).join('') + '</tbody></table>' : '<div class="pos-empty">No stock records</div>') + '</div></section><aside class="pos-card"><h2>Workspace notice</h2><p>Stock changes, voucher remittance, and custody actions are recorded with IP and user metadata.</p><a class="pos-button pos-button-secondary" href="' + window.location.pathname + '">Reload workspace</a></aside></div>';
			html += '<section class="pos-card pos-sales-period"><h2>Sales period report</h2><div class="pos-field-row"><div class="pos-field"><label for="sales-from">From</label><input id="sales-from" type="date"></div><div class="pos-field"><label for="sales-to">To</label><input id="sales-to" type="date"></div></div><button class="pos-button" type="button" id="sales-period-button">Get sales</button><div id="sales-period-result" class="pos-period-result"></div></section>';
			view.innerHTML = html;
			document.getElementById('sales-period-button').addEventListener('click', async function () {
				var from = document.getElementById('sales-from').value;
				var to = document.getElementById('sales-to').value;
				if (!from || !to) { notice('Choose both dates', true); return; }
				try {
					var report = await api('dashboard?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to));
					document.getElementById('sales-period-result').innerHTML = '<span>Sales for ' + escapeHtml(from) + ' – ' + escapeHtml(to) + '</span><strong>' + naira(report.sales.custom_kobo) + '</strong>';
				} catch (error) { notice(error.message, true); }
			});
		} catch (error) { notice(error.message, true); }
	}

	function setView(name) {
		document.querySelectorAll('[data-view]').forEach(function (button) { button.classList.toggle('is-active', button.dataset.view === name); });
		if (name === 'intakes') return renderIntakes();
		if (name === 'warehouse') return renderWarehouse();
		if (name === 'bursar') return renderBursar();
		if (name === 'pos') return renderPos();
		if (name === 'receiving') return renderReceiving();
		if (name === 'audit') return renderAudit();
		if (name === 'catalog') return renderCatalog();
		return renderOverview();
	}

	document.querySelectorAll('[data-view]').forEach(function (button) {
		button.addEventListener('click', function () { setView(button.dataset.view); });
	});
	document.querySelector('[data-action="refresh"]').addEventListener('click', function () { loadDashboard(); setView(document.querySelector('[data-view].is-active').dataset.view); });
	loadDashboard();
	renderOverview();
}());
