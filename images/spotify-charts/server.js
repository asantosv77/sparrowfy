'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const PORT = Number(process.env.PORT || 8099);
const PROFILE = process.env.PROFILE_DIR || '/data/profile';
const STATE_DIR = process.env.STATE_DIR || '/data';
const STATE_FILE = path.join(STATE_DIR, 'state.json');
const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const NAV_TIMEOUT = Number(process.env.NAV_TIMEOUT_MS || 45000);
const localPort = Number(process.env.SPOTIFY_LOCAL_PORT || 16080);
const USER_AGENT = process.env.USER_AGENT ||
  'Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

const ALLOWED_ALIASES = new Set(
  (process.env.ALLOWED_ALIASES || 'regional-mx-weekly,regional-us-weekly,regional-global-weekly')
    .split(',').map((s) => s.trim()).filter(Boolean)
);

const API_PREFIX = 'https://charts-spotify-com-service.spotify.com/auth/v0/charts/';
const LOGIN_URL = 'https://charts.spotify.com/login';

let browser = null;
let browserMode = null; // 'login' | 'fetch' | null
let browserPromise = null;
let lock = Promise.resolve();

function serialize(fn) {
  const run = lock.then(fn, fn);
  lock = run.catch(() => {});
  return run;
}

function readState() {
  try {
    return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
  } catch {
    return {};
  }
}

function writeState(patch) {
  const next = { ...readState(), ...patch, updated_at: new Date().toISOString() };
  fs.mkdirSync(STATE_DIR, { recursive: true, mode: 0o700 });
  const tmp = STATE_FILE + '.' + process.pid + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(next, null, 2), { mode: 0o600 });
  fs.renameSync(tmp, STATE_FILE);
  return next;
}

function profileExists() {
  try {
    return fs.existsSync(PROFILE) && fs.readdirSync(PROFILE).length > 0;
  } catch {
    return false;
  }
}

const SINGLETON_FILES = ['SingletonLock', 'SingletonSocket', 'SingletonCookie'];

// Chromium deja estos archivos si el contenedor se recrea o se detiene de golpe.
// Al cambiar el hostname del contenedor, la siguiente ejecución cree que el perfil
// está "en uso en otro equipo" y muestra un diálogo en vez de abrir Spotify.
function clearStaleProfileLock() {
  if (browser) return;
  for (const name of SINGLETON_FILES) {
    try {
      fs.rmSync(path.join(PROFILE, name), { force: true });
    } catch {
      // Sin permisos o ya no existe: Chromium decidirá igualmente.
    }
  }
}

function isProfileLockError(error) {
  const message = String((error && error.message) || '');
  return /SingletonLock|profile appears to be in use|ProcessSingleton|profile.*locked/i.test(message);
}

function browserOptions() {
  return {
    executablePath: CHROME,
    headless: false,
    userDataDir: PROFILE,
    defaultViewport: null,
    ignoreDefaultArgs: ['--enable-automation'],
    args: [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-session-crashed-bubble',
      '--hide-crash-restore-bubble',
      '--disable-background-networking',
      '--disable-sync',
      '--metrics-recording-only',
      '--mute-audio',
      '--window-size=1280,900',
      '--lang=en-US',
      '--user-agent=' + USER_AGENT,
    ],
  };
}

async function launch(mode) {
  if (browser) return browser;
  if (browserPromise) return browserPromise;
  browserPromise = (async () => {
    fs.mkdirSync(PROFILE, { recursive: true, mode: 0o700 });
    clearStaleProfileLock();
    let created;
    try {
      created = await puppeteer.launch(browserOptions());
    } catch (error) {
      if (!isProfileLockError(error)) throw error;
      // Segundo intento: el bloqueo pudo recrearse entre la limpieza y el arranque.
      clearStaleProfileLock();
      created = await puppeteer.launch(browserOptions());
    }
    browser = created;
    browserMode = mode;
    created.on('disconnected', () => {
      browser = null;
      browserMode = null;
    });
    return created;
  })();
  try {
    return await browserPromise;
  } finally {
    browserPromise = null;
  }
}

async function closeBrowser() {
  const current = browser;
  browser = null;
  browserMode = null;
  if (!current) return;
  try {
    await current.close();
  } catch {
    // The process may already be gone.
  }
}

function friendlyError(error) {
  const message = String((error && error.message) || error || 'error_desconocido');
  if (message.includes('login_required')) {
    return {
      status: 401,
      code: 'login_required',
      message: 'La sesión de Spotify Charts no está iniciada o caducó. Usa «Conectar / renovar sesión».',
    };
  }
  if (message.includes('alias_not_allowed') || message.includes('invalid_date')) {
    return { status: 400, code: 'invalid_request', message: 'Ranking o fecha no permitidos.' };
  }
  if (message.includes('chart_response_timeout') || message.includes('Timeout')) {
    return {
      status: 504,
      code: 'chart_timeout',
      message: 'Spotify Charts no devolvió el ranking a tiempo. Se conserva el último ranking válido.',
    };
  }
  if (message.startsWith('chart_http_')) {
    return {
      status: 502,
      code: 'chart_http',
      message: 'Spotify Charts respondió con un error temporal. Se conserva el último ranking válido.',
    };
  }
  if (isProfileLockError(error)) {
    return {
      status: 409,
      code: 'profile_locked',
      message: 'El perfil del navegador quedó bloqueado por un cierre anterior. Pulsa «Conectar / renovar sesión» para reintentar.',
    };
  }
  if (message.includes('net::') || message.includes('ERR_')) {
    return {
      status: 502,
      code: 'network_error',
      message: 'No se pudo conectar con Spotify Charts. Se conserva el último ranking válido.',
    };
  }
  return { status: 500, code: 'bridge_error', message: 'El puente de Spotify Charts falló. Revisa el estado del contenedor.' };
}

async function fetchChart(alias, date = 'latest', options = {}) {
  if (!ALLOWED_ALIASES.has(alias)) throw new Error('alias_not_allowed');
  if (!/^(latest|\d{4}-\d{2}-\d{2})$/.test(date)) throw new Error('invalid_date');

  const created = await launch('fetch');
  const page = await created.newPage();
  try {
    await page.setCacheEnabled(false).catch(() => {});
    const responsePromise = page
      .waitForResponse(
        (response) =>
          response.url().startsWith(API_PREFIX + alias + '/') &&
          response.request().method() === 'GET',
        { timeout: NAV_TIMEOUT }
      )
      .catch(() => null);

    await page.goto(`https://charts.spotify.com/charts/view/${alias}/${date}`, {
      waitUntil: 'domcontentloaded',
      timeout: NAV_TIMEOUT,
    });

    const response = await responsePromise;
    if (!response) {
      const current = page.url();
      if (/accounts\.spotify\.com|\/login(?:\?|$)/.test(current)) {
        throw new Error('login_required');
      }
      throw new Error('chart_response_timeout');
    }

    const status = response.status();
    if (status === 401 || status === 403) throw new Error('login_required');
    if (!response.ok()) throw new Error('chart_http_' + status);

    const data = await response.json();
    const display = (data && data.displayChart) || {};
    return {
      ok: true,
      alias,
      requested_date: date,
      chart_date: display.date || (data && data.date) || null,
      response_url: response.url(),
      data,
    };
  } finally {
    await page.close().catch(() => {});
    if (!options.keepOpen) await closeBrowser();
  }
}

async function startLogin() {
  if (browser && browserMode !== 'login') await closeBrowser();
  const current = await launch('login');
  const pages = await current.pages();
  const page = pages[0] || (await current.newPage());
  await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: NAV_TIMEOUT }).catch(() => {});
  return {
    ok: true,
    login_open: true,
    novnc: `http://127.0.0.1:${localPort}/vnc.html?autoconnect=1&resize=scale`,
    tunnel: 'Escritorio local de esta instalación',
    message: 'Inicia sesión en Spotify dentro del navegador. Después pulsa «Comprobar conexión».',
  };
}

async function testConnection() {
  const wasLogin = browserMode === 'login';
  try {
    const result = await fetchChart('regional-mx-weekly', 'latest', { keepOpen: true });
    await closeBrowser();
    writeState({
      connected: true,
      last_success: new Date().toISOString(),
      last_error: null,
      last_error_at: null,
      last_chart_date: result.chart_date,
      last_alias: result.alias,
    });
    return {
      ok: true,
      connected: true,
      chart_date: result.chart_date,
      entries: Array.isArray(result.data && result.data.entries) ? result.data.entries.length : 0,
      message: 'Conexión correcta: Spotify Charts devolvió el ranking de México.',
    };
  } catch (error) {
    if (!wasLogin) await closeBrowser();
    const info = friendlyError(error);
    writeState({
      connected: info.code === 'login_required' ? false : readState().connected === true,
      last_error: info.message,
      last_error_at: new Date().toISOString(),
      last_error_code: info.code,
    });
    const failure = new Error(info.message);
    failure.statusCode = info.status;
    throw failure;
  }
}

function clearProfileContents() {
  let entries;
  try {
    entries = fs.readdirSync(PROFILE);
  } catch {
    return;
  }
  for (const entry of entries) {
    try {
      fs.rmSync(path.join(PROFILE, entry), { recursive: true, force: true });
    } catch {
      // Si algo queda vivo, el siguiente arranque lo reintentará.
    }
  }
}

async function disconnect() {
  await closeBrowser();
  // No se puede borrar /data/profile: es el punto de montaje del volumen.
  // Se vacía su contenido, que es lo que elimina la sesión y los bloqueos.
  clearProfileContents();
  writeState({
    connected: false,
    last_error: null,
    last_error_at: null,
    last_error_code: null,
    last_success: null,
    last_chart_date: null,
    last_alias: null,
  });
  return { ok: true, connected: false, message: 'Sesión de Spotify Charts eliminada de este equipo.' };
}

function statusPayload() {
  const state = readState();
  return {
    ok: true,
    connected: state.connected === true,
    profile: profileExists(),
    login_open: browserMode === 'login',
    last_success: state.last_success || null,
    last_error: state.last_error || null,
    last_error_at: state.last_error_at || null,
    last_chart_date: state.last_chart_date || null,
    last_alias: state.last_alias || null,
  };
}

function readJson(request) {
  return new Promise((resolve, reject) => {
    let body = '';
    request.on('data', (chunk) => {
      body += chunk;
      if (body.length > 65536) {
        reject(new Error('request_too_large'));
        request.destroy();
      }
    });
    request.on('end', () => {
      if (!body) return resolve({});
      try {
        resolve(JSON.parse(body));
      } catch {
        reject(new Error('invalid_json'));
      }
    });
    request.on('error', reject);
  });
}

function send(response, status, payload) {
  const body = JSON.stringify(payload);
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
    'Content-Length': Buffer.byteLength(body),
  });
  response.end(body);
}

const server = http.createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    if (request.method === 'GET' && url.pathname === '/health') {
      return send(response, 200, { ok: true, profile: profileExists(), browser: browserMode });
    }
    if (request.method === 'GET' && url.pathname === '/status') {
      return send(response, 200, await serialize(async () => statusPayload()));
    }
    if (request.method !== 'POST') {
      return send(response, 405, { ok: false, error: 'method_not_allowed' });
    }

    const body = await readJson(request);
    if (url.pathname === '/login/start') {
      return send(response, 200, await serialize(startLogin));
    }
    if (url.pathname === '/login/stop') {
      await serialize(closeBrowser);
      return send(response, 200, { ok: true, login_open: false });
    }
    if (url.pathname === '/test') {
      return send(response, 200, await serialize(testConnection));
    }
    if (url.pathname === '/disconnect') {
      return send(response, 200, await serialize(disconnect));
    }
    if (url.pathname === '/fetch') {
      const alias = String(body.alias || '');
      const date = String(body.date || 'latest');
      const result = await serialize(() => fetchChart(alias, date));
      writeState({
        connected: true,
        last_success: new Date().toISOString(),
        last_error: null,
        last_error_at: null,
        last_error_code: null,
        last_chart_date: result.chart_date,
        last_alias: result.alias,
      });
      return send(response, 200, result);
    }
    return send(response, 404, { ok: false, error: 'not_found' });
  } catch (error) {
    if (error && error.statusCode) {
      return send(response, error.statusCode, { ok: false, error: error.message });
    }
    const info = friendlyError(error);
    console.error('spotify-charts-bridge:', info.code);
    return send(response, info.status, { ok: false, code: info.code, error: info.message });
  }
});

server.listen(PORT, '0.0.0.0', () => {
  fs.mkdirSync(STATE_DIR, { recursive: true, mode: 0o700 });
  fs.mkdirSync(PROFILE, { recursive: true, mode: 0o700 });
  clearStaleProfileLock();
  console.log(`spotify-charts-bridge listening on ${PORT}`);
});

async function shutdown() {
  await closeBrowser();
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
