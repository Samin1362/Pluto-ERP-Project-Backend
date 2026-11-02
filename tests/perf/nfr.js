// k6 load test for the performance requirements (backlog P17.1-BE-02; 09: TC-NFR-001, TC-NFR-002, TC-NFR-003).
// Run against a disposable stack loaded with `php artisan pluto:perf:seed` (see tests/perf/README.md), never live:
//   docker run --rm -i --network host -e BASE=http://localhost:8081 -e EMAIL=… -e PASSWORD=… grafana/k6 run - < tests/perf/nfr.js
// Scenarios:
//   lists   TC-NFR-001: 50 virtual users, list/detail mix (vouchers, a ledger's entries, parties, invoices), p95 < 300 ms
//   reports TC-NFR-002: trial balance and profit and loss for the whole financial year, p95 < 2 s
//   post    TC-NFR-003: create and post a 50-line sales invoice, post p95 < 1 s
import http from 'k6/http'
import { check, fail, sleep } from 'k6'

const BASE = __ENV.BASE || 'http://localhost:8081'
const API = `${BASE}/api/v1`
const DURATION = __ENV.DURATION || '2m'

export const options = {
  scenarios: {
    lists: { executor: 'constant-vus', vus: Number(__ENV.VUS || 50), duration: DURATION, exec: 'lists' },
    reports: { executor: 'per-vu-iterations', vus: 1, iterations: Number(__ENV.REPORT_RUNS || 10), exec: 'reports', maxDuration: '10m' },
    post: { executor: 'per-vu-iterations', vus: 1, iterations: Number(__ENV.POST_RUNS || 20), exec: 'post', maxDuration: '10m' },
  },
  thresholds: {
    'http_req_duration{scenario:lists}': ['p(95)<300'],
    'http_req_failed{scenario:lists}': ['rate<0.001'],
    'http_req_duration{report:yes}': ['p(95)<2000'],
    'http_req_duration{step:post}': ['p(95)<1000'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max'],
  setupTimeout: '10m',
}

const json = (r) => { try { return r.json() } catch (e) { return null } }
// Idempotency-Key must be a UUID (BR-050).
const uuid = () => 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16) })

export function setup() {
  const login = http.post(`${API}/auth/login`, JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } })
  if (login.status !== 200) fail(`login ${login.status} ${login.body}`)
  const token = json(login).data.access_token
  const me = json(http.get(`${API}/me`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }))
  const company = String(me.data.companies[0].company_id)
  const h = (extra = {}) => ({ headers: { Authorization: `Bearer ${token}`, 'X-Company-Id': company, Accept: 'application/json', 'Content-Type': 'application/json', ...extra } })
  const get = (p) => json(http.get(`${API}${p}`, h()))
  const post = (p, body) => {
    const r = http.post(`${API}${p}`, JSON.stringify(body), h({ 'Idempotency-Key': uuid() }))
    if (r.status >= 300) fail(`setup POST ${p} -> ${r.status} ${r.body}`)
    return json(r)
  }

  const vouchers = get('/vouchers?per_page=50').data.map((v) => v.id)
  const ledgers = get('/ledgers?per_page=100').data.map((l) => l.id)
  const fy = get('/fiscal-years').data.find((y) => y.status !== 'closed') || get('/fiscal-years').data[0]

  // 50 products in stock for the posting scenario (one purchase bill of 50 lines).
  const branch = get('/branches').data[0].id
  const warehouse = post('/warehouses', { code: `PERF-${Date.now() % 100000}`, name: 'Performance depot', branch_id: branch }).data.id
  const unit = get('/units?per_page=100').data[0].id
  const income = get('/ledgers?purpose=income_for_product&per_page=100').data[0].id
  const products = []
  for (let i = 0; i < 50; i++) products.push(post('/products', { item_type: 'product', name: `Perf item ${i} ${Date.now()}`, unit_id: unit, selling_price: '120.00', income_ledger_id: income }).data.id)
  const supplier = post('/parties', { party_type: 'supplier', name: `Perf supplier ${Date.now()}`, mobile: '01700000001' }).data.id
  const customer = post('/parties', { party_type: 'customer', name: `Perf customer ${Date.now()}`, mobile: '01700000002' }).data.id
  const bill = post('/purchase-bills', { party_id: supplier, supplier_bill_no: `PERF-${Date.now()}`, bill_date: fy.start_date > today() ? fy.start_date : today(), warehouse_id: warehouse,
    lines: products.map((p) => ({ product_id: p, quantity: '100000', unit_cost: '100.00' })) })
  post(`/purchase-bills/${bill.data.id}/post`, {})
  return { token, company, vouchers, ledgers, fy, warehouse, products, customer }
}

function today() {
  return new Date(Date.now() + 6 * 3600_000).toISOString().slice(0, 10) // Asia/Dhaka, UTC+6
}

function headers(d, extra = {}) {
  return { headers: { Authorization: `Bearer ${d.token}`, 'X-Company-Id': d.company, Accept: 'application/json', 'Content-Type': 'application/json', ...extra } }
}

const pick = (a) => a[Math.floor(Math.random() * a.length)]

export function lists(d) {
  const page = 1 + Math.floor(Math.random() * 50)
  const calls = [
    `/vouchers?per_page=25&page=${page}`,
    `/vouchers/${pick(d.vouchers)}`,
    `/ledgers/${pick(d.ledgers)}/entries?page=${1 + Math.floor(Math.random() * 5)}`,
    '/parties?type=customer&per_page=25',
    '/sales-invoices?per_page=25',
  ]
  const r = http.get(`${API}${pick(calls)}`, headers(d))
  check(r, { 'list/detail 200': (x) => x.status === 200 })
  sleep(1)
}

export function reports(d) {
  const tb = http.get(`${API}/reports/trial-balance?as_of=${today()}`, { ...headers(d), tags: { report: 'yes' } })
  const pl = http.get(`${API}/reports/profit-and-loss?date_from=${d.fy.start_date}&date_to=${today()}`, { ...headers(d), tags: { report: 'yes' } })
  check(tb, { 'trial balance 200': (x) => x.status === 200 })
  check(pl, { 'profit and loss 200': (x) => x.status === 200 })
}

export function post(d) {
  const lines = d.products.map((p) => ({ product_id: p, quantity: '1' }))
  const inv = http.post(`${API}/sales-invoices`, JSON.stringify({ party_id: d.customer, invoice_date: today(), warehouse_id: d.warehouse, lines }),
    { ...headers(d, { 'Idempotency-Key': uuid() }), tags: { step: 'create' } })
  if (!check(inv, { 'invoice created': (x) => x.status === 201 })) return
  const posted = http.post(`${API}/sales-invoices/${json(inv).data.id}/post`, '{}', { ...headers(d, { 'Idempotency-Key': uuid() }), tags: { step: 'post' } })
  check(posted, { 'invoice posted': (x) => x.status === 200 })
}
