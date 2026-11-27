'use strict';
const net = require('node:net');

function probeVnc() {
  return new Promise((resolve,reject) => {
    const socket = net.createConnection(5900,'127.0.0.1');
    let greeting = '';
    socket.setTimeout(1500);
    socket.on('data',chunk => {
      greeting += chunk.toString('ascii');
      if (greeting.length < 12) return;
      socket.destroy();
      if (/^RFB \d{3}\.\d{3}\n/.test(greeting)) resolve();
      else reject(Error('Saludo VNC inválido'));
    });
    socket.on('error',reject);
    socket.on('timeout',() => socket.destroy(Error('X11VNC no responde')));
    socket.on('end',() => reject(Error('X11VNC cerró la conexión')));
  });
}

async function main() {
  await probeVnc();
  if (process.argv.includes('--vnc-only')) return;
  const port = Number(process.env.PORT || 8099);
  for (const url of [`http://127.0.0.1:${port}/health`,'http://127.0.0.1:6080/vnc.html']) {
    const response = await fetch(url,{signal:AbortSignal.timeout(1500)});
    if (!response.ok) throw Error(url + ' devolvió HTTP ' + response.status);
    await response.body?.cancel();
  }
}
main().catch(error => { console.error(error.message); process.exitCode=1; });
