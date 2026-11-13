/* Click Hive Leads: save stage, checkboxes and notes straight from the list. */
(function () {
	'use strict';
	var table = document.querySelector('.wp-list-table');
	if (!table || !window.CHL) {
		return;
	}

	// Many columns can be wider than the screen: scroll the table sideways
	// instead of letting it run past the edge.
	if (!table.parentNode.classList.contains('chl-scroll')) {
		var wrap = document.createElement('div');
		wrap.className = 'chl-scroll';
		table.parentNode.insertBefore(wrap, table);
		wrap.appendChild(table);
	}

	function flash(el, cls) {
		el.classList.add(cls);
		setTimeout(function () { el.classList.remove(cls); }, 1500);
	}

	function save(lead, field, value) {
		var body = new FormData();
		body.append('action', 'chl_inline');
		body.append('nonce', CHL.nonce);
		body.append('lead', lead);
		body.append('field', field);
		body.append('value', value);
		return fetch(CHL.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					throw new Error('not saved');
				}
				return res.data || {};
			});
	}

	table.querySelectorAll('select.chl-inline').forEach(function (el) {
		el.dataset.saved = el.value;
	});

	table.addEventListener('change', function (e) {
		var el = e.target;
		if (!el.classList.contains('chl-inline')) {
			return;
		}
		if (el.type === 'checkbox') {
			var on = el.checked;
			save(el.dataset.lead, el.dataset.field, on ? '1' : '0')
				.then(function () { flash(el, 'is-saved'); })
				.catch(function () { el.checked = !on; flash(el, 'is-error'); });
		} else if (el.tagName === 'SELECT') {
			save(el.dataset.lead, el.dataset.field, el.value)
				.then(function () { el.dataset.saved = el.value; flash(el, 'is-saved'); })
				.catch(function () { el.value = el.dataset.saved; flash(el, 'is-error'); });
		}
	});

	function addNote(button) {
		var cell = button.closest('td');
		var input = cell.querySelector('.chl-note-input');
		var text = input.value.trim();
		if (!text) {
			return;
		}
		button.disabled = true;
		save(button.dataset.lead, 'note', text)
			.then(function (data) {
				cell.querySelector('.chl-latest-note').innerHTML = data.html || '';
				input.value = '';
				flash(input, 'is-saved');
			})
			.catch(function () { flash(input, 'is-error'); })
			.then(function () { button.disabled = false; });
	}

	table.addEventListener('click', function (e) {
		var button = e.target.closest('.chl-note-save');
		if (button) {
			e.preventDefault();
			addNote(button);
		}
	});

	// Enter adds the note; Shift+Enter starts a new line.
	table.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && e.target.classList.contains('chl-note-input')) {
			e.preventDefault();
			addNote(e.target.closest('td').querySelector('.chl-note-save'));
		}
	});
}());
