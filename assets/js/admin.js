/**
 * EasyBusy Connect — admin behaviour.
 *
 * Progressive: the tabs are real links with `?tab=…`, so the screen works with
 * JavaScript off; this file only makes switching instant and lets a placeholder
 * chip be inserted at the caret of the last focused template field.
 */
(function () {
	'use strict';

	function activateTab(root, slug, push) {
		var tabs = root.querySelectorAll('[data-ebc-tab]');
		var panels = root.querySelectorAll('[data-ebc-panel]');
		var found = false;

		panels.forEach(function (panel) {
			var active = panel.getAttribute('data-ebc-panel') === slug;
			panel.classList.toggle('is-active', active);
			found = found || active;
		});

		if (!found) {
			return false;
		}

		tabs.forEach(function (tab) {
			var active = tab.getAttribute('data-ebc-tab') === slug;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});

		var hidden = root.querySelector('input[name="tab"]');
		if (hidden) {
			hidden.value = slug;
		}

		if (push && window.history && window.history.replaceState) {
			var url = new URL(window.location.href);
			url.searchParams.set('tab', slug);
			url.searchParams.delete('ebc-flash');
			window.history.replaceState({}, '', url.toString());
		}

		return true;
	}

	function initTabs(root) {
		root.querySelectorAll('[data-ebc-tab]').forEach(function (tab) {
			tab.addEventListener('click', function (event) {
				var slug = tab.getAttribute('data-ebc-tab');
				if (activateTab(root, slug, true)) {
					event.preventDefault();
					root.scrollIntoView({ block: 'start', behavior: 'smooth' });
				}
			});
		});
	}

	/** Remember which template field the editor touched last. */
	function initPlaceholders(root) {
		var target = null;

		root.querySelectorAll('input.ebc-input, textarea.ebc-input').forEach(function (field) {
			field.addEventListener('focus', function () {
				target = field;
			});
		});

		root.querySelectorAll('[data-ebc-insert]').forEach(function (button) {
			button.addEventListener('click', function () {
				var token = button.getAttribute('data-ebc-insert');
				var field = target || root.querySelector('textarea.ebc-input');
				if (!field) {
					return;
				}

				var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
				var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;
				field.value = field.value.slice(0, start) + token + field.value.slice(end);
				field.focus();
				var caret = start + token.length;
				if (field.setSelectionRange) {
					field.setSelectionRange(caret, caret);
				}
			});
		});
	}

	/**
	 * Entries: expand a row to show the technical detail strip. The strip ships
	 * in the markup with `hidden`, so no request is needed to read it.
	 */
	function initRows(root) {
		root.querySelectorAll('[data-ebc-toggle]').forEach(function (button) {
			button.addEventListener('click', function () {
				var detail = document.getElementById('ebc-detail-' + button.getAttribute('data-ebc-toggle'));
				if (!detail) {
					return;
				}

				var open = button.getAttribute('aria-expanded') === 'true';
				button.setAttribute('aria-expanded', open ? 'false' : 'true');
				detail.hidden = open;
			});
		});
	}

	/** Destructive links ask first; without JS the red styling is the warning. */
	function initConfirms(root) {
		root.querySelectorAll('[data-ebc-confirm]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				if (!window.confirm(link.getAttribute('data-ebc-confirm'))) {
					event.preventDefault();
				}
			});
		});
	}

	function init() {
		var root = document.querySelector('.ebc-admin');
		if (!root) {
			return;
		}
		initTabs(root);
		initPlaceholders(root);
		initRows(root);
		initConfirms(root);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
