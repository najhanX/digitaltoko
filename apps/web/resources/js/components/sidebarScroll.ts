type Frames = {request:(callback:FrameRequestCallback)=>number;cancel:(id:number)=>void};

/** Restore after accordion layout settles, without saving intermediate clamped offsets. */
export function restoreSidebarPosition(element:HTMLElement, offset:number, restoring:(active:boolean)=>void, frames:Frames = {request:callback=>requestAnimationFrame(callback),cancel:id=>cancelAnimationFrame(id)}):()=>void {
    if (!Number.isFinite(offset) || offset < 0) return ()=>{};
    let pending = 0;
    let finished = false;
    const events = ['wheel','touchstart','pointerdown','keydown'];
    function stop() {
        if (finished) return;
        finished = true;
        frames.cancel(pending);
        events.forEach(event=>element.removeEventListener(event,stop));
        restoring(false);
    }
    restoring(true);
    events.forEach(event=>element.addEventListener(event,stop,{passive:true}));
    element.scrollTop = offset;
    pending = frames.request(()=>{
        element.scrollTop = offset;
        pending = frames.request(()=>{
            element.scrollTop = offset;
            pending = frames.request(stop);
        });
    });
    return stop;
}
