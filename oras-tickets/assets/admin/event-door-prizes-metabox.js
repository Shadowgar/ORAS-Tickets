(function () {
	'use strict';

	function toArray(nodeList) {
		return Array.prototype.slice.call(nodeList || []);
	}

	function nextIndex(tbody) {
		var max = -1;
		toArray(tbody.querySelectorAll('tr.oras-door-prize-row')).forEach(function (row) {
			var idx = Number.parseInt(row.getAttribute('data-index') || '-1', 10);
			if (!Number.isNaN(idx) && idx > max) {
				max = idx;
			}
		});
		return max + 1;
	}

	function addRow(root) {
		var table = root.querySelector('#oras-door-prizes-table');
		var template = root.querySelector('#oras-door-prize-template');
		if (!table || !table.tBodies.length || !template) {
			return;
		}

		var tbody = table.tBodies[0];
		var html = template.innerHTML;
		var idx = String(nextIndex(tbody));
		html = html.replaceAll('__INDEX__', idx);
		tbody.insertAdjacentHTML('beforeend', html);
	}

	function init() {
		var roots = toArray(document.querySelectorAll('#oras-door-prizes-metabox'));
		if (roots.length === 0) {
			return;
		}

		roots.forEach(function (root) {
			root.addEventListener('click', function (event) {
				var addButton = event.target.closest('#oras-door-prize-add');
				if (addButton) {
					event.preventDefault();
					addRow(root);
					return;
				}

				var removeButton = event.target.closest('.oras-door-prize-remove');
				if (removeButton) {
					event.preventDefault();
					var row = removeButton.closest('tr.oras-door-prize-row');
					if (row) {
						row.remove();
					}
				}
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
