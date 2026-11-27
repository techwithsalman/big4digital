// DigitalsNova Enterprise POS Offline Service Worker & PWA Cache
const CACHE_NAME = 'digitalsnova-pos-v21-offline';
const PRECACHE_ASSETS = [
  './',
  './index.html',
  './manifest.json',
  './favicon.ico',
  './icon.svg',
  './icon-192.png',
  './icon-512.png',
  './icon-maskable-512.png',
  './assets/index-DNv15Live.js',
  './assets/index-DNv15Live.js?v=20261111v21',
  './assets/index-Db2yxC1M.css',
  './assets/index-Db2yxC1M.css?v=20261111v21',
  'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap'
];

function fetchWithTimeout(req, ms) {
  return new Promise((resolve, reject) => {
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = setTimeout(() => {
      if (controller) try { controller.abort(); } catch {}
      reject(new Error('Network timeout'));
    }, ms);
    const opts = { cache: 'no-cache' };
    if (controller) opts.signal = controller.signal;
    fetch(req, opts).then((res) => {
      clearTimeout(timer);
      resolve(res);
    }).catch((err) => {
      clearTimeout(timer);
      reject(err);
    });
  });
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      console.log('[SW] Pre-caching offline POS shell v21');
      return Promise.all(
        PRECACHE_ASSETS.map((url) =>
          fetch(url, { cache: 'no-cache' }).then((res) => {
            if (res && (res.ok || res.type === 'opaque')) {
              return cache.put(url, res);
            }
          }).catch((err) => {
            console.warn('[SW] Pre-cache skipped for:', url, err);
          })
        )
      );
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            console.log('[SW] Purging obsolete cache:', key);
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim()).then(() =>
      self.clients.matchAll({ includeUncontrolled: true, type: 'window' }).then((clients) => {
        clients.forEach((client) => {
          client.postMessage({ type: 'SW_UPDATED', version: 'v21' });
        });
      })
    )
  );
});

self.addEventListener('message', (event) => {
  if (!event.data) return;
  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  } else if (event.data.type === 'CLEAR_CACHES') {
    event.waitUntil(
      caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k)))).then(() => self.skipWaiting())
    );
  }
});

self.addEventListener('sync', (event) => {
  if (event.tag === 'digitalsnova-sync-offline') {
    event.waitUntil(
      self.clients.matchAll({ includeUncontrolled: true, type: 'window' }).then((clients) => {
        clients.forEach((client) => {
          client.postMessage({ type: 'SYNC_OFFLINE_QUEUES' });
        });
      })
    );
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  // Prevent any browser window from loading sw.js directly as a navigation page
  if (req.mode === 'navigate' && url.pathname.endsWith('sw.js')) {
    event.respondWith(Response.redirect('/portal/', 302));
    return;
  }


  // Never intercept downloadable setup packages
  if (url.pathname.includes('/downloads/')) {
    return;
  }

  // API routes: Network First with clean offline failover JSON
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(req, { cache: 'no-store' }).catch(() => {
        return new Response(
          JSON.stringify({
            offline: true,
            status: 'offline_buffered',
            message: 'Device is offline. Transaction safely queued in IndexedDB & local storage.'
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
      })
    );
    return;
  }

  // Network-First with timeout for HTML shell, manifest, JS, and CSS so code updates reach installed apps immediately when online
  const isCodeOrShell =
    req.mode === 'navigate' ||
    url.pathname.endsWith('/') ||
    url.pathname.endsWith('.html') ||
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('manifest.json');

  if (isCodeOrShell) {
    event.respondWith(
      fetchWithTimeout(req, 4500).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200 && (networkResponse.type === 'basic' || networkResponse.type === 'cors')) {
          const toCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, toCache));
        }
        return networkResponse;
      }).catch(() =>
        caches.match(req).then((exactMatch) =>
          exactMatch ||
          caches.match(req, { ignoreSearch: true }) ||
          (req.mode === 'navigate' ? caches.match('./index.html', { ignoreSearch: true }) : undefined)
        ).then((res) => res || new Response('Offline', { status: 503, statusText: 'Offline', headers: { 'Content-Type': 'text/plain' } }))
      )
    );
    return;
  }

  // Stale-While-Revalidate for images, icons, and fonts
  event.respondWith(
    caches.match(req).then((exactMatch) => {
      if (exactMatch) {
        fetch(req, { cache: 'no-cache' }).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
            caches.open(CACHE_NAME).then((cache) => cache.put(req, networkResponse));
          }
        }).catch(() => {});
        return exactMatch;
      }

      return caches.match(req, { ignoreSearch: true }).then((searchIgnoredMatch) => {
        return fetch(req, { cache: 'no-cache' }).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
            const toCache = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(req, toCache));
          }
          return networkResponse;
        }).catch(() => {
          if (searchIgnoredMatch) return searchIgnoredMatch;
          if (req.mode === 'navigate') {
            return caches.match('./index.html', { ignoreSearch: true }).then((m) => m || new Response('Offline', { status: 503, statusText: 'Offline', headers: { 'Content-Type': 'text/plain' } }));
          }
          return new Response('Offline', { status: 503, statusText: 'Offline', headers: { 'Content-Type': 'text/plain' } });
        });
      });
    })
  );
});
