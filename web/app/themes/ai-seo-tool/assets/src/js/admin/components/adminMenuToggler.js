const storageKey = "bbseoHideSystemMenus";

class AdminMenuToggler {
	constructor() {
		this.state = this.loadState();
		this.toggleButton = null;
		document.addEventListener("DOMContentLoaded", () => {
			this.injectButton();
			this.applyState();
		});
	}

	loadState() {
		try {
			const saved = window.localStorage?.getItem(storageKey);
			if (saved === null) {
				return true;
			}
			return saved === "1";
		} catch {
			return true;
		}
	}

	saveState(value) {
		try {
			window.localStorage?.setItem(storageKey, value ? "1" : "0");
		} catch {
			// ignore
		}
	}

	toggleState() {
		this.state = !this.state;
		this.saveState(this.state);
		this.applyState();
	}

	applyState() {
		if (this.state) {
			document.body.classList.add("bbseo-hide-system-menus");
			if (this.toggleButton) {
				this.toggleButton.textContent = "Show menu";
			}
		} else {
			document.body.classList.remove("bbseo-hide-system-menus");
			if (this.toggleButton) {
				this.toggleButton.textContent = "Hide menu";
			}
		}
	}

	injectButton() {
		const bar = document.getElementById("wp-admin-bar-top-secondary");
		if (!bar) {
			return;
		}

		const li = document.createElement("li");
		li.id = "wp-admin-bar-bbseo-menu-toggle";
		li.className = "menupop bbseo-admin-menu-toggle";

		const a = document.createElement("a");
		a.setAttribute("href", "#");
		a.className = "ab-item";
		a.setAttribute("aria-label", "Toggle default dashboard menus");
		a.addEventListener("click", (event) => {
			event.preventDefault();
			this.toggleState();
		});

		const icon = document.createElement("span");
		icon.className = "dashicons dashicons-visibility";

		const label = document.createElement("span");
		label.className = "bbseo-admin-menu-toggle-label";
		label.textContent = this.state ? "Show menu" : "Hide menu";

		a.appendChild(icon);
		a.appendChild(label);
		li.appendChild(a);
		bar.appendChild(li);
		this.toggleButton = label;
	}
}

new AdminMenuToggler();
