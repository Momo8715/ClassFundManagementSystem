// 班级班费管理系统 - Service Worker（PWA 离线缓存）
// 只缓存静态资源，绝不缓存动态 API / HTML 登录页（防止数据陈旧）
// 注意：不要预缓存 './'——登录态下的首页 HTML 含用户名与 CSRF token，不应写入 Cache Storage
const CACHE_NAME = 'classfund-static-v34';
const STATIC_ASSETS = [
  './assets/css/style.css?v=17',
  './assets/js/app.js?v=41',
  './assets/js/security.js?v=5',
  './assets/js/pay.js?v=23',
  './assets/js/tabs.js?v=2',
  './assets/js/modal-tx.js?v=3',
  './manifest.json'
];

// 安装：预缓存核心静态资源
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
  );
});

// 激活：清理旧缓存
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// 请求拦截：静态资源走缓存优先（stale-while-revalidate），其他网络请求不缓存
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  // 只处理同源 GET 请求
  if (e.request.method !== 'GET' || url.origin !== self.location.origin) return;

  const path = url.pathname;

  // 静态资源（css/js/图片/字体）缓存优先，后台更新
  if (/\/assets\//.test(path) || /\.(png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf)$/i.test(path)) {
    e.respondWith(
      caches.match(e.request).then((cached) => {
        const network = fetch(e.request).then((resp) => {
          if (resp && resp.status === 200) {
            const clone = resp.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
          }
          return resp;
        }).catch(() => cached);
        return cached || network;
      })
    );
    return;
  }

  // 动态 API（/api.php）和 HTML 页面：网络优先，不缓存（确保数据实时）
  if (path.includes('api.php') || path === '/' ) return;
});
