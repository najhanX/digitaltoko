import { useState } from 'react';

export default function TutorialCommand({label, code}:{label:string;code:string}) {
    const [message,setMessage] = useState('');
    async function copy() {
        try {
            await navigator.clipboard.writeText(code);
            setMessage('Perintah disalin.');
        } catch {
            setMessage('Pilih teks perintah, lalu salin dengan Ctrl+C atau Cmd+C.');
        }
    }
    return <div className="tutorial-command"><header><strong>{label}</strong><button type="button" className="button secondary small" onClick={copy}>Salin perintah</button></header><pre tabIndex={0}><code>{code}</code></pre>{message && <p role="status">{message}</p>}</div>;
}
