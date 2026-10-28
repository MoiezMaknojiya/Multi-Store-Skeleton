// What the WebCodecs prototype is doing now.
(() => {
    const wc = window.__wc;
    if (!wc) return { installed: false };
    const canvas = document.getElementById('wc-canvas');
    const box = canvas ? canvas.getBoundingClientRect() : null;
    return {
        installed: true,
        state: wc.state,
        config: wc.config || null,
        drawn: wc.drawn,
        late: wc.late,
        decoded: wc.decoded,
        loops: wc.loops,
        silenced: wc.silenced,
        decoderState: wc.decoder ? wc.decoder.state : null,
        decodeQueueSize: wc.decoder ? wc.decoder.decodeQueueSize : null,
        errors: wc.errors.slice(-3),
        canvasBox: box ? [Math.round(box.left), Math.round(box.top), Math.round(box.width), Math.round(box.height)] : null,
        orientation: document.getElementById('stage').dataset.orientation,
        playingVideos: [...document.querySelectorAll('video')].filter((v) => !v.paused && v.getAttribute('src')).length,
        t: Math.round(performance.now()),
    };
})()
