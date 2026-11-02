import { useId } from 'react';
import { ZoomIn } from 'lucide-react';

export type TutorialMarker = {label:string;x:number;y:number;w:number;h:number};
export type TutorialVisual = {image:string;width:number;height:number;markers:TutorialMarker[];caption:string;illustration?:boolean;capture?:'device';exampleData?:boolean};

export function AnnotatedTutorialImage({visual}:{visual:TutorialVisual}) {
    const id = 'tutorial-arrow-'+useId().replaceAll(':','');
    const width = visual.capture === 'device' ? 360 : visual.width;
    const height = visual.height * width / visual.width;
    return <div className={'tutorial-annotated-frame'+(visual.capture === 'device' ? ' tutorial-device-frame' : '')}>
        <img src={visual.image} alt={visual.caption} width={visual.width} height={visual.height} loading="lazy" decoding="async"/>
        <svg className="tutorial-annotation-layer" viewBox={`0 0 ${width} ${height}`} aria-hidden="true">
            <defs><marker id={id} markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L0,6 L9,3 z" fill="#e83e57"/></marker></defs>
            {visual.markers.map((marker,index)=>{
                const x=marker.x*width/100, y=marker.y*height/100;
                const w=marker.w*width/100,h=marker.h*height/100;
                const count=visual.markers.length;
                const boxWidth=visual.capture === 'device' ? Math.min(300,width-32) : Math.min(300, (width-64)/Math.min(count,3)-20);
                const column=index%3, row=Math.floor(index/3);
                const cx=visual.capture === 'device'||count===1?width*.5:(column+.5)*width/Math.min(count,3);
                const bottom=marker.y<32;
                const boxY=visual.capture === 'device' ? Math.max(36, y-h/2-100) : bottom?height-82-row*64:28+row*64;
                const words=(`${index+1}. ${marker.label}`).split(' ');const lines:string[]=[];let line='';
                for(const word of words){if((line+' '+word).trim().length>29&&line){lines.push(line);line=word;}else{line=(line+' '+word).trim();}}
                if(line)lines.push(line);
                const boxHeight=lines.length>1?56:42;
                const fromBelow=visual.capture !== 'device' && bottom;
                const startY=fromBelow?boxY:boxY+boxHeight;
                const endY=fromBelow?y+h/2+4:y-h/2-4;
                return <g key={index}>
                    <rect x={x-w/2-4} y={y-h/2-4} width={w+8} height={h+8} rx="8" fill="#e83e570c" stroke="#e83e57" strokeWidth="3"/>
                    <path d={`M ${cx} ${startY} Q ${cx} ${(startY+endY)/2} ${x} ${endY}`} fill="none" stroke="white" strokeWidth="7"/>
                    <path d={`M ${cx} ${startY} Q ${cx} ${(startY+endY)/2} ${x} ${endY}`} fill="none" stroke="#e83e57" strokeWidth="3" markerEnd={`url(#${id})`}/>
                    <rect x={cx-boxWidth/2} y={boxY} width={boxWidth} height={boxHeight} rx="10" fill="white" stroke="#e83e57" strokeWidth="2"/>
                    {lines.map((text,i)=><text key={i} x={cx} y={boxY+(lines.length>1?23:27)+i*20} textAnchor="middle" fill="#8e1e35" fontFamily="Inter, system-ui, sans-serif" fontSize="17" fontWeight="700">{text}</text>)}
                </g>;
            })}
        </svg>
    </div>;
}

export default function TutorialStepImage({visual,onZoom}:{visual:TutorialVisual;onZoom:()=>void}) {
    return <figure className={'tutorial-figure tutorial-step-figure'+(visual.capture === 'device' ? ' tutorial-device-figure' : '')}>
        <button type="button" onClick={onZoom} aria-label={'Perbesar: '+visual.caption}><AnnotatedTutorialImage visual={visual}/><span><ZoomIn size={16}/>Perbesar petunjuk</span></button>
        <figcaption>{visual.capture === 'device' ? 'Screenshot HP asli · '+(visual.exampleData ? 'data contoh · ' : '') : visual.illustration ? 'Ilustrasi alur · ' : 'Screenshot simulasi · '}{visual.caption} Gunakan nilai dari panel toko Anda.</figcaption>
        <ol className="tutorial-marker-key">{visual.markers.map((marker,index)=><li key={index}><span>{index+1}</span>{marker.label}</li>)}</ol>
    </figure>;
}
