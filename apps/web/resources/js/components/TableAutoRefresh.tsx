import { usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';

export default function TableAutoRefresh({ only, paused = false }: { only: string[]; paused?: boolean }) {
    const [enabled, setEnabled] = useState(false);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const { start, stop } = usePoll(10000, {
        only,
        onStart: () => setBusy(true),
        onSuccess: () => setFailed(false),
        onError: () => setFailed(true),
        onFinish: () => setBusy(false),
    }, { autoStart: false, mode: 'rest', background: 'pause' });

    useEffect(() => {
        if (enabled && !paused) start(); else stop();
        return stop;
    }, [enabled, paused, start, stop]);

    return <div className="table-auto-refresh">
        <label><input type="checkbox" checked={enabled} onChange={event => setEnabled(event.target.checked)} />Auto refresh</label>
        {enabled && <span role="status"><RefreshCw size={14} className={busy ? 'is-refreshing' : ''} />{paused ? 'Dijeda' : busy ? 'Memperbarui…' : failed ? 'Akan dicoba kembali' : 'Setiap 10 detik'}</span>}
    </div>;
}
