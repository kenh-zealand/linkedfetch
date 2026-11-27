// LinkedFetch – gemte LinkedIn-opslag
// Copyright (c) 2026 Kenneth Hansen

const $ = (id) => document.getElementById(id);
$("version").textContent = chrome.runtime.getManifest().version;

function render(data) {
  if (data.autoDaily !== undefined) $("auto").checked = !!data.autoDaily;
  if (data.log) {
    $("log").value = data.log.join("\n");
    $("log").scrollTop = $("log").scrollHeight;
  }
  if (data.diagnose !== undefined) $("diagOut").value = data.diagnose || "";
  if (data.status) {
    const s = data.status;
    $("status").textContent = s.running
      ? `Kører (${s.mode === "full" ? "fuld hentning" : "tjek for nye"}): ${s.seen} gennemgået på LinkedIn, ${s.found || 0} nye fundet, ${s.uploaded} sendt til keha.dk, ${s.queued || 0} i kø, ${s.errors} fejl.`
      : `Ingen kørsel i gang. Sidst: ${s.uploaded} sendt til keha.dk, ${s.errors} fejl.`;
  }
}

chrome.storage.local
  .get(["autoDaily", "log", "status", "diagnose", "kehaUrl", "kehaUser", "kehaPass"])
  .then((d) => {
    render(d);
    $("kehaUrl").value = d.kehaUrl || "https://keha.dk";
    $("kehaUser").value = d.kehaUser || "";
    $("kehaPass").value = d.kehaPass || "";
    $("kehaDash").href = $("kehaUrl").value.replace(/\/+$/, "") + "/linkedfetch";
  });

async function saveKeha() {
  $("kehaMsg").textContent = "Tester forbindelsen…";
  $("kehaDash").href = ($("kehaUrl").value.trim() || "https://keha.dk").replace(/\/+$/, "") + "/linkedfetch";
  const r = await chrome.runtime.sendMessage({
    cmd: "kehaSetup",
    url: $("kehaUrl").value,
    user: $("kehaUser").value,
    pass: $("kehaPass").value
  });
  const esc = (s) => String(s).replace(/</g, "&lt;");
  $("kehaMsg").innerHTML = !r?.ok
    ? `<span class="err">${esc(r?.error || "Ukendt fejl")}</span>`
    : `<span class="ok">Forbindelsen virker. ${r.count} opslag ligger på keha.dk.</span>`;
}
$("kehaSave").onclick = saveKeha;

chrome.storage.onChanged.addListener((changes) => {
  const d = {};
  for (const [k, v] of Object.entries(changes)) d[k] = v.newValue;
  render(d);
});

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
