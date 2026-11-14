/*
 * Service worker – Central de Dúvidas Guido Parque
 * - Arquivos do site: cache primeiro (abre na hora, inclusive sem internet)
 * - Conteúdo (api.php?action=data): rede primeiro, com a última versão salva como reserva offline
 * - Busca nos documentos: só online (responde vazio quando offline)
 * - Miniaturas de fotos do Drive: guardadas conforme são vistas (até 80)
 *
 * Ao publicar mudanças no CSS/JS/HTML, aumente a VERSION para os aparelhos baixarem a versão nova.
 */
const VERSION = "v7-2026-11-13";
const SHELL_CACHE = `gp-shell-${VERSION}`;
const DATA_CACHE = "gp-data";
const IMG_CACHE = "gp-img";
const IMG_LIMIT = 80;

const SHELL = [
  "./",
  "./index.html",
  "./css/styles.css",
  "./js/app.js",
  "./js/pwa.js",
  "./img/icons.svg",
  "./img/logo.svg",
  "./img/favicon.svg",
  "./img/icon-192.png",
  "./manifest.webmanifest",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(SHELL_CACHE)
      .then((c) => c.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith("gp-shell-") && k !== SHELL_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

async function trimCache(name, max) {
  const cache = await caches.open(name);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);

  // Painel admin e sincronização: nunca passam pelo cache
  if (url.origin === location.origin && (url.pathname.includes("/admin") || url.pathname.endsWith("sync-web.php"))) return;

  // Conteúdo do site (data.json no GitHub Pages / api.php no computador local): rede primeiro, cache como reserva
  const isData =
    url.origin === location.origin &&
    (url.pathname.endsWith("/data.json") || (url.pathname.endsWith("api.php") && url.searchParams.get("action") === "data"));
  if (isData) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(DATA_CACHE).then((c) => c.put("api-data", copy));
          }
          return res;
        })
        .catch(async () => {
          const cached = await caches.open(DATA_CACHE).then((c) => c.match("api-data"));
          return cached || new Response(JSON.stringify({ error: "offline" }), { status: 503, headers: { "Content-Type": "application/json" } });
        })
    );
    return;
  }

  // Índice de busca (search.json): rede primeiro, cache como reserva (permite buscar nos documentos offline)
  if (url.origin === location.origin && url.pathname.endsWith("/search.json")) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(DATA_CACHE).then((c) => c.put("search-index", copy));
          }
          return res;
        })
        .catch(async () => (await caches.open(DATA_CACHE).then((c) => c.match("search-index"))) || Response.error())
    );
    return;
  }

  // Busca nos documentos pela API local: só online
  if (url.origin === location.origin && url.pathname.endsWith("api.php")) {
    event.respondWith(
      fetch(req).catch(() => new Response(JSON.stringify({ results: [], offline: true }), { headers: { "Content-Type": "application/json" } }))
    );
    return;
  }

  // Miniaturas do Google Drive: cache conforme forem vistas
  if (url.hostname === "drive.google.com" && url.pathname === "/thumbnail") {
    event.respondWith(
      caches.open(IMG_CACHE).then(async (cache) => {
        const hit = await cache.match(req);
        if (hit) return hit;
        try {
          const res = await fetch(req);
          if (res.ok || res.type === "opaque") {
            cache.put(req, res.clone());
            trimCache(IMG_CACHE, IMG_LIMIT);
          }
          return res;
        } catch {
          return Response.error();
        }
      })
    );
    return;
  }

  // Fontes do Google: cache primeiro
  if (url.hostname === "fonts.googleapis.com" || url.hostname === "fonts.gstatic.com") {
    event.respondWith(
      caches.open(SHELL_CACHE).then(async (cache) => {
        const hit = await cache.match(req);
        if (hit) return hit;
        const res = await fetch(req);
        cache.put(req, res.clone());
        return res;
      })
    );
    return;
  }

  // Arquivos do próprio site: cache primeiro, atualizando em segundo plano
  if (url.origin === location.origin) {
    event.respondWith(
      caches.open(SHELL_CACHE).then(async (cache) => {
        const hit = await cache.match(req, { ignoreSearch: url.pathname.endsWith("index.html") || url.pathname.endsWith("/") });
        const network = fetch(req)
          .then((res) => {
            if (res.ok) cache.put(req, res.clone());
            return res;
          })
          .catch(() => hit || caches.match("./index.html"));
        return hit || network;
      })
    );
  }
});
