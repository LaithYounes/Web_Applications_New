// Plaintext unary gRPC relay for an isolated QA network. Node.js 22+.
const h2 = require('node:http2');
const http = require('node:http');

const [upstream = '127.0.0.1:9090', bind = '127.0.0.1'] = process.argv.slice(2);
const methods = new Set(['GetStock', 'ReserveStock', 'CommitReservation', 'ReleaseStock']);
const modes = new Set(['internal', 'unavailable', 'reset', 'delay']);
let active;

function log(event, fields) {
  console.log(JSON.stringify({ time: new Date().toISOString(), event, ...fields }));
}

function grpcError(stream, status) {
  if (!stream.destroyed && !stream.closed) {
    stream.respond({ ':status': 200, 'content-type': 'application/grpc', 'grpc-status': status }, { endStream: true });
  }
}

const relay = h2.createServer();
relay.on('sessionError', (error) => log('downstream_session_error', { message: error.message }));
relay.on('stream', (down, headers) => {
  const path = headers[':path'];
  const method = path?.split('/').pop();
  const rule = active && active.method === method && Date.now() < active.expires ? active : undefined;
  const inject = rule && rule.hits === 0;
  if (rule) rule.hits++;
  down.on('error', (error) => log('downstream_error', { method, message: error.message }));
  if (inject) {
    log('injected', { id: rule.id, method, mode: rule.mode, deadline: headers['grpc-timeout'] });
    down.once('aborted', () => {
      rule.aborted = true;
      log('gateway_aborted', { id: rule.id, method });
    });
    if (rule.mode === 'reset') {
      down.session.destroy();
      return;
    }
    if (rule.mode !== 'delay') {
      down.resume();
      grpcError(down, rule.mode === 'internal' ? '13' : '14');
      return;
    }
  }

  // Buffer unary responses so the delay can begin after receiving real trailers.
  const peer = h2.connect(`http://${upstream}`);
  let completed = false;
  let responseHeaders;
  let trailers;
  let size = 0;
  const chunks = [];
  function failed(error) {
    if (completed) return;
    completed = true;
    log('upstream_error', { id: rule?.id, method, message: error.message });
    grpcError(down, '14');
    peer.destroy();
  }
  peer.on('error', failed);
  const up = peer.request({ ...headers, ':authority': upstream });
  up.on('error', failed);
  up.on('aborted', () => failed(new Error('Upstream aborted')));
  up.setTimeout(15000, () => failed(new Error('Upstream response timeout')));
  up.on('response', (value) => { responseHeaders = value; });
  up.on('trailers', (value) => { trailers = value; });
  up.on('data', (chunk) => {
    size += chunk.length;
    if (size > 1024 * 1024) failed(new Error('Upstream response exceeded 1 MiB'));
    else chunks.push(chunk);
  });
  up.on('end', () => {
    if (completed) return;
    const status = trailers?.['grpc-status'] ?? responseHeaders?.['grpc-status'];
    if (status === undefined) {
      failed(new Error('Upstream ended without grpc-status'));
      return;
    }
    completed = true;
    peer.close();
    if (rule) {
      log('upstream_result', { id: rule.id, method, injectedCall: !!inject, status, bytes: size });
      if (inject) rule.upstreamStatus = Number(status);
    }
    function reply() {
      if (down.destroyed || down.closed) return;
      if (trailers) {
        down.once('wantTrailers', () => down.sendTrailers(trailers));
        down.respond(responseHeaders, { waitForTrailers: true });
        down.end(Buffer.concat(chunks));
      } else {
        down.respond(responseHeaders, { endStream: true });
      }
    }
    if (inject) setTimeout(reply, rule.delayMs);
    else reply();
  });
  down.once('close', () => {
    // For an injected delay, keep observing the real result after the client disconnects.
    if (!inject && !completed) peer.destroy();
  });
  down.pipe(up);
});

const control = http.createServer(async (req, res) => {
  res.setHeader('Content-Type', 'application/json');
  if (req.url === '/state' && req.method === 'GET') {
    res.end(JSON.stringify(active ?? null));
  } else if (req.url === '/arm' && req.method === 'DELETE') {
    active = undefined;
    res.end('{}');
  } else if (req.url === '/arm' && req.method === 'POST') {
    try {
      let body = '';
      for await (const chunk of req) {
        body += chunk;
        if (body.length > 1024) throw new Error('Payload too large');
      }
      const { id, method, mode, delayMs } = JSON.parse(body);
      if (typeof id !== 'string' || !id || !methods.has(method) || !modes.has(mode) ||
          !Number.isInteger(delayMs) || delayMs < 100 || delayMs > 12000) throw new Error('Invalid fault rule');
      active = { id, method, mode, delayMs, hits: 0, expires: Date.now() + 30000 };
      res.end(JSON.stringify(active));
    } catch (error) {
      res.writeHead(400).end(JSON.stringify({ error: error.message }));
    }
  } else {
    res.writeHead(404).end('{}');
  }
});

relay.listen(19090, bind, () => log('relay_ready', { bind, port: 19090, upstream }));
control.listen(18443, '127.0.0.1', () => log('control_ready', { port: 18443 }));
