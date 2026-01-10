(function () {
  const LS_KEY = "alexk_active_job_id";
  const POLL_MS = 1200;

  function el(tag, attrs = {}, text = "") {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
    if (text) node.textContent = text;
    return node;
  }

  function ensureHud() {
    let box = document.getElementById("alexk-hud");
    if (box) return box;

    box = el("div", { id: "alexk-hud" });
    box.style.position = "fixed";
    box.style.right = "12px";
    box.style.bottom = "12px";
    box.style.zIndex = "999999";
    box.style.background = "rgba(0,0,0,0.85)";
    box.style.color = "#fff";
    box.style.font = "12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, Arial";
    box.style.padding = "10px 12px";
    box.style.borderRadius = "8px";
    box.style.maxWidth = "340px";
    box.style.whiteSpace = "pre-wrap";
    box.style.boxShadow = "0 6px 20px rgba(0,0,0,0.25)";
    box.style.cursor = "default";

    const close = el("button", { type: "button", "aria-label": "Dismiss" }, "×");
    close.style.all = "unset";
    close.style.position = "absolute";
    close.style.top = "6px";
    close.style.right = "10px";
    close.style.cursor = "pointer";
    close.style.fontSize = "16px";
    close.style.opacity = "0.85";
    close.onclick = () => {
      localStorage.removeItem(LS_KEY);
      box.remove();
    };

    box.appendChild(close);
    box.appendChild(el("div", { id: "alexk-hud-body" }, "No active job."));
    document.body.appendChild(box);
    return box;
  }

  function setBody(text) {
    const box = ensureHud();
    const body = box.querySelector("#alexk-hud-body");
    body.textContent = text;
  }

  async function fetchStatus(jobId) {
    const url = `${window.ajaxurl}?action=alexk_job_status&job_id=${encodeURIComponent(jobId)}`;
    const res = await fetch(url, { credentials: "same-origin" });
    return res.json();
  }

  function fmtAge(seconds) {
    if (seconds == null) return "?";
    if (seconds < 2) return "just now";
    if (seconds < 60) return `${seconds}s ago`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s ago`;
  }

  async function loop() {
    const jobId = localStorage.getItem(LS_KEY);
    if (!jobId) return;

    try {
      const json = await fetchStatus(jobId);
      if (!json || json.success !== true) {
        setBody(`Carousel build\nJob: ${jobId}\nStatus: unknown / expired`);
        return;
      }

      const job = json.data.job || {};
      const c = json.data.computed || {};
      const done = Number(job.done || 0);
      const total = Number(job.total || 0);
      const pct = Number(c.percent || 0);
      const status = job.status || "unknown";

      const current = job.current || {};
      const currentLine = current.filename
        ? `Currently: ${String(current.filename).split("/").pop()}${current.variant ? ` (${current.variant})` : ""}`
        : "Currently: —";

      const stalled = !!c.stalled;
      const stallAge = c.stall_age;

      const lines = [];
      lines.push("Carousel build");
      lines.push(`Job: ${jobId}`);
      lines.push(`Progress: ${done} / ${total} (${pct}%)`);
      lines.push(currentLine);
      lines.push(`Last update: ${fmtAge(stallAge)}`);
      if (job.errors) lines.push(`Errors: ${job.errors}${job.last_error ? ` (last: ${job.last_error})` : ""}`);

      if (status !== "running") lines.push(`Status: ${status}`);
      if (stalled) lines.push("⚠ STALLED (no progress) — check server/logs");

      setBody(lines.join("\n"));
    } catch (e) {
      setBody(`Carousel build\nJob: ${jobId}\nStatus: error fetching status`);
    }
  }

  // Start polling only if a job is active
  setInterval(loop, POLL_MS);
  loop();

  // Expose helpers for your bulk UI to set/clear active job id
  window.__alexkHud = {
    setActiveJob(jobId) {
      localStorage.setItem(LS_KEY, jobId);
      loop();
    },
    clear() {
      localStorage.removeItem(LS_KEY);
      const box = document.getElementById("alexk-hud");
      if (box) box.remove();
    }
  };
})();
