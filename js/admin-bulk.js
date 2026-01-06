/* js/admin-bulk.js (RESET + AMEND)
 * AlexK Carousel — minimal, readable bulk UI
 *
 * Keeps the "green dot = in carousel" indicator and adds minimal bulk Add/Remove logic:
 * - Green dot is visible in normal state AND bulk select state (only for included items)
 * - Add/Remove buttons are ONLY visible in Bulk Select mode
 * - Buttons only show when the action is actually possible for the current selection
 * - Bulk actions update BOTH:
 *     - server via AJAX (existing PHP handlers)
 *     - client model so the checkbox UI reflects state immediately
 * - After action, auto-exit Bulk Select (returns to normal library)
 *
 * Assumes PHP adds: response['alexk_in_carousel'] via wp_prepare_attachment_for_js (already in your plugin).
 */

(() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // ---------------------------
  // Bulk mode detection (your WP 6.9 toolbar)
  // ---------------------------
  function isBulkModeActive() {
    const toggle = $("button.select-mode-toggle-button");
    if (!toggle) return false;
    return (toggle.textContent || "").trim().toLowerCase() === "cancel";
  }

  function exitBulkSelectMode() {
    const toggle = $("button.select-mode-toggle-button");
    if (!toggle) return;
    if ((toggle.textContent || "").trim().toLowerCase() === "cancel") toggle.click();
  }

  // ---------------------------
  // Green dot indicator
  // ---------------------------
  function ensureDotEl(tile) {
    if (!tile) return null;
    let dot = tile.querySelector(".alexk-carousel-dot");
    if (dot) return dot;
    dot = document.createElement("span");
    dot.className = "alexk-carousel-dot";
    dot.setAttribute("aria-hidden", "true");
    tile.appendChild(dot);
    return dot;
  }

  function applyIncludedStateToTile(tile, included) {
    if (!tile) return;
    if (included) {
      tile.classList.add("alexk-in-carousel");
      ensureDotEl(tile);
    } else {
      tile.classList.remove("alexk-in-carousel");
      const dot = tile.querySelector(".alexk-carousel-dot");
      if (dot) dot.remove();
    }
  }

  function patchWpMediaAttachmentRender() {
    const Attachment = window.wp?.media?.view?.Attachment;
    if (!Attachment) return false;

    const proto = Attachment.prototype;
    if (proto.__alexkPatched) return true;
    proto.__alexkPatched = true;

    const originalRender = proto.render;
    proto.render = function (...args) {
      const out = originalRender.apply(this, args);
      try {
        const included = !!this.model?.get?.("alexk_in_carousel");
        applyIncludedStateToTile(this.el, included);
      } catch {}
      return out;
    };

    return true;
  }

  // ---------------------------
  // Selection helpers
  // ---------------------------
  function selectedTiles() {
    return $$(".attachments .attachment.selected");
  }

  function partitionSelection() {
    const inCarousel = [];
    const notInCarousel = [];
    for (const el of selectedTiles()) {
      const id = parseInt(el.getAttribute("data-id"), 10);
      if (!Number.isFinite(id)) continue;
      (el.classList.contains("alexk-in-carousel") ? inCarousel : notInCarousel).push(id);
    }
    return { inCarousel, notInCarousel };
  }

  // ---------------------------
  // AJAX + model sync 
  // ---------------------------
  async function postAjax(action, ids) {
    const body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", window.ALEXK_BULK?.nonce || "");
    body.set("ids", (ids || []).join(","));

    const res = await fetch(window.ajaxurl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    });
    return res.json();
  }

  function setWpMediaModelFlag(id, included) {
    try {
      const att = window.wp?.media?.attachment?.(id);
      if (!att || typeof att.set !== "function") return;

      // Our UI flag
      att.set("alexk_in_carousel", !!included);

      // The actual meta key used by the checkbox UI
      att.set("alexk_include_in_carousel", included ? "1" : "0");

      if (typeof att.trigger === "function") att.trigger("change");
    } catch {}
  }


  function updateUiForIds(ids, included) {
    for (const id of ids) {
      const tile = document.querySelector(`.attachments .attachment[data-id="${id}"]`);
      if (tile) applyIncludedStateToTile(tile, included);
      setWpMediaModelFlag(id, included);
     

    }
  }

  // ---------------------------
  // Toolbar buttons (minimal)
  // ---------------------------
  function findToolbarContainer() {
    const toolbar = $(".media-toolbar.wp-filter") || $(".media-toolbar");
    if (!toolbar) return null;
    return $(".media-toolbar-secondary", toolbar) || toolbar;
  }

  function ensureButtons() {
    const container = findToolbarContainer();
    if (!container) return false;

    if (!$("#alexk-add-to-carousel")) {
      const add = document.createElement("button");
      add.id = "alexk-add-to-carousel";
      add.type = "button";
      add.className = "button button-primary";
      add.textContent = "Add to carousel";
      container.append(add);
      add.addEventListener("click", onAdd);
    }

    if (!$("#alexk-remove-from-carousel")) {
      const rm = document.createElement("button");
      rm.id = "alexk-remove-from-carousel";
      rm.type = "button";
      rm.className = "button";
      rm.textContent = "Remove from carousel";
      container.append(rm);
      rm.addEventListener("click", onRemove);
    }

    return true;
  }

  function updateButtonsVisibility() {
    const add = $("#alexk-add-to-carousel");
    const rm = $("#alexk-remove-from-carousel");
    if (!add || !rm) return;

    // Never show outside Bulk Select mode
    if (!isBulkModeActive()) {
      add.style.display = "none";
      rm.style.display = "none";
      return;
    }

    const { inCarousel, notInCarousel } = partitionSelection();
    add.style.display = notInCarousel.length ? "" : "none";
    rm.style.display = inCarousel.length ? "" : "none";
  }

  // ---------------------------
  // Click handlers
  // ---------------------------
  async function onAdd() {
    const { inCarousel, notInCarousel } = partitionSelection();
    const toAdd = notInCarousel;
    const skipped = inCarousel;

    if (!toAdd.length) {
      alert("All selected items are already in the carousel.");
      return;
    }

    const msg = skipped.length
      ? `Add ${toAdd.length} item(s) to the carousel?\n\nSkipping ${skipped.length} already included.`
      : `Add ${toAdd.length} item(s) to the carousel?`;

    if (!confirm(msg)) return;

    const json = await postAjax("alexk_bulk_add_to_carousel", toAdd);
    if (!json?.success) {
      alert(json?.data?.message || "Bulk add failed.");
      return;
    }

    // reload and exit bulk selection mode resets the state. Reload refreshes the checkbox. Both are essential.
    updateUiForIds(toAdd, true);
    alert(`Added ${json.data.updated} item(s).`);
    exitBulkSelectMode();
    updateButtonsVisibility();
    window.location.reload();
  }

  async function onRemove() {
    const { inCarousel, notInCarousel } = partitionSelection();
    const toRemove = inCarousel;
    const skipped = notInCarousel;

    if (!toRemove.length) {
      alert("None of the selected items are currently in the carousel.");
      return;
    }

    const msg = skipped.length
      ? `Remove ${toRemove.length} item(s) from the carousel?\n\nSkipping ${skipped.length} not in carousel.`
      : `Remove ${toRemove.length} item(s) from the carousel?`;

    if (!confirm(msg)) return;

    const json = await postAjax("alexk_bulk_remove_from_carousel", toRemove);
    if (!json?.success) {
      alert(json?.data?.message || "Remove failed.");
      return;
    }

     // reload and exit bulk selection mode resets the state. Reload refreshes the checkbox. Both are essential.
    updateUiForIds(toRemove, false);
    alert(`Removed ${json.data.updated} item(s).`);
    exitBulkSelectMode();
    updateButtonsVisibility();
    window.location.reload();
  }

  // ---------------------------
  // Observers (small)
  // ---------------------------
  function bindObservers() {
    // Selection changes happen via clicks in the grid
    document.addEventListener(
      "click",
      (e) => {
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;

        if (t.closest(".attachments") || t.closest("button.select-mode-toggle-button")) {
          setTimeout(updateButtonsVisibility, 30);
        }
      },
      true
    );

    // Toggle button text changes (Bulk select <-> Cancel)
    const toggle = $("button.select-mode-toggle-button");
    if (toggle) {
      const mo = new MutationObserver(() => updateButtonsVisibility());
      mo.observe(toggle, { childList: true, subtree: true, attributes: true });
    }

    // WP may re-render toolbar/tiles; re-ensure buttons and state
    const domMo = new MutationObserver(() => {
      ensureButtons();
      updateButtonsVisibility();
    });
    domMo.observe(document.documentElement, { childList: true, subtree: true });
  }

  function boot() {
    // Patch tile rendering for dot
    if (!patchWpMediaAttachmentRender()) {
      const mo = new MutationObserver(() => {
        if (patchWpMediaAttachmentRender()) mo.disconnect();
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    }

    ensureButtons();
    updateButtonsVisibility();
    bindObservers();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();