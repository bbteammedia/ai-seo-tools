class SectionsController {
	constructor(options) {
		this.nonceInputName = options.nonceInputName || "bbseo_sections_nonce";
		this.postIdInputName = options.postIdInputName || "bbseo_post_id";

		this.nonce = this.getHiddenValue(this.nonceInputName);
		this.postId = this.getHiddenValue(this.postIdInputName);

		this.sectionsRoot = this.qs(".bbseo-sections");

		// Bind instance methods once
		this.onDocumentClick = this.onDocumentClick.bind(this);
		this.onClickAll = this.onClickAll.bind(this);

		this.bindEvents();
	}

	// ---------------- Utilities ----------------

	qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	qsa(sel, root) {
		return (root || document).querySelectorAll(sel);
	}

	getHiddenValue(name) {
		var el = document.querySelector('input[name="' + name + '"]');
		return el ? el.value : "";
	}

	resolveEditorForm() {
		return (
			document.getElementById("post") ||
			this.qs("form#post") ||
			this.qs('form[name="post"]') ||
			this.qs("form.editor-post-form")
		);
	}

	getFormValue(form, selector, fallback) {
		var el = form ? form.querySelector(selector) : null;
		return el && typeof el.value !== "undefined" ? el.value : fallback;
	}

	getSelectedRunsValue(form) {
		const opts = form ? form.querySelectorAll('[name="bbseo_runs[]"] option:checked') : [];
		var runs = [];
		Array.prototype.forEach.call(opts, function (opt) {
			if (opt.value) {
				runs.push(opt.value);
			}
		});
		return JSON.stringify(runs);
	}

	wpAjaxPost(data) {
		var url =
			typeof ajaxurl !== "undefined" ? ajaxurl : "/wp-admin/admin-ajax.php";
		var body = new URLSearchParams(data).toString();

		return fetch(url, {
			method: "POST",
			headers: {
				"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
			},
			body: body,
			credentials: "same-origin",
		}).then(function (res) {
			return res.text().then(function (text) {
				try {
					return { ok: res.ok, json: JSON.parse(text) };
				} catch (e) {
					return { ok: res.ok, text: text };
				}
			});
		});
	}

	setEditorContent(editorId, content) {
		var val = content || "";
		if (window.tinymce && typeof window.tinymce.get === "function") {
			var editor = window.tinymce.get(editorId);
			if (editor && typeof editor.setContent === "function") {
				editor.setContent(val);
			}
		}
		var textarea = document.getElementById(editorId);
		if (textarea) textarea.value = val;
	}

	// ---------------- Core ----------------

	aiForSection(sectionId) {
		var form = this.resolveEditorForm();
		if (!form) return;

		var sectionEl = this.qs(
			'.bbseo-sections .section[data-id="' + sectionId + '"]'
		);
		if (!sectionEl) return;

		var editorId = sectionEl.getAttribute("data-editor") || "";

		var type = this.getFormValue(form, '[name="bbseo_report_type"]', "general");
		var project = this.getFormValue(form, '[name="bbseo_project_slug"]', "");
		var page = this.getFormValue(form, '[name="bbseo_page"]', "");
		var runs = this.getSelectedRunsValue(form);

		sectionEl.classList.add("opacity-60", "pointer-events-none");

		var payload = {
			action: "bbseo_sections_generate",
			post_id: this.postId,
			section_id: sectionId,
			type: type,
			project: project,
			page: page,
			runs: runs,
			_wpnonce: this.nonce,
		};

		var self = this;
		this.wpAjaxPost(payload)
			.then(function (result) {
				if (result && result.json && result.json.success) {
					var data = result.json.data || {};
					self.setEditorContent(editorId, data.body || "");

					var recoList = data.reco_list || [];
					var recoEl = sectionEl.querySelector('textarea[name*="[reco_raw]"]');
					if (recoEl) {
						recoEl.value = recoList.join("\n");
					}
				}
			})
			.finally(function () {
				sectionEl.classList.remove("opacity-60", "pointer-events-none");
			});
	}

	// ---------------- Events ----------------

	onDocumentClick(e) {
		if (!e.target || typeof e.target.closest !== "function") return;

		// Single-section generate
		var oneBtn = e.target.closest(".bbseo-ai-one");
		if (oneBtn) {
			e.preventDefault();
			var id = oneBtn.getAttribute("data-id") || "";
			if (id) this.aiForSection(id);
			return;
		}
	}

	onClickAll(e) {
		e.preventDefault();
		var self = this;
		var nodes = this.qsa(".bbseo-sections .section");
		Array.prototype.forEach.call(nodes, function (sectionEl) {
			var id = sectionEl.getAttribute("data-id") || "";
			if (id) self.aiForSection(id);
		});
	}

	bindEvents() {
		document.addEventListener("click", this.onDocumentClick);

		var allBtn = document.getElementById("bbseo-ai-all");
		if (allBtn) {
			allBtn.addEventListener("click", this.onClickAll);
		}
	}
}

// ----------- Initialize on DOM ready -----------
document.addEventListener("DOMContentLoaded", function () {
	new SectionsController({
		nonceInputName: "bbseo_sections_post_id",
		postIdInputName: "bbseo_post_id",
	});
});
