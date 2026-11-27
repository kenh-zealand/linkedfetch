// LinkedIn til Notion – gemte opslag
// Copyright (c) 2026 Kenneth Hansen

const $ = (id) => document.getElementById(id);
$("version").textContent = chrome.runtime.getManifest().version;

function render(data) {
  if (data.token !== undefined && !$("token").value) $("token").value = data.token || "";
  if (data.autoDaily !== undefined) $("auto").checked = !!data.autoDaily;
  if (data.log) {
    $("log").value = data.log.join("\n");
    $("log").scrollTop = $("log").scrollHeight;
  }
  if (data.diagnose !== undefined) $("diagOut").value = data.diagnose || "";
  if (data.status) {
    const s = data.status;
    $("status").textContent = s.running
      ? `Kører (${s.mode === "full" ? "fuld hentning" : "tjek for nye"}): ${s.seen} gennemgået på LinkedIn, ${s.found || 0} nye fundet, ${s.uploaded} lagt i Notion, ${s.queued} i kø, ${s.errors} fejl.`
      : `Ingen kørsel i gang. Sidst: ${s.uploaded} lagt i Notion, ${s.errors} fejl.`;
  }
  if (data.databaseId !== undefined && data.databaseId) {
    $("setupMsg").innerHTML = '<span class="ok">Forbundet. Databasen findes i Notion.</span>';
  }
}

chrome.storage.local
  .get(["token", "pageId", "databaseId", "autoDaily", "log", "status", "diagnose"])
  .then(render);

chrome.storage.onChanged.addListener((changes) => {
  const d = {};
  for (const [k, v] of Object.entries(changes)) d[k] = v.newValue;
  delete d.token;
  render(d);
});

$("setup").onclick = async () => {
  $("setupMsg").textContent = "Forbinder…";
  const r = await chrome.runtime.sendMessage({
    cmd: "setup",
    token: $("token").value.trim(),
    pageUrl: $("pageUrl").value.trim()
  });
  $("setupMsg").innerHTML = r?.ok
    ? `<span class="ok">${r.created ? "Database oprettet i Notion." : "Forbindelsen virker."}</span>`
    : `<span class="err">${(r?.error || "Ukendt fejl").replace(/</g, "&lt;")}</span>`;
};

$("full").onclick = () => chrome.runtime.sendMessage({ cmd: "run", mode: "full" });
$("new").onclick = () => chrome.runtime.sendMessage({ cmd: "run", mode: "new" });
$("stop").onclick = () => chrome.runtime.sendMessage({ cmd: "stop" });
$("auto").onchange = (e) => chrome.runtime.sendMessage({ cmd: "setAuto", on: e.target.checked });
// Viser kort en besked på knappen, så man kan se, at den er trykket
function flash(btn, text, ms = 2000) {
  const old = btn.dataset.label || btn.textContent;
  btn.dataset.label = old;
  btn.textContent = text;
  btn.disabled = true;
  setTimeout(() => {
    btn.textContent = old;
    btn.disabled = false;
  }, ms);
}

$("diag").onclick = () => {
  flash($("diag"), "Kører…", 4000);
  $("diagOut").value = "Kører diagnose… (LinkedIn åbner i en ny fane)";
  chrome.runtime.sendMessage({ cmd: "diagnose" });
};
$("full").addEventListener("click", () => flash($("full"), "Startet", 2000));
$("new").addEventListener("click", () => flash($("new"), "Startet", 2000));
$("stop").addEventListener("click", () => flash($("stop"), "Stopper…", 2000));
$("capture").onclick = () => {
  flash($("capture"), "Opsnapper…", 4000);
  $("diagOut").value = "Opsnapper… (LinkedIn åbner i en ny fane)";
  chrome.runtime.sendMessage({ cmd: "capture" });
};
$("copyDiag").onclick = async () => {
  try {
    await navigator.clipboard.writeText($("diagOut").value);
    flash($("copyDiag"), "Kopieret");
  } catch (_) {
    flash($("copyDiag"), "Kunne ikke kopiere");
  }
};
