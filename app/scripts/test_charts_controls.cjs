const fs=require('fs'),vm=require('vm'),assert=require('assert');
const listeners={},boxes=Array.from({length:26},()=>({checked:true})),enabled={checked:false},note={textContent:''};
const document={hidden:false,addEventListener:(type,fn)=>listeners[type]=fn,querySelector:selector=>selector==='[data-genre-enabled]'?enabled:selector==='[data-genre-feedback]'?note:null,querySelectorAll:selector=>selector==='[data-genre-option]'?boxes:[]};
const window={};window.parent=window;
vm.runInNewContext(fs.readFileSync(require('path').join(__dirname,'../assets/music-charts.js'),'utf8'),{document,window,URL,setTimeout:()=>{throw Error('Unexpected timer')},clearTimeout:()=>{},fetch:()=>{throw Error('Selection must not send a request')}});
(async()=>{
 boxes[0].checked=false;listeners.change({target:{matches:selector=>selector==='[data-genre-option]'}});assert.equal(enabled.checked,true);assert(note.textContent.includes('Guarda'));
 const action=all=>({target:{closest:()=>({hasAttribute:()=>all})}});
 await listeners.click(action(true));assert(boxes.every(box=>box.checked));assert.equal(enabled.checked,false);
 await listeners.click(action(false));assert(boxes.every(box=>!box.checked));assert.equal(enabled.checked,true);
 console.log('CHARTS_CONTROLS_OK: changes enable filter; all/none buttons do not submit');
})().catch(error=>{console.error(error);process.exitCode=1});
