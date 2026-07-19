import http from 'k6/http';
import { check, fail } from 'k6';

const baseUrl = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/+$/, '');
const dashboardUrl = `${baseUrl}/`;
const queueUrl = `${baseUrl}/requests?status=in_progress&page=1`;

export const options = {
  scenarios: {
    authenticated_web: {
      executor: 'constant-vus',
      vus: 10,
      duration: '60s',
      gracefulStop: '5s',
    },
  },
  thresholds: {
    'checks{route:dashboard}': ['rate==1'],
    'checks{route:filtered_queue}': ['rate==1'],
    'http_req_duration{route:dashboard}': ['p(95)<1500'],
    'http_req_duration{route:filtered_queue}': ['p(95)<1500'],
    'http_req_failed{route:dashboard}': ['rate<0.01'],
    'http_req_failed{route:filtered_queue}': ['rate<0.01'],
  },
};

function responseCookies(response) {
  const values = [];

  for (const name of Object.keys(response.cookies).sort()) {
    for (const cookie of response.cookies[name]) {
      values.push(`${name}=${cookie.value}`);
    }
  }

  return values.join('; ');
}

function login() {
  http.cookieJar().clear(`${baseUrl}/`);
  const loginPage = http.get(`${baseUrl}/login`, {
    tags: { route: 'login_setup' },
  });
  if (loginPage.status !== 200) {
    fail(`Login setup returned HTTP ${loginPage.status}.`);
  }

  const csrfMatch = loginPage.body.match(/name="_csrf_token"[^>]*value="([^"]+)"/);
  if (!csrfMatch) {
    fail('Login setup did not expose the CSRF field.');
  }

  const anonymousCookie = responseCookies(loginPage);
  if (!anonymousCookie) {
    fail('Login setup did not establish a synthetic session.');
  }

  const login = http.post(`${baseUrl}/login`, {
    _username: 'agent@partnerops.test',
    _password: 'PartnerOps!2026',
    _csrf_token: csrfMatch[1],
  }, {
    redirects: 0,
    headers: {
      Cookie: anonymousCookie,
      // k6 is not a browser and does not synthesize Sec-Fetch-Site.
      Referer: `${baseUrl}/login`,
    },
    tags: { route: 'login_setup' },
  });
  const loginLocation = login.headers.Location || login.headers.location || '';
  const expectedAbsoluteLocation = `${baseUrl}/`;
  if (login.status !== 302 || (loginLocation !== '/' && loginLocation !== expectedAbsoluteLocation)) {
    const safeLocation = loginLocation.startsWith('/') && !loginLocation.startsWith('//')
      ? loginLocation.split(/[?#]/, 1)[0]
      : '[external-or-missing]';
    fail(`Synthetic login returned HTTP ${login.status} with Location ${safeLocation}.`);
  }

  const authenticatedCookie = responseCookies(login) || anonymousCookie;

  return authenticatedCookie;
}

export function setup() {
  const readiness = http.get(`${baseUrl}/health/ready`, {
    tags: { route: 'readiness_setup' },
  });
  if (readiness.status !== 200) {
    fail(`Readiness returned HTTP ${readiness.status}.`);
  }

  const cookies = [];
  for (let vu = 0; vu < 10; vu += 1) {
    const cookie = login();
    if (cookies.includes(cookie)) {
      fail('Login setup reused a session cookie.');
    }
    cookies.push(cookie);
  }

  return { cookies };
}

export default function ({ cookies }) {
  const authenticatedCookie = cookies[(__VU - 1) % cookies.length];
  const headers = {
    'Cache-Control': 'no-cache',
    Cookie: authenticatedCookie,
  };

  const dashboard = http.get(dashboardUrl, {
    headers,
    tags: { route: 'dashboard' },
  });
  check(dashboard, {
    'dashboard returns 200': (response) => response.status === 200,
    'dashboard renders operating metrics': (response) => response.body.includes('服務營運指標'),
  }, { route: 'dashboard' });

  const queue = http.get(queueUrl, {
    headers,
    tags: { route: 'filtered_queue' },
  });
  check(queue, {
    'filtered queue returns 200': (response) => response.status === 200,
    'filtered queue renders the work list': (response) => response.body.includes('工作佇列'),
  }, { route: 'filtered_queue' });
}

export function handleSummary(data) {
  const metric = (name, value) => data.metrics[name]?.values[value] ?? 'n/a';
  const output = {
    stdout: [
      'PartnerOps 10-VU/60-second non-production load profile finished.',
      `dashboard: p95=${metric('http_req_duration{route:dashboard}', 'p(95)')}ms, failures=${metric('http_req_failed{route:dashboard}', 'rate')}`,
      `filtered_queue: p95=${metric('http_req_duration{route:filtered_queue}', 'p(95)')}ms, failures=${metric('http_req_failed{route:filtered_queue}', 'rate')}`,
      '',
    ].join('\n'),
  };
  const summaryPath = __ENV.K6_SUMMARY_PATH;

  if (summaryPath) {
    output[summaryPath] = JSON.stringify({
      context: {
        commit: __ENV.GIT_SHA || 'local',
        runner: __ENV.RUNNER_DESCRIPTION || 'local',
        target: 'synthetic PartnerOps production-image environment',
      },
      summary: data,
    }, null, 2);
  }

  return output;
}
