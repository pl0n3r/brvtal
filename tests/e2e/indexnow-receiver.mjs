import http from 'node:http';

const port = Number(process.env.BRVTAL_INDEXNOW_STUB_PORT || 4175);
const payloads = [];

function json(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type':'application/json; charset=utf-8',
    'Content-Length':Buffer.byteLength(data),
    'Cache-Control':'no-store',
  });
  res.end(data);
}

const server = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    json(res, 200, {ok:true});
    return;
  }

  if (req.method === 'GET' && req.url === '/captured') {
    json(res, 200, {ok:true,payloads});
    return;
  }

  if (req.method === 'DELETE' && req.url === '/captured') {
    payloads.splice(0, payloads.length);
    json(res, 200, {ok:true});
    return;
  }

  if (req.method !== 'POST' || req.url !== '/indexnow') {
    json(res, 404, {ok:false});
    return;
  }

  let raw = '';
  req.setEncoding('utf8');
  req.on('data', chunk => {
    raw += chunk;
    if (raw.length > 1024 * 1024) req.destroy();
  });
  req.on('end', () => {
    try {
      const payload = JSON.parse(raw || '{}');
      payloads.push(payload);
      json(res, 200, {ok:true});
    } catch {
      json(res, 400, {ok:false,error:'INVALID_JSON'});
    }
  });
});

server.listen(port, '127.0.0.1');

function stop() {
  server.close(() => process.exit(0));
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
