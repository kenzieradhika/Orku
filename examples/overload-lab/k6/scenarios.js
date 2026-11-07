import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const statuses = new Counter('http_status_count');
const successful = new Counter('successful_requests');
const statusCounters = Object.fromEntries([200, 429, 503, 'other'].map((status) => [status, new Counter(`http_status_${status}`)]));

const base = __ENV.LAB_TARGET || 'http://nginx:8080';
const scenario = __ENV.SCENARIO || 'baseline';
const phases = {
  baseline: [{ duration: '6s', target: 1 }],
  ramp: [{ duration: '3s', target: 1 }, { duration: '3s', target: 4 }, { duration: '3s', target: 1 }],
  spike: [{ duration: '3s', target: 1 }, { duration: '2s', target: 4 }, { duration: '3s', target: 1 }],
  recovery: [{ duration: '2s', target: 4 }, { duration: '6s', target: 1 }],
};
if (!['baseline', 'ramp', 'spike', 'recovery'].includes(scenario)) throw new Error('Unknown scenario');

export const options = {
  scenarios: { bounded: { executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1s', stages: phases[scenario], preAllocatedVUs: 4, maxVUs: 4, gracefulStop: '1s' } },
  noConnectionReuse: true,
  thresholds: { http_req_duration: ['p(95)<5000'] },
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
};

export default function () {
  const response = http.get(`${base}/api/demo-work`, { timeout: '5s', tags: { scenario } });
  statuses.add(1, { status: String(response.status) });
  statusCounters[statusCounters[response.status] ? response.status : 'other'].add(1);
  if (response.status === 200) successful.add(1);
  check(response, { 'expected bounded response': (r) => [200, 429, 503].includes(r.status) });
  sleep(0.25);
}

export function handleSummary(data) {
  return { [__ENV.RESULT_FILE]: JSON.stringify(data, null, 2) };
}
