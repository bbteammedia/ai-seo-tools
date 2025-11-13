import Choices from "choices.js";
import "choices.js/public/assets/styles/choices.min.css";

class Report {
	constructor() {
		this.state = {
			nonce: this.getHiddenValue("bbseo_refresh_sections_nonce"),
			postId: this.getHiddenValue("bbseo_post_id"),
			runListNonce:
				document.getElementById("bbseo_project_runs_nonce")?.value || "",
		};

		this.refreshBtn = document.getElementById("bbseo-refresh-data");
		this.statusEl = document.getElementById("bbseo-refresh-status");
		this.typeEl = document.getElementById("bbseo_report_type");
		this.perPageRow = document.getElementById("bbseo_per_page_row");
		this.projectEl = document.getElementById("bbseo_project_slug");
		this.runsSelect = document.querySelector('[name="bbseo_runs[]"]');
		this.choices = null;
		this.initialSelectedRuns = [];

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

	populateRuns(runs = [], selected = []) {
		if (!this.runsSelect) {
			return;
		}

		if (!this.choices) {
			this.initRunsChoices();
		}

		if (!this.choices) {
			return;
		}

		const placeholderText = this.projectEl?.value
			? "No runs found for this project"
			: "Select a project to list runs";

		const choiceData =
			runs.length > 0
				? runs.map((run) => ({
						value: run.run,
						label: run.label || run.run,
				  }))
				: [
						{
							value: "",
							label: placeholderText,
							disabled: true,
							selected: true,
							placeholder: true,
						},
				  ];

		this.choices.setChoices(choiceData, "value", "label", true);

		if (runs.length) {
			const values = Array.isArray(selected) ? selected : [];
			values.forEach((value) => {
				if (value) {
					this.choices.setChoiceByValue(value);
				}
			});
		}
	}

	async loadRuns() {
		if (!this.projectEl || !this.runsSelect) {
			return;
		}

		const project = this.projectEl.value;
		if (!project) {
			this.populateRuns([], []);
			return;
		}

		this.runsSelect.disabled = true;

		try {
			const currentSelection = this.getCurrentRunSelection();
			const payload = {
				action: "bbseo_project_runs",
				project,
				_wpnonce: this.state.runListNonce,
			};
			const response = await this.wpAjaxPost(payload);
			if (response?.json?.success) {
				this.populateRuns(response.json.data || [], currentSelection);
			} else {
				this.populateRuns([], currentSelection);
			}
		} catch (error) {
			this.populateRuns([], []);
		} finally {
			this.runsSelect.disabled = false;
		}
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

	getSelectedRunsValue(form) {
		if (this.choices) {
			const values = this.choices.getValue(true);
			if (!values) {
				return "[]";
			}
			if (Array.isArray(values)) {
				return JSON.stringify(values.filter((val) => val));
			}
			return values ? JSON.stringify([values]) : "[]";
		}
		if (!form) return "[]";
		const opts = form.querySelectorAll('[name="bbseo_runs[]"] option:checked');
		const values = Array.from(opts)
			.map((opt) => opt.value)
			.filter((val) => val);
		return JSON.stringify(values);
	}

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
			const runs = this.getSelectedRunsValue(form);

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

		if (this.projectEl) {
			this.projectEl.addEventListener("change", this.loadRuns.bind(this));
		}

		if (this.refreshBtn) {
			this.refreshBtn.addEventListener("click", this.handleRefresh.bind(this));
		}
	}

	init() {
		this.bindEvents();
		this.initRunsChoices();
		this.loadRuns();
	}

	initRunsChoices() {
		if (!this.runsSelect) {
			return;
		}

		this.initialSelectedRuns = this.parseInitialRuns();
		if (this.choices) {
			this.choices.destroy();
		}

		this.choices = new Choices(this.runsSelect, {
			removeItemButton: true,
			searchEnabled: true,
			shouldSort: false,
			placeholder: true,
			placeholderValue: "Search runs…",
			duplicateItemsAllowed: false,
			searchResultLimit: 10,
			itemSelectText: "",
		});
	}

	getCurrentRunSelection() {
		if (this.choices) {
			const values = this.choices.getValue(true);
			if (Array.isArray(values)) {
				return values.filter(Boolean);
			}
			return values ? [values] : [];
		}
		return this.initialSelectedRuns;
	}

	parseInitialRuns() {
		if (!this.runsSelect) {
			return [];
		}
		const attr = this.runsSelect.dataset.initialRuns;
		if (!attr) {
			return [];
		}
		try {
			const parsed = JSON.parse(attr);
			return Array.isArray(parsed) ? parsed : [];
		} catch {
			return [];
		}
	}
}

// Init on DOM ready
document.addEventListener("DOMContentLoaded", () => {
	new Report();
});
