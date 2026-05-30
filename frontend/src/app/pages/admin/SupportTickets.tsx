import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { AlertCircle, CheckCircle2, Loader2, MessageSquare, RefreshCw, Search, Send } from 'lucide-react';
import {
  getAdminSupportTicket,
  getAdminSupportTickets,
  replyAdminSupportTicket,
  updateAdminSupportTicketStatus,
} from '../../utils/api';

type TicketStatus = 'open' | 'pending_admin' | 'pending_customer' | 'resolved' | 'closed';

interface Ticket {
  id: number;
  subject: string;
  category: string;
  priority: 'low' | 'normal' | 'high' | 'urgent';
  status: TicketStatus;
  unread_for_admin?: boolean;
  last_message_at?: string;
  messages_count?: number;
  tenant?: { id: number; name: string; slug?: string };
  user?: { id: number; name: string; email: string };
  messages?: TicketMessage[];
}

interface TicketMessage {
  id: number;
  sender_type: 'tenant' | 'admin' | 'system';
  sender_name: string;
  body: string;
  edited_at?: string | null;
  created_at?: string;
}

const statuses = [
  ['all', 'All'],
  ['open', 'Open'],
  ['pending_admin', 'Waiting on Admin'],
  ['pending_customer', 'Waiting on Customer'],
  ['resolved', 'Resolved'],
  ['closed', 'Closed'],
];

export default function AdminSupportTickets() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [selected, setSelected] = useState<Ticket | null>(null);
  const [status, setStatus] = useState('all');
  const [search, setSearch] = useState('');
  const [reply, setReply] = useState('');
  const [replyStatus, setReplyStatus] = useState<TicketStatus>('pending_customer');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const loadTickets = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getAdminSupportTickets({ status, search });
      const rows = data.data || [];
      setTickets(rows);
      const ticketFromUrl = Number(searchParams.get('ticket'));
      const nextId = ticketFromUrl || selected?.id || rows[0]?.id;
      if (nextId) await openTicket(nextId, false);
      if (!nextId) setSelected(null);
    } catch (err: any) {
      setError(err.message || 'Failed to load support tickets.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadTickets();
  }, [status]);

  const openTicket = async (id: number, updateUrl = true) => {
    try {
      const data = await getAdminSupportTicket(id);
      setSelected(data.ticket);
      setReplyStatus(data.ticket.status === 'closed' ? 'open' : 'pending_customer');
      if (updateUrl) setSearchParams({ ticket: String(id) });
    } catch (err: any) {
      setError(err.message || 'Failed to open ticket.');
    }
  };

  const sendReply = async (event: FormEvent) => {
    event.preventDefault();
    if (!selected || !reply.trim()) return;
    setSaving(true);
    try {
      const data = await replyAdminSupportTicket(selected.id, reply, replyStatus);
      setSelected(data.ticket);
      setReply('');
      await loadTickets();
    } catch (err: any) {
      setError(err.message || 'Failed to send reply.');
    } finally {
      setSaving(false);
    }
  };

  const changeStatus = async (nextStatus: TicketStatus) => {
    if (!selected) return;
    setSaving(true);
    try {
      const data = await updateAdminSupportTicketStatus(selected.id, {
        status: nextStatus,
        priority: selected.priority,
      });
      setSelected(data.ticket);
      await loadTickets();
    } catch (err: any) {
      setError(err.message || 'Failed to update ticket.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6 text-slate-100">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <MessageSquare className="w-8 h-8 text-indigo-400" />
            Support Tickets
          </h1>
          <p className="text-slate-400 mt-1">Reply to tenants, track issues, and close resolved requests.</p>
        </div>
        <button onClick={loadTickets} className="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl border border-slate-700 hover:bg-slate-800">
          <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          Refresh
        </button>
      </div>

      {error && <div className="rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300">{error}</div>}

      <div className="flex flex-col xl:flex-row xl:items-center gap-3">
        <div className="flex flex-wrap gap-2">
          {statuses.map(([id, label]) => (
            <button
              key={id}
              onClick={() => setStatus(id)}
              className={`px-3 py-1.5 rounded-lg text-sm border ${status === id ? 'bg-indigo-500 text-white border-indigo-500' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'}`}
            >
              {label}
            </button>
          ))}
        </div>
        <form onSubmit={(event) => { event.preventDefault(); loadTickets(); }} className="xl:ml-auto flex gap-2">
          <div className="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 min-w-[260px]">
            <Search className="w-4 h-4 text-slate-500" />
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search tenant or subject" className="bg-transparent outline-none text-sm flex-1 text-white placeholder-slate-500" />
          </div>
          <button className="px-4 py-2 rounded-xl bg-slate-700 hover:bg-slate-600 text-sm">Search</button>
        </form>
      </div>

      <div className="grid xl:grid-cols-[390px_1fr] gap-6">
        <div className="rounded-xl border border-slate-700 bg-slate-800 overflow-hidden">
          <div className="p-4 border-b border-slate-700">
            <h2 className="font-semibold">Queue</h2>
          </div>
          <div className="divide-y divide-slate-700 max-h-[740px] overflow-y-auto">
            {loading && tickets.length === 0 ? (
              <div className="p-8 grid place-items-center"><Loader2 className="w-6 h-6 animate-spin text-indigo-400" /></div>
            ) : tickets.length === 0 ? (
              <div className="p-8 text-center text-sm text-slate-400">No tickets found.</div>
            ) : tickets.map((ticket) => (
              <button
                key={ticket.id}
                onClick={() => openTicket(ticket.id)}
                className={`w-full text-left p-4 hover:bg-slate-700/70 ${selected?.id === ticket.id ? 'bg-indigo-500/15' : ''}`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-medium truncate">#{ticket.id} {ticket.subject}</p>
                    <p className="text-xs text-slate-400 mt-1">{ticket.tenant?.name || 'Unknown tenant'} • {ticket.priority}</p>
                  </div>
                  {ticket.unread_for_admin && <span className="w-2 h-2 rounded-full bg-red-400 mt-2" />}
                </div>
                <div className="flex items-center justify-between mt-3">
                  <StatusBadge status={ticket.status} />
                  <span className="text-xs text-slate-500">{ticket.last_message_at ? new Date(ticket.last_message_at).toLocaleDateString() : ''}</span>
                </div>
              </button>
            ))}
          </div>
        </div>

        <div className="rounded-xl border border-slate-700 bg-slate-800 min-h-[620px]">
          {!selected ? (
            <div className="h-full min-h-[620px] grid place-items-center text-center p-8">
              <div>
                <MessageSquare className="w-14 h-14 text-slate-500 mx-auto mb-3" />
                <p className="font-medium">Select a ticket</p>
                <p className="text-sm text-slate-400 mt-1">Open a ticket to reply or change its status.</p>
              </div>
            </div>
          ) : (
            <div className="flex flex-col min-h-[740px]">
              <div className="p-5 border-b border-slate-700 flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-xl font-semibold">#{selected.id} {selected.subject}</h2>
                    <StatusBadge status={selected.status} />
                  </div>
                  <p className="text-sm text-slate-400 mt-1">
                    {selected.tenant?.name || 'Unknown tenant'} • {selected.user?.name || 'Tenant user'} • {selected.category} • {selected.priority}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {(['open', 'pending_customer', 'resolved', 'closed'] as TicketStatus[]).map((item) => (
                    <button key={item} disabled={saving} onClick={() => changeStatus(item)} className="px-3 py-1.5 rounded-lg border border-slate-700 text-sm hover:bg-slate-700 disabled:opacity-50">
                      {item.replace('_', ' ')}
                    </button>
                  ))}
                </div>
              </div>

              <div className="flex-1 p-5 space-y-4 overflow-y-auto">
                {(selected.messages || []).map((item) => (
                  <div key={item.id} className={`rounded-xl border p-4 ${item.sender_type === 'admin' ? 'bg-indigo-500/10 border-indigo-400/25 ml-auto max-w-[88%]' : 'bg-slate-900/80 border-slate-700 mr-auto max-w-[88%]'}`}>
                    <div className="flex items-center justify-between gap-3 text-xs text-slate-400 mb-2">
                      <span className="font-medium">{item.sender_name} • {item.sender_type === 'admin' ? 'Admin' : 'Tenant'}</span>
                      <span>{item.created_at ? new Date(item.created_at).toLocaleString() : ''}{item.edited_at ? ' • edited' : ''}</span>
                    </div>
                    <p className="whitespace-pre-wrap text-sm leading-6 text-slate-100">{item.body}</p>
                  </div>
                ))}
              </div>

              <form onSubmit={sendReply} className="p-5 border-t border-slate-700 space-y-3">
                <FriendlyEditor value={reply} onChange={setReply} />
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                  <label className="flex items-center gap-2 text-sm text-slate-300">
                    Set status after reply
                    <select value={replyStatus} onChange={(e) => setReplyStatus(e.target.value as TicketStatus)} className="px-3 py-2 rounded-lg bg-slate-900 border border-slate-700">
                      <option value="pending_customer">Waiting on Customer</option>
                      <option value="open">Open</option>
                      <option value="resolved">Resolved</option>
                      <option value="closed">Closed</option>
                    </select>
                  </label>
                  <button disabled={saving || !reply.trim()} className="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-indigo-500 text-white hover:bg-indigo-600 disabled:opacity-50">
                    {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                    Send Reply
                  </button>
                </div>
              </form>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function FriendlyEditor({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const ref = useRef<HTMLTextAreaElement | null>(null);
  const [preview, setPreview] = useState(false);

  const insert = (before: string, after = '') => {
    const target = ref.current;
    if (!target) return;
    const start = target.selectionStart;
    const end = target.selectionEnd;
    const selected = value.slice(start, end);
    onChange(value.slice(0, start) + before + selected + after + value.slice(end));
    window.setTimeout(() => target.focus(), 0);
  };

  return (
    <div className="rounded-xl border border-slate-700 overflow-hidden bg-slate-900">
      <div className="flex flex-wrap items-center gap-1 p-2 border-b border-slate-700 bg-slate-800">
        <button type="button" onClick={() => insert('**', '**')} className="px-2 py-1 rounded text-sm font-bold hover:bg-slate-700">B</button>
        <button type="button" onClick={() => insert('_', '_')} className="px-2 py-1 rounded text-sm italic hover:bg-slate-700">I</button>
        <button type="button" onClick={() => insert('- ')} className="px-2 py-1 rounded text-sm hover:bg-slate-700">List</button>
        <button type="button" onClick={() => insert('[', '](https://)')} className="px-2 py-1 rounded text-sm hover:bg-slate-700">Link</button>
        <button type="button" onClick={() => setPreview(!preview)} className="ml-auto px-2 py-1 rounded text-sm hover:bg-slate-700">{preview ? 'Edit' : 'Preview'}</button>
      </div>
      {preview ? (
        <div className="min-h-[150px] p-3 text-sm whitespace-pre-wrap leading-6 text-slate-100">{value || 'Nothing to preview yet.'}</div>
      ) : (
        <textarea ref={ref} required value={value} onChange={(e) => onChange(e.target.value)} placeholder="Write a clear reply..." rows={7} className="w-full p-3 bg-transparent outline-none text-sm resize-y text-slate-100 placeholder-slate-500" />
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: TicketStatus }) {
  const styles: Record<TicketStatus, string> = {
    open: 'bg-blue-500/15 text-blue-300',
    pending_admin: 'bg-yellow-500/15 text-yellow-300',
    pending_customer: 'bg-purple-500/15 text-purple-300',
    resolved: 'bg-emerald-500/15 text-emerald-300',
    closed: 'bg-slate-700 text-slate-300',
  };
  const Icon = status === 'resolved' || status === 'closed' ? CheckCircle2 : AlertCircle;
  return <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs ${styles[status]}`}><Icon className="w-3 h-3" /> {status.replace('_', ' ')}</span>;
}
