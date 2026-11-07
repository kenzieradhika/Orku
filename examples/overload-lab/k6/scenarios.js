import http from 'k6/http';
import { check, sleep } from 'k6';

const base = __ENV.LAB_TARGET || 'http://127.0.0.1:18080';
const scenario = __ENV.SCENARIO || 'baseline';
const maxDuration = __ENV.MAX_DURATION || '12s';
const phases = {
  baseline: [{ duration: '6s', target: 1 }],
  ramp: [{ duration: '3s', target: 1 }, { duration: '3s', target: 4 }, { duration: '3s', target: 1 }],
  spike: [{ duration: '3s', target: 1 }, { duration: '2s', target: 4 }, { duration: '3s', target: 1 }],
  recovery: [{ duration: '2s', target: 4 }, { duration: '6s', target: 1 }],
};
if (!['baseline', 'ramp', 'spike', 'recovery'].includes(scenario)) throw new Error('Unknown scenario');

export const options = {
  scenarios: { bounded: { executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1s', stages: phases[scenario], preAllocatedVUs: 4, maxVUs: 4, maxDuration, gracefulStop: '1s' } },
  noConnectionReuse: true,
  thresholds: { http_req_duration: ['p(95)<5000'] },
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
};

export default function () {
  // 4 VUs × 1 request per second × 15 seconds caps the run at 60 requests.
  const response = http.get(`${base}/api/demo-work`, { timeout: '5s', tags: { scenario } });
  check(response, { 'expected bounded response': (r) => [200, 429, 503].includes(r.status) });
  sleep(0.25);
}
