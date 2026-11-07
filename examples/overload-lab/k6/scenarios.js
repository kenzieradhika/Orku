import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const statusCounters = Object.fromEntries([200, 429, 503, 'other'].map((status) => [status, new Counter(`http_status_${status}`)]));
const successful = new Counter('successful_requests');
const transportFailures = new Counter('transport_failures');
const base = __ENV.LAB_TARGET || 'http://nginx:8080';
const scenario = __ENV.SCENARIO || 'baseline';
const phases = {
  baseline: [{ duration: '6s', target: 1 }],
  ramp: [{ duration: '3s', target: 1 }, { duration: '3s', target: 4 }, { duration: '3s', target: 1 }],
  spike: [{ duration: '3s', target: 1 }, { duration: '2s', target: 4 }, { duration: '3s', target: 1 }],
  recovery: [{ duration: '2s', target: 4 }, { duration: '6s', target: 1 }],
  admission: [{ duration: '6s', target: 4 }],
  legitimate: [{ duration: '6s', target: 1 }],
  recovery_check: [{ duration: '6s', target: 1 }],
  overload_fixture: [{ duration: '2s', target: 1 }],
};
if (!Object.hasOwn(phases, scenario)) throw new Error('Unknown scenario');
http.setResponseCallback(http.expectedStatuses(200, 429, 503));

export const options = {
  scenarios: { bounded: { executor: 'ramping-arrival-rate', startRate: 1, timeUnit: '1s', stages: phases[scenario], preAllocatedVUs: 4, maxVUs: 4, gracefulStop: '1s' } },
  noConnectionReuse: true,
  thresholds: { http_req_duration: ['p(95)<5000'] },
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
};

export default function () {
  const path = scenario === 'overload_fixture' ? '/api/demo-work?overload=1' : '/api/demo-work';
  const response = http.get(`${base}${path}`, { timeout: '5s', tags: { scenario } });
  statusCounters[statusCounters[response.status] ? response.status : 'other'].add(1);
  if (response.status === 200) successful.add(1);
  if (response.status === 0) transportFailures.add(1);
  check(response, {
    'expected bounded response': (r) => [200, 429, 503].includes(r.status),
    'intentional admission response': (r) => r.status === 429 || r.status === 503,
    'successful response': (r) => r.status === 200,
  });
  sleep(0.25);
}

export function handleSummary(data) {
  return { [__ENV.RESULT_FILE]: JSON.stringify({ ...data, lab: {
    variant: __ENV.LAB_VARIANT,
    nginxConfig: __ENV.LAB_NGINX_CONFIG,
    appVariant: __ENV.LAB_APP_VARIANT,
    scenario,
    target: base,
    k6Image: 'grafana/k6:1.8.2',
  } }, null, 2) };
}
