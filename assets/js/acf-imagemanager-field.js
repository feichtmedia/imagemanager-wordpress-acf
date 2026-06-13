/**
 * FeichtMedia ImageManager ACF – file browser modal and field UI.
 *
 * One modal per page (lazily created on first open, then reused).
 * Assets are enqueued once regardless of how many field instances exist.
 * No API calls are made on field render — only when the browser is opened.
 *
 * All UI strings come pre-translated from PHP via window.fmImageManager.strings.
 *
 * WCAG 2.2 compliance notes:
 *  - Native <dialog> with showModal() provides built-in focus trap (2.1.2); triggerEl.focus() explicitly restores focus on close (2.4.3).
 *  - aria-labelledby links the dialog to its visible title (1.3.1 / 4.1.2).
 *  - Image tiles use <button> + <a> siblings — no nested interactive elements.
 *  - Live region announces loading state reliably (4.1.3).
 *  - All colour values in CSS meet 4.5 : 1 AA contrast (1.4.3).
 *  - Touch targets >= 24 x 24 px (2.5.8).
 */
(function () {
	'use strict';

	const cfg = window.fmImageManager || {};
	const s = cfg.strings || {};
	const ns = cfg.restNamespace || 'feichtmedia/imagemanager/v2';

	const PER_PAGE = 24;

	// Global filters for thumbnail images to reduce bandwidth and increase loading speed.
	const THUMB_FILTERS = 'fit-in/300x300/filters:quality(80)/filters:strip_exif()/filters:strip_icc()/filters:no_upscale()';


	// ── State ──────────────────────────────────────────────────────────────────
	let activeFieldKey = null;
	let selectedImage = null;
	let preSelectedImageId = null; // image ID already stored in the field when the browser opens
	let modalEl = null; // single modal DOM node, created lazily
	let triggerEl = null; // element that opened the modal; focus is restored here on close

	/**
	 * Navigation stack. Each entry = { id: number|null, name: string }.
	 * navStack[0] is always the root. The last entry is the current level.
	 *
	 * @type {Array<{id: number|null, name: string}>}
	 */
	let navStack = [];
	let currentOffset = 0;
	let currentSearch = '';
	let isLoading = false;

	// ── ACF field initialisation ────────────────────────────────────────────────

	if (typeof acf !== 'undefined') {
		acf.addAction('ready_field/type=imagemanager_image', initField);
		acf.addAction('append_field/type=imagemanager_image', initField);

		// Register Conditional Logic operators so the field is not grayed out
		// in other fields' Conditional Logic dropdowns. ACF grays out any field
		// whose type has zero registered condition types. We only support
		// "has a value" / "has no value" because the stored value is an opaque
		// image ID, not something editors can compare against a fixed string.
		if (typeof acf.Condition !== 'undefined' && typeof acf.registerConditionType !== 'undefined') {
			acf.registerConditionType(acf.Condition.extend({
				type: 'imagemanagerHasValue',
				operator: '!=empty',
				label: s.hasValue || 'Has a value',
				fieldTypes: ['imagemanager_image'],
				/**
				 * Returns true when the field contains an image ID.
				 *
				 * @param {Object} rule  - Condition rule data.
				 * @param {Object} field - ACF field object (has a .val() method).
				 * @returns {boolean}
				 */
				match: function (rule, field) {
					return !!field.val();
				},
				/**
				 * No value to compare — render a disabled placeholder input.
				 *
				 * @returns {string}
				 */
				choices: function () {
					return '<input type="text" disabled />';
				}
			}));

			acf.registerConditionType(acf.Condition.extend({
				type: 'imagemanagerHasNoValue',
				operator: '==empty',
				label: s.hasNoValue || 'Has no value',
				fieldTypes: ['imagemanager_image'],
				/**
				 * Returns true when the field is empty (no image selected).
				 *
				 * @param {Object} rule  - Condition rule data.
				 * @param {Object} field - ACF field object (has a .val() method).
				 * @returns {boolean}
				 */
				match: function (rule, field) {
					return !field.val();
				},
				/**
				 * No value to compare — render a disabled placeholder input.
				 *
				 * @returns {string}
				 */
				choices: function () {
					return '<input type="text" disabled />';
				}
			}));
		}
	}

	/**
	 * Bind events to a single field instance.
	 *
	 * @param {Object} field - ACF field object.
	 * @returns {void}
	 */
	function initField(field) {
		const $el = field.$el;

		$el.on('click', '.fm-imagemanager-btn-add, .fm-imagemanager-btn-change', function () {
			// Save the triggering element so focus can be restored when the modal closes.
			triggerEl = this;
			openBrowser(field.get('key'));
		});

		$el.on('click', '.fm-imagemanager-btn-remove', function () {
			clearField($el[0]);
		});

		// Thumbnail 404 handling — show placeholder, do NOT clear the stored value
		// because the error may be caused by a misconfigured domain setting.
		const preview = $el.find('[data-fm-imagemanager-preview]')[0];
		if (preview) {
			preview.addEventListener('error', function () {
				this.style.display = 'none';
				const errEl = this.closest('.fm-imagemanager-preview')
					&& this.closest('.fm-imagemanager-preview').querySelector('.fm-imagemanager-img-error');
				if (errEl) {
					errEl.style.display = '';
				}
			});
		}
	}

	// ── Modal lifecycle ─────────────────────────────────────────────────────────

	/**
	 * Open the file browser modal for a specific ACF field instance.
	 * Sets activeFieldKey so a confirmed selection goes to the right field.
	 *
	 * @param {string} fieldKey - ACF field key of the triggering field instance.
	 * @returns {void}
	 */
	function openBrowser(fieldKey) {
		activeFieldKey = fieldKey;
		selectedImage = null;

		// Read the image ID that is currently stored in the field so it can be
		// highlighted in the grid — the user needs to see which image is active.
		preSelectedImageId = null;
		const fieldEl = document.querySelector(
			'.fm-imagemanager-field[data-field-key="' + CSS.escape(fieldKey) + '"]'
		);
		if (fieldEl) {
			const input = fieldEl.querySelector('[data-fm-imagemanager-input]');
			if (input && input.value) {
				preSelectedImageId = input.value;
			}
		}

		if (!modalEl) {
			modalEl = createModal();
			document.body.appendChild(modalEl);
		}

		resetBrowser();

		// Clear stale grid content before showing so old tiles are never visible.
		const catsGrid = modalEl.querySelector('.fm-imagemanager-cats-grid');
		const imgsGrid = modalEl.querySelector('.fm-imagemanager-imgs-grid');
		const paginationEl = modalEl.querySelector('.fm-imagemanager-pagination');
		if (catsGrid) { catsGrid.innerHTML = ''; }
		if (imgsGrid) { imgsGrid.innerHTML = ''; }
		if (paginationEl) { paginationEl.innerHTML = ''; }
		const catsSection = modalEl.querySelector('.fm-imagemanager-cats-section');
		if (catsSection) { catsSection.style.display = 'none'; }
		const countEl = modalEl.querySelector('.fm-imagemanager-count');
		if (countEl) { countEl.textContent = ''; }

		document.body.classList.add('fm-imagemanager-modal-open');
		modalEl.showModal();

		const searchInput = modalEl.querySelector('.fm-imagemanager-search');
		if (searchInput) {
			searchInput.value = '';
		}

		// Move focus into the modal — search input is the natural first control (WCAG 2.4.3).
		const firstFocusTarget = modalEl.querySelector('.fm-imagemanager-search') ||
			modalEl.querySelector('.fm-imagemanager-btn-close');
		if (firstFocusTarget) {
			firstFocusTarget.focus();
		}

		// Enable confirm immediately when there is already a selection — the user
		// may want to keep the current image without clicking it again.
		setConfirmEnabled(!!preSelectedImageId);

		if (preSelectedImageId) {
			// Show the spinner and resolve the image's category path before loading
			// so the grid opens directly in the right category with the image visible.
			setLoading(true);
			resolveImageCategory(preSelectedImageId)
				.catch(function () { /* silently fall back to root on any error */ })
				.then(function () {
					setLoading(false); // reset flag so loadPage() can proceed
					loadPage();
				});
		} else {
			loadPage();
		}
	}

	/**
	 * Close the modal, reset ephemeral state, and return focus to the trigger.
	 *
	 * @returns {void}
	 */
	function closeModal() {
		if (modalEl) {
			modalEl.close();
		}
		document.body.classList.remove('fm-imagemanager-modal-open');
		activeFieldKey = null;
		selectedImage = null;
		preSelectedImageId = null;

		// Return focus to the element that opened the modal (WCAG 2.4.3).
		if (triggerEl) {
			triggerEl.focus();
			triggerEl = null;
		}
	}

	/**
	 * Reset navigation to root and clear search / offset.
	 *
	 * @returns {void}
	 */
	function resetBrowser() {
		navStack = [{ id: null, name: s.root || 'All images' }];
		currentOffset = 0;
		currentSearch = '';
		renderBreadcrumbs();
	}

	/**
	 * Build the modal DOM element. Called exactly once.
	 *
	 * @returns {HTMLElement}
	 */
	function createModal() {
		const el = document.createElement('dialog');
		el.className = 'fm-imagemanager-modal';
		// aria-labelledby references the visible h2 — preferred over aria-label (WCAG 1.3.1).
		el.setAttribute('aria-labelledby', 'fm-imagemanager-modal-title');

		const dashUrl = cfg.dashboardUrl || '';
		const newWindowLabel = s.newWindow ? ' (' + s.newWindow + ')' : ' (opens in new window)';

		el.innerHTML =
			'<div class="fm-imagemanager-modal-header">' +
			'<h2 class="fm-imagemanager-modal-title" id="fm-imagemanager-modal-title">' + escHtml(s.selectImage || 'Select image') + '</h2>' +
			'<div class="fm-imagemanager-modal-tools">' +
			'<input type="search" class="fm-imagemanager-search"' +
			' placeholder="' + escAttr(s.search || 'Search…') + '"' +
			' aria-label="' + escAttr(s.search || 'Search…') + '" />' +
			'<button type="button" class="fm-imagemanager-btn-close button-link"' +
			' aria-label="' + escAttr(s.close || 'Close') + '"></button>' +
			'</div>' +
			'</div>' +
			'<nav class="fm-imagemanager-breadcrumbs" aria-label="' + escAttr(s.breadcrumbs || 'Breadcrumb navigation') + '"></nav>' +
			'<div class="fm-imagemanager-modal-body">' +
			'<div class="fm-imagemanager-cats-section">' +
			'<div class="fm-imagemanager-cats-grid"></div>' +
			'</div>' +
			'<div class="fm-imagemanager-imgs-section">' +
			'<div class="fm-imagemanager-imgs-grid"></div>' +
			'<div class="fm-imagemanager-pagination"></div>' +
			'</div>' +
			// Visual spinner — no aria-live; announcements go through #fm-imagemanager-live.
			'<div class="fm-imagemanager-spinner" style="display:none;" aria-hidden="true">' +
			escHtml(s.loading || 'Loading…') +
			'</div>' +
			'<div class="fm-imagemanager-error-msg" style="display:none;" role="alert"></div>' +
			'</div>' +
			'<div class="fm-imagemanager-modal-footer">' +
			'<a href="' + escAttr(dashUrl + '/upload') + '" class="button fm-imagemanager-btn-upload"' +
			' target="_blank" rel="noopener"' +
			' aria-label="' + escAttr((s.upload || 'Upload') + newWindowLabel) + '">' +
			'<span class="dashicons-before dashicons-upload" aria-hidden="true"></span>' +
			escHtml(s.upload || 'Upload') +
			'</a>' +
			'<span class="fm-imagemanager-count" aria-live="polite"></span>' +
			'<button type="button" class="button button-primary fm-imagemanager-btn-confirm" disabled>' +
			escHtml(s.select || 'Select') +
			'</button>' +
			'</div>' +
			// Always-present live region for loading / status announcements (WCAG 4.1.3).
			// Never toggled with display:none so the aria-live contract is always active.
			'<div id="fm-imagemanager-live" class="fm-imagemanager-sr-only" aria-live="polite" aria-atomic="true"></div>';

		// Clicking the ::backdrop fires a click event on the <dialog> itself — close the modal.
		el.addEventListener('click', function (e) {
			if (e.target === el) {
				closeModal();
			}
		});
		// Native cancel event fires on Escape — prevent auto-close and delegate to closeModal().
		el.addEventListener('cancel', function (e) {
			e.preventDefault();
			closeModal();
		});
		el.querySelector('.fm-imagemanager-btn-close').addEventListener('click', closeModal);
		el.querySelector('.fm-imagemanager-btn-confirm').addEventListener('click', confirmSelection);

		// Debounced search — project-wide (not scoped to current category).
		const searchInput = el.querySelector('.fm-imagemanager-search');
		let searchTimer;
		searchInput.addEventListener('input', function () {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				currentSearch = searchInput.value.trim();
				// Reset navigation: search is project-wide.
				navStack = [{ id: null, name: s.root || 'All images' }];
				currentOffset = 0;
				renderBreadcrumbs();
				loadPage();
			}, 400);
		});

		return el;
	}

	// ── Data loading ────────────────────────────────────────────────────────────

	/**
	 * Load categories and images for the current navigation state.
	 * Replaces both grids when called (offset is reset before calling).
	 *
	 * @returns {void}
	 */
	function loadPage() {
		if (isLoading) {
			return;
		}
		setLoading(true);
		clearError();

		// During search, skip category fetch (search is project-wide).
		const catPromise = currentSearch ? Promise.resolve([]) : fetchCategories();
		const imgPromise = fetchImages();

		Promise.all([catPromise, imgPromise])
			.then(function (results) {
				setLoading(false);
				renderCategories(results[0]);
				renderImages(results[1].images, results[1].total, true);
				renderCount(
					results[1].images.length,
					results[1].total
				);
			})
			.catch(function (err) {
				setLoading(false);
				showError((err && err.message) || s.loadError || 'Could not load images. Please try again.');
			});
	}

	/**
	 * Fetch sub-categories of the current navigation level.
	 *
	 * @returns {Promise<Array>}
	 */
	function fetchCategories() {
		const categoryId = getCurrentCategoryId();
		// parentCategory=0 restricts to top-level categories only; otherwise the API
		// returns all categories including sub-categories of every depth.
		const params = { limit: 100, parentCategory: categoryId !== null ? categoryId : 0 };

		return wp.apiFetch({ path: buildPath('/categories', params) })
			.then(function (data) {
				if (Array.isArray(data)) {
					return data;
				}
				return data.categories || data.data || data.items || [];
			});
	}

	/**
	 * Fetch images for the current category / search query.
	 *
	 * @returns {Promise<{images: Array, total: number}>}
	 */
	function fetchImages() {
		const categoryId = getCurrentCategoryId();
		const params = { limit: PER_PAGE, offset: currentOffset };

		if (currentSearch) {
			params.search = currentSearch;
		} else {
			// category=0 at root restricts to uncategorised images only; without it
			// the API returns all images regardless of their category assignment.
			params.category = categoryId !== null ? categoryId : 0;
		}

		return wp.apiFetch({ path: buildPath('/images', params) })
			.then(function (data) {
				if (Array.isArray(data)) {
					return { images: data, total: data.length };
				}
				const images = data.images || data.data || data.items || [];
				const total = ( data.meta && data.meta.total ) || data.total || data.count || images.length;
				return { images: images, total: Number(total) };
			});
	}

	/**
	 * Append the next page of images without replacing existing tiles.
	 *
	 * @returns {void}
	 */
	function loadMore() {
		if (isLoading) {
			return;
		}
		currentOffset += PER_PAGE;
		setLoading(true);
		clearError();

		fetchImages()
			.then(function (imgData) {
				setLoading(false);
				renderImages(imgData.images, imgData.total, false);
				const shown = modalEl.querySelectorAll('.fm-imagemanager-img-tile').length;
				renderCount(shown, imgData.total);
			})
			.catch(function (err) {
				setLoading(false);
				currentOffset -= PER_PAGE; // roll back on failure
				showError((err && err.message) || s.loadError || 'Could not load images. Please try again.');
			});
	}

	// ── Navigation ──────────────────────────────────────────────────────────────

	/**
	 * Navigate into a category, pushing the current level onto the nav stack.
	 *
	 * @param {number} categoryId   - Category ID.
	 * @param {string} categoryName - Display name of the category.
	 * @returns {void}
	 */
	function navigateToCategory(categoryId, categoryName) {
		navStack.push({ id: categoryId, name: categoryName });
		currentOffset = 0;
		selectedImage = null;
		preSelectedImageId = null;
		setConfirmEnabled(false);
		renderBreadcrumbs();
		loadPage();
	}

	/**
	 * Navigate to a specific index in the nav stack (breadcrumb click).
	 *
	 * @param {number} index - Index in navStack to navigate to (slice to index+1).
	 * @returns {void}
	 */
	function navigateTo(index) {
		navStack = navStack.slice(0, index + 1);
		currentOffset = 0;
		selectedImage = null;
		preSelectedImageId = null;
		setConfirmEnabled(false);
		renderBreadcrumbs();
		loadPage();
	}

	/**
	 * Return the ID of the current category level (null = root).
	 *
	 * @returns {number|null}
	 */
	function getCurrentCategoryId() {
		return navStack[navStack.length - 1].id;
	}

	// ── Category path resolution ────────────────────────────────────────────────

	/**
	 * Resolve the category path for a pre-selected image and push it onto navStack.
	 *
	 * Fetches the image with its embedded category (`includeCategory=1`), then
	 * resolves the full ancestor path so the breadcrumbs and grid can reflect the
	 * correct location. Resolves without modifying navStack for uncategorised images.
	 *
	 * @param {string} imageId - The image ID currently stored in the field.
	 * @returns {Promise<void>}
	 */
	function resolveImageCategory(imageId) {
		return wp.apiFetch({
			path: buildPath('/images/' + encodeURIComponent(imageId), { includeCategory: 1 })
		}).then(function (response) {
			// The API wraps single-resource responses in a "data" envelope.
			const image = (response && response.data && typeof response.data === 'object' && !Array.isArray(response.data))
				? response.data
				: response;

			const catRaw = image.category;
			let catId = null;
			let catName = '';
			if (catRaw !== null && catRaw !== undefined) {
				if (typeof catRaw === 'object') {
					catId  = catRaw.id || catRaw.categoryId || null;
					catName = catRaw.displayName || catRaw.name || '';
				} else {
					catId = catRaw; // scalar category ID
				}
			}
			catId = catId || image.categoryId || null;

			if (!catId) {
				return; // uncategorised image — root is the correct view
			}
			return resolveCategoryPath(catId, catName);
		});
	}

	/**
	 * Fetch the full ancestor chain for a category and push all entries onto navStack.
	 *
	 * Primary: uses `includePath=1` which the API can return as a pre-built path array.
	 * Fallback: recursive parent traversal via `buildAncestorPath` when the API does
	 * not include a path array in the response.
	 *
	 * @param {number|string} categoryId   - Category ID to resolve.
	 * @param {string}        fallbackName - Display name used only if the API returns no name.
	 * @returns {Promise<void>}
	 */
	function resolveCategoryPath(categoryId, fallbackName) {
		return wp.apiFetch({
			path: buildPath('/categories/' + encodeURIComponent(categoryId), { includePath: 1 })
		}).then(function (response) {
			// Unwrap "data" envelope (same API convention as single-image endpoint).
			const cat = (response && response.data && typeof response.data === 'object' && !Array.isArray(response.data))
				? response.data
				: response;
			const pathArr = cat.path || cat.ancestors || null;
			let pathPromise;

			if (Array.isArray(pathArr) && pathArr.length) {
				// API returned a ready-made path from root to this category.
				pathPromise = Promise.resolve(pathArr.map(function (entry) {
					return {
						id: entry.id || entry.categoryId,
						name: entry.displayName || entry.name || ''
					};
				}));
			} else {
				// includePath not available — traverse parents recursively.
				const name = cat.displayName || cat.name || fallbackName || '';
				const parentId = cat.parentCategory || cat.parentId || null;
				if (parentId) {
					pathPromise = buildAncestorPath(parentId, 0).then(function (ancestors) {
						return ancestors.concat([{ id: categoryId, name: name }]);
					});
				} else {
					pathPromise = Promise.resolve([{ id: categoryId, name: name }]);
				}
			}

			return pathPromise.then(function (path) {
				path.forEach(function (e) { navStack.push(e); });
				renderBreadcrumbs();
			});
		});
	}

	/**
	 * Recursively build the ancestor chain of a category from root downward.
	 *
	 * Used as a fallback when the API does not support `includePath`.
	 * Depth is capped at 8 to prevent runaway recursion on malformed data.
	 *
	 * @param {number|string} categoryId - Category ID to fetch.
	 * @param {number}        depth      - Current recursion depth.
	 * @returns {Promise<Array<{id: number|string, name: string}>>}
	 */
	function buildAncestorPath(categoryId, depth) {
		if (depth > 8) {
			return Promise.resolve([]);
		}
		return wp.apiFetch({ path: buildPath('/categories/' + encodeURIComponent(categoryId), {}) })
			.then(function (response) {
				// Unwrap "data" envelope (same API convention as single-image endpoint).
				const cat = (response && response.data && typeof response.data === 'object' && !Array.isArray(response.data))
					? response.data
					: response;
				const name = cat.displayName || cat.name || '';
				const parentId = cat.parentCategory || cat.parentId || null;
				if (parentId) {
					return buildAncestorPath(parentId, depth + 1).then(function (ancestors) {
						return ancestors.concat([{ id: categoryId, name: name }]);
					});
				}
				return [{ id: categoryId, name: name }];
			});
	}

	// ── Rendering ───────────────────────────────────────────────────────────────

	/**
	 * Render the breadcrumb navigation from the current nav stack.
	 *
	 * @returns {void}
	 */
	function renderBreadcrumbs() {
		const el = modalEl.querySelector('.fm-imagemanager-breadcrumbs');
		el.innerHTML = '';

		navStack.forEach(function (entry, i) {
			if (i > 0) {
				const sep = document.createElement('span');
				sep.className = 'fm-imagemanager-crumb-sep';
				sep.textContent = '/';
				sep.setAttribute('aria-hidden', 'true');
				el.appendChild(sep);
			}

			const isCurrent = (i === navStack.length - 1);

			if (isCurrent) {
				const span = document.createElement('span');
				span.className = 'fm-imagemanager-crumb fm-imagemanager-crumb--current';
				span.textContent = entry.name;
				span.setAttribute('aria-current', 'page');
				el.appendChild(span);
			} else {
				const btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'fm-imagemanager-crumb button-link';
				btn.textContent = entry.name;
				btn.addEventListener('click', (function (idx) {
					return function () { navigateTo(idx); };
				})(i));
				el.appendChild(btn);
			}
		});

		updateUploadLink();
	}

	/**
	 * Update the upload button href to include the current category as a query param.
	 *
	 * Called after every navigation change so the link always points to the correct
	 * upload destination. At root level (categoryId === null) no param is appended.
	 *
	 * @returns {void}
	 */
	function updateUploadLink() {
		if (!modalEl) {
			return;
		}
		const btn = modalEl.querySelector('.fm-imagemanager-btn-upload');
		if (!btn) {
			return;
		}
		const dashUrl = cfg.dashboardUrl || '';
		const categoryId = getCurrentCategoryId();
		let url = dashUrl + '/upload';
		if (categoryId !== null) {
			url += '?category=' + encodeURIComponent(categoryId);
		}
		btn.href = url;
	}

	/**
	 * Render category folder tiles.
	 *
	 * Hides the section entirely when there are no categories.
	 *
	 * @param {Array} cats - Category objects from the API.
	 * @returns {void}
	 */
	function renderCategories(cats) {
		const grid = modalEl.querySelector('.fm-imagemanager-cats-grid');
		const section = modalEl.querySelector('.fm-imagemanager-cats-section');
		grid.innerHTML = '';

		if (!cats || cats.length === 0) {
			section.style.display = 'none';
			return;
		}
		section.style.display = '';

		cats.forEach(function (cat) {
			const name = cat.displayName || cat.name || '';
			const id = cat.id;
			const tile = document.createElement('button');
			tile.type = 'button';
			tile.className = 'fm-imagemanager-cat-tile';
			tile.innerHTML =
				'<span class="fm-imagemanager-cat-icon" aria-hidden="true"></span>' +
				'<span class="fm-imagemanager-cat-name">' + escHtml(name) + '</span>';
			tile.addEventListener('click', function () {
				navigateToCategory(id, name);
			});
			grid.appendChild(tile);
		});
	}

	/**
	 * Render the image grid.
	 *
	 * Each tile is a plain div container with two interactive children:
	 *   - .fm-imagemanager-img-select  (<button>) — toggles the selection
	 *   - .fm-imagemanager-img-info    (<a>)      — opens the image in the dashboard
	 * Separating them avoids nested interactive elements (invalid HTML, WCAG 4.1.2).
	 *
	 * @param {Array}   images  - Image objects from the API.
	 * @param {number}  total   - Total available image count (for pagination).
	 * @param {boolean} replace - true = replace all tiles; false = append.
	 * @returns {void}
	 */
	function renderImages(images, total, replace) {
		const grid = modalEl.querySelector('.fm-imagemanager-imgs-grid');

		if (replace) {
			grid.innerHTML = '';
		}

		const domain = cfg.domain || '';
		const projectId = cfg.projectId || '';
		const dashUrl = cfg.dashboardUrl || '';
		const newWindowLabel = s.newWindow ? ' (' + s.newWindow + ')' : ' (opens in new window)';

		if (replace && (!images || images.length === 0)) {
			const empty = document.createElement('p');
			empty.className = 'fm-imagemanager-no-results';
			empty.textContent = s.noResults || 'No images found.';
			grid.appendChild(empty);
		} else if (images && images.length) {
			images.forEach(function (image) {
				const imageId = image.newFilename || image.imageId || image.id || '';
				const owner = image.owner || projectId;
				const thumbSrc = 'https://' + domain + '/' + THUMB_FILTERS + '/' + owner + '/' + imageId;
				const infoUrl = dashUrl ? dashUrl + '/overview/edit?id=' + encodeURIComponent(imageId) : '';
				const isSelected = !!(
					(selectedImage && selectedImage.newFilename === imageId) ||
					(!selectedImage && preSelectedImageId && preSelectedImageId === imageId)
				);

				// Sync selectedImage so confirmSelection() works without requiring a click.
				if (isSelected && !selectedImage) {
					selectedImage = image;
				}
				const label = image.customTitle || image.orgFilename || imageId;

				const uploadDate = image.uploadDate ? formatDate(image.uploadDate) : '';
				const filetype = image.filetype ? image.filetype.toUpperCase() : '';
				const dims = (image.width && image.height) ? (image.width + '×' + image.height) : '';
				const metaParts = [uploadDate, filetype, dims].filter(Boolean);

				// Tile: layout container only — not interactive itself.
				const tile = document.createElement('div');
				tile.className = 'fm-imagemanager-img-tile' + (isSelected ? ' is-selected' : '');
				tile.setAttribute('data-image-id', imageId);

				// Select button — accessible name combines image label and key metadata.
				const metaLabel = metaParts.length ? ', ' + metaParts.join(', ') : '';
				const selectBtn = document.createElement('button');
				selectBtn.type = 'button';
				selectBtn.className = 'fm-imagemanager-img-select';
				selectBtn.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
				selectBtn.setAttribute('aria-label', label + metaLabel);

				selectBtn.innerHTML =
					'<div class="fm-imagemanager-img-thumb">' +
					// alt="" — button aria-label is the accessible name; img is presentational.
					'<img src="' + escAttr(thumbSrc) + '" alt="" loading="lazy" />' +
					'<div class="fm-imagemanager-img-check" aria-hidden="true"></div>' +
					'</div>' +
					'<div class="fm-imagemanager-img-meta">' +
					'<span class="fm-imagemanager-img-name">' + escHtml(label) + '</span>' +
					(metaParts.length
						? '<span class="fm-imagemanager-img-info-row">' + escHtml(metaParts.join(' · ')) + '</span>'
						: '') +
					'</div>';

				selectBtn.addEventListener('click', function () {
					onImageClick(image);
				});

				tile.appendChild(selectBtn);

				// Info link — sibling of selectBtn, positioned absolute via CSS over the thumbnail.
				if (infoUrl) {
					const infoLink = document.createElement('a');
					infoLink.href = infoUrl;
					infoLink.className = 'fm-imagemanager-img-info';
					infoLink.target = '_blank';
					infoLink.rel = 'noopener';
					infoLink.setAttribute(
						'aria-label',
						(s.infoTooltip || 'View in ImageManager') + newWindowLabel
					);
					infoLink.title = s.infoTooltip || 'View in ImageManager';
					tile.appendChild(infoLink);
				}

				grid.appendChild(tile);
			});
		}

		// Pagination: show "Load more" button when there are more images.
		const paginationEl = modalEl.querySelector('.fm-imagemanager-pagination');
		paginationEl.innerHTML = '';
		const loaded = grid.querySelectorAll('.fm-imagemanager-img-tile').length;
		if (loaded < total) {
			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button fm-imagemanager-btn-loadmore';
			btn.textContent = s.loadMore || 'Load more';
			btn.addEventListener('click', loadMore);
			paginationEl.appendChild(btn);
		}
	}

	/**
	 * Render the "Showing X of Y" count in the modal footer.
	 *
	 * @param {number} shown - Number of tiles currently in the grid.
	 * @param {number} total - Total images from the API.
	 * @returns {void}
	 */
	function renderCount(shown, total) {
		const el = modalEl && modalEl.querySelector('.fm-imagemanager-count');
		if (!el) {
			return;
		}
		if (total > 0) {
			el.textContent = (s.showingCount || 'Showing %1$d of %2$d')
				.replace('%1$d', String(shown))
				.replace('%2$d', String(total));
		} else {
			el.textContent = '';
		}
	}

	// ── Selection ───────────────────────────────────────────────────────────────

	/**
	 * Handle image select-button click: single-select toggle.
	 * Clicking a selected image deselects it; clicking an unselected one selects it.
	 *
	 * @param {Object} image - API image object.
	 * @returns {void}
	 */
	function onImageClick(image) {
		const imageId = image.newFilename || image.imageId || image.id || '';

		if (selectedImage && ((selectedImage.newFilename || '') === imageId)) {
			selectedImage = null;
		} else {
			selectedImage = image;
		}

		// Sync visual selection state on all tiles.
		modalEl.querySelectorAll('.fm-imagemanager-img-tile').forEach(function (tile) {
			const isNowSelected = !!(selectedImage && tile.getAttribute('data-image-id') === imageId);
			tile.classList.toggle('is-selected', isNowSelected);
			const btn = tile.querySelector('.fm-imagemanager-img-select');
			if (btn) {
				btn.setAttribute('aria-pressed', isNowSelected ? 'true' : 'false');
			}
		});

		setConfirmEnabled(!!selectedImage);
	}

	/**
	 * Commit the current selection to the active field and close the modal.
	 *
	 * @returns {void}
	 */
	function confirmSelection() {
		if (selectedImage && activeFieldKey) {
			updateField(activeFieldKey, selectedImage);
		}
		closeModal();
	}

	/**
	 * Write a selected image to a specific ACF field instance.
	 *
	 * @param {string} fieldKey  - ACF field key.
	 * @param {Object} imageData - API image object.
	 * @returns {void}
	 */
	function updateField(fieldKey, imageData) {
		const imageId = imageData.newFilename || imageData.imageId || imageData.id || '';
		const owner = imageData.owner || cfg.projectId || '';
		const domain = cfg.domain || '';
		const thumbSrc = 'https://' + domain + '/' + THUMB_FILTERS + '/' + owner + '/' + imageId;
		const label = imageData.customTitle || imageData.orgFilename || imageId;

		const fieldEl = document.querySelector(
			'.fm-imagemanager-field[data-field-key="' + CSS.escape(fieldKey) + '"]'
		);
		if (!fieldEl) {
			return;
		}

		const input = fieldEl.querySelector('[data-fm-imagemanager-input]');
		if (input) {
			input.value = imageId;
			// Notify ACF's Conditional Logic system that the value changed.
			// The event must bubble so ACF's delegated listeners on the field $el catch it.
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}

		const preview = fieldEl.querySelector('[data-fm-imagemanager-preview]');
		if (preview) {
			preview.src = thumbSrc;
			preview.alt = label;
			preview.style.display = '';
			const errEl = preview.closest('.fm-imagemanager-preview') &&
				preview.closest('.fm-imagemanager-preview').querySelector('.fm-imagemanager-img-error');
			if (errEl) {
				errEl.style.display = 'none';
			}
		}

		const emptyState = fieldEl.querySelector('.fm-imagemanager-empty-state');
		const previewState = fieldEl.querySelector('.fm-imagemanager-preview-state');
		if (emptyState) { emptyState.style.display = 'none'; }
		if (previewState) { previewState.style.display = ''; }
	}

	/**
	 * Clear the field value and show the empty state.
	 *
	 * @param {HTMLElement} fieldEl - The .fm-imagemanager-field wrapper element.
	 * @returns {void}
	 */
	function clearField(fieldEl) {
		if (!fieldEl) {
			return;
		}

		const input = fieldEl.querySelector('[data-fm-imagemanager-input]');
		if (input) {
			input.value = '';
			// Notify ACF's Conditional Logic system that the value changed.
			// The event must bubble so ACF's delegated listeners on the field $el catch it.
			input.dispatchEvent(new Event('change', { bubbles: true }));
		}

		const emptyState = fieldEl.querySelector('.fm-imagemanager-empty-state');
		const previewState = fieldEl.querySelector('.fm-imagemanager-preview-state');
		if (emptyState) { emptyState.style.display = ''; }
		if (previewState) { previewState.style.display = 'none'; }
	}

	// ── UI helpers ──────────────────────────────────────────────────────────────

	/**
	 * Show or hide the loading spinner and announce the state via the live region.
	 *
	 * The visual spinner uses display:none and has no aria-live attribute.
	 * The sr-only live region (#fm-imagemanager-live) is always present in the DOM
	 * so its text change is reliably picked up by screen readers (WCAG 4.1.3).
	 *
	 * @param {boolean} show
	 * @returns {void}
	 */
	function setLoading(show) {
		isLoading = show;
		const spinner = modalEl && modalEl.querySelector('.fm-imagemanager-spinner');
		if (spinner) {
			spinner.style.display = show ? '' : 'none';
		}
		const grid = modalEl && modalEl.querySelector('.fm-imagemanager-imgs-grid');
		if (grid) {
			grid.classList.toggle('is-loading', show);
		}
		const live = document.getElementById('fm-imagemanager-live');
		if (live) {
			live.textContent = show ? (s.loading || 'Loading…') : '';
		}
	}

	/**
	 * Enable or disable the confirm ("Select") button.
	 *
	 * @param {boolean} enabled
	 * @returns {void}
	 */
	function setConfirmEnabled(enabled) {
		const btn = modalEl && modalEl.querySelector('.fm-imagemanager-btn-confirm');
		if (btn) {
			btn.disabled = !enabled;
		}
	}

	/**
	 * Display an error message inside the modal.
	 *
	 * @param {string} message
	 * @returns {void}
	 */
	function showError(message) {
		const el = modalEl && modalEl.querySelector('.fm-imagemanager-error-msg');
		if (!el) {
			return;
		}
		el.textContent = message;
		el.style.display = '';
	}

	/**
	 * Clear any displayed error message.
	 *
	 * @returns {void}
	 */
	function clearError() {
		const el = modalEl && modalEl.querySelector('.fm-imagemanager-error-msg');
		if (el) {
			el.textContent = '';
			el.style.display = 'none';
		}
	}

	// ── Utilities ───────────────────────────────────────────────────────────────

	/**
	 * Build a WP REST API path with query parameters.
	 *
	 * @param {string} endpoint - Route relative to the namespace, e.g. '/images'.
	 * @param {Object} params   - Query parameters (undefined/null values are skipped).
	 * @returns {string} Full path including namespace and query string.
	 */
	function buildPath(endpoint, params) {
		const base = '/' + ns + endpoint;
		const qs = Object.entries(params)
			.filter(function (pair) { return pair[1] !== null && pair[1] !== undefined && pair[1] !== ''; })
			.map(function (pair) { return encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1]); })
			.join('&');
		return qs ? base + '?' + qs : base;
	}

	/**
	 * Escape a string for safe HTML text-node insertion.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Escape a string for use in an HTML attribute value.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escAttr(str) {
		return escHtml(str);
	}

	/**
	 * Format an ISO date string to a short locale date.
	 *
	 * @param {string} dateStr
	 * @returns {string}
	 */
	function formatDate(dateStr) {
		try {
			return new Date(dateStr).toLocaleDateString();
		} catch (e) {
			return dateStr;
		}
	}

})();
