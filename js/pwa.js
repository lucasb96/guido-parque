/* Tema (claro/escuro), app instalável (PWA) e aviso de conexão. Independente do app.js. */
(() => {
  "use strict";
  const $ = (s) => document.querySelector(s);
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch {} },
  };

  /* ---------------- Tema: automático → claro → escuro ---------------- */
  const THEMES = {
    auto: { icon: "monitor", label: "automático (segue o aparelho)", next: "light" },
    light: { icon: "sun", label: "claro", next: "dark" },
    dark: { icon: "moon", label: "escuro", next: "auto" },
  };
  const metas = [...document.querySelectorAll('meta[name="theme-color"]')].map((m) => ({ el: m, original: m.content }));

  function applyTheme(mode) {
    const root = document.documentElement;
    if (mode === "auto") root.removeAttribute("data-theme");
    else root.setAttribute("data-theme", mode);
    // cor da barra do navegador/app acompanha o tema escolhido
    metas.forEach(({ el, original }) => (el.content = mode === "auto" ? original : mode === "dark" ? "#0f1720" : "#ffffff"));
    const btn = $("#theme-btn");
    if (btn) {
      const t = THEMES[mode];
      btn.querySelector("use").setAttribute("href", `img/icons.svg#i-${t.icon}`);
      btn.setAttribute("aria-label", `Tema: ${t.label}. Clique para trocar.`);
      btn.title = `Tema: ${t.label}`;
    }
  }

  let theme = store.get("gp-theme") || "auto";
  if (!THEMES[theme]) theme = "auto";
  applyTheme(theme);
  $("#theme-btn")?.addEventListener("click", () => {
    theme = THEMES[theme].next;
    store.set("gp-theme", theme === "auto" ? null : theme);
    applyTheme(theme);
    toast(`Tema ${THEMES[theme].label}`);
  });

  function toast(msg) {
    const t = $("#toast");
    if (!t) return;
    t.textContent = msg;
    t.classList.add("show");
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => t.classList.remove("show"), 2000);
  }

  /* ---------------- Service worker (funciona offline) ---------------- */
  if ("serviceWorker" in navigator && /^https?:$/.test(location.protocol)) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("sw.js").catch((e) => console.warn("Service worker não registrado:", e));
    });
  }

  /* ---------------- Aviso de conexão ---------------- */
  const offlineBar = $("#offline-bar");
  const updateOnline = () => offlineBar && (offlineBar.hidden = navigator.onLine);
  window.addEventListener("online", () => { updateOnline(); toast("Conexão restabelecida"); });
  window.addEventListener("offline", updateOnline);
  updateOnline();

  /* ---------------- Convite para instalar ---------------- */
  const card = $("#install-card");
  const isStandalone = window.matchMedia("(display-mode: standalone)").matches || navigator.standalone === true;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  const dismissedAt = +store.get("gp-install-dismissed") || 0;
  const recentlyDismissed = Date.now() - dismissedAt < 30 * 864e5; // não insiste por 30 dias
  let deferredPrompt = null;

  function showCard() {
    if (!card || isStandalone || recentlyDismissed) return;
    card.hidden = false;
  }

  // Android / Chrome / Edge: o navegador oferece o prompt de instalação
  window.addEventListener("beforeinstallprompt", (e) => {
    e.preventDefault();
    deferredPrompt = e;
    showCard();
  });

  // iPhone/iPad (Safari): não há prompt automático; mostramos o passo a passo
  if (isIOS && !isStandalone) {
    const hint = $("#install-hint");
    if (hint) {
      hint.innerHTML =
        'No Safari, toque em <strong>Compartilhar</strong> <svg class="ico" aria-hidden="true"><use href="img/icons.svg#i-share"/></svg> ' +
        'e depois em <strong>Adicionar à Tela de Início</strong> <svg class="ico" aria-hidden="true"><use href="img/icons.svg#i-square-plus"/></svg>.';
    }
    const btn = $("#install-btn");
    if (btn) btn.hidden = true;
    const dismiss = $("#install-dismiss");
    if (dismiss) dismiss.textContent = "Entendi";
    showCard();
  }

  $("#install-btn")?.addEventListener("click", async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;
    card.hidden = true;
    if (outcome === "accepted") toast("App instalado!");
  });

  $("#install-dismiss")?.addEventListener("click", () => {
    card.hidden = true;
    store.set("gp-install-dismissed", String(Date.now()));
  });

  window.addEventListener("appinstalled", () => {
    if (card) card.hidden = true;
  });
})();
