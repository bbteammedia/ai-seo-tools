const TRASH_ICON = '<svg viewBox="0 0 16 16" class="h-3 w-3 stroke-current text-slate-500" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h8M6 5V3h4v2M5 5l-1 8h8l-1-8"/></svg>';

class MetricsEditorInstance {
	constructor(container) {
		this.container = container;
		this.section = container.closest(".section");
		if (!this.section) {
			return;
		}

		this.hiddenInput = this.section.querySelector(
			'input[name$="[metrics_json]"]'
		);
		if (!this.hiddenInput) {
			return;
		}

		this.headersList = container.querySelector(".metrics-editor__header-list");
		this.rowsRoot = container.querySelector(".metrics-editor__rows");
		this.emptyStateIndicator = container.querySelector(
			"[data-metrics-empty-state]"
		);
		this.emptyInput = container.querySelector(".metrics-empty-input");
		this.noteInput = container.querySelector(".metrics-note-input");

		this.handleClick = this.handleClick.bind(this);
		this.handleInput = this.handleInput.bind(this);

		this.container.addEventListener("click", this.handleClick);
		this.container.addEventListener("input", this.handleInput);

		this.syncJson();
	}

	handleClick(event) {
		const { target } = event;

		const addRowButton = target.closest(".metrics-editor__add-row");
		if (addRowButton) {
			event.preventDefault();
			this.addRow();
			return;
		}

		const addHeaderButton = target.closest(".metrics-editor__add-header");
		if (addHeaderButton) {
			event.preventDefault();
			this.addHeader();
			return;
		}

		const removeRowButton = target.closest(".metrics-editor__remove-row");
		if (removeRowButton) {
			event.preventDefault();
			this.removeRow(removeRowButton);
			this.syncJson();
			return;
		}

		const removeHeaderButton = target.closest(
			".metrics-editor__remove-header"
		);
		if (removeHeaderButton) {
			event.preventDefault();
			this.removeHeader(removeHeaderButton);
			this.syncJson();
		}
	}

	handleInput() {
		this.syncJson();
	}

	getHeaderInputs() {
		if (!this.headersList) {
			return [];
		}
		return Array.from(
			this.headersList.querySelectorAll(".metrics-editor__header-input")
		);
	}

	addHeader() {
		if (!this.headersList || !this.rowsRoot) {
			return;
		}

		const node = this.buildHeaderElement("");
		this.headersList.appendChild(node);

		this.rowsRoot
			.querySelectorAll(".metrics-editor__row")
			.forEach((row) => {
				const cell = this.buildCellElement("", "");
				const removeBtn = row.querySelector(".metrics-editor__remove-row");
				if (removeBtn) {
					row.insertBefore(cell, removeBtn);
				} else {
					row.appendChild(cell);
				}
			});

		this.syncJson();
	}

	addRow() {
		if (!this.rowsRoot) {
			return;
		}

		let headers = this.getHeaderInputs();
		if (!headers.length) {
			this.addHeader();
			headers = this.getHeaderInputs();
		}

		const row = document.createElement("div");
		row.className =
			"metrics-editor__row flex flex-wrap items-start gap-2 border border-slate-200 rounded-2xl bg-white px-3 py-2 shadow-sm shadow-slate-50";

		headers.forEach((headerInput) => {
			row.appendChild(
				this.buildCellElement("", headerInput.value || headerInput.placeholder)
			);
		});

		row.appendChild(this.buildRemoveRowButton());
		this.rowsRoot.appendChild(row);
		this.syncJson();
	}

	removeRow(button) {
		const row = button.closest(".metrics-editor__row");
		if (row && this.rowsRoot) {
			this.rowsRoot.removeChild(row);
		}
	}

	removeHeader(button) {
		const header = button.closest(".metrics-editor__header");
		if (!header || !this.headersList || !this.rowsRoot) {
			return;
		}

		const headers = Array.from(
			this.headersList.querySelectorAll(".metrics-editor__header")
		);
		if (headers.length <= 1) {
			return;
		}

		const index = headers.indexOf(header);
		if (index === -1) {
			return;
		}

		header.remove();
		this.rowsRoot
			.querySelectorAll(".metrics-editor__row")
			.forEach((row) => {
				const cells = row.querySelectorAll(".metrics-editor__cell");
				if (cells[index]) {
					cells[index].remove();
				}
			});
	}

	buildHeaderElement(label = "") {
		const wrapper = document.createElement("div");
		wrapper.className =
			"metrics-editor__header flex items-center gap-1 border border-slate-200 rounded-full bg-white px-2 py-1 shadow-sm";

		const input = document.createElement("input");
		input.type = "text";
		input.className = "metrics-editor__header-input bg-transparent text-[12px] placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-slate-300";
		input.placeholder = "Column label";
		input.value = label;
		wrapper.appendChild(input);

		const button = document.createElement("button");
		button.type = "button";
		button.className = "metrics-editor__remove-header inline-flex items-center gap-1 text-slate-400 hover:text-slate-600 focus:outline-none";
		button.setAttribute("aria-label", "Remove column");
		button.innerHTML = `<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="sr-only">Remove column</span>`;
		wrapper.appendChild(button);

		return wrapper;
	}

	buildCellElement(value = "", placeholder = "") {
		const wrapper = document.createElement("div");
		wrapper.className = "metrics-editor__cell flex-1 min-w-[150px]";
		const input = document.createElement("input");
		input.type = "text";
		input.className =
			"metrics-editor__cell-input block w-full rounded-md border border-slate-200 px-3 py-2 text-sm focus:border-slate-400 focus:ring-2 focus:ring-slate-200 focus:outline-none";
		input.value = value;
		if (placeholder) {
			input.placeholder = placeholder;
		}
		wrapper.appendChild(input);
		return wrapper;
	}

	buildRemoveRowButton() {
		const button = document.createElement("button");
		button.type = "button";
		button.className =
			"metrics-editor__remove-row inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 focus:outline-none";
		button.innerHTML = `<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="sr-only">Remove row</span>`;
		return button;
	}

	updateEmptyState() {
		if (!this.emptyStateIndicator || !this.rowsRoot) {
			return;
		}
		const hasRows = Boolean(
			this.rowsRoot.querySelector(".metrics-editor__row")
		);
		this.emptyStateIndicator.hidden = hasRows;
	}

	syncJson() {
		if (!this.hiddenInput) {
			return;
		}

		const headerInputs = this.getHeaderInputs();
		const headers =
			headerInputs.length > 0
				? headerInputs.map((input) => input.value.trim())
				: ["Metric"];

		const rows = [];
		if (this.rowsRoot) {
			this.rowsRoot.querySelectorAll(".metrics-editor__row").forEach((row) => {
				const cells = Array.from(row.querySelectorAll(".metrics-editor__cell-input"));
				const rowData = {};
				let hasValue = false;
				cells.forEach((cellInput, index) => {
					const label = headers[index] ?? `Column ${index + 1}`;
					const value = cellInput.value.trim();
					if (value !== "") {
						hasValue = true;
					}
					rowData[label] = value;
				});
				if (hasValue) {
					rows.push(rowData);
				}
			});
		}

		const payload = {
			headers,
			rows,
			empty: this.emptyInput ? this.emptyInput.value.trim() : "",
			note: this.noteInput ? this.noteInput.value.trim() : "",
		};

		this.hiddenInput.value = JSON.stringify(payload);
		this.updateEmptyState();
	}
}

document.addEventListener("DOMContentLoaded", function () {
	document
		.querySelectorAll(".bbseo-sections .metrics")
		.forEach((container) => {
			new MetricsEditorInstance(container);
		});
});
