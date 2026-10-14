import { Head, Link, useForm } from '@inertiajs/react';
import ThemeToggle from '../../components/ThemeToggle';
import Brand from '../../components/Brand';
export default function AdminLogin() {
    const form=useForm({email:'',password:''});
    return <><Head title="Masuk admin"/><div className="auth-page panel-surface"><div className="auth-card"><div className="auth-brand-row"><Brand/><ThemeToggle/></div><h1>Admin platform</h1><p>Akses pemeriksaan owner dan pengelolaan DigitalToko.</p><form onSubmit={e=>{e.preventDefault();form.post('/admin/login');}}><label>Email admin<input type="email" value={form.data.email} onChange={e=>form.setData('email',e.target.value)} required autoComplete="username"/></label><label>Password<input type="password" value={form.data.password} onChange={e=>form.setData('password',e.target.value)} required autoComplete="current-password"/></label>{Object.values(form.errors).map(e=><p className="form-error" key={e}>{e}</p>)}<button className="button" disabled={form.processing}>Masuk admin</button></form><Link className="text-link" href="/login">Masuk sebagai owner</Link></div></div></>;
}
