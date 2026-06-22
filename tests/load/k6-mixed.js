// Mixed-workload load test modeling ~50→100 concurrent library users.
//
//   k6 run -e BASE_URL=https://staging.example.org -e LOAD_USER=admin@e2e.test -e LOAD_PASS='secret' tests/load/k6-mixed.js
//
// Each virtual user logs in once, then loops the common read-heavy actions
// (dashboard, inventory browse, search, stats, my-borrows) with ~20% writes
// (borrow requests). Thresholds fail the run if latency or error budgets blow out.
import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8080';
const USER = __ENV.LOAD_USER || 'admin@e2e.test';
const PASS = __ENV.LOAD_PASS || 'adminadmin!';

const appErrors = new Rate('app_errors');

export const options = {
  scenarios: {
    mixed: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 50 },   // ramp to 50 concurrent
        { duration: '1m',  target: 50 },    // hold
        { duration: '30s', target: 100 },   // ramp to 100 (peak)
        { duration: '2m',  target: 100 },   // hold peak
        { duration: '30s', target: 0 },     // ramp down
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    http_req_failed:   ['rate<0.01'],                 // <1% transport-level failures
    http_req_duration: ['p(95)<800', 'p(99)<2000'],   // p95 < 0.8s, p99 < 2s
    app_errors:        ['rate<0.02'],                 // <2% app-level {success:false}
  },
};

function login() {
  http.get(`${BASE}/login.php`);
  const res = http.post(`${BASE}/login.php`, { mode: 'login', email: USER, password: PASS });
  const m = res.body && res.body.match(/name="csrf-token" content="([^"]+)"/);
  return m ? m[1] : '';
}

function appCheck(res, allowFail) {
  const httpOk = check(res, { 'status 200': (r) => r.status === 200 });
  let appOk = true;
  try { appOk = JSON.parse(res.body).success !== false; } catch (e) { appOk = res.status === 200; }
  appErrors.add(!(httpOk && (appOk || allowFail)));
}

export default function () {
  const csrf = login();
  check(csrf, { 'authenticated': (t) => t.length > 0 });
  if (!csrf) { appErrors.add(1); return; }

  const api = `${BASE}/api/library_handler.php`;

  for (let i = 0; i < 8; i++) {
    group('dashboard', () => appCheck(http.get(`${api}?action=book_stats`)));
    group('inventory_browse', () => appCheck(http.get(`${api}?action=books_get&page=1&per_page=20`)));
    group('search', () => appCheck(http.get(`${api}?action=books_get&per_page=20&q=the`)));
    group('inventory_stats', () => appCheck(http.get(`${api}?action=inventory_stats`)));
    group('my_borrows', () => appCheck(http.get(`${api}?action=book_borrow_requests_get&scope=mine`)));

    // ~1 in 5 iterations submits a borrow request (write path).
    if (Math.random() < 0.2) {
      group('borrow_request', () => {
        const body = 'action=book_borrow_request_add&borrower_name=Load+Tester'
                   + '&items%5B0%5D%5Bbook_id%5D=1&items%5B0%5D%5Bquantity%5D=1';
        const res = http.post(api, body, {
          headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
        });
        appCheck(res, true);   // tolerate "book 1 not found" on an empty DB — we measure latency
      });
    }
    sleep(Math.random() * 2 + 0.5);   // 0.5–2.5s think time
  }
}
