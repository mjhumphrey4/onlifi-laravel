import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { AlertCircle, CheckCircle2, Edit, Loader2, MessageSquare, Plus, RefreshCw, Send } from 'lucide-react';
import {
  createTenantSupportTicket,
  getTenantSupportTicket,
  getTenantSupportTickets,
  replyTenantSupportTicket,
  updateTenantSupportTicket,
} from '../utils/api';

type TicketStatus = 'open' | 'pending_admin' | 'pending_customer' | 'resolved' | 'closed';

interface Ticket {
  id: number;
  subject: string;
  category: string;
  priority: 'low' | 'normal' | 'high' | 'urgent';
  status: TicketStatus;
  unread_for_tenant?: boolean;
  last_message_at?: string;
  messages_count?: number;
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

const emptyForm = {
  subject: '',
  category: 'general',
  priority: 'normal',
  body: '',
};

interface TicketFormState {
  subject: string;
  category: string;
  priority: string;
  body: string;
}

const statuses = [
  ['all', 'All'],
  ['open', 'Open'],
  ['pending_admin', 'Waiting on Admin'],
  ['pending_customer', 'Waiting on You'],
  ['resolved', 'Resolved'],
  ['closed', 'Closed'],
];

export function SupportTickets() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [selected, setSelected] = useState<Ticket | null>(null);
  const [status, setStatus] = useState('all');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ ...emptyForm });
  const [reply, setReply] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const loadTickets = async () => {
    setLoading(true);
    setError('');
    try {
      const data = await getTenantSupportTickets({ status });
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
      const data = await getTenantSupportTicket(id);
      setSelected(data.ticket);
      setEditing(false);
      if (updateUrl) setSearchParams({ ticket: String(id) });
    } catch (err: any) {
      setError(err.message || 'Failed to open ticket.');
    }
  };

  const createTicket = async (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const data = await createTenantSupportTicket(form);
      setShowNew(false);
      setForm({ ...emptyForm });
      setMessage('Support ticket created.');
      await loadTickets();
      await openTicket(data.ticket.id);
    } catch (err: any) {
      setError(err.message || 'Failed to create support ticket.');
    } finally {
      setSaving(false);
    }
  };

  const saveTicketEdits = async (event: FormEvent) => {
    event.preventDefault();
    if (!selected) return;
    setSaving(true);
    try {
      const data = await updateTenantSupportTicket(selected.id, {
        subject: form.subject,
        category: form.category,
        priority: form.priority,
        body: form.body,
      });
      setSelected(data.ticket);
      setEditing(false);
      setMessage('Ticket updated.');
      await loadTickets();
    } catch (err: any) {
      setError(err.message || 'Failed to update ticket.');
    } finally {
      setSaving(false);
    }
  };

  const sendReply = async (event: FormEvent) => {
    event.preventDefault();
    if (!selected || !reply.trim()) return;
    setSaving(true);
    try {
      const data = await replyTenantSupportTicket(selected.id, reply);
      setSelected(data.ticket);
      setReply('');
      await loadTickets();
    } catch (err: any) {
      setError(err.message || 'Failed to add reply.');
    } finally {
      setSaving(false);
    }
  };

  const startEdit = () => {
    if (!selected) return;
    setForm({
      subject: selected.subject,
      category: selected.category || 'general',
      priority: selected.priority,
      body: selected.messages?.find((item) => item.sender_type === 'tenant')?.body || '',
    });
    setEditing(true);
  };

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8 space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
            <MessageSquare className="w-7 h-7 text-primary" />
            Support Tickets
          </h1>
          <p className="text-muted-foreground mt-1">Ask for help, track admin replies, and keep your issue history in one place.</p>
        </div>
        <div className="flex gap-2">
          <button onClick={loadTickets} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-border hover:bg-muted">
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </button>
          <button onClick={() => setShowNew(true)} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90">
            <Plus className="w-4 h-4" />
            New Ticket
          </button>
        </div>
      </div>

      {message && <div className="rounded-lg border border-border bg-card p-3 text-sm">{message}</div>}
      {error && <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}

      <div className="flex flex-wrap gap-2">
        {statuses.map(([id, label]) => (
          <button
            key={id}
            onClick={() => setStatus(id)}
            className={`px-3 py-1.5 rounded-lg text-sm border ${status === id ? 'bg-primary text-primary-foreground border-primary' : 'bg-card border-border hover:bg-muted'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {showNew && (
        <TicketForm
          title="Create Support Ticket"
          form={form}
          setForm={setForm}
          saving={saving}
          onSubmit={createTicket}
          onCancel={() => setShowNew(false)}
        />
      )}

      <div className="grid xl:grid-cols-[360px_1fr] gap-6">
        <div className="bg-card border border-border rounded-lg overflow-hidden">
          <div className="p-4 border-b border-border">
            <h2 className="font-semibold">Tickets</h2>
          </div>
          <div className="divide-y divide-border max-h-[720px] overflow-y-auto">
            {loading && tickets.length === 0 ? (
              <div className="p-8 grid place-items-center"><Loader2 className="w-6 h-6 animate-spin text-primary" /></div>
            ) : tickets.length === 0 ? (
              <div className="p-8 text-center text-sm text-muted-foreground">No support tickets yet.</div>
            ) : tickets.map((ticket) => (
              <button
                key={ticket.id}
                onClick={() => openTicket(ticket.id)}
                className={`w-full text-left p-4 hover:bg-muted/60 ${selected?.id === ticket.id ? 'bg-primary/10' : ''}`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-medium truncate">#{ticket.id} {ticket.subject}</p>
                    <p className="text-xs text-muted-foreground mt-1 capitalize">{ticket.category} • {ticket.priority}</p>
                  </div>
                  {ticket.unread_for_tenant && <span className="w-2 h-2 rounded-full bg-primary mt-2" />}
                </div>
                <div className="flex items-center justify-between mt-3">
                  <StatusBadge status={ticket.status} />
                  <span className="text-xs text-muted-foreground">{ticket.last_message_at ? new Date(ticket.last_message_at).toLocaleDateString() : ''}</span>
                </div>
              </button>
            ))}
          </div>
        </div>

        <div className="bg-card border border-border rounded-lg min-h-[560px]">
          {!selected ? (
            <div className="h-full min-h-[560px] grid place-items-center text-center p-8">
              <div>
                <MessageSquare className="w-14 h-14 text-muted-foreground mx-auto mb-3" />
                <p className="font-medium">Select a ticket</p>
                <p className="text-sm text-muted-foreground mt-1">Open a ticket thread or create a new one.</p>
              </div>
            </div>
          ) : editing ? (
            <div className="p-5">
              <TicketForm
                title="Edit Ticket"
                form={form}
                setForm={setForm}
                saving={saving}
                onSubmit={saveTicketEdits}
                onCancel={() => setEditing(false)}
              />
            </div>
          ) : (
            <div className="flex flex-col min-h-[720px]">
              <div className="p-5 border-b border-border flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-semibold">#{selected.id} {selected.subject}</h2>
                    <StatusBadge status={selected.status} />
                  </div>
                  <p className="text-sm text-muted-foreground mt-1 capitalize">{selected.category} • {selected.priority} priority</p>
                </div>
                <button onClick={startEdit} className="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-border hover:bg-muted">
                  <Edit className="w-4 h-4" />
                  Edit
                </button>
              </div>

              <div className="flex-1 p-5 space-y-4 overflow-y-auto">
                {(selected.messages || []).map((item) => (
                  <div key={item.id} className={`rounded-lg border p-4 ${item.sender_type === 'tenant' ? 'bg-primary/5 border-primary/20 ml-auto max-w-[88%]' : 'bg-muted/60 border-border mr-auto max-w-[88%]'}`}>
                    <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground mb-2">
                      <span className="font-medium">{item.sender_name} • {item.sender_type === 'tenant' ? 'You' : 'Admin'}</span>
                      <span>{item.created_at ? new Date(item.created_at).toLocaleString() : ''}{item.edited_at ? ' • edited' : ''}</span>
                    </div>
                    <p className="whitespace-pre-wrap text-sm leading-6">{item.body}</p>
                  </div>
                ))}
              </div>

              <form onSubmit={sendReply} className="p-5 border-t border-border space-y-3">
                <FriendlyEditor value={reply} onChange={setReply} placeholder="Write your reply..." />
                <div className="flex justify-end">
                  <button disabled={saving || !reply.trim()} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
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

function TicketForm({
  title,
  form,
  setForm,
  saving,
  onSubmit,
  onCancel,
}: {
  title: string;
  form: TicketFormState;
  setForm: (form: TicketFormState) => void;
  saving: boolean;
  onSubmit: (event: FormEvent) => void;
  onCancel: () => void;
}) {
  return (
    <form onSubmit={onSubmit} className="bg-card border border-border rounded-lg p-5 space-y-4">
      <h2 className="font-semibold">{title}</h2>
      <div className="grid md:grid-cols-2 gap-4">
        <label className="space-y-1 md:col-span-2">
          <span className="text-sm text-muted-foreground">Subject</span>
          <input required value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg" />
        </label>
        <label className="space-y-1">
          <span className="text-sm text-muted-foreground">Category</span>
          <select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg">
            <option value="general">General</option>
            <option value="billing">Billing</option>
            <option value="radius">RADIUS</option>
            <option value="router">Router</option>
            <option value="payments">Payments</option>
          </select>
        </label>
        <label className="space-y-1">
          <span className="text-sm text-muted-foreground">Priority</span>
          <select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} className="w-full px-3 py-2 bg-background border border-input rounded-lg">
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </label>
      </div>
      <FriendlyEditor value={form.body} onChange={(body) => setForm({ ...form, body })} placeholder="Describe the issue, expected result, and anything you already tried..." />
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onCancel} className="px-4 py-2 rounded-lg border border-border hover:bg-muted">Cancel</button>
        <button disabled={saving || !form.subject.trim() || !form.body.trim()} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
          {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
          Save
        </button>
      </div>
    </form>
  );
}

function FriendlyEditor({ value, onChange, placeholder }: { value: string; onChange: (value: string) => void; placeholder: string }) {
  const ref = useRef<HTMLTextAreaElement | null>(null);
  const [preview, setPreview] = useState(false);

  const insert = (before: string, after = '') => {
    const target = ref.current;
    if (!target) return;
    const start = target.selectionStart;
    const end = target.selectionEnd;
    const selected = value.slice(start, end);
    const next = value.slice(0, start) + before + selected + after + value.slice(end);
    onChange(next);
    window.setTimeout(() => target.focus(), 0);
  };

  return (
    <div className="rounded-lg border border-input overflow-hidden bg-background">
      <div className="flex flex-wrap items-center gap-1 p-2 border-b border-border bg-muted/50">
        <button type="button" onClick={() => insert('**', '**')} className="px-2 py-1 rounded text-sm font-bold hover:bg-background">B</button>
        <button type="button" onClick={() => insert('_', '_')} className="px-2 py-1 rounded text-sm italic hover:bg-background">I</button>
        <button type="button" onClick={() => insert('- ')} className="px-2 py-1 rounded text-sm hover:bg-background">List</button>
        <button type="button" onClick={() => insert('[', '](https://)')} className="px-2 py-1 rounded text-sm hover:bg-background">Link</button>
        <button type="button" onClick={() => setPreview(!preview)} className="ml-auto px-2 py-1 rounded text-sm hover:bg-background">{preview ? 'Edit' : 'Preview'}</button>
      </div>
      {preview ? (
        <div className="min-h-[160px] p-3 text-sm whitespace-pre-wrap leading-6">{value || 'Nothing to preview yet.'}</div>
      ) : (
        <textarea ref={ref} required value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} rows={7} className="w-full p-3 bg-transparent outline-none text-sm resize-y" />
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: TicketStatus }) {
  const styles: Record<TicketStatus, string> = {
    open: 'bg-blue-500/10 text-blue-600',
    pending_admin: 'bg-yellow-500/10 text-yellow-600',
    pending_customer: 'bg-purple-500/10 text-purple-600',
    resolved: 'bg-emerald-500/10 text-emerald-600',
    closed: 'bg-muted text-muted-foreground',
  };
  const Icon = status === 'resolved' || status === 'closed' ? CheckCircle2 : AlertCircle;
  return <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs ${styles[status]}`}><Icon className="w-3 h-3" /> {status.replace('_', ' ')}</span>;
}
