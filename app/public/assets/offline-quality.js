/* Shared nominal AAC bitrate selection and explicitly approximate storage estimates. */
(()=>{'use strict';
const rates=[64,96,128,160,192,256,320];
function normalize(value){const text=String(value);return text==='max'?'max':rates.includes(Number(text))?String(Number(text)):'128';}
function variants(master,base,resolve){const lines=master.split(/\r?\n/),out=[];for(let i=0;i<lines.length;i++){const line=lines[i];if(!line.startsWith('#EXT-X-STREAM-INF:'))continue;const next=lines.slice(i+1).find(l=>l.trim()&&!l.startsWith('#'));if(!next)continue;const attrs=line.slice(line.indexOf(':')+1),name=attrs.match(/(?:^|,)NAME="(\d+)k"/),average=attrs.match(/(?:^|,)AVERAGE-BANDWIDTH=(\d+)/),bandwidth=attrs.match(/(?:^|,)BANDWIDTH=(\d+)/);const kbps=name?Number(name[1]):Number(average?.[1]||bandwidth?.[1]||0)/1000;if(!Number.isFinite(kbps)||kbps<=0)continue;out.push({kbps,bw:Number(bandwidth?.[1]||kbps*1000),line,url:resolve(new URL(next.trim(),base).href)});}return out.sort((a,b)=>a.kbps-b.kbps);}
function choose(items,value){if(!items.length)throw Error('No hay una calidad descargable');const sorted=items.slice().sort((a,b)=>a.kbps-b.kbps),preference=normalize(value);if(preference==='max')return sorted[sorted.length-1];const requested=Number(preference);return sorted.reduce((best,item)=>Math.abs(item.kbps-requested)<Math.abs(best.kbps-requested)?item:best);}
function estimate(value,quota,used=0,highest=256){const preference=normalize(value),kbps=preference==='max'?highest:Number(preference),perMinute=kbps*1000/8*60*1.15,perSong=perMinute*4;return {kbps,perMinute,perSong,totalSongs:Math.floor(Math.max(0,quota)/perSong),remainingSongs:Math.floor(Math.max(0,quota-used)/perSong)};}
window.SFOfflineQuality={rates,normalize,variants,choose,estimate};
})();
