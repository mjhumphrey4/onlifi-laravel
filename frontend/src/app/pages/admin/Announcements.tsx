import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card';
import { Button } from '@/app/components/ui/button';
import { Badge } from '@/app/components/ui/badge';
import { Input } from '@/app/components/ui/input';
import { Label } from '@/app/components/ui/label';
import { Textarea } from '@/app/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/app/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/app/components/ui/dialog';
import { Alert, AlertDescription } from '@/app/components/ui/alert';
import { Plus, Edit, Trash2, Megaphone } from 'lucide-react';

interface Announcement {
  id: number;
  title: string;
  content: string;
  type: 'info' | 'warning' | 'success' | 'error';
  target: 'all' | 'active' | 'trial' | 'specific';
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string;
}

export default function Announcements() {
  const navigate = useNavigate();
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [loading, setLoading] = useState(true);
  const [showDialog, setShowDialog] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [formData, setFormData] = useState({
    title: '',
    content: '',
    type: 'info',
    target: 'all',
    is_active: true,
    starts_at: '',
    ends_at: '',
  });
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    fetchAnnouncements();
  }, []);

  const fetchAnnouncements = async () => {
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch('/api/super-admin/announcements', {
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (!response.ok) throw new Error('Failed to fetch');
      const data = await response.json();
      setAnnouncements(data.data || []);
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to load announcements' });
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const token = localStorage.getItem('admin_token');
    const url = editingId 
      ? `/api/super-admin/announcements/${editingId}`
      : '/api/super-admin/announcements';
    
    try {
      const response = await fetch(url, {
        method: editingId ? 'PUT' : 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData),
      });

      if (!response.ok) throw new Error('Failed to save');
      
      setMessage({ type: 'success', text: `Announcement ${editingId ? 'updated' : 'created'} successfully` });
      setShowDialog(false);
      resetForm();
      fetchAnnouncements();
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to save announcement' });
    }
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this announcement?')) return;

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/announcements/${id}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` },
      });

      if (!response.ok) throw new Error('Failed to delete');
      
      setMessage({ type: 'success', text: 'Announcement deleted successfully' });
      fetchAnnouncements();
    } catch (error) {
      setMessage({ type: 'error', text: 'Failed to delete announcement' });
    }
  };

  const openEditDialog = (announcement: Announcement) => {
    setEditingId(announcement.id);
    setFormData({
      title: announcement.title,
      content: announcement.content,
      type: announcement.type,
      target: announcement.target,
      is_active: announcement.is_active,
      starts_at: announcement.starts_at || '',
      ends_at: announcement.ends_at || '',
    });
    setShowDialog(true);
  };

  const resetForm = () => {
    setEditingId(null);
    setFormData({
      title: '',
      content: '',
      type: 'info',
      target: 'all',
      is_active: true,
      starts_at: '',
      ends_at: '',
    });
  };

  const getTypeBadgeColor = (type: string) => {
    const colors = {
      info: 'bg-blue-100 text-blue-800',
      warning: 'bg-yellow-100 text-yellow-800',
      success: 'bg-green-100 text-green-800',
      error: 'bg-red-100 text-red-800',
    };
    return colors[type as keyof typeof colors] || colors.info;
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Announcements</h1>
          <p className="text-gray-600 mt-1">Manage system-wide announcements</p>
        </div>
        <Button onClick={() => { resetForm(); setShowDialog(true); }}>
          <Plus className="h-4 w-4 mr-2" />
          New Announcement
        </Button>
      </div>

      {message && (
        <Alert variant={message.type === 'error' ? 'destructive' : 'default'}>
          <AlertDescription>{message.text}</AlertDescription>
        </Alert>
      )}

      {loading ? (
        <div className="text-center py-12">Loading...</div>
      ) : announcements.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Megaphone className="h-16 w-16 text-gray-400 mb-4" />
            <h3 className="text-xl font-semibold text-gray-700 mb-2">No Announcements</h3>
            <p className="text-gray-500">Create your first announcement</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {announcements.map((announcement) => (
            <Card key={announcement.id}>
              <CardHeader>
                <div className="flex justify-between items-start">
                  <div className="flex-1">
                    <div className="flex items-center gap-2 mb-2">
                      <CardTitle>{announcement.title}</CardTitle>
                      <Badge className={getTypeBadgeColor(announcement.type)}>
                        {announcement.type}
                      </Badge>
                      {announcement.is_active ? (
                        <Badge variant="outline" className="bg-green-50 text-green-700">Active</Badge>
                      ) : (
                        <Badge variant="outline" className="bg-gray-50 text-gray-700">Inactive</Badge>
                      )}
                    </div>
                    <CardDescription>{announcement.content}</CardDescription>
                    <div className="mt-2 text-xs text-gray-500">
                      Target: {announcement.target} | Created: {new Date(announcement.created_at).toLocaleDateString()}
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline" onClick={() => openEditDialog(announcement)}>
                      <Edit className="h-4 w-4" />
                    </Button>
                    <Button size="sm" variant="destructive" onClick={() => handleDelete(announcement.id)}>
                      <Trash2 className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </CardHeader>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editingId ? 'Edit' : 'Create'} Announcement</DialogTitle>
            <DialogDescription>
              {editingId ? 'Update the announcement details' : 'Create a new system announcement'}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="title">Title</Label>
              <Input
                id="title"
                value={formData.title}
                onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="content">Content</Label>
              <Textarea
                id="content"
                value={formData.content}
                onChange={(e) => setFormData({ ...formData, content: e.target.value })}
                rows={4}
                required
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="type">Type</Label>
                <Select value={formData.type} onValueChange={(value) => setFormData({ ...formData, type: value })}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="info">Info</SelectItem>
                    <SelectItem value="warning">Warning</SelectItem>
                    <SelectItem value="success">Success</SelectItem>
                    <SelectItem value="error">Error</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="target">Target</Label>
                <Select value={formData.target} onValueChange={(value) => setFormData({ ...formData, target: value })}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Tenants</SelectItem>
                    <SelectItem value="active">Active Only</SelectItem>
                    <SelectItem value="trial">Trial Only</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setShowDialog(false)}>Cancel</Button>
              <Button type="submit">{editingId ? 'Update' : 'Create'}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
