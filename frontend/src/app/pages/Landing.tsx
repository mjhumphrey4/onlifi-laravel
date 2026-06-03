import { Link } from 'react-router';
import { Activity, ArrowRight, CheckCircle2, Network, Router, ShieldCheck, Ticket, Wallet } from 'lucide-react';

const features = [
  { icon: Router, title: 'Mikrotik and Omada Ready', text: 'Provision hotspot, RADIUS, captive files, telemetry, and SSTP from one script.' },
  { icon: Network, title: 'Multi-Site Control', text: 'Each site works as its own router-backed business unit under one account.' },
  { icon: Ticket, title: 'Voucher Operations', text: 'Generate, sell, print, monitor, and expire vouchers with clean accounting.' },
  { icon: Wallet, title: 'Mobile Money First', text: 'Support MTN Momo and Airtel Money mobile money payments with our API backends or bring your own YoPayments or IOTEC API.' },
  { icon: Wallet, title: 'FREE', text: 'Free like Free Beer. No Monthly Subscriptions, But per transaction charges limited to 1%.' },
  { icon: Router, title: 'Remote Access', text: 'Not Home, No Problem. Roam on the Go!' },
];

export function Landing() {
  return (
    <main className="min-h-screen bg-slate-950 text-white">
      <section className="min-h-screen flex flex-col">
        <nav className="flex items-center justify-between px-6 lg:px-12 py-5 border-b border-white/10">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-cyan-500/20 border border-cyan-300/30 grid place-items-center">
              <Activity className="w-5 h-5 text-cyan-300" />
            </div>
            <div>
              <p className="font-bold tracking-wide">ONLIFI</p>
              <p className="text-xs text-slate-400">Network Management System</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <Link to="/login" className="px-4 py-2 text-sm text-slate-200 hover:text-white">Sign in</Link>
            <Link to="/signup" className="px-4 py-2 rounded-lg bg-cyan-400 text-slate-950 text-sm font-semibold hover:bg-cyan-300">Create account</Link>
          </div>
        </nav>

        <div className="flex-1 grid lg:grid-cols-[1.05fr_0.95fr] gap-10 items-center px-6 lg:px-12 py-12">
          <div className="max-w-3xl">
            <p className="text-cyan-300 font-semibold mb-4"> The ZERO Nonsense Network Management System - Built by Network Owners for Network Owners</p>
            <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold leading-tight tracking-normal">
              Run hotspot billing, manage router, and control vouchers from one serious dashboard.
            </h1>
            <p className="text-lg text-slate-300 mt-6 max-w-2xl">
              ONLIFI gives you the practical tools you need: site isolation, MikroTik or Omada provisioning, RADIUS, captive page designs, mobile money payments, and router monitoring on the go. Under active development and new feature enrollments overtime. Expect functionality in brief, just like this page, we gurantee satisfaction not excuses. Get started today!
            </p>
            <div className="flex flex-wrap gap-3 mt-8">
              <Link to="/signup" className="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-cyan-400 text-slate-950 font-semibold hover:bg-cyan-300">
                Start setup <ArrowRight className="w-4 h-4" />
              </Link>
              <Link to="/login" className="inline-flex items-center gap-2 px-5 py-3 rounded-lg border border-white/15 text-white hover:bg-white/10">
                Open dashboard
              </Link>
            </div>
          </div>

          <div className="rounded-xl border border-white/10 bg-slate-900 p-5 shadow-2xl">
            <div className="flex items-center justify-between border-b border-white/10 pb-4">
              <div>
                <p className="text-sm text-slate-400">What you Get</p>
                <p className="text-xl font-semibold">Network operations</p>
              </div>
              <ShieldCheck className="w-8 h-8 text-cyan-300" />
            </div>
            <div className="grid sm:grid-cols-2 gap-3 mt-5">
              {features.map((feature) => {
                const Icon = feature.icon;
                return (
                  <div key={feature.title} className="rounded-lg bg-slate-800 border border-white/10 p-4">
                    <Icon className="w-5 h-5 text-cyan-300 mb-3" />
                    <h2 className="font-semibold">{feature.title}</h2>
                    <p className="text-sm text-slate-400 mt-2">{feature.text}</p>
                  </div>
                );
              })}
            </div>
            <div className="mt-5 rounded-lg bg-emerald-500/10 border border-emerald-400/20 p-4">
              <div className="flex items-center gap-2 text-emerald-300 font-semibold">
                <CheckCircle2 className="w-4 h-4" />
                Built for network owners and operators, not brochures
              </div>
              <p className="text-sm text-slate-300 mt-2">No fluff or bluff. Just the controls you need to keep clients online, paid, and visible while tracking your installed network routers.</p>
            </div>
          </div>
        </div>
      </section>
    </main>
  );
}
