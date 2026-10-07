/* Folderino: folder sidebar, drag and drop and media modal filter. */
(function ($, _, wp, RDB) {
	'use strict';

	if (!RDB || !wp || !wp.media || !wp.media.view) {
		return;
	}

	/* ---------- A. Helpers and AJAX ---------- */

	var i18n = RDB.i18n;
	var TREE = RDB.tree; // { folders: [{id, name, parent, order, count}], total, none }
	var state = {
		current: String(RDB.current || 'all'), // 'all', 'none' or a folder ID
		rev: 0,          // bumped after every move so wp.media skips its query cache
		browser: null,   // the Media Library grid (AttachmentsBrowser view)
		hover: null,     // sidebar row under the pointer while dragging
		msgTimer: null,
		collapsed: loadCollapsed()
	};
	RDB.uploadFolder = 0; // Folder that new uploads go into (0 = none)

	// Remember which folders are collapsed (per browser; safe if storage is blocked).
	function loadCollapsed() {
		try { return JSON.parse(window.localStorage.getItem('folderino_collapsed') || '{}') || {}; } catch (e) { return {}; }
	}
	function saveCollapsed() {
		try { window.localStorage.setItem('folderino_collapsed', JSON.stringify(state.collapsed)); } catch (e) {}
	}

	function findFolder(id) {
		id = parseInt(id, 10);
		return _.find(TREE.folders, function (f) { return f.id === id; });
	}
	function childrenOf(parentId) {
		parentId = parseInt(parentId, 10) || 0;
		return _.filter(TREE.folders, function (f) { return f.parent === parentId; });
	}
	function childIds(parentId) {
		return _.pluck(childrenOf(parentId), 'id');
	}
	// Is folder "id" somewhere inside folder "ancestorId"?
	function isDescendant(id, ancestorId) {
		var f = findFolder(id), guard = 0;
		ancestorId = parseInt(ancestorId, 10);
		while (f && f.parent && guard++ < 100) {
			if (f.parent === ancestorId) { return true; }
			f = findFolder(f.parent);
		}
		return false;
	}
	// Walk the tree top-down: cb(folder, depth).
	function walk(parentId, depth, cb) {
		_.each(childrenOf(parentId), function (f) {
			cb(f, depth);
			walk(f.id, depth + 1, cb);
		});
	}
	function isFolderId(value) {
		return /^\d+$/.test(String(value || ''));
	}

	// POST to admin-ajax with our nonce. Resolves with data, rejects with a message.
	function ajax(action, data) {
		return $.post(RDB.ajaxUrl, $.extend({ action: action, nonce: RDB.nonce }, data || {})).then(
			function (res) {
				if (res && res.success) { return res.data; }
				return $.Deferred().reject((res && res.data && res.data.message) || i18n.error).promise();
			},
			function (xhr) {
				var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || i18n.error;
				return $.Deferred().reject(msg).promise();
			}
		);
	}

	// Show a short message in the sidebar and read it out for screen readers.
	function notify(message, isError) {
		if (!message) { return; }
		var $msg = $('#folderino-sidebar .folderino-msg');
		if ($msg.length) {
			$msg.removeClass('notice-error notice-success').addClass(isError ? 'notice-error' : 'notice-success')
				.prop('hidden', false).find('p').text(message);
			clearTimeout(state.msgTimer);
			state.msgTimer = setTimeout(function () { $msg.prop('hidden', true); }, isError ? 7000 : 3000);
		}
		if (wp.a11y && wp.a11y.speak) { wp.a11y.speak(message, isError ? 'assertive' : 'polite'); }
	}

	/* ---------- B. Uploads go into the open folder ---------- */

	// wp.Uploader calls init() at the end of its constructor; we hook in there
	// and add our folder + nonce to the upload request right before it is sent.
	if (wp.Uploader && wp.Uploader.prototype) {
		var folderinoOrigInit = wp.Uploader.prototype.init;
		wp.Uploader.prototype.init = function () {
			if (typeof folderinoOrigInit === 'function') { folderinoOrigInit.apply(this, arguments); }
			if (!this.uploader || !this.uploader.bind) { return; }
			this.uploader.bind('BeforeUpload', function (up) {
				var params = up.settings.multipart_params || (up.settings.multipart_params = {});
				if (RDB.uploadFolder > 0) {
					params.folderino_folder = RDB.uploadFolder;
					params.folderino_nonce = RDB.nonce;
				} else {
					delete params.folderino_folder;
					delete params.folderino_nonce;
				}
			});
		};
	}

	/* ---------- C. Media modals: the "Folder" dropdown ---------- */

	// A core-style filter <select>. Choosing an option sets "folderino_folder" on the
	// library query; PHP (ajax_query_attachments_args) turns that into a tax_query.
	var FolderFilter = wp.media.view.AttachmentFilters.extend({
		className: 'attachment-filters folderino-filter',
		createFilters: function () {
			var filters = {
				all: { text: _.escape(i18n.allFolders), props: { folderino_folder: null }, priority: 10 },
				none: { text: _.escape(i18n.none), props: { folderino_folder: 'none' }, priority: 20 }
			};
			var priority = 30;
			walk(0, 0, function (f, depth) {
				var indent = new Array(depth + 1).join('&nbsp;&nbsp;&nbsp;') + (depth ? '&ndash;&nbsp;' : '');
				filters['f' + f.id] = { text: indent + _.escape(f.name), props: { folderino_folder: String(f.id) }, priority: priority++ };
			});
			this.filters = filters;
		},
		change: function () {
			wp.media.view.AttachmentFilters.prototype.change.apply(this, arguments);
			var value = this.model.get('folderino_folder');
			RDB.uploadFolder = isFolderId(value) ? parseInt(value, 10) : 0;
		}
	});

	// Hook into every media browser right after WordPress builds its toolbar.
	var folderinoOrigToolbar = wp.media.view.AttachmentsBrowser.prototype.createToolbar;
	wp.media.view.AttachmentsBrowser.prototype.createToolbar = function () {
		folderinoOrigToolbar.apply(this, arguments);
		try { afterToolbar(this); } catch (e) { if (window.console) { window.console.error('[rdb_mf]', e); } }
	};

	function afterToolbar(browser) {
		// Only real library queries (not e.g. "Edit gallery", which shows a fixed selection).
		if (!browser.collection || !browser.collection.props || !browser.collection.props.get('query')) {
			return;
		}
		// Media > Library grid: the sidebar does the filtering, no dropdown needed.
		if (RDB.isLibrary && document.getElementById('wp-media-grid')) {
			state.browser = browser;
			// Refresh the counts when files are deleted from the grid.
			browser.collection.on('remove', _.debounce(refreshTree, 600));
			return;
		}
		RDB.uploadFolder = 0; // A fresh modal starts at "All folders".
		var id = 'folderino-folder-filter-' + browser.cid;
		browser.toolbar.set('folderinoFolderLabel', new wp.media.view.Label({
			value: i18n.filterLabel,
			attributes: { 'for': id },
			priority: -70
		}).render());
		browser.toolbar.set('folderinoFolderFilter', new FolderFilter({
			id: id,
			controller: browser.controller,
			model: browser.collection.props,
			priority: -70
		}).render());
	}

	/* ---------- D. Media Library sidebar ---------- */

	$(function () {
		if (!RDB.isLibrary) { return; }
		RDB.screen = document.getElementById('wp-media-grid') ? 'grid' : ($('#the-list').length ? 'list' : '');
		if (!RDB.screen) { return; }

		mountSidebar();
		renderSidebar();
		bindSidebarEvents();
		if (RDB.screen === 'grid') { initGridDrag(); } else { initListDrag(); }

		if (wp.Uploader && wp.Uploader.queue) {
			bindUploadFeedback();
		}
	});

	// Uploads: say where the files are going, and when they're done reload the
	// open folder + counts, so the new files show up right away.
	// wp.Uploader.queue holds the files being uploaded; it resets when all are done.
	function bindUploadFeedback() {
		var batch = 0;
		var folderName = '';

		wp.Uploader.queue.on('add', function () {
			if (!batch) {
				var f = RDB.uploadFolder ? findFolder(RDB.uploadFolder) : null;
				folderName = f ? f.name : '';
				if (folderName) { notify(i18n.uploadingTo.replace('%s', folderName)); }
			}
			batch++;
		});

		wp.Uploader.queue.on('reset', function () {
			if (!batch) { return; }
			var count = batch;
			batch = 0;
			state.rev++;   // Skip wp.media's cached results.
			requery();     // Reload the open folder (grid view).
			ajax('folderino_tree').then(function (data) {
				applyTree(data.tree);
				var msg = (count === 1 ? i18n.uploadedOne : i18n.uploadedMany.replace('%d', count));
				msg += folderName ? i18n.uploadedTo.replace('%s', folderName) : '.';
				notify(msg);
			});
		});
	}

	// Wrap the normal page (.wrap) in a flex layout with our sidebar on the left.
	function mountSidebar() {
		var $wrap = $('#wpbody-content > .wrap').first();
		if (!$wrap.length) { return; }
		var $layout = $('<div class="folderino-layout">').addClass('folderino-' + RDB.screen);
		var $side = $('<aside id="folderino-sidebar" class="folderino-sidebar">').attr('aria-label', i18n.folders);
		var $head = $('<div class="folderino-head">').append(
			$('<h2>').text(i18n.folders),
			$('<div class="folderino-head-actions">').append(
				// Expand / collapse: switches the panel between 250px and 320px.
				// Icon: "arrow-right-from-line" (Lucide, ISC licence). It turns around when the panel is wide.
				$('<button type="button" class="button button-small folderino-size">').html(
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
					'<path d="M3 5v14"/><path d="M21 12H7"/><path d="m15 18 6-6-6-6"/></svg>'
				),
				$('<button type="button" class="button button-small folderino-new">')
					.append('<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ', document.createTextNode(i18n.newFolder))
			)
		);
		var $msg = $('<div class="folderino-msg notice inline" hidden><p></p></div>');
		// The message sits below the tree so rows never jump while you work.
		$side.append(
			$head,
			'<ul class="folderino-tree folderino-special"></ul>',
			'<ul class="folderino-tree folderino-folders"></ul>',
			$msg,
			$('<p class="folderino-hint">').text(i18n.hint)
		);
		$wrap.before($layout);
		$layout.append($side, $('<div class="folderino-main">').append($wrap));
		setWide(loadWide());
	}

	// Wide or narrow panel, remembered per browser (safe if storage is blocked).
	function loadWide() {
		try { return window.localStorage.getItem('folderino_wide') === '1'; } catch (e) { return false; }
	}
	function setWide(wide) {
		var label = wide ? i18n.narrow : i18n.widen;
		$('#folderino-sidebar').toggleClass('is-wide', wide);
		$('#folderino-sidebar .folderino-size')
			.attr({ 'aria-label': label, title: label, 'aria-pressed': wide ? 'true' : 'false' });
		try { window.localStorage.setItem('folderino_wide', wide ? '1' : '0'); } catch (e) {}
	}

	function rowFor(id) {
		return $('#folderino-sidebar .folderino-row[data-id="' + id + '"]');
	}

	// One row: [toggle] [icon] name count [actions]
	function makeRow(type, id, name, count, depth, icon) {
		var $row = $('<div class="folderino-row" tabindex="0">')
			.attr({ 'data-type': type, 'data-id': id, 'data-depth': depth })
			.css('--folderino-depth', depth);
		var $toggle = $('<button type="button" class="folderino-toggle">');
		var kids = type === 'folder' ? childrenOf(id) : [];
		if (kids.length) {
			var open = !state.collapsed[id];
			$toggle.attr({ 'aria-expanded': open ? 'true' : 'false', 'aria-label': i18n.toggle })
				.append('<span class="dashicons ' + (open ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-right-alt2') + '" aria-hidden="true"></span>');
		} else {
			$toggle.addClass('is-empty').attr({ tabindex: -1, 'aria-hidden': 'true' });
		}
		$row.append(
			$toggle,
			'<span class="dashicons ' + icon + ' folderino-icon" aria-hidden="true"></span>',
			$('<span class="folderino-name">').text(name),
			$('<span class="folderino-count">').text(count)
		);
		if (type === 'folder') {
			$row.append($('<span class="folderino-actions">').append(
				actionButton('add', 'dashicons-plus-alt2', i18n.addSub, name),
				actionButton('rename', 'dashicons-edit', i18n.rename, name),
				actionButton('delete', 'dashicons-trash', i18n.del, name)
			));
		}
		return $row;
	}

	function actionButton(act, icon, label, name) {
		return $('<button type="button" class="folderino-act">')
			.attr({ 'data-act': act, 'aria-label': label + ': ' + name, title: label })
			.append('<span class="dashicons ' + icon + '" aria-hidden="true"></span>');
	}

	// Build the nested list of folders under a parent.
	function buildLevel(parentId, $ul, depth) {
		_.each(childrenOf(parentId), function (f) {
			var $li = $('<li>').attr('data-id', f.id).append(makeRow('folder', f.id, f.name, f.count, depth, 'dashicons-category'));
			if (childrenOf(f.id).length) {
				var $sub = $('<ul class="folderino-children">');
				if (state.collapsed[f.id]) { $sub.prop('hidden', true); }
				buildLevel(f.id, $sub, depth + 1);
				$li.append($sub);
			}
			$ul.append($li);
		});
	}

	function renderSidebar() {
		var $side = $('#folderino-sidebar');
		if (!$side.length) { return; }
		$side.find('.folderino-special').empty().append(
			$('<li>').append(makeRow('all', 'all', i18n.all, TREE.total, 0, 'dashicons-admin-media')),
			$('<li>').append(makeRow('none', 'none', i18n.none, TREE.none, 0, 'dashicons-portfolio'))
		);
		var $folders = $side.find('.folderino-folders').empty();
		buildLevel(0, $folders, 0);
		if (!TREE.folders.length) {
			$folders.append($('<li class="folderino-empty">').text(i18n.empty));
		}
		markCurrent();
		initDroppables();
		initFolderDraggables();
	}

	function markCurrent() {
		$('#folderino-sidebar .folderino-row').removeClass('is-current').removeAttr('aria-current');
		rowFor(state.current).addClass('is-current').attr('aria-current', 'true');
	}

	// New data from the server: redraw (unless someone is typing a name).
	function applyTree(tree) {
		if (tree) { TREE = tree; }
		if ($('#folderino-sidebar .folderino-input').not(':disabled').length) { return; }
		renderSidebar();
	}

	function refreshTree() {
		ajax('folderino_tree').then(function (data) { applyTree(data.tree); });
	}

	// Click a folder: filter the library.
	function selectFolder(id) {
		id = String(id);
		if (RDB.screen === 'list') {
			// List view is a normal page: reload with ?folderino_folder=
			var url = new URL(window.location.href);
			url.searchParams.delete('paged');
			if (id === 'all') { url.searchParams.delete('folderino_folder'); } else { url.searchParams.set('folderino_folder', id); }
			window.location.href = url.toString();
			return;
		}
		state.current = id;
		RDB.uploadFolder = isFolderId(id) ? parseInt(id, 10) : 0;
		markCurrent();
		requery();
	}

	// Grid view: change the library query. wp.media fetches the files for us.
	function requery() {
		if (!state.browser) { return; }
		state.browser.collection.props.set({
			folderino_folder: state.current === 'all' ? null : state.current,
			folderino_rev: state.rev
		});
	}

	function bindSidebarEvents() {
		var $side = $('#folderino-sidebar');

		$side.on('click', '.folderino-size', function (e) {
			e.preventDefault();
			setWide(!$('#folderino-sidebar').hasClass('is-wide'));
		});

		$side.on('click', '.folderino-new', function (e) {
			e.preventDefault();
			startCreate(0);
		});

		$side.on('click', '.folderino-toggle', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var id = $(this).closest('.folderino-row').attr('data-id');
			if (state.collapsed[id]) { delete state.collapsed[id]; } else { state.collapsed[id] = 1; }
			saveCollapsed();
			renderSidebar();
			rowFor(id).find('.folderino-toggle').trigger('focus');
		});

		$side.on('click', '.folderino-act', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var act = $(this).attr('data-act');
			var id = parseInt($(this).closest('.folderino-row').attr('data-id'), 10);
			if (act === 'add') { startCreate(id); }
			if (act === 'rename') { startRename(id); }
			if (act === 'delete') { confirmDelete(id); }
		});

		$side.on('click', '.folderino-row[data-type]', function (e) {
			if ($(e.target).closest('input, button').length) { return; }
			selectFolder($(this).attr('data-id'));
		});

		// Long folder names are cut off with "...". On hover, show the full name
		// as a tooltip, but only when it doesn't fit (checked at hover time,
		// because the action buttons take up extra room while hovering).
		$side.on('mouseenter', '.folderino-row[data-type]', function () {
			var name = $(this).find('.folderino-name')[0];
			if (!name) { return; }
			if (name.scrollWidth > name.clientWidth) {
				this.setAttribute('title', name.textContent);
			} else {
				this.removeAttribute('title');
			}
		});

		// Keyboard: Enter/Space opens, F2 renames, Delete asks to delete.
		$side.on('keydown', '.folderino-row[data-type]', function (e) {
			if (e.target !== this) { return; }
			var id = $(this).attr('data-id');
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				selectFolder(id);
			} else if (e.key === 'F2' && isFolderId(id)) {
				e.preventDefault();
				startRename(parseInt(id, 10));
			} else if (e.key === 'Delete' && isFolderId(id)) {
				e.preventDefault();
				confirmDelete(parseInt(id, 10));
			}
		});
	}

	// Inline text field used for "new folder" and "rename".
	// Enter or clicking away saves, Escape cancels.
	function editInput($input, save) {
		var finished = false;
		function finish(commit) {
			if (finished) { return; }
			finished = true;
			var value = $.trim($input.val());
			var request = (commit && value) ? save(value) : null;
			if (!request) { renderSidebar(); return; }
			$input.prop('disabled', true);
			request.then(
				function (data) { applyTree(data.tree); notify(data.message); },
				function (msg) { renderSidebar(); notify(msg, true); }
			);
		}
		$input.on('keydown', function (e) {
			e.stopPropagation(); // Keep wp.media keyboard shortcuts out of it.
			if (e.key === 'Enter') { e.preventDefault(); finish(true); }
			if (e.key === 'Escape') { e.preventDefault(); finish(false); }
		});
		$input.on('blur', function () { finish(true); });
		$input.on('click mousedown', function (e) { e.stopPropagation(); });
	}

	function nameInput(value) {
		return $('<input type="text" class="folderino-input" maxlength="200">')
			.attr({ 'aria-label': i18n.folderName, placeholder: i18n.folderName })
			.val(value || '');
	}

	function startCreate(parentId) {
		if (parentId && state.collapsed[parentId]) {
			delete state.collapsed[parentId];
			saveCollapsed();
		}
		renderSidebar();

		var $list, depth = 0;
		if (parentId) {
			var $parentLi = $('#folderino-sidebar li[data-id="' + parentId + '"]');
			$list = $parentLi.children('ul');
			if (!$list.length) { $list = $('<ul class="folderino-children">').appendTo($parentLi); }
			depth = (parseInt($parentLi.children('.folderino-row').attr('data-depth'), 10) || 0) + 1;
		} else {
			$list = $('#folderino-sidebar .folderino-folders');
			$list.children('.folderino-empty').remove();
		}

		var $input = nameInput('');
		$('<li class="folderino-new-item">').append(
			$('<div class="folderino-row is-editing">').css('--folderino-depth', depth).append(
				'<span class="folderino-toggle is-empty" aria-hidden="true"></span>',
				'<span class="dashicons dashicons-category folderino-icon" aria-hidden="true"></span>',
				$input
			)
		).appendTo($list);
		$input.trigger('focus');

		editInput($input, function (name) {
			return ajax('folderino_create', { name: name, parent: parentId || 0 });
		});
	}

	function startRename(id) {
		var f = findFolder(id);
		var $row = rowFor(id);
		if (!f || !$row.length) { return; }
		if ($row.hasClass('ui-draggable')) { $row.draggable('disable'); }
		var $input = nameInput(f.name);
		$row.addClass('is-editing').find('.folderino-name, .folderino-count, .folderino-actions').remove();
		$row.find('.folderino-icon').after($input);
		$input.trigger('focus').trigger('select');

		editInput($input, function (name) {
			if (name === f.name) { return null; } // Nothing changed.
			return ajax('folderino_rename', { id: id, name: name });
		});
	}

	// Inline confirmation (no browser pop-up).
	function confirmDelete(id) {
		var f = findFolder(id);
		if (!f) { return; }
		$('#folderino-sidebar .folderino-confirm').remove();

		var $yes = $('<button type="button" class="button button-small button-link-delete">').text(i18n.del);
		var $no = $('<button type="button" class="button button-small">').text(i18n.cancel);
		var $box = $('<div class="folderino-confirm" role="alert">').append(
			$('<p>').text(i18n.deleteConfirm.replace('%s', f.name)), $yes, ' ', $no
		);
		rowFor(id).after($box);
		$yes.trigger('focus');

		$no.on('click', function () {
			$box.remove();
			rowFor(id).trigger('focus');
		});
		$yes.on('click', function () {
			$yes.prop('disabled', true);
			ajax('folderino_delete', { id: id }).then(function (data) {
				state.rev++;
				applyTree(data.tree);
				notify(data.message);
				if (state.current === String(id)) {
					selectFolder('all'); // The open folder is gone.
				} else if (state.current === 'none') {
					requery(); // Uncategorized just got more files.
				}
			}, function (msg) {
				$box.remove();
				notify(msg, true);
			});
		});
	}

	/* ---------- E. Drag and drop ---------- */

	function dragHelper(icon, text) {
		return $('<div class="folderino-drag-helper">').append(
			'<span class="dashicons ' + icon + '" aria-hidden="true"></span>',
			$('<span class="folderino-drag-label">').text(text)
		);
	}

	function filesLabel(n) {
		return n === 1 ? i18n.moveOne : i18n.moveMany.replace('%d', n);
	}

	// Make a file (grid tile or list row) draggable. "collect" returns the IDs to move.
	function makeFileDraggable($el, collect) {
		if ($el.hasClass('ui-draggable')) { return; }
		$el.draggable({
			helper: function () { return dragHelper('dashicons-images-alt2', ''); },
			appendTo: 'body',
			distance: 8,              // A normal click still opens the file.
			cursorAt: { left: -12, top: -12 },
			zIndex: 160100,
			refreshPositions: true,   // The sidebar is sticky, so keep drop zones up to date.
			scroll: false,
			start: function (e, ui) {
				var ids = collect($el);
				$el.data('folderinoIds', ids);
				ui.helper.find('.folderino-drag-label').text(filesLabel(ids.length));
				$('body').addClass('folderino-is-dragging');
			},
			stop: function () {
				$('body').removeClass('folderino-is-dragging');
				$('#folderino-sidebar .folderino-row').removeClass('is-drop-hover');
			}
		});
	}

	// Grid: tiles are created on the fly, so we set them up on first hover.
	// In "Bulk select" mode, dragging a selected tile moves all selected tiles.
	function initGridDrag() {
		$(document).on('mouseenter', '.folderino-layout .attachments-browser li.attachment', function () {
			makeFileDraggable($(this), function ($li) {
				if ($li.hasClass('selected')) {
					return $li.closest('.attachments').find('li.attachment.selected').map(function () {
						return parseInt($(this).attr('data-id'), 10);
					}).get();
				}
				return [parseInt($li.attr('data-id'), 10)];
			});
		});
	}

	// List: drag a row. If its checkbox is ticked, all ticked rows move together.
	function initListDrag() {
		$(document).on('mouseenter', '#the-list > tr[id^="post-"]', function () {
			makeFileDraggable($(this), function ($tr) {
				var own = parseInt($tr.attr('id').replace('post-', ''), 10);
				var checked = $('#the-list input[name="media[]"]:checked').map(function () {
					return parseInt(this.value, 10);
				}).get();
				return checked.indexOf(own) !== -1 ? checked : [own];
			});
		});
	}

	// Folders can be dragged onto other folders (see folderZone for before/inside/after).
	function initFolderDraggables() {
		$('#folderino-sidebar .folderino-row[data-type="folder"]').draggable({
			helper: function () {
				return dragHelper('dashicons-category', $(this).find('.folderino-name').text());
			},
			appendTo: 'body',
			distance: 6,
			cursorAt: { left: -12, top: -12 },
			zIndex: 160100,
			refreshPositions: true,
			scroll: false,
			cancel: 'button, input',
			start: function () {
				$('#folderino-sidebar').addClass('is-dragging-folder');
				$('body').addClass('folderino-is-dragging');
			},
			drag: function (e) {
				if (!state.hover) { return; }
				state.hover.removeClass('folderino-zone-before folderino-zone-inside folderino-zone-after')
					.addClass('folderino-zone-' + folderZone(state.hover, e.pageY));
			},
			stop: function () {
				$('#folderino-sidebar').removeClass('is-dragging-folder');
				$('body').removeClass('folderino-is-dragging');
				$('#folderino-sidebar .folderino-row').removeClass('is-drop-hover folderino-zone-before folderino-zone-inside folderino-zone-after');
			}
		});
	}

	// Top 30% of a row = put before it, bottom 30% = after it, middle = inside it.
	function folderZone($row, pageY) {
		if ($row.attr('data-type') !== 'folder') { return 'inside'; }
		var y = pageY - $row.offset().top;
		var h = $row.outerHeight();
		if (y < h * 0.3) { return 'before'; }
		if (y > h * 0.7) { return 'after'; }
		return 'inside';
	}

	// Every sidebar row is a drop target.
	function initDroppables() {
		$('#folderino-sidebar .folderino-row[data-type]').droppable({
			tolerance: 'pointer',
			greedy: true,
			accept: function ($drag) {
				var type = $(this).attr('data-type');
				var id = $(this).attr('data-id');
				if ($drag.hasClass('folderino-row')) {
					// A folder: onto "All files" = back to the top level; never into itself.
					if (type === 'none') { return false; }
					if (type === 'all') { return true; }
					var dragId = $drag.attr('data-id');
					return id !== dragId && !isDescendant(id, dragId);
				}
				// Files: onto any folder or Uncategorized, but not onto "All files".
				return $drag.is('li.attachment, #the-list > tr') && type !== 'all';
			},
			over: function () {
				state.hover = $(this).addClass('is-drop-hover');
			},
			out: function () {
				$(this).removeClass('is-drop-hover folderino-zone-before folderino-zone-inside folderino-zone-after');
				if (state.hover && state.hover[0] === this) { state.hover = null; }
			},
			drop: function (e, ui) {
				var $target = $(this);
				var type = $target.attr('data-type');
				var $drag = ui.draggable;
				var zone = folderZone($target, e.pageY);
				$target.removeClass('is-drop-hover folderino-zone-before folderino-zone-inside folderino-zone-after');
				state.hover = null;

				if ($drag.hasClass('folderino-row')) {
					var targetId = type === 'all' ? 0 : parseInt($target.attr('data-id'), 10);
					moveFolder(parseInt($drag.attr('data-id'), 10), targetId, zone);
					return;
				}
				var ids = $drag.data('folderinoIds') || [];
				if (ids.length) {
					assignFiles(ids, type === 'none' ? 0 : parseInt($target.attr('data-id'), 10));
				}
			}
		});
	}

	// Work out the new parent + sibling order, then save it.
	function moveFolder(id, targetId, zone) {
		var parent, order;
		if (!targetId || zone === 'inside') {
			parent = targetId || 0; // Into the target (or the top level), at the bottom.
			order = _.without(childIds(parent), id);
			order.push(id);
		} else {
			var target = findFolder(targetId);
			if (!target) { return; }
			parent = target.parent; // Next to the target, same parent.
			order = _.without(childIds(parent), id);
			var index = order.indexOf(targetId);
			order.splice(zone === 'before' ? index : index + 1, 0, id);
		}
		if (parent && state.collapsed[parent]) {
			delete state.collapsed[parent];
			saveCollapsed();
		}
		ajax('folderino_move_folder', { id: id, parent: parent, order: order }).then(
			function (data) { applyTree(data.tree); notify(data.message); },
			function (msg) { notify(msg, true); }
		);
	}

	// Put files in a folder (0 = Uncategorized) and refresh what's on screen.
	function assignFiles(ids, folderId) {
		ajax('folderino_assign', { ids: ids, folder: folderId }).then(function (data) {
			state.rev++;
			applyTree(data.tree);
			notify(data.message);
			if (state.current === 'all') { return; } // Nothing disappears from "All files".
			if (RDB.screen === 'list') {
				window.location.reload();
			} else {
				requery();
			}
		}, function (msg) {
			notify(msg, true);
		});
	}

})(jQuery, window._, window.wp, window.folderino);
