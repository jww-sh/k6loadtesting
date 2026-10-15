// Generic HTTP load test scenario — works against any URL.
//
// Environment variables:
//   HTTP_METHOD   HTTP method to use (default: GET)
//   HTTP_PATH     Path appended to URL (default: /)
//   HTTP_BODY     Request body string, used for POST/PUT/PATCH (default: empty)
//   HTTP_HEADERS  Extra request headers as a JSON object string (default: {})
//   THINK_MS      Milliseconds to sleep between iterations (overrides config default)
import http from 'k6/http';
import { sleep } from 'k6';
import { cfg } from '../lib/config.js';
import { runIteration } from '../lib/failures.js';

const method  = (__ENV.HTTP_METHOD  || 'GET').toUpperCase();
const reqPath = __ENV.HTTP_PATH     || '/';
const body    = __ENV.HTTP_BODY     || null;
const noBody  = ['GET', 'HEAD', 'DELETE', 'OPTIONS'].includes(method);

let extraHeaders = {};
try {
  if (__ENV.HTTP_HEADERS) extraHeaders = JSON.parse(__ENV.HTTP_HEADERS);
} catch (_) {}

export function httpLoad() {
  const url     = cfg.baseUrl + reqPath;
  const headers = Object.assign({}, extraHeaders);
  if (!noBody && !headers['Content-Type']) headers['Content-Type'] = 'application/json';

  runIteration(`${method} ${reqPath}`, () => {
    http.request(method, url, noBody ? null : body, { headers });
  });

  const jitter = (Math.random() * 2 - 1) * cfg.thinkDeviationMs;
  sleep(Math.max(0.05, (cfg.thinkMs + jitter) / 1000));
}
