class Report {
	constructor() {
		this.state = {
			nonce: this.getHiddenValue("bbseo_refresh_sections_nonce"),
			postId: this.getHiddenValue("bbseo_post_id"),
		};

		this.refreshBtn = document.getElementById("bbseo-refresh-data");
		this.statusEl = document.getElementById("bbseo-refresh-status");
		this.typeEl = document.getElementById("BBSEO_report_type");
		this.perPageRow = document.getElementById("BBSEO_per_page_row");

		this.init();
	}

	// --- Utilities ------------------------------------------------------------

	$(selector, root = document) {
		return root.querySelector(selector);
	}

	getHiddenValue(name) {
		return document.querySelector(`input[name="${name}"]`)?.value || "";
	}

	resolveEditorForm() {
		return (
			document.getElementById("post") ||
			document.querySelector("form#post") ||
			document.querySelector('form[name="post"]') ||
			document.querySelector("form.editor-post-form")
		);
	}

	setStatus(message, isError = false) {
		if (!this.statusEl) return;
		this.statusEl.textContent = message;
		this.statusEl.style.color = isError ? "#d63638" : "#646970";
	}

	togglePage() {
		if (!this.typeEl || !this.perPageRow) return;
		this.perPageRow.style.display =
			this.typeEl.value === "per_page" ? "" : "none";
	}

	async wpAjaxPost(data) {
		const url =
			typeof ajaxurl !== "undefined" ? ajaxurl : "/wp-admin/admin-ajax.php";
		const body = new URLSearchParams(data).toString();

		const controller = new AbortController();
		const timeoutId = setTimeout(() => controller.abort(), 30000);

		try {
			const res = await fetch(url, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
				},
				body,
				credentials: "same-origin",
				signal: controller.signal,
			});

			const text = await res.text();
			try {
				return { ok: res.ok, json: JSON.parse(text) };
			} catch {
				return { ok: res.ok, text };
			}
		} finally {
			clearTimeout(timeoutId);
		}
	}

	// --- Core -----------------------------------------------------------------

	async handleRefresh(e) {
		e.preventDefault();

		const form = this.resolveEditorForm();
		if (!form) {
			this.setStatus(
				"Editor form missing. Reload the page and try again.",
				true
			);
			return;
		}

		const type =
			form.querySelector('[name="bbseo_report_type"]')?.value || "general";
		const project =
			form.querySelector('[name="bbseo_project_slug"]')?.value || "";
		const page = form.querySelector('[name="bbseo_page"]')?.value || "";
		const runs = form.querySelector('[name="bbseo_runs"]')?.value || "[]";

		if (!project) {
			this.setStatus("Select a project before refreshing data.", true);
			return;
		}

		this.setStatus("Refreshing data… this may take a moment.");
		this.refreshBtn.disabled = true;
		this.refreshBtn.classList.add("updating-message");

		try {
			const payload = {
				action: "bbseo_refresh_sections",
				post_id: this.state.postId,
				_wpnonce: this.state.nonce,
				type,
				project,
				page,
				runs,
			};

			const result = await this.wpAjaxPost(payload);

			if (result?.json) {
				const res = result.json;
				if (res?.success) {
					this.setStatus("Data refreshed. Reloading…");
					setTimeout(() => window.location.reload(), 600);
				} else if (res?.data?.msg) {
					this.setStatus(res.data.msg, true);
				} else {
					this.setStatus("Refresh failed. Please try again.", true);
				}
				return;
			}

			// Fallback if response isn't JSON
			if (result?.ok) {
				this.setStatus("Data refreshed. Reloading…");
				setTimeout(() => window.location.reload(), 600);
			} else {
				this.setStatus("Refresh failed. Please try again.", true);
			}
		} catch (err) {
			const msg =
				err?.name === "AbortError"
					? "Request timed out. Please try again."
					: err?.message || "AJAX error";
			this.setStatus(msg, true);
		} finally {
			this.refreshBtn.disabled = false;
			this.refreshBtn.classList.remove("updating-message");
		}
	}

	bindEvents() {
		if (this.typeEl) {
			this.typeEl.addEventListener("change", this.togglePage.bind(this));
			this.togglePage();
		}

		if (this.refreshBtn) {
			this.refreshBtn.addEventListener("click", this.handleRefresh.bind(this));
		}
	}

	init() {
		this.bindEvents();
	}
}

// Init on DOM ready
document.addEventListener("DOMContentLoaded", () => {
	new Report();
});
