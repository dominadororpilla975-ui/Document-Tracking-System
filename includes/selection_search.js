(() => {
  const minimumSearchableOptions = 8;
  const selectSearchClass = "selection-search-control";
  const emptyMessageClass = "selection-search-empty";
  const originalHidden = new WeakMap();
  const originalGroupHidden = new WeakMap();
  const originalSearchParentDisplay = new WeakMap();
  const selectControls = new WeakMap();
  const comboboxControls = new WeakMap();
  const checkboxControls = new WeakMap();

  function createSearchInput(placeholder) {
    const input = document.createElement("input");
    input.type = "search";
    input.className = selectSearchClass;
    input.placeholder = placeholder;
    input.setAttribute("aria-label", placeholder.replace(/\.\.\.$/, ""));
    return input;
  }

  function createEmptyMessage() {
    const message = document.createElement("p");
    message.className = emptyMessageClass;
    message.setAttribute("role", "status");
    message.textContent = "No results found.";
    message.hidden = true;
    return message;
  }

  function getSelectLabel(select) {
    const label =
      select.labels && select.labels.length ? select.labels[0].textContent : "";
    const cleanLabel = (label || "").replace(/\*/g, "").trim().toLowerCase();
    if (cleanLabel.includes("department") || cleanLabel.includes("office")) {
      return "Search department...";
    }
    if (cleanLabel.includes("client")) {
      return "Search clients...";
    }
    if (
      cleanLabel.includes("staff") ||
      cleanLabel.includes("user") ||
      cleanLabel.includes("officer")
    ) {
      return "Search users...";
    }
    return "Search options...";
  }

  function placeSelectSearch(select, search, emptyMessage) {
    const wrappingLabel = select.closest("label");
    const insertionTarget = wrappingLabel || select;
    const parent = insertionTarget.parentNode;
    if (!parent) {
      return false;
    }
    parent.insertBefore(search, insertionTarget);
    parent.insertBefore(emptyMessage, insertionTarget);
    return true;
  }

  function filterSelect(select, search, emptyMessage) {
    const query = search.value.trim().toLocaleLowerCase();
    let matchCount = 0;
    const options = Array.from(select.options);

    options.forEach((option) => {
      if (!originalHidden.has(option)) {
        originalHidden.set(option, option.hidden);
      }
      const matches =
        !query ||
        option.textContent.toLocaleLowerCase().includes(query) ||
        option.value.toLocaleLowerCase().includes(query);
      const isPlaceholder = option.value === "";
      option.hidden =
        originalHidden.get(option) || (!matches && !isPlaceholder);
      if (!isPlaceholder && matches && !originalHidden.get(option)) {
        matchCount += 1;
      }
    });

    select.querySelectorAll("optgroup").forEach((group) => {
      if (!originalGroupHidden.has(group)) {
        originalGroupHidden.set(group, group.hidden);
      }
      group.hidden =
        originalGroupHidden.get(group) ||
        Array.from(group.options).every((option) => option.hidden);
    });
    emptyMessage.hidden = query === "" || matchCount > 0;
  }

  function setupSearchableCombobox(select) {
    const options = Array.from(select.options).filter(
      (option) => option.value !== "",
    );
    let controls = comboboxControls.get(select);

    if (options.length < minimumSearchableOptions) {
      if (controls) {
        controls.wrapper.remove();
        select.hidden = false;
        select.required = controls.required;
        if (controls.label) {
          controls.label.htmlFor = select.id;
        }
        comboboxControls.delete(select);
      }
      return;
    }

    if (!controls) {
      const wrapper = document.createElement("div");
      wrapper.className = "searchable-select";
      const input = createSearchInput(getSelectLabel(select));
      input.classList.add("searchable-select-input");
      input.id = `${select.id}-search`;
      input.setAttribute("role", "combobox");
      input.setAttribute("aria-autocomplete", "list");
      input.setAttribute("aria-expanded", "false");
      const listbox = document.createElement("div");
      listbox.className = "searchable-select-options";
      listbox.setAttribute("role", "listbox");
      listbox.hidden = true;
      const emptyMessage = createEmptyMessage();

      select.parentNode.insertBefore(wrapper, select);
      wrapper.append(input, listbox, emptyMessage);
      const required = select.required;
      const label = select.labels?.[0];
      if (label) {
        label.htmlFor = input.id;
      }
      select.hidden = true;
      select.required = false;
      controls = { wrapper, input, listbox, emptyMessage, required, label };
      comboboxControls.set(select, controls);

      input.addEventListener("focus", () => {
        renderComboboxOptions(select, controls, true);
      });
      input.addEventListener("input", () => {
        if (
          select.value &&
          select.selectedOptions[0]?.textContent !== input.value
        ) {
          select.value = "";
          select.dispatchEvent(new Event("change", { bubbles: true }));
        }
        input.setCustomValidity(
          input.value === "" ? "" : "Please select an option from the list.",
        );
        renderComboboxOptions(select, controls, true);
      });
      input.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          controls.listbox.hidden = true;
          input.setAttribute("aria-expanded", "false");
        }
        if (event.key === "ArrowDown") {
          event.preventDefault();
          controls.listbox.querySelector('button[role="option"]')?.focus();
        }
      });
      listbox.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          controls.listbox.hidden = true;
          input.setAttribute("aria-expanded", "false");
          input.focus();
        }
      });
      listbox.addEventListener("click", (event) => {
        const optionButton = event.target.closest('button[role="option"]');
        if (!optionButton) {
          return;
        }
        select.value = optionButton.dataset.value;
        input.value = optionButton.textContent;
        input.setCustomValidity("");
        controls.listbox.hidden = true;
        input.setAttribute("aria-expanded", "false");
        select.dispatchEvent(new Event("change", { bubbles: true }));
      });
      select.addEventListener("change", () => {
        synchronizeComboboxValue(select, controls);
      });
      select.form?.addEventListener("submit", (event) => {
        if (controls.required && !select.value) {
          event.preventDefault();
          input.setCustomValidity("Please select an option.");
          input.reportValidity();
        } else {
          input.setCustomValidity("");
        }
      });
      document.addEventListener("click", (event) => {
        if (!wrapper.contains(event.target)) {
          controls.listbox.hidden = true;
          input.setAttribute("aria-expanded", "false");
        }
      });
    }

    controls.required = select.required || controls.required;
    controls.input.required = controls.required;
    controls.input.disabled = select.disabled;
    synchronizeComboboxValue(select, controls);
  }

  function synchronizeComboboxValue(select, controls) {
    const selectedOption = select.selectedOptions[0];
    controls.input.value =
      selectedOption && selectedOption.value !== ""
        ? selectedOption.textContent
        : "";
    controls.input.setCustomValidity("");
    renderComboboxOptions(select, controls, false);
  }

  function renderComboboxOptions(select, controls, showList) {
    const query = controls.input.value.trim().toLocaleLowerCase();
    const options = Array.from(select.options).filter(
      (option) => option.value !== "",
    );
    const matchingOptions = options.filter(
      (option) =>
        option.textContent.toLocaleLowerCase().includes(query) ||
        option.value.toLocaleLowerCase().includes(query),
    );
    controls.listbox.replaceChildren();
    matchingOptions.forEach((option) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "searchable-select-option";
      button.setAttribute("role", "option");
      button.setAttribute("aria-selected", String(option.selected));
      button.dataset.value = option.value;
      button.textContent = option.textContent;
      controls.listbox.append(button);
    });
    controls.listbox.hidden = !showList;
    controls.emptyMessage.hidden = !showList || matchingOptions.length > 0;
    controls.input.setAttribute("aria-expanded", String(showList));
  }

  function setupSelect(select) {
    // Keep native selects as the sole control; clean up any legacy search enhancement.
    const selectControlsForElement = selectControls.get(select);
    selectControlsForElement?.search.remove();
    selectControlsForElement?.emptyMessage.remove();
    selectControls.delete(select);
    Array.from(select.options).forEach((option) => {
      if (originalHidden.has(option)) {
        option.hidden = originalHidden.get(option);
      }
    });
    select.querySelectorAll("optgroup").forEach((group) => {
      group.hidden = originalGroupHidden.get(group) || false;
    });

    const combobox = comboboxControls.get(select);
    if (combobox) {
      combobox.wrapper.remove();
      select.hidden = false;
      select.required = combobox.required;
      if (combobox.label) {
        combobox.label.htmlFor = select.id;
      }
      comboboxControls.delete(select);
    }
  }

  function getCheckboxGroups() {
    return Array.from(
      document.querySelectorAll("fieldset, .rbac-role-permissions"),
    ).filter(
      (group) =>
        group.querySelectorAll('input[type="checkbox"], input[type="radio"]')
          .length > 0,
    );
  }

  function setupCheckboxGroup(group) {
    const choices = Array.from(
      group.querySelectorAll('input[type="checkbox"], input[type="radio"]'),
    );
    const controls = checkboxControls.get(group);
    const searchParent =
      group.querySelector("#departmentSearch")?.parentElement;
    const choiceLabels = choices
      .map((choice) => choice.closest("label"))
      .filter(Boolean);

    if (choices.length < minimumSearchableOptions) {
      if (searchParent) {
        if (!originalSearchParentDisplay.has(searchParent)) {
          originalSearchParentDisplay.set(
            searchParent,
            searchParent.style.display,
          );
        }
        searchParent.hidden = true;
        searchParent.style.display = "none";
      }
      if (controls?.searchParent) {
        controls.searchParent.hidden = true;
        controls.searchParent.style.display = "none";
      } else if (controls?.ownsSearch) {
        controls?.search.remove();
      }
      controls?.emptyMessage.remove();
      checkboxControls.delete(group);
      choiceLabels.forEach((label) => {
        label.hidden = false;
      });
      return;
    }

    let search = controls?.search || group.querySelector("#departmentSearch");
    let emptyMessage = controls?.emptyMessage;
    let ownsSearch = controls?.ownsSearch || false;
    if (!search) {
      search = createSearchInput("Search options...");
      const legend = group.querySelector(":scope > legend");
      const anchor = legend ? legend.nextSibling : group.firstChild;
      group.insertBefore(search, anchor);
      ownsSearch = true;
    }
    if (!emptyMessage) {
      emptyMessage = createEmptyMessage();
      (searchParent || search).insertAdjacentElement("afterend", emptyMessage);
    }
    if (searchParent) {
      if (!originalSearchParentDisplay.has(searchParent)) {
        originalSearchParentDisplay.set(
          searchParent,
          searchParent.style.display,
        );
      }
      searchParent.hidden = false;
      searchParent.style.display =
        originalSearchParentDisplay.get(searchParent);
    }
    checkboxControls.set(group, {
      search,
      emptyMessage,
      searchParent,
      ownsSearch,
    });

    if (!search.dataset.searchBound) {
      search.addEventListener("input", () => {
        const query = search.value.trim().toLocaleLowerCase();
        let matchCount = 0;
        choiceLabels.forEach((label) => {
          const matches =
            !query || label.textContent.toLocaleLowerCase().includes(query);
          label.hidden = !matches;
          if (matches) {
            matchCount += 1;
          }
        });
        emptyMessage.hidden = query === "" || matchCount > 0;
      });
      search.dataset.searchBound = "true";
    }
    search.dispatchEvent(new Event("input"));
  }

  function synchronizeSearchControls() {
    document.querySelectorAll("select").forEach(setupSelect);
    getCheckboxGroups().forEach(setupCheckboxGroup);
  }

  function initialize() {
    synchronizeSearchControls();
    const observer = new MutationObserver((records) => {
      const selectionChanged = records.some((record) => {
        if (
          record.target instanceof Element &&
          record.target.closest(".searchable-select")
        ) {
          return false;
        }
        if (
          record.target instanceof Element &&
          record.target.closest("select")
        ) {
          return true;
        }

        return [...record.addedNodes, ...record.removedNodes].some(
          (node) =>
            node instanceof Element &&
            (node.matches(
              'select, option, optgroup, fieldset, input[type="checkbox"], input[type="radio"], .rbac-role-permissions',
            ) ||
              node.querySelector(
                'select, option, optgroup, fieldset, input[type="checkbox"], input[type="radio"], .rbac-role-permissions',
              )),
        );
      });
      if (selectionChanged) {
        synchronizeSearchControls();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initialize, { once: true });
  } else {
    initialize();
  }
})();
