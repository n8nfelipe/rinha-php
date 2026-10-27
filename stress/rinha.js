import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { SharedArray } from 'k6/data';
import exec from 'k6/execution';

// Réplica em k6 do RinhaBackendSimulation.scala oficial.
//
// O perfil de chegada é o mesmo:
//   POST  2/s por 10s -> 5/s por 15s -> ramp 6/s..600/s por 3min
//   BUSCA 2/s por 25s -> ramp 6/s..100/s por 3min
//   INVÁLIDA 2/s por 25s -> ramp 6/s..40/s por 3min
//
// A única adaptação é o feeder: o Gatling percorre o TSV de forma circular e
// compartilhada. Em k6 usamos exec.scenario.iterationInTest, que é um contador
// global do cenário, exatamente como o feeder circular. Offsets separam os
// cenários de warm-up do ramp para não repetir payloads.

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:9999';

const payloads = new SharedArray('payloads', () => {
  return open('data/pessoas-payloads.tsv').split('\n').slice(1).filter((l) => l.length > 0);
});

const termos = new SharedArray('termos', () => {
  return open('data/termos-busca.tsv').split('\n').slice(1).filter((l) => l.length > 0);
});

const criados = new Counter('pessoas_criadas');
const rejeitados = new Counter('pessoas_rejeitadas');

http.setResponseCallback(http.expectedStatuses(200, 201, 400, 404, 422));

const PROFILE = __ENV.PROFILE || 'full';
const DURATION = __ENV.DURATION || '60s';

const fullScenarios = {
    post_warmup_a: {
      executor: 'constant-arrival-rate',
      exec: 'criacao',
      rate: 2,
      timeUnit: '1s',
      duration: '10s',
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
    post_warmup_b: {
      executor: 'constant-arrival-rate',
      exec: 'criacao',
      rate: 5,
      timeUnit: '1s',
      duration: '15s',
      startTime: '10s',
      preAllocatedVUs: 20,
      maxVUs: 40,
    },
    post_ramp: {
      executor: 'ramping-arrival-rate',
      exec: 'criacao',
      startRate: 6,
      timeUnit: '1s',
      stages: [{ target: 600, duration: '3m' }],
      startTime: '25s',
      preAllocatedVUs: 80,
      maxVUs: 80,
    },
    busca_warmup: {
      executor: 'constant-arrival-rate',
      exec: 'busca',
      rate: 2,
      timeUnit: '1s',
      duration: '25s',
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
    busca_ramp: {
      executor: 'ramping-arrival-rate',
      exec: 'busca',
      startRate: 6,
      timeUnit: '1s',
      stages: [{ target: 100, duration: '3m' }],
      startTime: '25s',
      preAllocatedVUs: 40,
      maxVUs: 100,
    },
    invalida_warmup: {
      executor: 'constant-arrival-rate',
      exec: 'buscaInvalida',
      rate: 2,
      timeUnit: '1s',
      duration: '25s',
      preAllocatedVUs: 5,
      maxVUs: 10,
    },
    invalida_ramp: {
      executor: 'ramping-arrival-rate',
      exec: 'buscaInvalida',
      startRate: 6,
      timeUnit: '1s',
      stages: [{ target: 40, duration: '3m' }],
      startTime: '25s',
      preAllocatedVUs: 20,
      maxVUs: 40,
    },
};

// Perfil de bancada: taxas constantes para medir capacidade e gargalo.
const benchScenarios = {
  post: {
    executor: 'constant-arrival-rate',
    exec: 'criacao',
    rate: Number(__ENV.POST_RATE || 300),
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: 80,
    maxVUs: 80,
  },
  busca: {
    executor: 'constant-arrival-rate',
    exec: 'busca',
    rate: Number(__ENV.SEARCH_RATE || 100),
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: 40,
    maxVUs: 100,
  },
  invalida: {
    executor: 'constant-arrival-rate',
    exec: 'buscaInvalida',
    rate: Number(__ENV.INVALID_RATE || 40),
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: 20,
    maxVUs: 40,
  },
};

export const options = {
  scenarios: PROFILE === 'bench' ? benchScenarios : fullScenarios,
  thresholds: {
    // p99 só aparece no resumo por causa do threshold; o valor é folgado para
    // não mascarar o resultado real.
    http_req_duration: ['p(99)<60000'],
    // Quebra por endpoint: sem isso o k6 não publica os sub-métricos.
    'http_req_duration{endpoint:criacao}': ['p(99)<60000'],
    'http_req_duration{endpoint:consulta}': ['p(99)<60000'],
    'http_req_duration{endpoint:busca}': ['p(99)<60000'],
    'http_req_duration{endpoint:busca_invalida}': ['p(99)<60000'],
  },
};

const OFFSETS = {
  post_warmup_a: 0,
  post_warmup_b: 100,
  post_ramp: 1000,
  post: 0,
};

function payloadDaVez() {
  const offset = OFFSETS[exec.scenario.name] || 0;
  const i = exec.scenario.iterationInTest + offset;
  return payloads[i % payloads.length];
}

export function criacao() {
  const res = http.post(`${BASE_URL}/pessoas`, payloadDaVez(), {
    headers: { 'Content-Type': 'application/json' },
    tags: { endpoint: 'criacao' },
  });

  const ok = check(res, {
    'POST 201/400/422': (r) => r.status === 201 || r.status === 400 || r.status === 422,
  });

  if (res.status === 201) {
    criados.add(1);
    const location = res.headers['Location'] || res.headers['location'];
    if (location) {
      const consulta = http.get(`${BASE_URL}${location}`, { tags: { endpoint: 'consulta' } });
      check(consulta, { 'GET 200': (r) => r.status === 200 });
    }
  } else {
    rejeitados.add(1);
  }

  if (!ok) {
    console.error(`POST inesperado: ${res.status} ${res.body}`);
  }

  sleep(0.001 + Math.random() * 0.029);
}

export function busca() {
  const termo = termos[Math.floor(Math.random() * termos.length)];
  const res = http.get(`${BASE_URL}/pessoas?t=${encodeURIComponent(termo)}`, {
    tags: { endpoint: 'busca' },
  });
  check(res, { 'GET busca 200': (r) => r.status === 200 });
}

export function buscaInvalida() {
  const res = http.get(`${BASE_URL}/pessoas`, { tags: { endpoint: 'busca_invalida' } });
  check(res, { 'GET sem t = 400': (r) => r.status === 400 });
}

export function teardown() {
  const res = http.get(`${BASE_URL}/contagem-pessoas`);
  console.log(`CONTAGEM_FINAL=${res.body}`);
}
